<?php

namespace go1\enrolment\services;

use DateInterval;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\lo\CompletionRule;
use go1\enrolment\services\lo\LoService;
use go1\util\DateTime;
use go1\util\lo\LoHelper;
use go1\util\lo\LoSuggestedCompletionTypes;
use go1\util\model\Enrolment;
use go1\util\plan\Plan;
use go1\util\plan\PlanStatuses;
use RuntimeException;
use stdClass;

class EnrolmentDueService
{
    private EnrolmentRepository $repository;
    private LoService           $loService;

    public function __construct(EnrolmentRepository $repository, LoService $loService)
    {
        $this->repository = $repository;
        $this->loService = $loService;
    }

    public function onEnrolmentCreate(Context $ctx, Enrolment $enrolment, stdClass $lo, $assignerId, $dueDate, bool $batch = false)
    {
        if (false === $dueDate) {
            if ($_ = $this->findCompletionRule($enrolment, $lo)) {
                [$completionRule, $dueDate] = $_;
            }
        }

        if (false !== $dueDate) {
            if (is_string($dueDate)) {
                $dueDate = DateTime::create($dueDate);
            }

            $this->insertAssignment($ctx, $enrolment, $assignerId, $dueDate, $completionRule ?? null, $batch);
        } else {
            # Only care on course & standalone learning item enrolment
            if (empty($enrolment->parentEnrolmentId)) {
                $this->loadAndLinkEnrolmentPlan($enrolment);
            }
        }
    }

    private function findCompletionRule(Enrolment $enrolment, stdClass $lo): array
    {
        // Standalone LI added to module
        $parentLoId = LoHelper::isSingleLi($lo) ? $enrolment->parentLoId : 0;
        if (!$completionRule = $this->loService->getCompletionRule($enrolment->loId, $parentLoId)) {
            return [];
        }

        $dueDate = call_user_func(
            function () use ($completionRule, $enrolment, $lo) {
                $baseDate = $this->findBaseDate($enrolment, $completionRule, $lo);

                if ($baseDate) {
                    switch ($completionRule->type()) {
                        case LoSuggestedCompletionTypes::E_DURATION:
                        case LoSuggestedCompletionTypes::COURSE_ENROLMENT:
                        case LoSuggestedCompletionTypes::E_PARENT_DURATION:
                            $baseDate->add(DateInterval::createFromDateString($completionRule->value()));
                            break;
                    }
                }

                return $baseDate;
            }
        );

        return !$dueDate ? [] : [$completionRule, $dueDate];
    }

    private function findBaseDate(Enrolment $enrolment, CompletionRule $rule, ?stdClass $lo = null)
    {
        switch ($rule->type()) {
            case LoSuggestedCompletionTypes::DUE_DATE:
                return DateTime::create($rule->value());

            case LoSuggestedCompletionTypes::E_DURATION:
                return DateTime::create($enrolment->startDate ?? time(), 'UTC', true);

            case LoSuggestedCompletionTypes::E_PARENT_DURATION:
                if ($enrolment->parentEnrolmentId) {
                    $parentEnrolment = $this->repository->load($enrolment->parentEnrolmentId);

                    if ($parentEnrolment) {
                        if ($parentRule = $this->loService->getCompletionRule($enrolment->parentLoId)) {
                            return $this->findBaseDate(Enrolment::create($parentEnrolment), $parentRule);
                        }

                        return DateTime::create($parentEnrolment->start_date, 'UTC', true);
                    }
                }

                return null;

            case LoSuggestedCompletionTypes::COURSE_ENROLMENT:
                $lo = $lo ?: $this->loService->load($enrolment->loId);
                $courseId = $this->repository->findCourseId($enrolment, $lo->type);
                if ($courseId) {
                    $courseEnrolment = $this->repository->loadByLoAndUserAndTakenInstanceId($courseId, $enrolment->userId, $enrolment->takenPortalId);
                    if ($courseEnrolment) {
                        return DateTime::create($courseEnrolment->start_date, 'UTC', true);
                    }
                }

                return null;

            default:
                throw new RuntimeException('Unknown suggested completion type: ' . $rule->type());
        }
    }

    private function insertAssignment(Context $ctx, Enrolment $enrolment, $assignerId, ?\DateTime $dueDate, ?CompletionRule $completionRule, bool $batch = false)
    {
        $plan = Plan::create((object) [
            'user_id'      => $enrolment->userId,
            'assigner_id'  => $assignerId,
            'instance_id'  => $enrolment->takenPortalId,
            'entity_type'  => !$completionRule ? 'lo' : $completionRule->getEntityType(),
            'entity_id'    => !$completionRule ? $enrolment->loId : $completionRule->getEntityId(),
            'status'       => PlanStatuses::SCHEDULED,
            'due_date'     => !$dueDate ? null : $dueDate->getTimestamp(),
            'created_date' => time(),
            'data'         => null,
        ]);

        $plan = $this->repository->mergePlan($ctx, $plan, ['account' => null]);
        if ($plan) {
            $this->linkExistingPlan($plan->id, $enrolment->id, $batch);
        }
    }

    private function linkExistingPlan(int $planId, int $enrolmentId, bool $isPublished = true, bool $batch = false)
    {
        if (!$this->repository->foundLink($planId, $enrolmentId)) {
            $this->repository->linkPlan($planId, $enrolmentId, $isPublished, $batch);
        }
    }

    private function loadAndLinkEnrolmentPlan(Enrolment $enrolment)
    {
        $planId = $this->repository->loadUserPlanIdByEntity($enrolment->takenPortalId, $enrolment->userId, $enrolment->loId);
        $planId && $this->linkExistingPlan($planId, $enrolment->id, false);
    }
}
