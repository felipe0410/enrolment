<?php

namespace go1\enrolment\consumer;

use go1\enrolment\domain\ConnectionWrapper;
use go1\clients\MqClient;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\util\contract\ServiceConsumerInterface;
use go1\util\DB;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\event_publishing\Events;
use go1\util\lo\LoHelper;
use go1\util\lo\LoTypes;
use go1\util\model\Enrolment;
use go1\util\plan\Plan;
use go1\util\plan\PlanRepository;
use go1\util\queue\Queue;
use Psr\Log\LoggerInterface;
use stdClass;

class DoEnrolmentConsumer implements ServiceConsumerInterface
{
    private ConnectionWrapper      $db;
    private MqClient               $queue;
    private EnrolmentRepository    $repository;
    private PlanRepository         $rPlan;
    private UserDomainHelper       $userDomainHelper;
    private LoggerInterface        $logger;

    public function __construct(
        ConnectionWrapper      $db,
        MqClient               $queue,
        EnrolmentRepository    $repository,
        PlanRepository         $planRepository,
        UserDomainHelper       $userDomainHelper,
        LoggerInterface        $logger
    ) {
        $this->db = $db;
        $this->queue = $queue;
        $this->repository = $repository;
        $this->rPlan = $planRepository;
        $this->userDomainHelper = $userDomainHelper;
        $this->logger = $logger;
    }

    public function aware(): array
    {
        return [
            Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENT  => 'If user already completed all dependencies, we change the status to "in-progress"',
            Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENTS => 'For each pending-enrolment in a module, if user already completed all dependencies, we change the status to "in-progress"',
            Queue::DO_ENROLMENT_PLAN_CREATE             => 'Create plan on DB',
            Queue::DO_ENROLMENT                         => 'Only update enrolment on DB (for now)',
            Events::EVENT_ATTENDANCE_DELETE             => "Archive attendance's enrolment"
        ];
    }

    public function consume(string $routingKey, stdClass $payload, stdClass $context = null): bool
    {
        switch ($routingKey) {
            case Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENT:
                $moduleId = $payload->moduleId;
                $enrolmentId = $payload->enrolmentId;
                $this->doCheckModuleEnrolment($moduleId, $enrolmentId);
                break;

            case Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENTS:
                $this->doCheckModuleEnrolments($payload->moduleId, $payload->userId);
                break;

            case Events::EVENT_ATTENDANCE_DELETE:
                $this->doDeleteEnrolmentViaAttendance($payload);
                break;

            case Queue::DO_ENROLMENT_PLAN_CREATE:
                $this->doPlanCreate($payload, $context);
                break;

            case Queue::DO_ENROLMENT:
                $this->onDoEnrolment($payload);
                break;
        }

        return true;
    }

    private function doCheckModuleEnrolment(int $moduleId, int $enrolmentId): void
    {
        $enrolment = 'SELECT * FROM gc_enrolment WHERE lo_id = ? AND id = ? AND status = ?';
        $enrolment = $this->db->get()->executeQuery($enrolment, [$moduleId, $enrolmentId, EnrolmentStatuses::PENDING])->fetch(DB::OBJ);
        if ($enrolment) {
            # Continue the logic from self::doCheckModuleEnrolments()
            # If user already completed all dependencies, we change the status to "in-progress"
            if (EnrolmentHelper::dependenciesCompleted($this->db->get(), $enrolment)) {
                $context = [
                    'action'  => 'invalid-pending-dependent-enrolment',
                    'actorId' => 0,
                    'note'    => 'Cron - Invalid pending status for dependent enrolment',
                ];

                $_ = Enrolment::create($enrolment);
                $this->repository->changeStatus($_, EnrolmentStatuses::IN_PROGRESS, $context);
            }
        }
    }

    private function doCheckModuleEnrolments(int $moduleId, int $userId): void
    {
        # Continue the logic from self::onEnrolmentUpdate()
        # For each pending-enrolment in a module, if user already completed all dependencies, we change the status to "in-progress"
        # But the number of enrolment can be large, we queue messages to be processed later.
        $lo = LoHelper::load($this->db->get(), $moduleId);
        if ($lo && ($lo->type == LoTypes::MODULE)) {
            $relatedEnrolmentIds = $this->db->get()->executeQuery(
                'SELECT id FROM gc_enrolment WHERE user_id = ? AND lo_id = ? AND status = ?',
                [$userId, $moduleId, EnrolmentStatuses::PENDING],
                [DB::INTEGER, DB::INTEGER, DB::STRING]
            );
            while ($relatedEnrolmentId = $relatedEnrolmentIds->fetchColumn()) {
                $this->queue->publish(
                    ['moduleId' => $moduleId, 'enrolmentId' => $relatedEnrolmentId],
                    Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENT
                );
            }
        }
    }

    private function doUpdateEnrolment(stdClass $body): void
    {
        if ($enrolment = $this->repository->load($body->id)) {
            $data = array_intersect_key((array) $body, [
                'start_date' => 1,
                'end_date'   => 1,
                'due_date'   => 1,
                'result'     => 1,
                'status'     => 1,
                'pass'       => 1,
            ]);

            if ($data) {
                $metadata = json_decode(json_encode($enrolment->data), true) ?: [];
                $metadata['history'] = $metadata['history'] ?? [];
                $metadata['history'][] = [
                    'action'          => $body->action ?? 'updated-via-queue',
                    'actorId'         => $body->actorId ?? 0,
                    'status'          => $data['status'] ?? null,
                    'original_status' => $enrolment->status,
                    'pass'            => $data['pass'] ?? null,
                    'original_pass'   => $enrolment->pass,
                    'timestamp'       => time(),
                ];

                $data['data'] = json_encode($metadata);
            }

            $context = $body->context ?? [];
            $context = is_array($context) ? $context : json_decode(json_encode($context), true);
            $data && $this->repository->update($enrolment->id, $data, true, false, $context);
        }
    }

    private function doDeleteEnrolmentViaAttendance(stdClass $attendance): void
    {
        if (!empty($attendance->enrolment_id)) {
            if ($enrolment = EnrolmentHelper::loadSingle($this->db->get(), $attendance->enrolment_id)) {
                $this->repository->deleteEnrolment($enrolment, 0, false);
            }
        }
    }

    private function doPlanCreate(stdClass $body, stdClass $context = null): void
    {
        if (empty($body->entity_id)) {
            $this->logger->error(sprintf('Bad message %s', json_encode($body)));

            return;
        }

        $lo = LoHelper::load($this->db->get(), $body->entity_id);
        $user = $this->userDomainHelper->loadUser($body->user_id);

        if ($lo && $user) {
            $plan = Plan::create($body);
            $dataContext = isset($context) ? json_decode(json_encode($context), true) : [];
            $this->rPlan->merge($plan, $body->notify, $dataContext);
        }
    }

    private function onDoEnrolment(stdClass $payload): void
    {
        switch ($payload->action) {
            case Queue::ENROLMENT_UPDATE:
                $body = $payload->body ?? null;
                if ($body) {
                    $this->doUpdateEnrolment($body);
                }
                break;
        }
    }
}
