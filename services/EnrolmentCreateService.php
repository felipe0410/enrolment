<?php

namespace go1\enrolment\services;

use go1\util\DateTime as DateTimeHelper;
use stdClass;
use DateTime;
use DateTimeZone;
use Exception;
use PDOException;
use go1\clients\MqClient;
use go1\enrolment\Constants;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\EnrolmentCreateOption;
use go1\enrolment\services\lo\LoAccessoryRepository;
use go1\enrolment\exceptions\ResourceAlreadyExistsException;
use go1\core\learning_record\plan\util\PlanReference;
use go1\util\Error;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\lo\LoHelper;
use go1\util\lo\LoTypes;
use go1\util\model\Enrolment;
use go1\util\plan\Plan;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanStatuses;
use go1\util\plan\PlanTypes;
use go1\util\queue\Queue;
use go1\util\payment\TransactionStatus;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;

class EnrolmentCreateResult
{
    public int $code;
    public $enrolment;
    public string $message;

    /**
     * @param stdClass|Enrolment $enrolment
     */
    public function __construct(int $code, string $message, $enrolment)
    {
        $this->code = $code;
        $this->message = $message;
        $this->enrolment = $enrolment;
    }
}

class EnrolmentCreateService
{
    private LoggerInterface $logger;
    private ConnectionWrapper $write;
    private EnrolmentEventPublishingService $publishingService;
    private EnrolmentRepository $repository;
    private PlanRepository $planRepo;
    private MqClient $queue;
    private EnrolmentDueService $dueService;
    private LoAccessoryRepository $loAccessoryRepo;

    public function __construct(
        LoggerInterface $logger,
        ConnectionWrapper $write,
        EnrolmentEventPublishingService $publishingService,
        EnrolmentRepository $repository,
        PlanRepository $planRepo,
        MqClient $queue,
        EnrolmentDueService $dueService,
        LoAccessoryRepository $loAccessoryRepo
    ) {
        $this->logger = $logger;
        $this->write = $write;
        $this->publishingService = $publishingService;
        $this->repository = $repository;
        $this->planRepo = $planRepo;
        $this->queue = $queue;
        $this->dueService = $dueService;
        $this->loAccessoryRepo = $loAccessoryRepo;
    }

    /**
     * @param stdClass | bool $existingEnrolment
     * @param DateTime | bool $dueDate
     * @param DateTime | bool $assignDate
     * @param bool $apiUpliftV3 indicate whether from new endpoint
     */
    public function create(
        Enrolment $newEnrolment,
        $existingEnrolment = false,
        ?int $enrolmentType = EnrolmentOriginalTypes::I_SELF_DIRECTED,
        bool $reEnrol = false,
        bool $reCalculate = false,
        $dueDate = false,
        ?int $assignerId = null,
        $assignDate = false,
        bool $notify = true,
        bool $apiUpliftV3 = false,
        PlanReference $planRef = null
    ): EnrolmentCreateResult {
        try {
            if (!$lo = LoHelper::load($this->write->get(), $newEnrolment->loId)) {
                $message = "LO $newEnrolment->loId not found";
                $this->logger->error($message);
                return new EnrolmentCreateResult(400, $message, (object) ['id' => 0]);
            }
        } catch (Exception $e) {
            $message = 'Failed to query existing lo';
            $this->logger->error($message, [
                'exception' => $e
            ]);
            return new EnrolmentCreateResult(500, $message, (object) ['id' => 0]);
        }

        if ($existingEnrolment && !$reEnrol) {
            $msg = 'Enrollment already exists. To create a new enrollment and archive the current enrollment, include the re_enroll=true parameter.';
            return new EnrolmentCreateResult(
                Error::CONFLICT,
                $msg,
                (object) $existingEnrolment
            );
        }

        try {
            // Check if queueing service is available
            $queueAvailable = $this->queue->isAvailable();
            if (!$queueAvailable) {
                throw new RuntimeException("Queue not available");
            }

            $this->write->get()->beginTransaction();

            // archive enrolment
            if ($existingEnrolment && $reEnrol) {
                $this->repository->deleteEnrolment(
                    Enrolment::create($existingEnrolment),
                    $assignerId ?? 0,
                    true,
                    null,
                    true,
                    true
                );
            }

            // archive plan for v3 endpoint
            if ($apiUpliftV3) {
                $this->archivePlanIfExists($newEnrolment);
            }

            $row = [];
            $ctx = new Context('enrolment.create');

            $this
                ->insert($newEnrolment, $row)
                ->processPlan(
                    $ctx,
                    $newEnrolment,
                    $lo,
                    $assignerId,
                    $assignDate,
                    $dueDate,
                    $apiUpliftV3,
                    $enrolmentType,
                    $planRef
                )
                ->spreadStatus($newEnrolment, $reCalculate)
                ->streamLog($newEnrolment, $row, $assignerId ?? 0)
                ->commit()
                ->publishEvent($newEnrolment, $lo, $dueDate, $assignerId, $notify);

            $newRecord = array_merge(['id' => $newEnrolment->id], $row);
            return new EnrolmentCreateResult(200, '', (object) $newRecord);
        } catch (Exception $e) {
            $this->rollBack();

            try {
                $this->checkForExistingEnrolment($e, $newEnrolment);
            } catch (ResourceAlreadyExistsException $e) {
                return new EnrolmentCreateResult(
                    Error::CONFLICT,
                    $e->getMessage(),
                    (object) ['id' => $e->getExistingId()]
                );
            }

            $message = 'Exception occurred when creating enrolment';
            $this->logger->error($message, [
                'exception' => $e,
                'old_id' => $existingEnrolment->id ?? null,
                'new_id' => $newEnrolment->id ?? null,
                'lo_id' => $newEnrolment->loId,
                'user_id' => $newEnrolment->userId,
                'taken_instance_id' => $newEnrolment->takenPortalId,
                'parent_enrolment_id' => $newEnrolment->parentEnrolmentId
            ]);
            return new EnrolmentCreateResult(500, $e->getMessage(), (object) ['id' => 0]);
        }
    }

