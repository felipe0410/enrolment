<?php

namespace go1\core\learning_record\plan;

use Doctrine\DBAL\Connection;
use go1\core\util\client\federation_api\v1\PortalAccountMapper;
use go1\core\util\client\federation_api\v1\schema\object\User;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\DateTime;
use go1\util\DB;
use go1\util\Error;
use go1\util\model\Enrolment;
use go1\util\plan\Plan;
use go1\util\plan\PlanRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use stdClass;

/**
 * @property Connection          $go1
 * @property PlanRepository      $planRepository
 * @property EnrolmentRepository $enrolmentRepository
 * @property AccessChecker       $accessChecker
 * @property UserDomainHelper    $userDomainHelper
 * @property PortalService       $portalService
 */
trait ReassignWithPlanTrait
{
    public function reassignWithPlan(Request $req, ReassignOptions $o, $currentUser)
    {
        if (!$originalPlan = $this->planRepository->load($o->planId)) {
            return Error::jr404('Plan object is not found.');
        }

        if (!$portal = $this->portalService->loadBasicById($originalPlan->instanceId)) {
            return Error::jr('The portal connected to this plan does not exist.');
        }

        if (!$actorPortalAccount = $this->accessChecker->validAccount($req, $portal->title)) {
            return Error::jr403('User not belong to portal.');
        }

        $learner = $this->userDomainHelper->loadUser($originalPlan->userId, $portal->title);
        if (!$learner || !$learner->account) {
            return Error::jr('The account connected to this plan does not exist.');
        }

        if ($learner->account->status != 'ACTIVE') {
            return Error::jr('The account connected to this plan is deactivated.');
        }

        if (!$this->canAccessPlan($req, $currentUser, $learner, $actorPortalAccount, $originalPlan, $portal->title)) {
            return Error::jr403("Only portal administrator and user's manager can re-assign learning.");
        }

        if ($o->assignerUserId) {
            $user = $this->userDomainHelper->loadUser($o->assignerUserId, $portal->title);
            if (empty($user->account ?? null)) {
                return Error::jr404('Assigner_user_id not found.');
            }
        }

        $plan = clone $originalPlan;
        $plan->due = $o->dueDate ? DateTime::create($o->dueDate) : null;
        $plan->created = DateTime::create(time());
        $plan->assignerId = $o->assignerUserId ?? $currentUser->id;
        $embedded['account'] = PortalAccountMapper::toLegacyStandardFormat($learner, $learner->account, $portal);
        $embedded['original'] = ['assigner_id' => $originalPlan->assignerId];

        // START: Step to reassign a plan:
        // Archive Current Plan/Assignment => Archive Current enrolment (if the user have already enrolled) => Create new Plan/Assignment
        $enrolment = $this->enrolmentRepository->loadByLoAndUserId($originalPlan->entityId, $originalPlan->userId);

        // Check if queueing service is available
        $queueAvailable = $this->queue->isAvailable();
        if (!$queueAvailable) {
            return new JsonResponse(['message' => 'Internal server error'], 500);
        }

        $planId = DB::transactional(
            $this->go1,
            function () use ($o, $plan, $enrolment, $embedded) {
                // Archive Current Plan/Assignment but without send notification
                $this->planRepository->archive($o->planId, $embedded, ['notify' => false], true);
                $this->enrolmentRepository->removeEnrolmentPlansByPlanId($o->planId);

                // Archive Current enrolment (if the user have already enrolled)
                $enrolment && $this->enrolmentRepository->deleteEnrolment(Enrolment::create($enrolment), $plan->assignerId ?? 0, true, null, true, true);

                // Create new Plan
                return $this->planRepository->create($plan, false, true, ['action' => ReassignOptions::REASSIGNED], $embedded, true);
            }
        );

        // Run queue after all step above finished.
        $this->queue->batchDone();

        // END: Reassign a plan
        return new JsonResponse([['id' => $planId]], 201);
    }

    private function canAccessPlan(Request $req, $currentUser, User $learner, stdClass $actorPortalAccount, Plan $originalPlan, string $instance): bool
    {
        if ($this->accessChecker->isContentAdministrator($req, $instance)) {
            return true;
        }

        if (($originalPlan->userId == $currentUser->id) && ($originalPlan->assignerId && $currentUser->id)) {
            return true;
        }

        if ($this->userDomainHelper->isManager($instance, $actorPortalAccount->id, $learner->account->legacyId, true)) {
            return true;
        }

        return false;
    }
}
