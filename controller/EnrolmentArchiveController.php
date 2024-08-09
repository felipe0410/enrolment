<?php

namespace go1\enrolment\controller;

use Exception;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\Error;
use go1\util\model\Enrolment;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentArchiveController
{
    protected ConnectionWrapper $go1;
    protected UserDomainHelper $userDomainHelper;
    private LoggerInterface $logger;
    private EnrolmentRepository $repository;
    private AccessChecker $accessChecker;
    private PortalService $portalService;

    public function __construct(
        ConnectionWrapper $go1,
        UserDomainHelper $userDomainHelper,
        LoggerInterface $logger,
        EnrolmentRepository $repository,
        AccessChecker $accessChecker,
        PortalService $portalService
    ) {
        $this->go1 = $go1;
        $this->userDomainHelper = $userDomainHelper;
        $this->logger = $logger;
        $this->repository = $repository;
        $this->accessChecker = $accessChecker;
        $this->portalService = $portalService;
    }

    public function archive(int $enrolmentId, Request $req): JsonResponse
    {
        $archiveChild = (bool) $req->get('archiveChild', true);
        $createRevision = (bool) $req->get('createRevision', true);
        // in order to get actor id for the enrolment upsert
        if (!$actor = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$createRevision && !$this->accessChecker->isAccountsAdmin($req)) {
            return Error::jr403('Only Go1 staff can delete enrolment without creating revision');
        }

        if (!$enrolment = EnrolmentHelper::loadSingle($this->go1->get(), $enrolmentId)) {
            $this->repository->deletePlanReferencesByEnrolmentId($enrolmentId);
            return Error::simpleErrorJsonResponse('Enrolment not found.', 404);
        }

        if (!$portal = $this->portalService->loadBasicById($enrolment->takenPortalId)) {
            return Error::simpleErrorJsonResponse('Invalid enrolment.', 400);
        }

        if (!$this->accessChecker->isPortalAdmin($req, $portal->title)) {
            if (!$user = $this->userDomainHelper->loadUser($enrolment->userId, $portal->title)) {
                return Error::simpleErrorJsonResponse('User not found.', 400);
            }

            $actorPortalAccount = $this->accessChecker->validAccount($req, $portal->title);
            $isManager = ($actorPortalAccount && !empty($user->account))
                ? $this->userDomainHelper->isManager($portal->title, $actorPortalAccount->id, $user->account->legacyId)
                : false;

            if (!$isManager) {
                return Error::simpleErrorJsonResponse('Only portal admin or manager can archive enrolment.', 403);
            }
        }

        return $this->doArchive($enrolment, $actor->id ?? 0, $archiveChild, $createRevision);
    }

    private function doArchive(Enrolment $enrolment, int $actorId, bool $archiveChild, bool $createRevision): JsonResponse
    {
        try {
            $this->repository->deleteEnrolment($enrolment, $actorId, $archiveChild, null, false, $createRevision);
            $this->repository->spreadCompletionStatus($enrolment->takenPortalId, $enrolment->loId, $enrolment->userId, true);

            return new JsonResponse([], 204);
        } catch (Exception $e) {
            $this->logger->error('Failed to archive enrolment', ['exception' => $e]);
        }

        return Error::simpleErrorJsonResponse('Failed to archive enrolment', 500);
    }
}
