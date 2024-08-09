<?php

namespace go1\core\learning_record\plan;

use Assert\Assert;
use Assert\LazyAssertionException;
use Doctrine\DBAL\Connection;
use go1\core\group\group_schema\v1\repository\GroupAssignmentRepository;
use go1\core\group\group_schema\v1\repository\GroupRepository;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\Error;
use go1\util\group\GroupHelper;
use go1\util\plan\Plan;
use go1\util\plan\PlanStatuses;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class PlanBrowsingController
{
    private Connection                $go1;
    private Connection                $dbSocial;
    private string                    $accountsName;
    private EnrolmentRepository       $rEnrolment;
    private GroupRepository           $rGroup;
    private GroupAssignmentRepository $rGroupAssignment;
    private AccessChecker             $accessChecker;
    private UserDomainHelper          $userDomainHelper;
    private PortalService             $portalService;

    public function __construct(
        Connection $go1,
        Connection $dbSocial,
        string $accountsName,
        EnrolmentRepository $rEnrolment,
        GroupRepository $rGroup,
        GroupAssignmentRepository $rGroupAssignment,
        AccessChecker $accessChecker,
        UserDomainHelper $userDomainHelper,
        PortalService $portalService
    ) {
        $this->go1           = $go1;
        $this->dbSocial      = $dbSocial;
        $this->accountsName  = $accountsName;
        $this->rEnrolment    = $rEnrolment;
        $this->rGroup        = $rGroup;
        $this->rGroupAssignment = $rGroupAssignment;
        $this->accessChecker = $accessChecker;
        $this->userDomainHelper = $userDomainHelper;
        $this->portalService = $portalService;
    }

    public function get($instanceId, Request $req)
    {
        try {
            $portalId = $req->query->get('portalId', 0);
            if ($portalId) {
                if (!$portal = $this->portalService->loadBasicById($portalId)) {
                    return Error::jr('Portal invalid.');
                }
            }

            if (!$user = $this->accessChecker->validUser($req)) {
                return Error::createMissingOrInvalidJWT();
            }

            $portal = null;
            if ('all' !== $instanceId) {
                Assert::lazy()
                    ->that($instanceId, 'instanceId')->integerish()
                    ->verifyNow();

                if (!$portal = $this->portalService->loadBasicById($instanceId)) {
                    return Error::jr404('Portal not found.');
                }
            }

            $o = PlanBrowsingOptions::create(
                $req,
                $this->go1,
                $this->dbSocial,
                $this->accountsName,
                $this->accessChecker,
                $this->userDomainHelper,
                $portal
            );
            return $this->doGet($o, $user, $portalId);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }
    }

    private function doGet(PlanBrowsingOptions $o, stdClass $user, int $portalId)
    {
        if ($o->groupId) {
            $options = array_filter(['entity_type' => $o->type]);
            $assigns = GroupHelper::groupAssignments($this->dbSocial, $o->groupId, $options);
            $o->entityId += array_map(function ($assign) {
                return $assign->entity_id;
            }, $assigns);
        }

        $plans = $this->rEnrolment->findPlan($o);
        if ($portalId && (0 == $o->offset)) {
            $assignments = [];
            $groups = $this->rGroup->loadMultipleByPortalId($portalId, 0, 200);
            $groups && $assignments = $this->rGroupAssignment->loadByGroupIds(array_column($groups, 'id'));
            foreach ($assignments as $assignment) {
                $plans[] = Plan::create((object)[
                    'user_id'      => $user->id,
                    'assigner_id'  => $assignment->userId,
                    'instance_id'  => $portalId,
                    'entity_type'  => Plan::TYPE_LO,
                    'entity_id'    => $assignment->loId,
                    'status'       => PlanStatuses::ASSIGNED,
                    'due_date'     => $assignment->dueDate,
                    'created_date' => time(),
                    'data'         => null,
                ]);
            }
        }

        return new JsonResponse($plans);
    }

    public function getEntity(int $groupId, Request $req)
    {
        if (!$this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$group = GroupHelper::load($this->dbSocial, $groupId)) {
            return Error::jr404('Group not found.');
        }

        if (!$portal = $this->portalService->loadBasicById($group->instance_id)) {
            return Error::jr404('Portal not found.');
        }

        if (
            !$this->accessChecker->isPortalAdmin($req, $portal->title)
            && !$this->accessChecker->hasAccount($req, $portal->title)
        ) {
            return Error::simpleErrorJsonResponse('User does not belong to portal.');
        }

        $assigns  = GroupHelper::groupAssignments($this->dbSocial, $groupId);
        $entities = array_map(function ($assign) {
            return (object) [
                'id'   => $assign->entity_id,
                'type' => $assign->entity_type,
            ];
        }, $assigns);

        return new JsonResponse($entities);
    }
}
