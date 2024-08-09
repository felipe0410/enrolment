<?php

namespace go1\core\learning_record\plan;

use Assert\Assert;
use Assert\LazyAssertionException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use go1\clients\MqClient;
use go1\clients\PortalClient;
use go1\core\learning_record\plan\util\PlanReference;
use go1\core\util\client\federation_api\v1\PortalAccountMapper;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\DateTime;
use go1\util\DB;
use go1\util\Error;
use go1\util\group\GroupAssignHelper;
use go1\util\group\GroupAssignTypes;
use go1\util\group\GroupHelper;
use go1\util\group\GroupItemTypes;
use go1\util\lo\LoChecker;
use go1\util\lo\LoHelper;
use go1\util\lo\LoStatuses;
use go1\util\lo\LoTypes;
use go1\util\model\Enrolment;
use go1\util\plan\Plan;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanStatuses;
use go1\util\plan\PlanTypes;
use go1\util\queue\Queue;
use go1\util\text\Xss;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class PlanCreateController
{
    private Connection                 $go1;
    private Connection                 $dbSocial;
    private PlanRepository             $planRepo;
    private EnrolmentRepository        $rEnrolment;
    private MqClient                   $mqClient;
    private AccessChecker              $accessChecker;
    private LoChecker                  $loChecker;
    private PortalClient               $portalClient;
    private ContentSubscriptionService $contentSubscriptionService;
    private UserDomainHelper           $userDomainHelper;
    private LoggerInterface            $logger;
    private PortalService              $portalService;

    public function __construct(
        Connection $go1,
        Connection $dbSocial,
        PlanRepository $planRepo,
        EnrolmentRepository $rEnrolment,
        MqClient $mqClient,
        AccessChecker $accessChecker,
        LoChecker $loChecker,
        PortalClient $portalClient,
        ContentSubscriptionService $contentSubscriptionService,
        UserDomainHelper $userDomainHelper,
        LoggerInterface $logger = null,
        PortalService $portalService
    ) {
        $this->go1           = $go1;
        $this->dbSocial      = $dbSocial;
        $this->planRepo      = $planRepo;
        $this->rEnrolment    = $rEnrolment;
        $this->mqClient      = $mqClient;
        $this->accessChecker = $accessChecker;
        $this->loChecker     = $loChecker;
        $this->portalClient = $portalClient;
        $this->contentSubscriptionService = $contentSubscriptionService;
        $this->userDomainHelper = $userDomainHelper;
        $this->logger = $logger;
        $this->portalService = $portalService;
    }

    public function postUser(int $instanceId, int $loId, $userId, Request $req)
    {
        if (!$currentUser = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$portal = $this->portalService->loadBasicById($instanceId)) {
            return Error::jr404('Portal not found.');
        }

        $lo = LoHelper::load($this->go1, $loId);
        if (!$lo || (LoStatuses::PUBLISHED != $lo->published)) {
            return Error::jr404('LO not found.');
        }

        if (
            $lo->type === LoTypes::MODULE ||
            (!in_array($lo->type, LoTypes::all()) && !LoHelper::isSingleLi($lo))
        ) {
            return Error::jr('Assignment is not supported for this LO');
        }

        $isStaffUser = $this->accessChecker->isAccountsAdmin($req);
        if (
            !$isStaffUser
            && !$this->accessChecker->hasAccount($req, $portal->title)
        ) {
            return Error::jr403('User not belong to portal.');
        }

        try {
            $status        = $req->get('status');
            $dueDate       = $req->get('due_date');
            $notify        = (bool) $req->get('notify', true);
            $data          = $req->get('data');
            $version       = $req->get('version');
            $userId        = ('self' == $userId) ? $currentUser->id : $userId;
            $assignerId    = $req->get('assigner_id');

            $claim = Assert::lazy();
            $claim
                ->that($userId, 'userId')->numeric()
                ->that($assignerId, 'assignerId')->nullOr()->numeric()
                ->that($status, 'status')->inArray(PlanStatuses::all())
                ->that($this->validateDueDate($dueDate), 'due_date', 'Invalid due date.')->true()
                ->that($data, 'data')->nullOr()->isArray()->keyIsset('note');
            if (isset($data['note'])) {
                $claim
                    ->that($data['note'], 'data.note')->string()->maxLength(255);
            }

            if (isset($version)) {
                $claim->that($version, 'version')->eq(2);
            }
            $claim->verifyNow();

            if (!$user = $this->userDomainHelper->loadUser($userId, $portal->title)) {
                return Error::jr404('User not found.');
            }

            $hasAccess = ($user->legacyId == $currentUser->id)
                || $this->accessChecker->isPortalAdmin($req, $portal->title)
                || $this->loChecker->isAuthor($this->go1, $loId, $currentUser->id);
            if (!$hasAccess) {
                $actorPortalAccount = $this->accessChecker->validAccount($req, $portal->title);
                $isManager = ($actorPortalAccount && !empty($user->account))
                    ? $this->userDomainHelper->isManager($portal->title, $actorPortalAccount->id, $user->account->legacyId, true)
                    : false;

                if (!$isManager) {
                    return Error::jr403("Only user, LO author, user's manager or admin can assign learning.");
                }
            }

            $user = $this->userDomainHelper->loadUserByEmail($user->email, $portal->title);

            Assert::lazy()
                ->that($user->account ?? false, 'user')->notSame(false, 'User does not belong to portal.')
                ->verifyNow();

            $hasAccount = isset($user->account) && is_object($user->account);
            $isAccountActivated = $hasAccount && ($user->account->status == 'ACTIVE');
            if (!$isAccountActivated) {
                return Error::jr('The account connected to this plan is deactivated.');
            }

            $portalLicensing = $this->portalClient->configuration($portal->title, "GO1", "portal_licensing", 0);
            if ($portalLicensing !== false) {
                $this->contentSubscriptionService->checkForLicense($user->legacyId, $lo->id, $portal->id);
            }

            $planAssignerId = $currentUser->id;
            if ($isStaffUser && $assignerId && ($assignerLoad = $this->userDomainHelper->loadUser($assignerId, $portal->title))) {
                $planAssignerId = $assignerLoad->legacyId;
            }

            $embedded['account'] = PortalAccountMapper::toLegacyStandardFormat($user, $user->account, $portal);
            if ($version === 2) {
                Assert::lazy()
                    ->that($this->validateDueDate($dueDate, true), 'due_date', 'Invalid due date.')->true()
                    ->verifyNow();

                $enrolment = $this->rEnrolment->loadByLoAndUserAndTakenInstanceId($loId, $userId, $portal->id);
                $plans = $this->planRepo->loadUserPlanByEntity($instanceId, $userId, $loId);
                if ($plans) {
                    $planId = $this->processReassign($planAssignerId, $dueDate, $plans[0], $embedded, $enrolment);

                    return new JsonResponse(['id' => $planId]);
                }
            }

            $planRef = PlanReference::create($req);
            if ($planRef && !$isStaffUser) {
                return Error::jr('Require Go1 Staff permission.');
            }

            $plan = Plan::create((object) [
                'user_id'      => $userId,
                'assigner_id'  => $planAssignerId,
                'instance_id'  => $instanceId,
                'entity_type'  => PlanTypes::ENTITY_LO,
                'entity_id'    => $loId,
                'status'       => $status,
                'due_date'     => $dueDate,
                'created_date' => time(),
                'data'         => $this->processData($data),
            ]);

            $queueContext = $planRef ? [$planRef->sourceType => $planRef->sourceId] : [];
            $queueContext['action'] = 'assigned';
            $planId = $this->planRepo->merge($plan, $notify, $queueContext, $embedded);

            if ($planRef) {
                $planRef->setPlanId($planId);
                $this->rEnrolment->linkPlanReference($planRef);
            }

            if (!empty($enrolment) && !$this->rEnrolment->foundLink($planId, $enrolment->id)) {
                $this->rEnrolment->linkPlan($planId, $enrolment->id);
            }

            return new JsonResponse(['id' => $planId]);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (UniqueConstraintViolationException $e) {
            //Log error if record already exist rather than throwing exception
            $this->logger->error('Error on plan create', [
                'exception' => $e,
            ]);
        }
    }

    public function postGroup(int $instanceId, int $loId, int $groupId, Request $req)
    {
        if (!$actor = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$portal = $this->portalService->loadBasicById($instanceId)) {
            return Error::jr404('Portal not found.');
        }

        $lo = LoHelper::load($this->go1, $loId);
        if (!$lo || (LoStatuses::PUBLISHED != $lo->published)) {
            return Error::jr404('LO not found.');
        }

        if (!$group = GroupHelper::load($this->dbSocial, $groupId)) {
            return Error::jr404('Group not found.');
        }

        if (!GroupHelper::groupAccess($group->user_id, $actor->id, $this->accessChecker, $req, $portal->title)) {
            return Error::jr403('Only group owner or admin can assign learning to group.');
        }

        try {
            $status        = $req->get('status');
            $dueDate       = $req->get('due_date');
            $notify        = (bool) $req->get('notify', true);
            $data          = $req->get('data');
            $excludeSelf   = (bool) $req->get('exclude_self');

            $claim = Assert::lazy();
            $claim
                ->that($status, 'status')->inArray(PlanStatuses::all())
                ->that($this->validateDueDate($dueDate), 'due_date', 'Invalid due date.')->true()
                ->that($data, 'data')->nullOr()->isArray()->keyIsset('note');
            if (isset($data['note'])) {
                $claim
                    ->that($data['note'], 'data.note')->string()->maxLength(255);
            }
            $claim->verifyNow();

            $userIds = $excludeSelf ? [] : [$group->user_id];
            foreach (GroupHelper::findItems($this->dbSocial, $groupId, GroupItemTypes::USER, 50, 0, true) as $groupItem) {
                $accountIds[] = $groupItem->entity_id;
            }

            if (!empty($accountIds)) {
                $accountIdChunks = array_chunk($accountIds, 100);
                foreach ($accountIdChunks as $accountIdChunk) {
                    $accounts = $this->userDomainHelper->loadMultiplePortalAccounts($portal->title, $accountIdChunk, true);
                    foreach ($accounts as $account) {
                        $user = $account->user ?? null;
                        if ($user) {
                            $userIds[] = $user->legacyId;
                        }
                    }
                }
            }

            foreach ($userIds as $userId) {
                $plan = Plan::create((object) [
                    'type'         => PlanTypes::ASSIGN,
                    'user_id'      => $userId,
                    'assigner_id'  => $actor->id,
                    'instance_id'  => $instanceId,
                    'entity_type'  => PlanTypes::ENTITY_LO,
                    'entity_id'    => $loId,
                    'status'       => $status,
                    'due_date'     => $dueDate,
                    'created_date' => time(),
                    'data'         => $this->processData($data),
                ]);

                $context = ['group_id' => $group->id, 'notify' => $notify];
                $this->mqClient->publish(
                    ['notify' => $notify] + $plan->jsonSerialize(),
                    Queue::DO_ENROLMENT_PLAN_CREATE,
                    $context
                );
            }

            GroupAssignHelper::merge(
                $this->dbSocial,
                $this->mqClient,
                $groupId,
                $instanceId,
                $actor->id,
                GroupAssignTypes::LO,
                $loId,
                $dueDate
            );

            return new JsonResponse();
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }
    }

    private function validateDueDate($dueDate, bool $isRequired = false)
    {
        if (is_null($dueDate)) {
            return $isRequired ? false : true;
        }

        if (!is_scalar($dueDate)) {
            return false;
        }

        try {
            $time = DateTime::atom($dueDate, DATE_ISO8601);
            if (('1970-01-01T00:00:00+0000' == $time) || ('2016-12-30T03:03:39+0000' == $time)) {
                return false;
            }
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return true;
    }

    private function processData($data)
    {
        if ($data) {
            $data['note'] = Xss::filter($data['note']);
        }

        return $data;
    }

    /**
     * @param int    $assignerId
     * @param string $dueDate
     * @param object $originalPlan
     * @param array  $embedded
     * @return int
     */
    private function processReassign(int $assignerId, string $dueDate, object $originalPlan, array $embedded, $enrolment = null): int
    {
        $plan = Plan::create($originalPlan);
        $plan->due = DateTime::create($dueDate);
        $plan->created = DateTime::create(time());
        $plan->assignerId = $assignerId;
        $embedded['original'] = ['assigner_id' => $originalPlan->assigner_id];

        $planId = DB::transactional(
            $this->go1,
            function () use ($plan, $enrolment, $embedded, $assignerId) {
                $this->planRepo->archive($plan->id, $embedded, ['notify' => false], true);
                $this->rEnrolment->removeEnrolmentPlansByPlanId($plan->id);
                if ($enrolment) {
                    $this->rEnrolment->unlinkPlan($plan->id, $enrolment->id);
                    $this->rEnrolment->deleteEnrolment(Enrolment::create($enrolment), $assignerId, true, null, true, true);
                }

                return $this->planRepo->create($plan, false, false, ['reassign' => true], $embedded, true);
            }
        );

        $this->mqClient->batchDone();

        return $planId;
    }
}
