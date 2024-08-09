<?php

namespace go1\enrolment;

use DateTime;
use Exception;
use Doctrine\DBAL\Connection;
use go1\clients\MqClient;
use go1\core\learning_record\attribute\EnrolmentAttributeRepository;
use go1\core\learning_record\plan\util\PlanReference;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\controller\create\LTIConsumerClient;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\AssignmentMutationService;
use go1\enrolment\services\AssignmentQueryServiceTrait;
use go1\enrolment\services\Context;
use go1\enrolment\services\EnrolmentEventPublishingService;
use go1\enrolment\services\EnrolmentMutationServiceTrait;
use go1\enrolment\services\EnrolmentQueryServiceTrait;
use go1\enrolment\services\EnrolmentValidationServiceTrait;
use go1\enrolment\services\lo\LoService;
use go1\enrolment\services\UserService;
use go1\util\AccessChecker;
use go1\util\DateTime as DateTimeHelper;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\EntityTypes;
use go1\util\lo\LiTypes;
use go1\util\lo\LoTypes;
use go1\util\model\Enrolment;
use go1\util\plan\Plan;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanStatuses;
use go1\util\plan\PlanTypes;
use go1\util\portal\PortalChecker;
use go1\util\queue\Queue;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

class EnrolmentRepository
{
    use EnrolmentValidationServiceTrait;
    use EnrolmentQueryServiceTrait;
    use AssignmentQueryServiceTrait;
    use EnrolmentMutationServiceTrait;
    use AssignmentMutationService;

    private LoggerInterface $logger;
    protected ConnectionWrapper $read;
    protected ConnectionWrapper $write;
    private ConnectionWrapper $db;
    private PlanRepository $rPlan;
    private AccessChecker $accessChecker;
    private PortalChecker $portalChecker;
    protected MqClient $queue;
    private UserDomainHelper $userDomainHelper;
    private LoService $loService;
    private UserService $userService;
    protected EnrolmentEventPublishingService $publishingService;
    private LTIConsumerClient $ltiConsumerClient;

    public function __construct(
        LoggerInterface $logger,
        ConnectionWrapper $read,
        ConnectionWrapper $write,
        ConnectionWrapper $db,
        PlanRepository $planRepository,
        AccessChecker $accessChecker,
        MqClient $mqClient,
        PortalChecker $portalChecker,
        UserDomainHelper $userDomainHelper,
        LTIConsumerClient $ltiConsumerClient,
        LoService $loService,
        UserService $userService,
        EnrolmentEventPublishingService $publishingService
    ) {
        $this->logger = $logger;
        $this->read = $read;
        $this->write = $write;
        $this->db = $db;
        $this->rPlan = $planRepository;
        $this->accessChecker = $accessChecker;
        $this->queue = $mqClient;
        $this->portalChecker = $portalChecker;
        $this->userDomainHelper = $userDomainHelper;
        $this->ltiConsumerClient = $ltiConsumerClient;
        $this->loService = $loService;
        $this->userService = $userService;
        $this->publishingService = $publishingService;
    }

    public function loService(): LoService
    {
        return $this->loService;
    }

