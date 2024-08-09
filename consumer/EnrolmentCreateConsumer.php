<?php

namespace go1\enrolment\consumer;

use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\domain\etc\SuggestedCompletionCalculator;
use go1\util\contract\ServiceConsumerInterface;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LoHelper;
use go1\util\model\Enrolment;
use go1\util\plan\Plan;
use go1\util\plan\PlanHelper;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanStatuses;
use go1\util\plan\PlanTypes;
use go1\util\queue\Queue;
use stdClass;

class EnrolmentCreateConsumer implements ServiceConsumerInterface
{
    private ConnectionWrapper             $go1;
    private PlanRepository                $planRepository;
    private SuggestedCompletionCalculator $calculator;
    private EnrolmentRepository           $rEnrolment;

    public function __construct(
        ConnectionWrapper             $go1,
        PlanRepository                $planRepository,
        SuggestedCompletionCalculator $calculator,
        EnrolmentRepository           $rEnrolment
    ) {
        $this->go1 = $go1;
        $this->planRepository = $planRepository;
        $this->calculator = $calculator;
        $this->rEnrolment = $rEnrolment;
    }

    public function aware(): array
    {
        return [
            Queue::ENROLMENT_UPDATE => "Calculate suggested completion when change from 'not-started' to 'in-progress'",
        ];
    }

    public function consume(string $routingKey, stdClass $enrolment, stdClass $context = null): bool
    {
        switch ($routingKey) {
            case Queue::ENROLMENT_UPDATE:
                if ((EnrolmentStatuses::IN_PROGRESS == $enrolment->status) && (EnrolmentStatuses::NOT_STARTED == $enrolment->original->status)) {
                    $this->doCalculate(Enrolment::create($enrolment));
                }
                break;
        }

        return true;
    }

    private function doCalculate(Enrolment $enrolment): void
    {
        // Load lo
        if (!$lo = LoHelper::load($this->go1->get(), $enrolment->loId)) {
            return;
        }

        // Standalone LI added to module
        $parentLoId = LoHelper::isSingleLi($lo) ? $enrolment->parentLoId : 0;
        $entityType = ($parentLoId) ? PlanTypes::ENTITY_RO : PlanTypes::ENTITY_LO;

        // Get entity id
        $types = [EdgeTypes::HAS_SUGGESTED_COMPLETION];
        $targetId = $parentLoId ? EdgeHelper::hasLink($this->go1->get(), EdgeTypes::HAS_LI, $parentLoId, $lo->id) : 0;
        $edges = $targetId ? EdgeHelper::edges($this->go1->get(), [$lo->id], [$targetId], $types) : EdgeHelper::edgesFromSource($this->go1->get(), $lo->id, $types);
        $entityId = ($parentLoId && $edges) ? $edges[0]->id : $enrolment->loId;

        // Do not do anything if plan already exists
        $plan = PlanHelper::loadByEntityAndUser($this->go1->get(), $entityType, $entityId, $enrolment->userId);
        if ($plan) {
            return;
        }

        // Get suggested completion settings
        if (!$settings = $this->calculator->getSettings($enrolment->loId, $parentLoId)) {
            return;
        }

        // Get suggested completion due date
        [$type, $value] = $settings;
        $dueDate = $this->calculator->calculate($type, $value, $enrolment);
        if (!$dueDate) {
            return;
        }

        // Upsert plan and create enrolment plan link
        $plan = Plan::create((object) [
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'instance_id' => $enrolment->takenPortalId,
            'user_id' => $enrolment->userId,
            'status' => PlanStatuses::ASSIGNED
        ]);
        $plan->due = $dueDate;
        $planId = $this->planRepository->merge($plan);
        if (!$this->rEnrolment->foundLink($planId, $enrolment->id)) {
            $this->rEnrolment->linkPlan($planId, $enrolment->id);
        }
    }
}
