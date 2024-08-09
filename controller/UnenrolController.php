<?php

namespace go1\enrolment\controller;

use Exception;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\Error;
use go1\util\model\Enrolment;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class UnenrolController
{
    private EnrolmentRepository $repository;
    private AccessChecker       $accessChecker;
    private PortalService       $portalService;

    public function __construct(
        EnrolmentRepository $repository,
        AccessChecker       $accessChecker,
        PortalService       $portalService
    ) {
        $this->repository = $repository;
        $this->accessChecker = $accessChecker;
        $this->portalService = $portalService;
    }

    public function delete(int $loId, Request $req): JsonResponse
    {
        // in order to get actor id for the enrolment upsert
        if (!$user = $this->accessChecker->validUser($req)) {
            return new JsonResponse(['message' => 'Invalid or missing JWT.'], 403);
        }
        $enrolment = $this->repository->loadByLoAndUserId($loId, $user->id);
        $portal = $req->attributes->get('portal');

        if (!$enrolment) {
            return new JsonResponse(['message' => 'Enrolment not found.'], 404);
        }

        if (!$portal && !$portal = $this->portalService->loadBasicByLoId($enrolment->lo_id)) {
            return new JsonResponse(['message' => 'Portal not found.'], 404);
        }

        $access = $this->accessChecker->isOwner($req, $enrolment->user_id, 'id');
        $access |= $this->accessChecker->isPortalAdmin($req, $portal->title) ? true : false;
        if (!$access) {
            return new JsonResponse(null, 403);
        }

        try {
            if (!$this->repository->deleteEnrolment(Enrolment::create($enrolment), $user->id ?? 0, true, null, false, true)) {
                return Error::jr500('Internal error.');
            }

            return new JsonResponse(null, 200);
        } catch (Exception $e) {
            return Error::jr500('Failed to delete enrolment');
        }
    }
}
