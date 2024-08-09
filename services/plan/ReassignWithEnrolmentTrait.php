<?php

namespace go1\core\learning_record\plan;

use Doctrine\DBAL\Connection;
use go1\core\util\client\federation_api\v1\PortalAccountMapper;
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
use go1\util\plan\PlanStatuses;
use RuntimeException;

/**
 * @property Connection          $go1
 * @property PlanRepository      $planRepository
 * @property EnrolmentRepository $enrolmentRepository
 * @property AccessChecker       $accessChecker
 * @property UserDomainHelper    $userDomainHelper
 * @property PortalService       $portalService
 */
trait ReassignWithEnrolmentTrait
{
    public function reassignWithEnrolment(Request $req, ReassignOptions $o, $currentUser)
    {
        if (!$portal = $this->portalService->loadBasicById($o->portalId)) {
            return Error::jr('The portal does not exist.');
        }

        $learner = $this->userDomainHelper->loadUser((string) $o->userId, $portal->title);
        if (!$learner || !$learner->account) {
            return Error::jr('The account does not exist.');
        }

        if ($learner->account->status != 'ACTIVE') {
            return Error::jr('The account is deactivated.');
        }

        // in order to get actor id for the enrolment upsert
        if (!$user = $this->accessChecker->validUser($req)) {
            return Error::jr403('Invalid or missing JWT.');
        }

        if (!$this->canAccess($req)) {
            return Error::jr403('Only accounts admin can re-assign learning with LO id.');
        }

        if ($o->assignerUserId) {
            $assigner = $this->userDomainHelper->loadUser($o->assignerUserId, $portal->title);
            if (empty($assigner->account ?? null)) {
                return Error::jr404('Assigner_user_id not found.');
            }
        }

        $currentEnrolment = $this->enrolmentRepository->loadByLoAndUserAndTakenInstanceId($o->loId, $o->userId, $o->portalId);
        $plans = $this->planRepository->loadUserPlanByEntity($o->portalId, $o->userId, $o->loId);
        $currentPlan = $plans[0] ?? null;

        $embedded['account'] = PortalAccountMapper::toLegacyStandardFormat($learner, $learner->account, $portal);
        if ($currentPlan) {
            $embedded['original'] = ['assigner_id' => $currentPlan->assigner_id];
        }

        $newPlan = (object) [
            'user_id' => $o->userId,
            'instance_id' => $o->portalId,
            'entity_id' => $o->loId,
            'entity_type' => Plan::TYPE_LO,
            'status' => PlanStatuses::ASSIGNED,
        ];
        $plan = Plan::create($currentPlan ?? $newPlan);
        $plan->due = $o->dueDate ? DateTime::create($o->dueDate) : null;
        $plan->created = DateTime::create($o->reassignDate);
        $plan->assignerId = $o->assignerUserId ?? $currentUser->id;

        // Check if queueing service is available
        $queueAvailable = $this->queue->isAvailable();
        if (!$queueAvailable) {
            return new JsonResponse(['message' => 'Internal server error'], 500);
        }

        /**
         * Step to reassign with loId
         *  1. Archive Current Enrolment if there is any
         *  2. Unlink Enrolment and Plan if both Enrolment & Plan exist
         *  3. Archive Current Plan(Assignment) if there is any
         *  4. Create a new Plan
         */
        // START
        $planId = DB::transactional(
            $this->go1,
            function () use ($plan, $currentPlan, $currentEnrolment, $embedded, $user) {
                if ($currentEnrolment) {
                    $this->enrolmentRepository->deleteEnrolment(Enrolment::create($currentEnrolment), $user->id ?? 0, true, null, true, true);
                    $currentPlan && $this->enrolmentRepository->unlinkPlan($currentPlan->id, $currentEnrolment->id);
                }
                if ($currentPlan) {
                    $this->planRepository->archive($currentPlan->id, $embedded, ['notify' => false], true);
                    $this->enrolmentRepository->removeEnrolmentPlansByPlanId($currentPlan->id);
                }

                return $this->planRepository->create($plan, false, true, ['action' => ReassignOptions::AUTO_REASSIGNED], $embedded, true);
            }
        );
        $this->queue->batchDone();

        // END: Reassign a plan
        return new JsonResponse([['id' => $planId]], 201);
    }

    private function canAccess(Request $req): bool
    {
        return (bool) $this->accessChecker->isAccountsAdmin($req);
    }
}
