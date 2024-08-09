<?php

namespace go1\enrolment\services;

use go1\core\util\client\federation_api\v1\PortalAccountMapper;
use go1\util\enrolment\event_publishing\EnrolmentEventsEmbedder;
use go1\util\plan\event_publishing\PlanCreateEventEmbedder;
use go1\util\plan\event_publishing\PlanUpdateEventEmbedder;

class EnrolmentEventPublishingService
{
    private UserService             $userService;
    private EnrolmentEventsEmbedder $eventsEmbedder;
    private PlanCreateEventEmbedder $planCreateEventEmbedder;
    private PlanUpdateEventEmbedder $planUpdateEventEmbedder;

    public function __construct(
        UserService             $userService,
        EnrolmentEventsEmbedder $eventsEmbedder,
        PlanCreateEventEmbedder $planCreateEventEmbedder,
        PlanUpdateEventEmbedder $planUpdateEventEmbedder
    ) {
        $this->userService = $userService;
        $this->eventsEmbedder = $eventsEmbedder;
        $this->planCreateEventEmbedder = $planCreateEventEmbedder;
        $this->planUpdateEventEmbedder = $planUpdateEventEmbedder;
    }

    public function enrolmentEventEmbedder(): EnrolmentEventsEmbedder
    {
        return $this->eventsEmbedder;
    }

    public function planCreateEventEmbedder(): PlanCreateEventEmbedder
    {
        return $this->planCreateEventEmbedder;
    }

    public function planUpdateEventEmbedder(): PlanUpdateEventEmbedder
    {
        return $this->planUpdateEventEmbedder;
    }

    public function embedPortalAccount(int $userId, array &$embedded)
    {
        $portal = $embedded['portal'] ?? null;
        if ($portal) {
            $user = $this->userService->load($userId, $portal->title);
            $account = $user ? $user->account : null;
            if ($account) {
                $embedded['account'] = PortalAccountMapper::toLegacyStandardFormat($user, $account, $portal);
            }
        }
    }
}
