<?php

namespace go1\enrolment\consumer;

use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\domain\etc\EnrolmentPlanRepository;
use go1\util\contract\ServiceConsumerInterface;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanTypes;
use go1\util\queue\Queue;
use stdClass;

class EnrolmentPlanConsumer implements ServiceConsumerInterface
{
    private ConnectionWrapper       $go1;
    private EnrolmentPlanRepository $enrolmentPlanRepository;
    private PlanRepository          $planRepository;

    public function __construct(
        ConnectionWrapper       $go1,
        EnrolmentPlanRepository $enrolmentPlanRepository,
        PlanRepository          $planRepository
    ) {
        $this->go1 = $go1;
        $this->enrolmentPlanRepository = $enrolmentPlanRepository;
        $this->planRepository = $planRepository;
    }

    public function aware(): array
    {
        return [
            Queue::PLAN_CREATE      => 'Link plan to enrolment',
        ];
    }

    public function consume(string $routingKey, stdClass $body, stdClass $context = null): void
    {
        switch ($routingKey) {
            case Queue::PLAN_CREATE:
                $this->onPlanCreate($body);
                break;
        }
    }

    private function onPlanCreate(stdClass $plan): void
    {
        $loadedPlan = $this->planRepository->load($plan->id);
        if ($loadedPlan && $loadedPlan->entityType == PlanTypes::ENTITY_LO) {
            $enrolment = EnrolmentHelper::findEnrolment($this->go1->get(), $plan->instance_id, $plan->user_id, $plan->entity_id, 0);

            if ($enrolment) {
                if (!$this->enrolmentPlanRepository->has($enrolment->id, $plan->id)) {
                    $this->enrolmentPlanRepository->create($enrolment->id, $plan->id);
                }
            }
        }
    }
}
