<?php

namespace go1\enrolment\services;

use DateTimeImmutable;
use DateTimeZone;
use go1\util\DateTime;
use go1\enrolment\Constants;
use go1\clients\MqClient;
use go1\core\learning_record\plan\util\PlanReference;
use go1\enrolment\domain\ConnectionWrapper;
use go1\util\DB;
use go1\util\plan\Plan;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanStatuses;
use go1\util\queue\Queue;
use RuntimeException;

/**
 * @property ConnectionWrapper $write
 * @property ConnectionWrapper $read
 * @property PlanRepository $rPlan
 * @property MqClient $queue
 * @property EnrolmentEventPublishingService $publishingService;
 */
trait AssignmentMutationService
{
    public function mergePlan(Context $ctx, Plan $plan, array $embedded = [], bool $batch = false, bool $apiUpliftV3 = false)
    {
        $keys = [
            'user_id'     => $plan->userId,
            'instance_id' => $plan->instanceId,
            'entity_type' => $plan->entityType,
            'entity_id'   => $plan->entityId,
            'type'        => $plan->type,
        ];

        $values = $keys + [
                'assigner_id' => $plan->assignerId,
                'due_date' => $plan->due
                    ? $plan->due->setTimeZone(new DateTimeZone("UTC"))->format(Constants::DATE_MYSQL) : null,
                'created_date' => ($plan->created
                    ?? new DateTime())->setTimeZone(new DateTimeZone("UTC"))->format(Constants::DATE_MYSQL),
                'updated_at' => (new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))->format(Constants::DATE_MYSQL)
            ];

        $original = $this->write->get()
            ->createQueryBuilder()
            ->select('*')
            ->from('gc_plan', 'p')
            ->where('user_id = :userId')
            ->andWhere('instance_id = :instanceId')
            ->andWhere('entity_type = :entityType')
            ->andWhere('entity_id = :entityId')
            ->andWhere('type = :type')
            ->setParameters([
                ':userId'     => $plan->userId,
                ':instanceId' => $plan->instanceId,
                ':entityType' => $plan->entityType,
                ':entityId'   => $plan->entityId,
                ':type'       => $plan->type,
            ])
            ->execute()
            ->fetch(DB::OBJ);

        if ($original) {
            if (!$apiUpliftV3) {
                $values['created_date'] = $original->created_date;
            }
        } else {
            if (!$plan->due && !$apiUpliftV3) {
                return null;
            }

            $values['status'] = PlanStatuses::SCHEDULED;
        }

        // Check if queueing service is available
        $queueAvailable = $this->queue->isAvailable();
        if (!$queueAvailable) {
            throw new RuntimeException("Queue not available");
        }

        DB::merge($this->write->get(), 'gc_plan', $keys, $values);

        $planId = $original->id ?? $this->write->get()->lastInsertId('gc_plan');
        $plan = $this->rPlan->load($planId);

        if ($original) {
            $original = Plan::create($original);
            $this->rPlan->createRevision($original);
        }

        // Publishing event: Don't need to do this if…
        if (!$ctx->onCreatingEnrolment() && !$apiUpliftV3) {
            if ($plan) {
                if ($original) {
                    $plan->original = $original;
                }

                $payload = $plan->jsonSerialize();
                $payload['embedded'] = $embedded + $this->publishingService->planCreateEventEmbedder()->embedded($plan);
                $this->queue->batchAdd($payload, $original ? Queue::PLAN_UPDATE : Queue::PLAN_CREATE, [
                    MqClient::CONTEXT_PORTAL_NAME => $plan->instanceId,
                    MqClient::CONTEXT_ENTITY_TYPE => 'po',
                ]);
            }

            if (!$batch) {
                $this->queue->batchDone();
            }
        }

        return $plan;
    }

    public function linkPlanReference(PlanReference $planRef)
    {
        $original = $this->loadPlanReference($planRef);
        if (!$original) {
            $writeDb = $this->write->get();
            $writeDb->insert('gc_plan_reference', $planRef->toCreateArray());
            $planReferenceId = (int) $writeDb->lastInsertId('gc_plan_reference');
            $this->queue->publish($planRef->toCreateArray() + ['id' => $planReferenceId], Queue::PLAN_REFERENCE_CREATE);
        }
    }

    private function updatePlanReference(PlanReference $originalPlanRef, PlanReference $planRef)
    {
        $this->write->get()->update('gc_plan_reference', $planRef->toUpdateArray(), ['id' => $planRef->id]);
        $originalPlanRefArray = $originalPlanRef->toCreateArray() + ['id' => $planRef->id];
        $planRefArray = $planRef->toCreateArray() + ['id' => $planRef->id];
        $this->queue->publish($planRefArray + ['original' => $originalPlanRefArray], Queue::PLAN_REFERENCE_UPDATE);
    }
}
