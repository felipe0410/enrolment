<?php

namespace go1\enrolment\controller;

use Assert\Assert;
use Assert\LazyAssertionException;
use go1\util\AccessChecker;
use go1\util\Error;
use go1\util\lo\LoTypes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentHistoryController extends EnrolmentLoadController
{
    public function getHistory(int $loId, int $userId, Request $req): JsonResponse
    {
        if (!$actor = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$lo = $this->repository->loService()->load($loId)) {
            return Error::jr404('Learning object not found.');
        }

        if (($userId > 0) && ($userId != $actor->id)) {
            if (!$portal = $this->portalService->loadBasicById($lo->instance_id)) {
                return Error::jr404('Portal not found.');
            }

            if (!$enrolment = $this->repository->loadByLoAndUserId($loId, $userId)) {
                // If there is no enrolment but there is a history of it then load the erolment from the revision.
                // This is a temporary solution Please revert this code once we release the unified enrolment.
                if (!$enrolment = $this->repository->loadEnrolmentFromRevision($loId, $userId)) {
                    return Error::jr('Enrolment not found');
                }
            }

            $takenPortal = $this->portalService->loadBasicById($enrolment->taken_instance_id);
            $portalName = $takenPortal->title ?? $portal->title;

            if (!$user = $this->userDomainHelper->loadUser((string) $userId, $portalName)) {
                return Error::jr('User not found');
            }

            if (!$this->accessChecker->isPortalAdmin($req, $portalName)) {
                $courseId = (LoTypes::COURSE == $lo->type) ? $lo->id : false;
                if (!$courseId) {
                    $courseEnrolment = $this->repository->findParentEnrolment($enrolment);
                    $courseId = $courseEnrolment ? $courseEnrolment->loId : false;
                }

                $isAssessor = $courseId && AccessChecker::isAssessor($this->go1ReadDbWrapper->get(), $courseId, $actor->id, $enrolment->user_id, $req);
                if (!$isAssessor) {
                    $actorPortalAccount = $this->accessChecker->validAccount($req, $portalName);

                    $isStudentManager = ($courseId && $actorPortalAccount && !empty($user->account))
                        ? $this->userDomainHelper->isManager($portalName, $actorPortalAccount->id, $user->account->legacyId)
                        : false;

                    if (!$isStudentManager) {
                        return Error::jr403('Permission denied');
                    }
                }
            }
        } else {
            $userId = $actor->id;
        }

        $offset = $req->get('offset', 0);
        $limit = $req->get('limit', 50);

        try {
            Assert::lazy()
                ->that($limit, 'limit')->nullOr()->numeric()->min(1)->max(50)
                ->that($offset, 'offset')->nullOr()->numeric()->min(0)
                ->verifyNow();

            $enrolments = $this->repository->revisions($loId, $userId, $offset, $limit);
            foreach ($enrolments as $enrolment) {
                $this->attachAssigner($enrolment);
            }

            return new JsonResponse($enrolments, 200);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }
    }
}
