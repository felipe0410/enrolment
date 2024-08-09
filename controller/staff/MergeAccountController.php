<?php

namespace go1\enrolment\controller\staff;

use go1\clients\MqClient;
use go1\util\AccessChecker;
use go1\util\Error;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class MergeAccountController
{
    private MqClient           $queue;
    private AccessChecker      $accessChecker;

    public const TASK = 'merge_account';

    public function __construct(MqClient $mqClient, AccessChecker $accessChecker)
    {
        $this->queue = $mqClient;
        $this->accessChecker = $accessChecker;
    }

    public function post(Request $req, int $portalId, string $from, string $to): JsonResponse
    {
        if (!$user = $this->accessChecker->isAccountsAdmin($req)) {
            return Error::jr403('Permission denied.');
        }

        if (strtolower($from) == strtolower($to)) {
            return Error::jr('From and to email must be different.');
        }

        $this->queue->publish(
            [
                'action'    => static::TASK,
                'from'      => $from,
                'to'        => $to,
                'portal_id' => $portalId,
            ],
            'etc.merge-account',
            [
                'actorUserId' => $user->id
            ]
        );

        return new JsonResponse(null, 204);
    }
}