    /**
     * @param int   $id
     * @param array $data
     * @param bool  $spreadStatus
     * @param bool  $reCalculate
     * @param array $context
     * @return bool
     * @throws Exception
     */
    public function update(int $id, array $data, bool $spreadStatus = true, bool $reCalculate = false, array $context = [], bool $apiUpliftV3 = false): bool
    {
        $data['changed'] = !empty($data['changed']) ? $data['changed'] : (new DateTime())->format(Constants::DATE_MYSQL);
        $note = $data['note'] ?? '';
        $dueDate = array_key_exists('due_date', $data) ? $data['due_date'] : false;
        $expectedCompletionDate = array_key_exists('expected_completion_date', $data) ? $data['expected_completion_date'] : false;
        $actorId = $data['actor_id'] ?? null;
        $assignerId = $data['assigner_id'] ?? $actorId;
        if (isset($data['assign_date'])) {
            // if assign_date is set then it means we need to create a gc_plan record, so do not set it if you do not want to create a gc_plan record
            if ($dueDate === false) {
                $dueDate = null;
            }
            $assignDate = $data['assign_date'];
        } else {
            $assignDate = time();
        }

        foreach (['note', 'due_date', 'expected_completion_date', 'assigner_id', 'actor_id', 'assign_date'] as $field) {
            unset($data[$field]);
        }

        // Check if queueing service is available
        $queueAvailable = $this->queue->isAvailable();
        if (!$queueAvailable) {
            throw new RuntimeException("Queue not available");
        }

        try {
            $original = EnrolmentHelper::loadSingle($this->write->get(), $id);
            if (!$original) {
                $this->logger->error('Can not load an original enrolment', [
                    'class'        => __CLASS__,
                    'method'       => __FUNCTION__,
                    'routing_key'  => Queue::ENROLMENT_UPDATE,
                    'context'      => $context,
                    'enrolment_id' => $id,
                    'data'         => $data,
                ]);

                return false;
            }

            $embedded = $this->publishingService->enrolmentEventEmbedder()->embedded((object) $original->jsonSerialize());
            $this->publishingService->embedPortalAccount($original->userId, $embedded);

            $this->write->get()->beginTransaction();
            $count = $this->write->get()->update('gc_enrolment', $data, ['id' => $id]);
            $revision = EnrolmentHelper::loadSingle($this->write->get(), $id);
            if (!$revision) {
                $this->logger->error('Enrolment was deleted before it could be updated', [
                    'class'        => __CLASS__,
                    'method'       => __FUNCTION__,
                    'routing_key'  => Queue::ENROLMENT_UPDATE,
                    'context'      => $context,
                    'enrolment_id' => $id,
                    'data'         => $data,
                ]);

                return false;
            }

            $this->commit($original, $revision, $actorId ?? 0);
            $this->createRevision($revision, $original, $note, true);

            $dueDate = ($dueDate !== false) ? $dueDate : $expectedCompletionDate;
            if (false !== $dueDate && $original->parentEnrolmentId == 0) {
                $plan = Plan::create((object)[
                    'user_id'      => $original->userId,
                    'assigner_id'  => ($original->userId == $assignerId) ? null : $assignerId,
                    'instance_id'  => $original->takenPortalId,
                    'entity_type'  => EntityTypes::LO,
                    'entity_id'    => $original->loId,
                    'status'       => PlanStatuses::SCHEDULED,
                    'due_date'     => $dueDate,
                    'created_date' => $assignDate,
                    'data'         => null,
                ]);
                $plan = $this->mergePlan(new Context('enrolment.update'), $plan, ['account' => $embedded['account'] ?? null], true, $apiUpliftV3);
                if ($plan && !$this->foundLink($plan->id, $id)) {
                    $this->linkPlan($plan->id, $id);
                }
            }

            if ($this->write->get()->getTransactionNestingLevel() !== 0) {
                $this->write->get()->commit();
            }

            if (!$count) {
                return true;
            }

            // If enrolment is found in the database
            if ($spreadStatus) {
                $enrolment = EnrolmentHelper::loadSingle($this->write->get(), $id);
                // Spread COMPLETED enrolment
                if ($reCalculate || ($enrolment && ($enrolment->status === EnrolmentStatuses::COMPLETED))) {
                    $this->spreadStatusByEnrolment($enrolment, $reCalculate, $context, true);
                }
            }

            // Reload again.
            $enrolment = $this->load($id);

            // if Enrolment was removed meantime then no need to publish message
            if (!$enrolment) {
                return true;
            }

            $enrolment->embedded = $embedded;

            // Original enrolment
            $this->extracted($original, $enrolment);
            if ($enrolment->lo_type == LoTypes::ACHIEVEMENT) {
                $enrolment->award = EnrolmentAttributeRepository::attachAwardAttribute($this->db->get(), $enrolment->id);
            }

            if ($enrolment->lo_type == LoTypes::COURSE) {
                $this->attachEnrolmentResult($enrolment);
            }

            // Unset history data before publish to queue
            unset($enrolment->data->history);
            unset($enrolment->original['data']->history);
            unset($enrolment->data->actor_user_id);
            unset($enrolment->original['data']->actor_user_id);
            $this->queue->batchAdd(
                (array) $enrolment,
                Queue::ENROLMENT_UPDATE,
                [
                    MqClient::CONTEXT_ACTOR_ID    => $actorId,
                    MqClient::CONTEXT_PORTAL_NAME => $original->takenPortalId,
                    MqClient::CONTEXT_ENTITY_TYPE => 'enrolment',
                ] + $context
            );

            $this->queue->batchDone();
        } catch (Exception $e) {
            $this->logger->error('Failed to update enrolment', [
                'id'   => $id ?? 0,
                'data' => json_encode($data),
                'exception' => $e
            ]);

            if ($this->write->get()->getTransactionNestingLevel() !== 0) {
                $this->write->get()->rollBack();
            }

            return false;
        }

        return true;
    }

