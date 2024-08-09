<?php

namespace go1\enrolment\domain\etc;

use go1\enrolment\domain\ConnectionWrapper;
use go1\clients\MqClient;
use go1\core\util\client\federation_api\v1\schema\object\User;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\util\DB;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\model\Enrolment;
use go1\util\plan\PlanRepository;
use go1\util\queue\Queue;
use PDO;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Move enrolments from an user to other user
 * - Move all enrolments
 * - Move all enrolment revisions
 *
 * @TODO    logging the merge account action
 *
 * Class EnrolmentMergeAccount
 * @package go1\etc\domain
 */
class EnrolmentMergeAccount
{
    private ConnectionWrapper          $go1;
    private ConnectionWrapper          $go1Write;
    private EnrolmentRepository $repository;
    private MqClient            $queue;
    private UserDomainHelper    $userDomainHelper;
    private LoggerInterface     $logger;
    private PlanRepository      $planRepository;

    public const MERGE_ACTION_UPDATE                     = 'update';
    public const MERGE_ACTION_ARCHIVE                    = 'archive';
    public const DO_ETC_MERGE_ACCOUNT                    = 'etc.merge-account';
    public const MERGE_ACCOUNT_ACTION_ENROLMENT          = 'merge_account_action_enrolment';
    public const MERGE_ACCOUNT_ACTION_ENROLMENT_REVISION = 'merge_account_action_enrolment_revision';

    public function __construct(
        ConnectionWrapper          $go1,
        ConnectionWrapper          $go1Write,
        EnrolmentRepository $repository,
        MqClient            $queue,
        UserDomainHelper    $userDomainHelper,
        LoggerInterface     $logger,
        PlanRepository      $planRepository
    ) {
        $this->go1 = $go1;
        $this->go1Write = $go1Write;
        $this->repository = $repository;
        $this->queue = $queue;
        $this->userDomainHelper = $userDomainHelper;
        $this->logger = $logger;
        $this->planRepository = $planRepository;
    }

    /**
     * Load all course enrolments from $fromMail
     * Add archived course enrolments (same course)
     */
    public function getEnrolments(string $fromMail, string $toMail, int $portalId): array
    {
        $enrolments = [];
        $fromUser = $this->userDomainHelper->loadUserByEmail($fromMail);
        if (!$fromUser) {
            return [];
        }

        $toUser = $this->userDomainHelper->loadUserByEmail($toMail);
        if (!$toUser) {
            return [];
        }

        if ($fromUser->legacyId !== $toUser->legacyId) {
            $fromEnrolments = $this->getLOEnrolments($fromUser->legacyId, $portalId);
            $toEnrolments = $this->getLOEnrolments($toUser->legacyId, $portalId);
            $enrolments = $this->processEnrolments($toUser, $fromEnrolments, $toEnrolments);
        }

        return $enrolments;
    }

