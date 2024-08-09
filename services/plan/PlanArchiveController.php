<?php

namespace go1\core\learning_record\plan;

use Assert\Assert;
use Doctrine\DBAL\Connection;
use go1\clients\MqClient;
use go1\core\util\client\federation_api\v1\schema\object\PortalAccountRole;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\Error;
use go1\util\group\GroupAssignHelper;
use go1\util\group\GroupAssignTypes;
use go1\util\group\GroupHelper;
use go1\util\lo\LoHelper;
use go1\util\plan\PlanRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class PlanArchiveController
{
    private Connection          $go1;
    private Connection          $dbSocial;
    private PlanRepository      $rPlan;
    private EnrolmentRepository $rEnrolment;
    private MqClient            $mqClient;
    private AccessChecker       $accessChecker;
    private UserDomainHelper    $userDomainHelper;
    private PortalService       $portalService;

    public function __construct(
        Connection          $go1,
        Connection          $dbSocial,
        PlanRepository      $rPlan,
        EnrolmentRepository $rEnrolment,
        MqClient            $mqClient,
        AccessChecker       $accessChecker,
        UserDomainHelper    $userDomainHelper,
        PortalService       $portalService
    ) {
        $this->go1 = $go1;
        $this->dbSocial = $dbSocial;
        $this->rPlan = $rPlan;
        $this->rEnrolment = $rEnrolment;
        $this->mqClient = $mqClient;
        $this->accessChecker = $accessChecker;
        $this->userDomainHelper = $userDomainHelper;
        $this->portalService = $portalService;
    }

    public function delete(int $planId, Request $req): JsonResponse
    {
        $notify = $req->get('notify');
        $claim = Assert::lazy();
        $claim->that($notify, 'notify')->nullOr()->numeric();
        $claim->verifyNow();

        if (!$currentUser = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$plan = $this->rPlan->load($planId)) {
            // Remove ghost enrolment plan records if they exist
            $this->rEnrolment->removeEnrolmentPlansByPlanId($planId);
            $this->rEnrolment->deletePlanReference($planId);
            return Error::jr404('Plan not found.');
        }

        if (!$this->accessChecker->isAccountsAdmin($req)) {
            $portal = $this->portalService->loadBasicById($plan->instanceId);
            $hasAccess = $this->accessChecker->isPortalAdmin($req, $portal->title);
            $assignee = $this->userDomainHelper->loadUser($plan->userId, $portal->title);
            if (!$hasAccess) {
                $actorPortalAccount = $this->accessChecker->validAccount($req, $portal->title);
                $hasAccess = ($actorPortalAccount && !empty($assignee->account))
                    ? $this->userDomainHelper->isManager($portal->title, $actorPortalAccount->id, $assignee->account->legacyId, true)
                    : false;

                if (!$hasAccess) {
                    // managers can delete their own assignments.
                    $managerRole = array_filter($assignee->account->roles, fn (PortalAccountRole $role) => $role->name === 'MANAGER');

                    if (!empty($managerRole)) {
                        if ($currentUser->id == $assignee->legacyId) {
                            $hasAccess = true;
                        }
                    }
                }
            }

            if (!$hasAccess) {
                return Error::jr403("Only portal administrator and user's manager can archive learning.");
            }
        }
        $queueContext = [];
        if ($notify !== null) {
            $queueContext['notify'] = $notify;
        }
        $this->rPlan->archive($planId, [], $queueContext);
        $this->rEnrolment->removeEnrolmentPlansByPlanId($planId);
        $this->rEnrolment->deletePlanReference($planId);

        return new JsonResponse(null, 204);
    }

    public function deleteGroup(int $instanceId, int $loId, int $groupId, Request $req): JsonResponse
    {
        if (!$currentUser = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$portal = $this->portalService->loadBasicById($instanceId)) {
            return Error::jr404('Portal not found.');
        }

        if (!$lo = LoHelper::load($this->go1, $loId)) {
            return Error::jr404('LO not found.');
        }

        if (!$group = GroupHelper::load($this->dbSocial, $groupId)) {
            return Error::jr404('Group not found.');
        }

        if (!GroupHelper::groupAccess($group->user_id, $currentUser->id, $this->accessChecker, $req, $portal->title)) {
            return Error::jr403('Only group owner or admin can delete group assign learning.');
        }

        GroupAssignHelper::archive($this->dbSocial, $this->mqClient, $groupId, $instanceId, $currentUser->id, GroupAssignTypes::LO, $loId);

        return new JsonResponse(null, 204);
    }
}
