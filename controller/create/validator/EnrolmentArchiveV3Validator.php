<?php

namespace go1\enrolment\controller\create\validator;

use stdClass;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\core\util\client\federation_api\v1\schema\object\User;
use go1\core\util\client\UserDomainHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnrolmentArchiveV3Validator
{
    private AccessChecker $accessChecker;
    private UserDomainHelper $userDomainHelper;
    private EnrolmentRepository $repository;
    private PortalService $portalService;

    public function __construct(
        AccessChecker $accessChecker,
        UserDomainHelper $userDomainHelper,
        EnrolmentRepository $repository,
        PortalService $portalService
    ) {
        $this->accessChecker = $accessChecker;
        $this->userDomainHelper = $userDomainHelper;
        $this->repository = $repository;
        $this->portalService = $portalService;
    }

    public function validateParameters(int $id, Request $req): array
    {
        if (!$actor = $this->accessChecker->validUser($req)) {
            throw new AccessDeniedHttpException('Permission denied. Missing or invalid jwt.');
        }

        if (!$enrolment = $this->repository->load($id)) {
            throw new NotFoundHttpException('Enrollment not found.');
        }

        if (!$portal = $this->portalService->loadBasicByLoId($enrolment->lo_id)) {
            throw new NotFoundHttpException('Portal not found.');
        }

        // root admin don't have portal account
        $actor->account = $this->accessChecker->validAccount($req, $portal->title);

        if (!$student = $this->userDomainHelper->loadUser($enrolment->user_id, $portal->title)) {
            throw new NotFoundHttpException('Student not found.');
        }

        $this->validateActorPermission($req, $portal->title, $actor, $student);

        $retainOriginal = filter_var(
            $req->query->get('retain_original', false),
            FILTER_VALIDATE_BOOLEAN
        );

        return [
            $retainOriginal,
            $portal,
            $actor,
            $enrolment
        ];
    }

    public function validateActorPermission(
        Request $req,
        string $instance,
        stdClass $actor,
        User $student
    ): void {
        if (
            $this->accessChecker->isAccountsAdmin($req)
            || $this->accessChecker->isPortalAdmin($req, $instance)
            || $this->accessChecker->isOwner($req, $student->legacyId, 'id')
            || $actor->account && $this->userDomainHelper->isManager(
                $instance,
                $actor->account->id,
                $student->account->legacyId
            )
        ) {
            return;
        }

        throw new AccessDeniedHttpException(
            'Permission denied. Only manager, admin or learning owner could archive enrollments.'
        );
    }
}