    private function getLOEnrolments(int $userId, int $portalId): array
    {
        $sql = 'SELECT * FROM gc_enrolment WHERE user_id = ? AND taken_instance_id = ?';

        return $this->go1
            ->get()
            ->executeQuery($sql, [$userId, $portalId], [DB::INTEGER, DB::INTEGER])
            ->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Process the enrolments
     *      Change profile_id of from enrolments
     *      Archived enrolments from same course not latest
     */
    private function processEnrolments(User $user, array $fromEnrolments, array $toEnrolments): array
    {
        $loIds = array_map(fn ($e) => $e->lo_id, $toEnrolments);

        $processedEnrolments = [];
        foreach ($fromEnrolments as $fromEnrolment) {
            if (in_array($fromEnrolment->lo_id, $loIds)) {
                $e = $this->getEnrolmentByLoId($toEnrolments, $fromEnrolment->lo_id);
                if ($e->timestamp >= $fromEnrolment->timestamp) {
                    $fromEnrolment->merge_action = self::MERGE_ACTION_ARCHIVE;
                    $fromEnrolment->new_profile_id = $user->profileId;
                    $fromEnrolment->new_user_id = $user->legacyId;
                    $processedEnrolments[] = $fromEnrolment;
                } else {
                    $e->merge_action = self::MERGE_ACTION_ARCHIVE;
                    $processedEnrolments[] = $e;

                    $fromEnrolment->merge_action = self::MERGE_ACTION_UPDATE;
                    $fromEnrolment->new_profile_id = $user->profileId;
                    $fromEnrolment->new_user_id = $user->legacyId;
                    $processedEnrolments[] = $fromEnrolment;
                }
            } else {
                $fromEnrolment->merge_action = self::MERGE_ACTION_UPDATE;
                $fromEnrolment->new_profile_id = $user->profileId;
                $fromEnrolment->new_user_id = $user->legacyId;
                $processedEnrolments[] = $fromEnrolment;
            }
        }

        return $processedEnrolments;
    }

    private function getEnrolmentByLoId(array $enrolments, int $loId): \stdClass
    {
        $newEnrolments = array_filter(array_map(function ($e) use ($loId) {
            if ($e->lo_id == $loId) {
                return $e;
            }

            return null;
        }, $enrolments));

        return reset($newEnrolments);
    }

    /**
     * Change profile_id of the enrolment revisions
     */
    public function updateRevision(string $from, string $to, int $portalId): void
    {
        if (!$fromUser = $this->userDomainHelper->loadUserByEmail($from)) {
            $this->logger->error('Errors on merging enrolment revision', [
                'message'  => 'Source user not found.',
                'source'   => $from,
                'target'   => $to,
                'portalId' => $portalId,
            ]);
        }

        if (!$toUser = $this->userDomainHelper->loadUserByEmail($to)) {
            $this->logger->error('Errors on merging enrolment revision', [
                'message'  => 'Target user not found.',
                'source'   => $from,
                'target'   => $to,
                'portalId' => $portalId,
            ]);
        }

        $sql = 'SELECT * FROM gc_enrolment_revision WHERE user_id = ? AND taken_instance_id = ?';
        $revisions = $this->go1
            ->get()
            ->executeQuery($sql, [$fromUser->legacyId, $portalId], [DB::INTEGER, DB::INTEGER])
            ->fetchAll(PDO::FETCH_OBJ);

        if (!empty($revisions)) {
            $this->go1Write->get()->transactional(function () use ($revisions, $toUser) {
                foreach ($revisions as $revision) {
                    $this->go1Write->get()
                        ->update(
                            'gc_enrolment_revision',
                            [
                                'profile_id' => 0,
                                'user_id' => $toUser->legacyId
                            ],
                            ['id' => $revision->id]
                        );
                }
            });

            $task = [
                'user_id' => $fromUser->legacyId,
                'portal_id' => $portalId
            ];
            $this->queue->publish($task, Queue::MERGE_ACCOUNT_ENROLMENT_REVISION);
        }
    }

    /**
     * Update enrolment if enrolment.merge_action = update
     * Archive enrolment if enrolment.merge_action = archive
     * Update/Archive all child enrolments
     */
    public function update(stdClass $e, stdClass $context): void
    {
        switch ($e->merge_action) {
            case self::MERGE_ACTION_UPDATE:
                $this->updateUserIdOfPlans($e); // update plan first so that it will be indexed while updating enrolment
                $this->repository->update($e->id, ['profile_id' => $e->new_profile_id, 'user_id' => $e->new_user_id], false);
                $this->updateChild($e, $context);
                break;

            case self::MERGE_ACTION_ARCHIVE:
                $enrolment = Enrolment::create($e);
                $this->repository->deleteEnrolment($enrolment, $context->actorUserId ?? 0, true);
                $this->archivePlans($enrolment);
                break;
        }
    }

    /**
     * Use this function while merging two users only and make sure to archive other user's plans to avoid duplcation
     */
    private function updateUserIdOfPlans(stdClass $enrolment): void
    {
        $existingPlans = $this->planRepository->loadUserPlanByEntity($enrolment->taken_instance_id, $enrolment->user_id, $enrolment->lo_id);
        $db = $this->go1->get();
        foreach ($existingPlans as $plan) {
            $db->update('gc_plan', ['user_id' => $enrolment->new_user_id], ['id' => $plan->id]);
            if (!$this->repository->foundLink($plan->id, $enrolment->id)) {
                $this->repository->linkPlan($plan->id, $enrolment->id);
            }
        }
    }

    private function archivePlans(Enrolment $enrolment): void
    {
        $existingPlans = $this->planRepository->loadUserPlanByEntity($enrolment->takenPortalId, $enrolment->userId, $enrolment->loId);
        if ($existingPlans) {
            foreach ($existingPlans as $plan) {
                $this->planRepository->archive($plan->id);
            }
        }
    }

    private function updateChild(stdClass $e, stdClass $context): void
    {
        $childIds = EnrolmentHelper::childIds($this->go1->get(), $e->id);
        foreach ($childIds as $childId) {
            $enrolment = EnrolmentHelper::loadSingle($this->go1->get(), $childId);
            if ($enrolment) {
                $child = (object) $enrolment->jsonSerialize();
                $child->merge_action = self::MERGE_ACTION_UPDATE;
                $child->new_profile_id = $e->new_profile_id;
                $child->new_user_id = $e->new_user_id;

                $this->update($child, $context);
            }
        }
    }
}
