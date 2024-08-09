<?php

namespace go1\enrolment\services;

use Assert\LazyAssertionException;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\domain\ConnectionWrapper;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\lo\LiTypes;
use go1\util\lo\LoTypes;
use go1\util\model\Enrolment;
use go1\util\plan\PlanRepository;
use PDO;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @property ConnectionWrapper $accessChecker
 * @property Connection $read
 * @property PlanRepository $rPlan
 * @property UserDomainHelper $userDomainHelper
 */
trait EnrolmentValidationServiceTrait
{
    public function validateStatusPermission($learningObject, $portalName, $takenInPortalName, $status, array $authorIds, Request &$req, $enrolment = null)
    {
        if ($status == EnrolmentStatuses::EXPIRED && !$this->accessChecker->isAccountsAdmin($req)) {
            return new JsonResponse(['message' => 'Only root admin can set expired status.'], 403);
        }

        if ($this->accessChecker->isPortalAdmin($req, $portalName) || $this->accessChecker->isPortalAdmin($req, $takenInPortalName)) {
            $req->attributes->set('isAdmin', true);

            return null;
        }

        $actor = $this->accessChecker->validUser($req);
        if (in_array($actor->id, $authorIds)) {
            $req->attributes->set('isAuthor', true);

            return null;
        }

        if ($enrolment) {
            $courseId = (LoTypes::COURSE == $learningObject->type) ? $learningObject->id : false;
            $learningObjectData = json_decode($learningObject->data);
            $shouldAllowManualCompletion = is_object($learningObjectData) && property_exists($learningObjectData, 'can_mark_as_complete') && $learningObjectData->can_mark_as_complete == true;

            if (!$courseId) {
                $courseEnrolment = $this->findParentEnrolment($enrolment);
                $courseId = $courseEnrolment ? $courseEnrolment->loId : false;
            }

            if ($courseId && AccessChecker::isAssessor($this->read->get(), $courseId, $actor->id, $enrolment->user_id, $req)) {
                $req->attributes->set('isAssessor', true);

                return null;
            }

            // checking event instructor
            if (LiTypes::EVENT == $learningObject->type && !empty($req->attributes->get('internal_data.is_instructor'))) {
                return null;
            }
            // Student CANNOT manually change enrolment status of another student.
            if ($enrolment->user_id != $actor->id) {
                # Manager can change status of their student.
                $learner = $this->userDomainHelper->loadUser($enrolment->user_id, $takenInPortalName);
                $actorPortalAccount = $this->accessChecker->validAccount($req, $takenInPortalName);
                if ($actorPortalAccount && !empty($learner->account)) {
                    if ($this->userDomainHelper->isManager($takenInPortalName, $actorPortalAccount->id, $learner->account->legacyId)) {
                        $req->attributes->set('isManager', true);

                        return null;
                    }
                }

                $errMsg = 'Only portal admin can update enrollment.';

                return new JsonResponse(['message' => $errMsg], 403);
            }

            // Student CAN start NOT-STARTED enrolment.
            if ((EnrolmentStatuses::NOT_STARTED == $enrolment->status) && (EnrolmentStatuses::IN_PROGRESS == $status)) {
                return null;
            }

            // Student CAN manually change enrolment status of simple LI.
            if (in_array($learningObject->type, LiTypes::all()) && !in_array($learningObject->type, LiTypes::COMPLEX)) {
                return null;
            }

            // Student CAN manually mark enrolment as complete if LO explicitly allows for manual completion
            if ($shouldAllowManualCompletion) {
                $errMsg = null;

                if ($status != EnrolmentStatuses::COMPLETED) {
                    $errMsg = 'Only portal administrators can update the enrolment to this status.';
                }

                if (!in_array($enrolment->status, [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::NOT_STARTED])) {
                    $errMsg = 'Only portal administrators can update the status of this enrolment.';
                }

                return $errMsg ? new JsonResponse(['message' => $errMsg], 403) : null;
            }

            // Student CANNOT manually change complex LI and LO enrolment status.
            return new JsonResponse(['message' => 'Only portal admin can update enrolment status.'], 403);
        } else {
            // Student CAN manually change enrolment status of simple LI.
            if (in_array($learningObject->type, LiTypes::all()) && !in_array($learningObject->type, LiTypes::COMPLEX)) {
                return null;
            }

            // Student CAN enrol into any LEARNING OBJECT but CANNOT change to another statuses, such as 'completed'.
            if (!in_array($status, [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::PENDING, EnrolmentStatuses::NOT_STARTED])) {
                return new JsonResponse(['message' => 'You can not to manually complete an learning object.'], 403);
            }
        }
    }

    public function validateDueDatePermission($dueDate, stdClass $enrolment, stdClass $learningObject, Request $req)
    {
        if (false === $dueDate) {
            return null;
        }

        if ($req->get('isAdmin') || $req->get('isManager') || $req->get('isAssessor') || $req->get('isAuthor')) {
            return null;
        }

        if (LoTypes::COURSE != $learningObject->type) {
            if ($parentEnrolment = $this->findParentEnrolment($enrolment)) {
                $enrolment = $parentEnrolment;
            } else {
                $enrolment = Enrolment::create($enrolment);
            }
        }

        $actor = $this->accessChecker->validUser($req);

        $edge = $this
            ->read->get()
            ->executeQuery('SELECT * FROM gc_enrolment_plans WHERE enrolment_id = ?', [$enrolment->id])
            ->fetch(PDO::FETCH_OBJ);

        if ($edge) {
            if (!$plan = $this->rPlan->load($edge->plan_id)) {
                $e = new LazyAssertionException(['message' => 'Plan object not found.', 'path' => 'dueDate'], []);

                return Error::createLazyAssertionJsonResponse($e);
            }

            // PO owner CAN update dueDate
            if ($plan->assignerId) {
                if ($plan->assignerId == $actor->id) {
                    return null;
                }
            } else {
                if ($enrolment->userId == $actor->id) {
                    return null;
                }
            }
        } else {
            // Student CAN create new PO for his enrolment
            if ($enrolment->userId == $actor->id) {
                return null;
            }
        }

        return Error::jr403('Only admin can change due date.');
    }
}