    /**
     * This function deletes an enrolment, create a enrolment revision by default
     *
     * @param Enrolment $enrolment         Enrolment Entity
     * @param int       $actorId           User initiate this operation
     * @param bool      $archiveChildren   Option allow archive child enrolment. Default: true
     * @param int|null  $parentEnrolmentId Parent Enrolment ID
     * @param bool      $isBatch           Option allow us to hold the message in the batchExchange array and release
     *                                     later when call MqClient::batchDone(). Default: false
     * @return bool
     * @throws \Throwable
     */
    public function deleteEnrolment(
        Enrolment $enrolment,
        int $actorId,
        bool $archiveChildren = true,
        int $parentEnrolmentId = null,
        bool $isBatch = false,
        bool $createRevision = true
    ): bool {
        $enrolment->parentEnrolmentId = $enrolment->parentEnrolmentId ?: ($parentEnrolmentId ?: null);

        $embedded = $this
            ->publishingService
            ->enrolmentEventEmbedder()
            ->embedded((object) $enrolment->jsonSerialize());

        $this
            ->publishingService
            ->embedPortalAccount($enrolment->userId, $embedded);

        // Check if queueing service is available
        $queueAvailable = $this->queue->isAvailable();
        if (!$queueAvailable) {
            throw new RuntimeException("Queue not available");
        }

        $this->write->get()->transactional(
            function (Connection $db) use ($enrolment, $actorId, $archiveChildren, $embedded, $isBatch, $createRevision) {
                $this->write->get()->delete('gc_enrolment', ['id' => $enrolment->id]);
                $this->write->get()->insert('enrolment_stream', [
                    'portal_id' => $enrolment->takenPortalId,
                    'action' => 'delete',
                    'created' => time(),
                    'enrolment_id' => $enrolment->id,
                    'payload' => '{}',
                    'actor_id' => $actorId
                ]);

                if ($createRevision) {
                    $this->createRevision($enrolment, null, '', $isBatch);
                }

                $body = json_decode(json_encode($enrolment));
                $body->embedded = $embedded;

                // If $isBatch is true: We must be call batchDone method to publish message to queue. If not, no message will send to queue
                if ($isBatch) {
                    $this->queue->batchAdd($body, Queue::ENROLMENT_DELETE, [
                        MqClient::CONTEXT_PORTAL_NAME => $enrolment->takenPortalId,
                        MqClient::CONTEXT_ENTITY_TYPE => 'enrolment',
                    ]);
                } else {
                    $this->queue->publish($body, Queue::ENROLMENT_DELETE, [
                        MqClient::CONTEXT_PORTAL_NAME => $enrolment->takenPortalId,
                        MqClient::CONTEXT_ENTITY_TYPE => 'enrolment',
                    ]);
                }

                // Find child enrolment, archive them all.
                if ($archiveChildren) {
                    $q = $db->executeQuery('SELECT id FROM gc_enrolment WHERE parent_enrolment_id = ?', [$enrolment->id]);
                    while ($childEnrolmentId = $q->fetchColumn()) {
                        $childEnrolment = EnrolmentHelper::load($db, $childEnrolmentId);
                        if ($childEnrolment) {
                            $this->deleteEnrolment(Enrolment::create($childEnrolment), $actorId, true, $enrolment->id, $isBatch, $createRevision);
                        }
                    }
                }
                $this->deletePlanReferencesByEnrolmentId($enrolment->id);
            }
        );

        return true;
    }

