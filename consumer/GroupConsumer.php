<?php

namespace go1\enrolment\consumer;

use go1\enrolment\domain\ConnectionWrapper;
use go1\clients\MqClient;
use go1\core\util\client\UserDomainHelper;
use go1\util\contract\ServiceConsumerInterface;
use go1\util\group\GroupAssignTypes;
use go1\util\group\GroupHelper;
use go1\util\group\GroupItemTypes;
use go1\util\plan\Plan;
use go1\util\plan\PlanStatuses;
use go1\util\plan\PlanTypes;
use go1\util\queue\Queue;
use stdClass;

class GroupConsumer implements ServiceConsumerInterface
{
    private ConnectionWrapper       $social;
    private MqClient         $queue;
    private UserDomainHelper $userDomainHelper;

    public function __construct(
        ConnectionWrapper $social,
        MqClient $queue,
        UserDomainHelper $userDomainHelper
    ) {
        $this->social = $social;
        $this->queue = $queue;
        $this->userDomainHelper = $userDomainHelper;
    }

    public function aware(): array
    {
        return [
            Queue::GROUP_ITEM_CREATE => 'Create assigned plan when assigned user to a group',
        ];
    }

    public function consume(string $routingKey, stdClass $groupItem, stdClass $context = null): bool
    {
        if (GroupItemTypes::USER != $groupItem->entity_type) {
            return true;
        }

        if (!$group = GroupHelper::load($this->social->get(), $groupItem->group_id)) {
            return false;
        }

        if (!$portalAccount = $this->userDomainHelper->loadPortalAccount($groupItem->entity_id, '', true)) {
            return false;
        }
        $assigns = GroupHelper::groupAssignments($this->social->get(), $groupItem->group_id, ['entityType' => GroupAssignTypes::LO]);

        foreach ($assigns as $assign) {
            $plan = Plan::create((object) [
                'type'         => PlanTypes::ASSIGN,
                'user_id'      => $portalAccount->user->legacyId,
                'assigner_id'  => null,
                'instance_id'  => $group->instance_id,
                'entity_type'  => PlanTypes::ENTITY_LO,
                'entity_id'    => $assign->entity_id,
                'status'       => PlanStatuses::ASSIGNED,
                'due_date'     => $assign->due_date ?? null,
                'created_date' => time(),
                'data'         => null,
            ]);

            $this->queue->publish(
                $plan->jsonSerialize(),
                Queue::DO_ENROLMENT_PLAN_CREATE,
                [
                    'group_id' => $groupItem->group_id,
                    'notify'   => ($context->notify ?? true),
                ]
            );
        }
        return true;
    }
}
