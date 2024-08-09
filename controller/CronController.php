<?php

namespace go1\enrolment\controller;

use Doctrine\DBAL\Connection;
use go1\enrolment\domain\ConnectionWrapper;
use go1\clients\MqClient;
use go1\enrolment\EnrolmentRepository;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;
use go1\util\Timeout;
use Symfony\Component\HttpFoundation\JsonResponse;

class CronController
{
    private ConnectionWrapper $db;
    private EnrolmentRepository $repository;
    private int $time;
    private MqClient $queue;

    public const TASK_CHECK_EXPIRING = 'checkExpiringEnrolments';
    public const TASK_ENABLE_PENDING = 'enablePendingEnrolments';

    public function __construct(
        ConnectionWrapper $db,
        EnrolmentRepository $repository,
        MqClient $mqClient
    ) {
        $this->db = $db;
        $this->repository = $repository;
        $this->queue = $mqClient;
        $this->time = time();
    }

    public function post(): JsonResponse
    {
        $this->queue->publish(['task' => static::TASK_CHECK_EXPIRING], Queue::DO_ENROLMENT_CRON);
        $this->queue->publish(['task' => static::TASK_ENABLE_PENDING], Queue::DO_ENROLMENT_CRON);

        return new JsonResponse(null, 204);
    }

    public function checkExpiringEnrolments(int $timeout = 60): void
    {
        // Find EXPIRING enrolments
        $q = 'SELECT id, source_id FROM gc_ro WHERE type = ? AND target_id <= ?';
        $q = $this->db->get()->executeQuery($q, [EdgeTypes::SCHEDULE_EXPIRE_ENROLMENT, strtotime('+ 15 minutes', $this->time)]);
        while ($edge = $q->fetch(DB::OBJ)) {
            if (Timeout::over($this->time, $timeout)) {
                break;
            }

            DB::safeThread($this->db->get(), "cron:expiring:{$edge->source_id}", 10, function (Connection $db) use ($edge) {
                if (!$enrolment = EnrolmentHelper::loadSingle($this->db->get(), $edge->source_id)) {
                    $db->delete('gc_ro', ['id' => $edge->id]);
                } else {
                    $context = [
                        'action'  => 'invalid-expried-enrolment',
                        'actorId' => 0,
                        'note'    => 'Cron - Invalid expried enrolment',
                    ];

                    $this->repository->changeStatus($enrolment, EnrolmentStatuses::EXPIRED, $context);
                    $db->update('gc_ro', ['type' => EdgeTypes::SCHEDULE_EXPIRE_ENROLMENT_DONE], ['id' => $edge->id]);
                }
            });
        }
    }

    public function enablePendingEnrolments(int $timeout = 60): void
    {
        $tenMinutes = strtotime('- 10 minutes', $this->time);
        $tenSeconds = 10;

        // Find the LO to be started in 10 minutes
        $q = 'SELECT id, source_id FROM gc_ro WHERE type = ? AND target_id <= ?';
        $q = $this->db->get()->executeQuery($q, [EdgeTypes::PUBLISH_ENROLMENT_LO_START_BASE, $tenMinutes]);
        while ($edge = $q->fetch(DB::OBJ)) {
            if (Timeout::over($this->time, $timeout)) {
                break;
            }

            DB::safeThread(
                $this->db->get(),
                "cron:publish:{$edge->source_id}",
                $tenSeconds,
                function (Connection $db) use ($edge) {
                    // Find PENDING enrolments from LO
                    $qq = 'SELECT profile_id, taken_instance_id, user_id FROM gc_enrolment WHERE lo_id = ? AND status = ?';
                    $qq = $db->executeQuery($qq, [$edge->source_id, EnrolmentStatuses::PENDING]);
                    while ($row = $qq->fetch(DB::OBJ)) {
                        $userId = $row->user_id;
                        $takenInstanceId = $row->taken_instance_id;

                        $context = [
                            'action'  => 'activate-upcoming-10minutes-enrolment',
                            'actorId' => 0,
                            'note'    => 'Cron - Activate upcoming (10 minutes) enrolment',
                        ];

                        // Change the status from PENDING to IN-PROGRESS
                        $enrolment = EnrolmentHelper::findEnrolment(
                            $this->db->get(),
                            $takenInstanceId,
                            $userId,
                            $edge->source_id
                        );
                        if ($enrolment) {
                            $this->repository->changeStatus($enrolment, EnrolmentStatuses::IN_PROGRESS, $context);
                        }
                    }

                    $db->update('gc_ro', ['type' => EdgeTypes::PUBLISH_ENROLMENT_LO_START_BASE_DONE], ['id' => $edge->id]);
                }
            );
        }
    }
}