    public function spreadStatusByEnrolment(
        Enrolment $enrolment,
        bool $reCalculate = false,
        array &$context = [],
        bool $batch = false
    ): void {
        $parentEnrolment = !$enrolment->parentEnrolmentId ? null : EnrolmentHelper::loadSingle($this->write->get(), $enrolment->parentEnrolmentId);
        if (!$parentEnrolment) {
            return;
        }

        // LI.status = in-progress && module.status = not-started
        if ($enrolment->status == EnrolmentStatuses::IN_PROGRESS && $parentEnrolment->status == EnrolmentStatuses::NOT_STARTED) {
            // change: module.status = in-progress
            $this->changeStatus($parentEnrolment, EnrolmentStatuses::IN_PROGRESS, $context, $batch);
            $parentEnrolment->status = EnrolmentStatuses::IN_PROGRESS;
            $this->spreadStatusByEnrolment($parentEnrolment, $reCalculate, $context, $batch);
        }

        if ($this->childrenCompleted($parentEnrolment)) {
            $this->changeStatus($parentEnrolment, EnrolmentStatuses::COMPLETED, $context, $batch);
        } elseif ($reCalculate && EnrolmentStatuses::COMPLETED == $parentEnrolment->status) {
            $this->changeStatus($parentEnrolment, EnrolmentStatuses::IN_PROGRESS, $context, $batch);
            $this->spreadStatusByEnrolment($parentEnrolment, true, $context, $batch);
        }

        // Publish course with results/result property
        $type = $this->read->get()->fetchColumn('SELECT type FROM gc_lo WHERE id = ?', [$enrolment->loId]) ?: '';
        if (!in_array($type, [LiTypes::ASSIGNMENT, LiTypes::QUIZ, LiTypes::INTERACTIVE, LiTypes::H5P, LiTypes::LTI])) {
            return;
        }
        $courseEnrolment = EnrolmentHelper::parentEnrolment($this->read->get(), $parentEnrolment);
        if (!$courseEnrolment) {
            $this->logger->error('Can not load a parent enrolment', [
                'class'        => __CLASS__,
                'method'       => __FUNCTION__,
                'routing_key'  => Queue::ENROLMENT_UPDATE,
                'context'      => $context,
                'enrolment_id' => $enrolment->id
            ]);
            return;
        }

        // Check if queueing service is available
        $queueAvailable = $this->queue->isAvailable();
        if (!$queueAvailable) {
            throw new RuntimeException("Queue not available");
        }

        $publishedMessage = (object) $courseEnrolment->jsonSerialize();
        $publishedMessage->start_date = $publishedMessage->start_date ? DateTimeHelper::atom($publishedMessage->start_date, DATE_ISO8601) : null;
        $publishedMessage->end_date = $publishedMessage->end_date ? DateTimeHelper::atom($publishedMessage->end_date, DATE_ISO8601) : null;
        $publishedMessage->changed = $publishedMessage->changed ? DateTimeHelper::atom($publishedMessage->changed, DATE_ISO8601) : null;
        $publishedMessage->lo_type = LoTypes::COURSE;
        $this->attachEnrolmentResult($publishedMessage);

        $publishedMessage->original = $courseEnrolment;
        // original enrolment
        $this->extracted($courseEnrolment, $publishedMessage);

        $embedded = $this->publishingService->enrolmentEventEmbedder()->embedded($publishedMessage);
        $this->publishingService->embedPortalAccount($enrolment->userId, $embedded);
        $publishedMessage->embedded = $embedded;

        // Unset history data before publish to queue
        unset($publishedMessage->data->history);
        unset($publishedMessage->original['data']->history);
        $this->queue->batchAdd((array) $publishedMessage, Queue::ENROLMENT_UPDATE, [
            MqClient::CONTEXT_PORTAL_NAME => $publishedMessage->taken_instance_id,
            MqClient::CONTEXT_ENTITY_TYPE => 'enrolment',
        ]);

        if (!$batch) {
            $this->queue->batchDone();
        }
    }