    /**
     * @param Exception $e
     * @param Enrolment $enrolment
     * @throws ResourceAlreadyExistsException
     */
    private function checkForExistingEnrolment(Exception $e, $enrolment): void
    {
        if ($previousError = $e->getPrevious()) {
            // Handling this error https://dev.mysql.com/doc/mysql-errors/5.7/en/server-error-reference.html#error_er_dup_entry
            if ($previousError instanceof PDOException && $previousError->getCode() == 23000) {
                // Check whether enrolment exist already
                $enrolment = $this->repository
                    ->loadByLoAndUserAndTakenInstanceId(
                        $enrolment->loId,
                        $enrolment->userId,
                        $enrolment->takenPortalId,
                        $enrolment->parentEnrolmentId
                    );
                if ($enrolment) {
                    $msg = 'Enrollment already exists. To create a new enrollment and archive the current enrollment, include the re_enroll=true parameter.';
                    throw new ResourceAlreadyExistsException($msg, $enrolment->id);
                }
            }
        }
    }

    /**
     * This function is only used for legacy endpoint
     * @param stdClass | bool $enrolment
     */
    public function preProcessEnrolment(
        $enrolment,
        EnrolmentCreateOption $option
    ): ?JsonResponse {
        if ($enrolment && !$option->reEnrol) {
            if ((EnrolmentStatuses::IN_PROGRESS == $option->status)
                && (EnrolmentStatuses::NOT_STARTED == $enrolment->status)
                && is_object($option->transaction)
                && isset($option->transaction->status)
                && ($option->transaction->status == TransactionStatus::COMPLETED)
            ) {
                $param = [
                    'status' => $option->status,
                    'actor_id' => $option->assigner->id ?? null,
                    'due_date' => $option->dueDate,
                ];
            }

            if ((EnrolmentStatuses::IN_PROGRESS == $enrolment->status) &&
                ($option->startDate)) {
                $param = ['start_date' => DateTimeHelper::atom($option->startDate, Constants::DATE_MYSQL) ];
            }

            if (!empty($param)) {
                $this->repository->update($enrolment->id, $param);
            }

            return new JsonResponse(['id' => (int) $enrolment->id]);
        }

        $option->startDate = $option->startDate
            ?: (new DateTime())->format(DATE_ISO8601);
        if (EnrolmentStatuses::COMPLETED === $option->status) {
            $option->endDate = $option->endDate
                ?: (new DateTime())->format(DATE_ISO8601);
        } else {
            $option->endDate = null;
        }

        return null;
    }

