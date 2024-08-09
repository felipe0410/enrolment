<?php

namespace go1\core\learning_record\enquiry;

use DateTime;
use go1\clients\MqClient;
use go1\enrolment\domain\ConnectionWrapper;
use go1\util\DB;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\model\Edge;
use go1\util\queue\Queue;
use RuntimeException;

class EnquiryRepository
{
    private ConnectionWrapper $go1;
    private MqClient $queue;

    public function __construct(ConnectionWrapper $go1, MqClient $queue)
    {
        $this->go1 = $go1;
        $this->queue = $queue;
    }

    public function queue(): MqClient
    {
        return $this->queue;
    }

    public function findEnquiry(int $loId, int $userId, $fetchAll = false)
    {
        $sql = 'SELECT * FROM gc_ro';
        $sql .= ' WHERE type = ? AND source_id = ?';
        $sql .= ' AND target_id = ?';
        $sql .= ' ORDER BY id DESC';

        if (!$fetchAll) {
            $sql .= ' LIMIT 1';
        }

        $edges = $this->go1->get()
            ->executeQuery($sql, [EdgeTypes::HAS_ENQUIRY, $loId, $userId], [DB::INTEGER, DB::INTEGER, DB::INTEGER])
            ->fetchAll(DB::OBJ);

        foreach ($edges as $i => $row) {
            $edges[$i] = Edge::create($row);
        }

        return $fetchAll ? $edges : reset($edges);
    }

    public function create($lo, $user, string $firstName, string $lastName, $phone, string $message, bool $reEnquiry = false, int $liEventId = null)
    {
        $data = [
            'course'     => $lo->title,
            'first'      => $firstName,
            'last'       => $lastName,
            'mail'       => $user->mail,
            'phone'      => $phone ?: '',
            'body'       => $message,
            'status'     => EnquiryServiceProvider::ENQUIRY_PENDING,
            'created'    => time(),
            'updated'    => null,
            'updated_by' => null,
        ];

        if ($reEnquiry) {
            $data['re_enquiry'] = true;
        }

        if ($liEventId) {
            $data['event'] = $liEventId;
        }

        return EdgeHelper::link($this->go1->get(), $this->queue, EdgeTypes::HAS_ENQUIRY, $lo->id, $user->id, 0, $data);
    }

    public function update(int $id, string $status, $manager): bool
    {
        if (!$original = EdgeHelper::load($this->go1->get(), $id)) {
            return false;
        }

        // Check if queueing service is available
        $queueAvailable = $this->queue->isAvailable();
        if (!$queueAvailable) {
            throw new RuntimeException("Queue not available");
        }

        $data = $original->data;
        $data->status = $status;
        $data->changed = (new DateTime())->format(DATE_ISO8601);
        $data->updated_by = $manager->mail;
        $this->go1->get()->update('gc_ro', ['data' => json_encode($data)], ['id' => $id]);

        $edge = EdgeHelper::load($this->go1->get(), $id);
        $edge->original = clone $original;
        $this->queue->publish($edge->jsonSerialize(), Queue::RO_UPDATE);

        return true;
    }

    public function archive(int $id, int $enrolmentId): bool
    {
        $data['changed'] = (new DateTime())->format(DATE_ISO8601);

        if (!$original = EdgeHelper::load($this->go1->get(), $id)) {
            return false;
        }

        // Check if queueing service is available
        $queueAvailable = $this->queue->isAvailable();
        if (!$queueAvailable) {
            throw new RuntimeException("Queue not available");
        }

        $data = $original->data;
        $data->changed = (new DateTime())->format(DATE_ISO8601);
        $data->student_id = $original->targetId;
        $data->enrolment_id = $enrolmentId;

        $this->go1->get()->update(
            'gc_ro',
            [
                'type'      => EdgeTypes::HAS_ARCHIVED_ENQUIRY,
                'target_id' => $enrolmentId,
                'data'      => json_encode($data),
            ],
            ['id' => $id]
        );

        $edge = EdgeHelper::load($this->go1->get(), $id);
        $edge->original = clone $original;
        $this->queue->publish($edge->jsonSerialize(), Queue::RO_UPDATE);

        return true;
    }
}