    public function spreadCompletionStatus(
        int $portalId,
        int $loId,
        int $userId,
        bool $reCalculate = false,
        array $context = [],
        bool $batch = false
    ): void {
        $parentLoIds = $this->read->get()
            ->createQueryBuilder()
            ->select('source_id')
            ->from('gc_ro')
            ->where('type IN (:types)')->setParameter('types', EdgeTypes::LO_HAS_CHILDREN, DB::INTEGERS)
            ->andWhere('target_id = :target_id')->setParameter('target_id', $loId)
            ->execute()
            ->fetchAll(DB::COL);

        foreach ($parentLoIds as $parentLoId) {
            if (!$parentEnrolment = EnrolmentHelper::findEnrolment($this->write->get(), $portalId, $userId, $parentLoId)) {
                continue;
            }

            if ($this->childrenCompleted($parentEnrolment)) {
                $this->changeStatus($parentEnrolment, EnrolmentStatuses::COMPLETED, $context, $batch);
            } elseif ($reCalculate && $parentEnrolment->status == EnrolmentStatuses::COMPLETED) {
                $this->changeStatus($parentEnrolment, EnrolmentStatuses::IN_PROGRESS, $context, $batch);
                $this->spreadCompletionStatus($portalId, $parentLoId, $userId, true, $context, $batch);
            }
        };
    }

    /**
     * @param Enrolment $enrolment
     * @param string    $status
     * @param array     $context
     * @param bool      $batch
     * @return bool
     * @throws Exception
     */
    public function changeStatus(Enrolment $enrolment, string $status, array $context = [], bool $batch = false): bool
    {
        $origin = $enrolment;
        $pass = $this->childrenPassed($enrolment);
        $id = $origin->id;
        $stateChanged = ($origin->status != $status) || ($origin->pass != $pass);
        if (!$stateChanged && ($status != EnrolmentStatuses::COMPLETED)) {
            return false;
        }

        // Check if queueing service is available
        $queueAvailable = $this->queue->isAvailable();
        if (!$queueAvailable) {
            throw new RuntimeException("Queue not available");
        }

        $data = [
            'status' => $status,
            'pass' => $pass,
            'changed' => (new DateTime())->format(Constants::DATE_MYSQL)
        ];

        if (EnrolmentStatuses::IN_PROGRESS == $status) {
            $data['start_date'] = (new DateTime())->format(Constants::DATE_MYSQL);
        }

        if (EnrolmentStatuses::COMPLETED == $status) {
            $data['end_date'] = (new DateTime())->format(Constants::DATE_MYSQL);
        }

        $metadata = is_scalar($origin->data) ? json_decode($origin->data, true) : json_decode(json_encode($origin->data), true);
        $metadata['history'][] = [
            'action'          => $context['action'] ?? 'change-status',
            'actorId'         => $context['actorId'] ?? -1,
            'status'          => $status,
            'original_status' => $origin->status,
            'pass'            => $pass,
            'original_pass'   => $origin->pass,
            'timestamp'       => time(),
        ];
        $data['data'] = json_encode($metadata);

        $count = $this->write->get()->update('gc_enrolment', $data, ['lo_id' => $origin->loId, 'user_id' => $origin->userId, 'taken_instance_id' => $origin->takenPortalId]);
        if (!$count) {
            return false;
        }
        $revision = EnrolmentHelper::loadSingle($this->write->get(), $id);
        if (!$revision) {
            $this->logger->error('Enrolment was deleted before it could be updated', [
                'class'        => __CLASS__,
                'method'       => __FUNCTION__,
                'routing_key'  => Queue::ENROLMENT_UPDATE,
                'context'      => $context,
                'enrolment_id' => $id,
                'data'         => $data,
            ]);

            return false;
        }
        $this->commit($origin, $revision, $context['actorId'] ?? 0);
        $this->createRevision($revision, $origin, ($context['note'] ?? ''), $batch);
        if ($this->write->get()->getTransactionNestingLevel() !== 0) {
            $this->write->get()->commit();
        }

        // Spread COMPLETED enrolment.
        if ((EnrolmentStatuses::COMPLETED == $status) && $stateChanged) {
            $this->spreadStatusByEnrolment($origin, false, $context, $batch);
        }

        $enrolment = $this->load($id);
        if (!$enrolment) {
            $this->logger->error('Enrolment was deleted before update could be synced', [
                'class'        => __CLASS__,
                'method'       => __FUNCTION__,
                'routing_key'  => Queue::ENROLMENT_UPDATE,
                'context'      => $context,
                'enrolment_id' => $id,
                'data'         => $data,
            ]);

            return false;
        }

        $this->extracted($origin, $enrolment);

        $embedded = $this->publishingService->enrolmentEventEmbedder()->embedded($enrolment);
        $this->publishingService->embedPortalAccount($enrolment->user_id, $embedded);

        $enrolment->embedded = $embedded;
        if ($enrolment->lo_type == LoTypes::COURSE) {
            $this->attachEnrolmentResult($enrolment);
        }

        // Unset history data before publish to queue
        unset($enrolment->data->history);
        unset($enrolment->original['data']->history);
        $this->queue->batchAdd((array) $enrolment, Queue::ENROLMENT_UPDATE, [
            MqClient::CONTEXT_PORTAL_NAME => $enrolment->taken_instance_id,
            MqClient::CONTEXT_ENTITY_TYPE => 'enrolment',
        ]);
        if (!$batch) {
            $this->queue->batchDone();
        }
        return true;
    }

