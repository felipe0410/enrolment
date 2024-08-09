<?php

namespace go1\core\learning_record\attribute;

use Doctrine\DBAL\Connection;
use go1\clients\MqClient;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\util\DateTime as DateTimeHelper;
use go1\util\DB;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\lo\LoTypes;
use go1\util\queue\Queue;

class EnrolmentAttributeRepository
{
    private ConnectionWrapper $db;
    private ConnectionWrapper $go1;
    private EnrolmentRepository $rEnrolment;
    private MqClient $queue;

    public function __construct(
        ConnectionWrapper $db,
        ConnectionWrapper $go1,
        EnrolmentRepository $rEnrolment,
        MqClient $mqClient
    ) {
        $this->db = $db;
        $this->go1 = $go1;
        $this->rEnrolment = $rEnrolment;
        $this->queue = $mqClient;
    }

    public function create(EnrolmentAttributes $attribute)
    {
        $this->db->get()->insert('enrolment_attributes', [
            'enrolment_id' => $attribute->enrolmentId,
            '`key`'        => $attribute->key,
            'value'        => $attribute->value,
            'created'      => $attribute->created,
        ]);

        return (int) $this->db->get()->lastInsertId('enrolment_attributes');
    }

    public function load(int $id): ?EnrolmentAttributes
    {
        return ($attribute = $this->loadMultiple([$id])) ? $attribute[0] : null;
    }

    public function loadMultiple(array $ids): array
    {
        $attributes = $this->db->get()
            ->executeQuery('SELECT * FROM enrolment_attributes WHERE id IN (?)', [$ids], [DB::INTEGERS])
            ->fetchAll(DB::OBJ);

        return array_map(function ($_) {
            return EnrolmentAttributes::create($_);
        }, $attributes);
    }

    public function loadBy(int $enrolmentId, int $key): ?EnrolmentAttributes
    {
        $_ = $this->db->get()
            ->executeQuery(
                'SELECT * FROM enrolment_attributes WHERE enrolment_id = ? AND `key` = ?',
                [$enrolmentId, $key],
                [DB::INTEGER, DB::INTEGER]
            )->fetch(DB::OBJ);

        return $_ ? EnrolmentAttributes::create($_) : null;
    }

    public function update(EnrolmentAttributes $attribute)
    {
        $this->db->get()->update('enrolment_attributes', [
            'enrolment_id' => $attribute->enrolmentId,
            '`key`'        => $attribute->key,
            'value'        => $attribute->value,
        ], ['id' => $attribute->id]);
    }

    private function isValidKey($key): bool
    {
        return in_array($key, EnrolmentAttributes::all());
    }

    public function loadByEnrolmentId(int $id): array
    {
        $_ = $this->db->get()
            ->executeQuery(
                'SELECT * FROM enrolment_attributes WHERE enrolment_id = ?',
                [$id],
                [DB::INTEGER]
            )->fetchAll(DB::OBJ);

        return array_map(function ($_) {
            return EnrolmentAttributes::create($_);
        }, $_);
    }

    public static function attachAwardAttribute(Connection $enrolment, int $enrolmentId): array
    {
        $q = 'SELECT `key`, value FROM enrolment_attributes WHERE enrolment_id = ? AND `key` IN (?)';
        $q = $enrolment
            ->executeQuery(
                $q,
                [$enrolmentId, [EnrolmentAttributes::AWARD_REQUIRED, EnrolmentAttributes::AWARD_ACHIEVED]],
                [DB::INTEGER, DB::INTEGERS]
            );

        while ($_ = $q->fetch(DB::OBJ)) {
            switch ($_->key) {
                case EnrolmentAttributes::AWARD_REQUIRED:
                    $required = json_decode($_->value) ?? [];
                    break;
                case EnrolmentAttributes::AWARD_ACHIEVED:
                    $achieved = json_decode($_->value) ?? [];
                    break;
            }
        }

        return [
            'required' => $required ?? [],
            'achieved' => $achieved ?? [],
        ];
    }

    public function publish(int $enrolmentId)
    {
        if (!$enrolment = $this->rEnrolment->load($enrolmentId)) {
            return;
        }

        if ($enrolment->lo_type != LoTypes::ACHIEVEMENT) {
            return;
        }

        $original = EnrolmentHelper::loadSingle($this->go1->get(), $enrolmentId);
        // original enrolment
        $enrolment->original = $original->jsonSerialize();
        $enrolment->original['start_date'] = $enrolment->original['start_date'] ? DateTimeHelper::atom($enrolment->original['start_date'], DATE_ISO8601) : null;
        $enrolment->original['end_date'] = $enrolment->original['end_date'] ? DateTimeHelper::atom($enrolment->original['end_date'], DATE_ISO8601) : null;
        $enrolment->original['changed'] = $enrolment->original['changed'] ? DateTimeHelper::atom($enrolment->original['changed'], DATE_ISO8601) : null;

        unset($enrolment->data);
        unset($enrolment->original['data']);
        $enrolment->award = self::attachAwardAttribute($this->db->get(), $enrolmentId);
        $this->queue->publish(
            (array) $enrolment,
            Queue::ENROLMENT_UPDATE,
            [
                MqClient::CONTEXT_PORTAL_NAME => $original->takenPortalId,
                MqClient::CONTEXT_ENTITY_TYPE => 'enrolment',
            ]
        );
    }
}
