<?php

namespace go1\core\learning_record\plan;

use Assert\Assert;
use Assert\LazyAssertionException;
use Exception;
use go1\core\util\client\federation_api\v1\PortalAccountMapper;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\DateTime;
use go1\util\Error;
use go1\util\plan\PlanRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class PlanUpdateController
{
    private PlanRepository     $planRepository;
    private AccessChecker      $accessChecker;
    private UserDomainHelper   $userDomainHelper;
    private LoggerInterface    $logger;
    private PortalService      $portalService;

    public function __construct(
        PlanRepository $planRepository,
        AccessChecker $accessChecker,
        UserDomainHelper $userDomainHelper,
        LoggerInterface $logger,
        PortalService $portalService
    ) {
        $this->portalService = $portalService;
        $this->planRepository = $planRepository;
        $this->accessChecker = $accessChecker;
        $this->userDomainHelper = $userDomainHelper;
        $this->logger = $logger;
    }

    public function updateAssignedDate(int $planId, Request $req)
    {
        if (!$this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$originalPlan = $this->planRepository->load($planId)) {
            return Error::jr404('Plan object is not found.');
        }

        if (!$portal = $this->portalService->loadBasicById($originalPlan->instanceId)) {
            return Error::jr('The portal connected to this plan does not exist.');
        }

        $isStaffUser = $this->accessChecker->isAccountsAdmin($req);

        if (!$isStaffUser) {
            return Error::jr403('Permission Denied. This is only accessible to Go1 staff.');
        }

        $user = $this->userDomainHelper->loadUser($originalPlan->userId, $portal->title);
        if (!$user || !$user->account) {
            return Error::jr('The account connected to this plan does not exist.');
        }

        if ($user->account->status != 'ACTIVE') {
            return Error::jr('The account connected to this plan is deactivated.');
        }

        try {
            $assignedDate = $req->request->get('assigned_date');
            $assertion = Assert::lazy();
            $assertion
                ->that($assignedDate, 'assigned_date')
                ->notNull('Assigned date must not be null.')
                ->numeric('Assigned date must be unix timestamp value.');
            $assertion->verifyNow();

            $plan = clone $originalPlan;
            $plan->created = DateTime::create($assignedDate);
            $embedded = [];
            $queueContext = [];
            //We don't want notifications sent out for this update as this endpoint should only be used to correct mistakes.
            $notify = false;
            $this->planRepository->update($originalPlan, $plan, $notify, $embedded, $queueContext);
            return new JsonResponse(null, 204);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error('Error updating plan assigned date', [
                  'id'        => $planId,
                  'exception' => $e,
              ]);
            return Error::jr500('Failed to update plan assigned date');
        }
    }

    public function put(int $planId, Request $req)
    {
        if (!$currentUser = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }
        $updatedAssignerId = $currentUser->id;

        if (!$originalPlan = $this->planRepository->load($planId)) {
            return Error::jr404('Plan object is not found.');
        }

        if (!$portal = $this->portalService->loadBasicById($originalPlan->instanceId)) {
            return Error::jr('The portal connected to this plan does not exist.');
        }

        $actorPortalAccount = null;
        $isStaffUser = $this->accessChecker->isAccountsAdmin($req);
        if ($isStaffUser) {
            if ($assignerId = $req->request->get('assigner_id')) {
                $actorPortalAccount = $this->userDomainHelper->loadUser($assignerId, $portal->title);
                if ($actorPortalAccount) {
                    $updatedAssignerId = $assignerId;
                }
            }
        }
        if (!$actorPortalAccount) {
            $actorPortalAccount = $this->accessChecker->validAccount($req, $portal->title);
        }
        if (!$actorPortalAccount) {
            return Error::jr403('User not belong to portal.');
        }

        $user = $this->userDomainHelper->loadUser($originalPlan->userId, $portal->title);
        if (!$user || !$user->account) {
            return Error::jr('The account connected to this plan does not exist.');
        }

        if ($user->account->status != 'ACTIVE') {
            return Error::jr('The account connected to this plan is deactivated.');
        }

        try {
            $payload = $req->request->all();
            $dueDate = $req->request->get('due_date');
            $fieldNames = array_keys($payload);

            $assertion = Assert::lazy();
            $assertion
                ->that($fieldNames, 'payload')
                ->all()
                ->inArray(['due_date', 'assigner_id'], fn ($arg) => "Unknown field '{$arg['value']}'");

            $hasChangedDueDate = false;
            if ($req->request->has('due_date')) {
                $hasChangedDueDate = true;
                if ($dueDate) {
                    $assertion
                        ->that($dueDate, 'due_date')
                        ->numeric('Due date must be unix timestamp value.')
                        ->greaterThan(time(), 'Due date can not be in the past.');
                }
            }

            $assertion->verifyNow();

            $owner = ($originalPlan->userId == $currentUser->id) && ($originalPlan->assignerId && $currentUser->id);
            $canAccess = $owner
                || $this->accessChecker->isPortalAdmin($req, $portal->title)
                || $this->userDomainHelper->isManager($portal->title, $actorPortalAccount->id, $user->account->legacyId, true);

            if (!$canAccess) {
                return Error::jr403("Only portal administrator and user's manager can update assign learning.");
            }

            $plan = clone $originalPlan;
            if ($hasChangedDueDate) {
                $plan->due = $dueDate ? DateTime::create($dueDate) : null;
                $plan->assignerId = $updatedAssignerId;
                $embedded['account'] = PortalAccountMapper::toLegacyStandardFormat($user, $user->account, $portal);
                $queueContext = [];
                $this->planRepository->update($originalPlan, $plan, true, $embedded, $queueContext);
            }

            return new JsonResponse(null, 204);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error('Error on plan update', [
                'id'        => $planId,
                'exception' => $e,
            ]);
            return Error::jr500('Failed to update plan');
        }
    }
}