    public function attachEnrolmentResult(stdClass &$courseEnrolment, array $types = []): void
    {
        $types = $types ?: [LiTypes::ASSIGNMENT, LiTypes::QUIZ, LiTypes::INTERACTIVE, LiTypes::H5P, LiTypes::LTI];
        $results = $this->getAssessmentResults($courseEnrolment, $types);
        if (!empty($results)) {
            if (count($results) > 1) {
                $courseEnrolment->assessments = $results;
            } else {
                if (count($results) == 1) {
                    $courseEnrolment->result = $results[0]['result'];
                }
            }
        }
    }

    public static function getPlanIdsForEnrolment(Connection $db, int $enrolmentId): array
    {
        $q = $db->createQueryBuilder();
        $expr = $q->expr();
        $planIds = $q
            ->select('plan_id')
            ->from('gc_enrolment_plans')
            ->where(
                $expr->eq('enrolment_id', ':enrolmentId')
            )
            ->setParameter('enrolmentId', $enrolmentId, DB::INTEGER)
            ->execute()
            ->fetchAll(DB::COL);
        return $planIds;
    }

    public function removeEnrolmentPlansByPlanId(int $planId): void
    {
        $enrolmentIds = $this->findEnrolmentIds($planId);
        foreach ($enrolmentIds as $enrolmentId) {
            $this->unlinkPlan($planId, $enrolmentId);
        }
    }

    public function deletePlanReference(int $planId): void
    {
        $planRefRecord = PlanReference::createFromRecord((object)['plan_id' => $planId]);
        $originalPlanRef = $this->loadPlanReference($planRefRecord);
        if (!$originalPlanRef) {
            return;
        }
        $planRef = clone $originalPlanRef;
        $planRef->setStatus(0)->setUpdatedAt('now');
        $this->updatePlanReference($originalPlanRef, $planRef);
    }

    public function deletePlanReferencesByEnrolmentId(int $enrolmentId): void
    {
        $planIds = self::getPlanIdsForEnrolment($this->read->get(), $enrolmentId);
        foreach ($planIds as $planId) {
            $this->unlinkPlan($planId, $enrolmentId);
            $this->deletePlanReference($planId);
        }
    }

    public function findParentEnrolment(stdClass $enrolment, $parentLoType = LoTypes::COURSE): ?Enrolment
    {
        $loadLo = function ($db, $loId) {
            return $db->executeQuery('SELECT /*+ MAX_EXECUTION_TIME(1000) */ id, type FROM gc_lo WHERE id = ?', [$loId])->fetch(DB::OBJ);
        };

        $parentQuery = function (Connection $db, stdClass $lo, Enrolment $enrolment) use ($loadLo) {
            $parentLoId = $enrolment->parentLoId ?: false;
            if (empty($parentLoId)) {
                $roTypes = [
                    EdgeTypes::HAS_LP_ITEM,
                    EdgeTypes::HAS_MODULE,
                    EdgeTypes::HAS_ELECTIVE_LO,
                    EdgeTypes::HAS_LI,
                    EdgeTypes::HAS_ELECTIVE_LI,
                ];
                $query = $db->executeQuery('SELECT source_id FROM gc_ro WHERE type IN (?) AND target_id = ?', [$roTypes, $lo->id], [DB::INTEGERS, DB::INTEGER]);
                $parentLoId = $query->fetchColumn();
            }

            return [
                $parentLo = $parentLoId ? $loadLo($this->read->get(), $parentLoId) : false,
                $parentEnrolment = $parentLo ? EnrolmentHelper::findEnrolment($db, $enrolment->takenPortalId, $enrolment->userId, $parentLo->id) : false,
            ];
        };
        $enrolment = Enrolment::create($enrolment);
        $lo = $loadLo($this->read->get(), $enrolment->loId);
        [$parentLo, $parentEnrolment] = $parentQuery($this->read->get(), $lo, $enrolment);
        while ($parentLo && $parentEnrolment && ($parentLo->type != $parentLoType)) {
            [$parentLo, $parentEnrolment] = $parentQuery($this->read->get(), $parentLo, $parentEnrolment);
        }

        return $parentLo && ($parentLo->type == $parentLoType) ? $parentEnrolment : null;
    }