    public function postProcessEnrolmentTracking(
        int $enrolmentId,
        ?int $enrolmentOriginalType,
        int $actorId
    ): void {
        if (!$enrolmentOriginalType) {
            $planIds = $this->repository->getPlanIdsForEnrolment(
                $this->write->get(),
                $enrolmentId
            );

            $enrolmentOriginalType = $planIds
                ? EnrolmentOriginalTypes::I_ASSIGNED
                    : EnrolmentOriginalTypes::I_SELF_DIRECTED;
        }

        $this->repository->createEnrolmentTracking(
            $enrolmentId,
            $enrolmentOriginalType,
            $actorId
        );
    }

    public function postProcessLoForEnrolment(
        int $enrolmentId,
        EnrolmentCreateOption $option
    ): void {
        if ($option->parentLearningObjectId) {
            $this->loAccessoryRepo->attachExpiringSchedule(
                $option,
                $enrolmentId
            );

            if (LoTypes::MODULE === $option->learningObject->type) {
                $this->loAccessoryRepo->addEnrolmentInstructor(
                    $enrolmentId,
                    $option->learningObject->id,
                    $option->parentLearningObjectId
                );
            }
        }

        if (isset($option->assigner->id)) {
            $this->repository->linkAssign($option->assigner, $enrolmentId);
        }

        if ($option->transaction) {
            $this->loAccessoryRepo->addEnrolmentTransaction(
                $enrolmentId,
                $option->transaction
            );
        }

        if ($option->attributes) {
            $this->loAccessoryRepo->createEnrolmentAttributes(
                $enrolmentId,
                $option->attributes
            );
        }
    }

    private function insert(Enrolment &$enrolment, array &$row): EnrolmentCreateService
    {
        $enrolment->timestamp = time();
        $this->write->get()->insert('gc_enrolment', $row = $this->buildRow($enrolment));
        $enrolment->id = (int) $this->write->get()->lastInsertId('gc_enrolment');
        return $this;
    }

    private function buildRow(Enrolment $enrolment): array
    {
        $row = [
            'profile_id' => $enrolment->profileId,
            'user_id' => $enrolment->userId,
            'parent_lo_id' => $enrolment->parentLoId,
            'parent_enrolment_id' => $enrolment->parentEnrolmentId,
            'lo_id' => $enrolment->loId,
            'instance_id' => 0, // This is no longer important, we will drop it soon.
            'taken_instance_id' => $enrolment->takenPortalId,
            'status' => $enrolment->status,
            'start_date' => $enrolment->startDate
                ? (new DateTime($enrolment->startDate))
                    ->setTimezone(new DateTimeZone("UTC"))->format(Constants::DATE_MYSQL)
                    : null,
            'end_date' => $enrolment->endDate
                ? (new DateTime($enrolment->endDate))
                    ->setTimezone(new DateTimeZone("UTC"))->format(Constants::DATE_MYSQL)
                    : null,
            'result' => $enrolment->result,
            'pass' => $enrolment->pass,
            'changed' => (new DateTime())->format(Constants::DATE_MYSQL),
            'timestamp' => $enrolment->timestamp,
            'data' => json_encode($enrolment->data),
        ];

        return $row;
    }

    private function archivePlanIfExists(Enrolment $newEnrolment): void
    {
        $existPlans = $this->planRepo->loadUserPlanByEntity(
            $newEnrolment->takenPortalId,
            $newEnrolment->userId,
            $newEnrolment->loId
        );
        if ($existPlans) {
            foreach ($existPlans as $plan) {
                $this->planRepo->archive($plan->id, [], ['notify' => false]);
                $this->repository->removeEnrolmentPlansByPlanId($plan->id);
                $this->repository->deletePlanReference($plan->id);
            }
        }
    }

