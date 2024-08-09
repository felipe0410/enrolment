<?php

namespace go1\enrolment\domain\etc;

use DateInterval;
use DateTime as DefaultDateTime;
use go1\enrolment\domain\ConnectionWrapper;
use go1\util\DateTime;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\lo\LoSuggestedCompletionTypes;
use go1\util\model\Enrolment;
use RuntimeException;

class SuggestedCompletionCalculator
{
    private ConnectionWrapper $go1;

    public function __construct(ConnectionWrapper $go1)
    {
        $this->go1 = $go1;
    }

    public function calculate(int $type, $value, Enrolment $enrolment): ?DefaultDateTime
    {
        $date = $this->dueDate($type, $value, $enrolment);
        if ($date) {
            switch ($type) {
                case LoSuggestedCompletionTypes::E_DURATION:
                case LoSuggestedCompletionTypes::COURSE_ENROLMENT:
                case LoSuggestedCompletionTypes::E_PARENT_DURATION:
                    $date->add(DateInterval::createFromDateString($value));
                    break;
            }
        }

        return $date;
    }

    public function dueDate(int $type, $value, Enrolment $enrolment): ?DefaultDateTime
    {
        switch ($type) {
            case LoSuggestedCompletionTypes::DUE_DATE:
                return DateTime::create($value);

            case LoSuggestedCompletionTypes::E_DURATION:
                return DateTime::create($enrolment->startDate ?? time(), 'UTC', true);

            case LoSuggestedCompletionTypes::E_PARENT_DURATION:
                if ($enrolment->parentLoId) {
                    $parentEnrolment = EnrolmentHelper::findEnrolment(
                        $this->go1->get(),
                        $enrolment->takenPortalId,
                        $enrolment->userId,
                        $enrolment->parentLoId
                    );

                    if ($parentEnrolment) {
                        if ($suggestedCompletion = $this->getSettings($enrolment->parentLoId)) {
                            [$parentType, $parentValue] = $suggestedCompletion;

                            return $this->dueDate($parentType, $parentValue, $parentEnrolment);
                        }

                        return DateTime::create($parentEnrolment->startDate, 'UTC', true);
                    }
                }

                return null;

            case LoSuggestedCompletionTypes::COURSE_ENROLMENT:
                $liEnrolment = EnrolmentHelper::load($this->go1->get(), $enrolment->id);
                $courseEnrolment = ($liEnrolment) ? EnrolmentHelper::parentEnrolment($this->go1->get(), Enrolment::create($liEnrolment)) : null;
                if ($courseEnrolment) {
                    return DateTime::create($courseEnrolment->startDate, 'UTC', true);
                }

                return null;

            default:
                throw new RuntimeException('Unknown suggested completion type: ' . $type);
        }
    }

    public function getSettings(int $loId, ?int $parentLoId = null): array
    {
        $data = $this->go1->get()
            ->createQueryBuilder()
            ->from('gc_ro')
            ->select('data')
            ->andWhere('type = :type AND source_id = :loId AND target_id = :edgeId')
            ->setParameters([
                ':type'   => EdgeTypes::HAS_SUGGESTED_COMPLETION,
                ':loId'   => $loId,
                ':edgeId' => (int) (
                    !$parentLoId ? 0 : $this->go1->get()
                        ->fetchColumn(
                            'SELECT id FROM gc_ro WHERE type = ? AND source_id = ? AND target_id = ?',
                            [EdgeTypes::HAS_LI, $parentLoId, $loId]
                        )
                ),
            ])
            ->execute()
            ->fetchColumn();

        if ($data) {
            $data = json_decode($data);

            return [$data->type, $data->value];
        }

        return [];
    }
}