    /**
     * @param \go1\util\model\Enrolment $origin
     * @param                           $enrolment
     * @return void
     */
    private function extracted(Enrolment $origin, stdClass $enrolment): void
    {
        $enrolment->original = $origin->jsonSerialize();
        $enrolment->original['start_date'] = $enrolment->original['start_date'] ? DateTimeHelper::atom($enrolment->original['start_date'], DATE_ISO8601) : null;
        $enrolment->original['end_date'] = $enrolment->original['end_date'] ? DateTimeHelper::atom($enrolment->original['end_date'], DATE_ISO8601) : null;
        $enrolment->original['changed'] = $enrolment->original['changed'] ? DateTimeHelper::atom($enrolment->original['changed'], DATE_ISO8601) : null;
    }

    public function loadEnrolment(Connection $db, int $portalId, int $userId, int $loId, int $parentLoId = null): ?Enrolment
    {
        $q = $db
            ->createQueryBuilder()
            ->select('*')
            ->from('gc_enrolment')
            ->add('orderBy', 'id DESC')
            ->setMaxResults(1)
            ->setFirstResult(0)
            ->where('lo_id = :loId')->setParameter(':loId', $loId)
            ->andWhere('user_id = :userId')->setParameter(':userId', $userId)
            ->andWhere('taken_instance_id = :takenInstanceId')->setParameter(':takenInstanceId', $portalId);

        !is_null($parentLoId) && $q
            ->andWhere('parent_lo_id = :parentLoId')
            ->setParameter(':parentLoId', $parentLoId);

        $row = $q->execute()->fetch(DB::OBJ);

        return $row ? Enrolment::create($row) : null;
    }

    public function createEnrolmentTracking(
        int $enrolmentId,
        int $enrolmentOriginalType,
        int $actorId
    ): void {
        $this->db->get()->insert('enrolment_tracking', [
            'enrolment_id' => $enrolmentId,
            'original_enrolment_type' => $enrolmentOriginalType,
            'actor_id' => $actorId
        ]);
    }

    public function loadEnrolmentTracking(int $enrolmentId): ?stdClass
    {
        $record = $this
            ->db->get()
            ->executeQuery(
                'SELECT * FROM enrolment_tracking WHERE enrolment_id = ?',
                [$enrolmentId]
            )
            ->fetch(DB::OBJ);

        return $record ?: null;
    }

    public function getLoEnrolledAssignedUserIds(int $loId, int $portalId): array
    {
        $enrolledUserIds = $this->read->get()->createQueryBuilder()
            ->select('user_id')
            ->from('gc_enrolment')
            ->where('taken_instance_id = :portal_id')
            ->andWhere('lo_id = :lo_id')
            ->setParameter(':portal_id', $portalId, DB::INTEGER)
            ->setParameter(':lo_id', $loId, DB::INTEGER)
            ->execute()
            ->fetchAll(DB::COL);

        $assignedUserIds = $this->read->get()->createQueryBuilder()
            ->select('user_id')
            ->from('gc_plan')
            ->where('instance_id = :portal_id')
            ->andWhere('entity_type = :entity_type')
            ->andWhere('entity_id = :entity_id')
            ->andWhere('type = :plan_type')
            ->setParameter(':portal_id', $portalId, DB::INTEGER)
            ->setParameter(':entity_type', PlanTypes::ENTITY_LO, DB::STRING)
            ->setParameter(':entity_id', $loId, DB::INTEGER)
            ->setParameter(':plan_type', PlanTypes::ASSIGN, DB::STRING)
            ->execute()
            ->fetchAll(DB::COL);

        return array_values(array_unique(array_merge($enrolledUserIds, $assignedUserIds)));
    }
}