    private function processPlan(
        Context $ctx,
        Enrolment $enrolment,
        stdClass $lo,
        $assignerId,
        $assignDate,
        $dueDate,
        bool $apiUpliftV3,
        ?int $enrolmentType,
        ?PlanReference $planRef
    ): EnrolmentCreateService {
        # Only allow to create a plan for a standalone learning item enrolment
        if (!empty($enrolment->parentEnrolmentId)) {
            return $this;
        }

        if (!$apiUpliftV3) {
            $this->processPlanLegacy(
                $ctx,
                $enrolment,
                $lo,
                $assignerId,
                $dueDate
            );
        } elseif ($enrolmentType == EnrolmentOriginalTypes::I_ASSIGNED) {
            $this->processPlanV3(
                $enrolment,
                $lo,
                $assignerId,
                $assignDate,
                $dueDate,
                $planRef
            );
        }

        return $this;
    }

    private function processPlanLegacy(
        Context $ctx,
        Enrolment $enrolment,
        stdClass $lo,
        $assignerId,
        $dueDate
    ): EnrolmentCreateService {
        $this->dueService->onEnrolmentCreate(
            $ctx,
            $enrolment,
            $lo,
            $assignerId,
            $dueDate,
            true
        );

        return $this;
    }

    private function processPlanV3(
        Enrolment $enrolment,
        stdClass $lo,
        $assignerId,
        $assignDate,
        $dueDate,
        ?PlanReference $planRef
    ): void {
        $plan = Plan::create((object) [
            'user_id' => $enrolment->userId,
            'assigner_id' => $assignerId,
            'instance_id' => $enrolment->takenPortalId,
            'entity_type' => PlanTypes::ENTITY_LO,
            'entity_id' => $lo->id,
            'status' => PlanStatuses::SCHEDULED,
            'due_date' => $dueDate
                ? (new DateTime($dueDate))->format(DATE_ATOM) : null,
            'created_date' => (new DateTime($assignDate))->format(DATE_ATOM),
            'data' => null
        ]);

        $this->planRepo->create($plan, true);
        $this->repository->linkPlan($plan->id, $enrolment->id);
        if ($planRef) {
            $planRef->setPlanId($plan->id);
            $this->repository->linkPlanReference($planRef);
        }
    }

    private function spreadStatus(
        Enrolment $enrolment,
        bool $reCalculate
    ): EnrolmentCreateService {
        $ctx = [];
        $this->repository->spreadStatusByEnrolment(
            $enrolment,
            $reCalculate,
            $ctx,
            true
        );

        return $this;
    }

    private function publishEvent(
        Enrolment $enrolment,
        stdClass $lo,
        $dueDate,
        ?int $assignerId,
        bool $notify
    ): EnrolmentCreateService {
        $embedded = $this->publishingService->enrolmentEventEmbedder()
            ->embedded((object) $enrolment->jsonSerialize());
        $this->publishingService->embedPortalAccount(
            $enrolment->userId,
            $embedded
        );

        $body = $enrolment->jsonSerialize();
        if ((false !== $dueDate) && (LiTypes::EVENT == $lo->type)) {
            $body['due_date'] = $dueDate;
        }

        $body = $enrolment->jsonSerialize();
        $body['embedded'] = $embedded;

        if (false !== $dueDate) {
            if (LiTypes::EVENT == $lo->type) {
                $body['due_date'] = $dueDate;
            }
        }

        $this->queue->batchAdd($body, Queue::ENROLMENT_CREATE, [
            'notify_email' => $notify,
            MqClient::CONTEXT_ACTOR_ID => $assignerId,
        ]);
        $this->queue->batchDone();

        return $this;
    }

    private function streamLog(
        Enrolment $enrolment,
        array $row,
        int $actorId
    ): EnrolmentCreateService {
        $this->write->get()->insert('enrolment_stream', [
            'portal_id' => $enrolment->takenPortalId,
            'action' => 'create',
            'created' => time(),
            'enrolment_id' => $enrolment->id,
            'payload' => json_encode($row),
            'actor_id' => $actorId
        ]);
        return $this;
    }

    private function commit(): EnrolmentCreateService
    {
        if ($this->write->get()->getTransactionNestingLevel() !== 0) {
            $this->write->get()->commit();
        }
        return $this;
    }

    private function rollBack()
    {
        if ($this->write->get()->getTransactionNestingLevel() !== 0) {
            $this->write->get()->rollBack();
        }
    }
}
