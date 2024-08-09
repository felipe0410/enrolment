<?php

namespace go1\enrolment\controller;

use Assert\Assert;
use Assert\LazyAssertionException;
use Exception;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\Error;
use go1\util\lo\LoHelper;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentReCalculateController
{
    private ConnectionWrapper $db;
    private EnrolmentRepository $repository;
    private UserDomainHelper $userDomainHelper;
    private AccessChecker $accessChecker;
    private LoggerInterface $logger;
    private ?stdClass $enrolment;
    private PortalService $portalService;
    private $user;

    public function __construct(
        ConnectionWrapper $db,
        EnrolmentRepository $repository,
        UserDomainHelper $userDomainHelper,
        AccessChecker $accessChecker,
        LoggerInterface $logger,
        PortalService $portalService
    ) {
        $this->db = $db;
        $this->repository = $repository;
        $this->userDomainHelper = $userDomainHelper;
        $this->accessChecker = $accessChecker;
        $this->logger = $logger;
        $this->portalService = $portalService;
    }

    public function post(int $enrolmentId, Request $req): JsonResponse
    {
        $response = $this->validate($req, $enrolmentId);
        if ($response) {
            return $response;
        }

        $context = [
            'action'  => 'manually-re-calculate-enrolment-progress',
            'actorId' => $this->user->id,
            'note'    => "Manually re-calculate enrolment progress by user #{$this->user->id}",
        ];

        try {
            $this->repository->spreadCompletionStatus($this->enrolment->taken_instance_id, $this->enrolment->lo_id, $this->enrolment->user_id, $reCalculate = true, $context);
            return new JsonResponse(null, 204);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to re-calculate enrolment progress',
                [
                    'enrolment_id' => $enrolmentId,
                    'exception' => $e
                ]
            );
            return new JsonResponse(['message' => 'Can not re-calculate enrolment progress.'], 500);
        }
    }

    private function validate(Request $req, int $enrolmentId): ?JsonResponse
    {
        if (!$this->enrolment = $this->repository->load($enrolmentId)) {
            return Error::jr404('Enrolment not found.');
        }

        if (!$this->user = $this->accessChecker->validUser($req)) {
            return Error::jr403('Invalid or missing JWT.');
        }

        try {
            $takenPortal = $this->portalService->loadBasicById($this->enrolment->taken_instance_id);
            $takenPortalName = $takenPortal ? $takenPortal->title : null;
            $lo = $this->repository->loService()->load($this->enrolment->lo_id);
            $learner = $this->userDomainHelper->loadUser($this->enrolment->user_id, $takenPortalName);

            $assert = Assert::lazy()
                ->that($lo, 'enrolment.lo', 'Invalid learning object.')->isObject()
                ->that($learner, 'enrolment.leaner', 'Invalid leaner.')->isObject();

            $membership = $req->request->has('membership') ? $req->request->get('membership') : null;
            if ($membership) {
                if (is_numeric($membership)) {
                    $portal = $this->portalService->loadBasicById((int) $membership)->title ?? null;
                } else {
                    $portal = $this->portalService->loadBasicByTitle($membership)->title ?? null;
                }
                $assert->that($portal, 'enrolment.lo.instance', 'Invalid membership.')->string();
            } else {
                $portal = $this->portalService->loadBasicByLoId($lo->id)->title ?? null;
                $assert->that($portal, 'enrolment.lo.instance', 'Invalid learning object portal.')->string();
            }

            $assert->verifyNow();
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }

        $validUser = $this->accessChecker->isPortalManager($req, $portal)
            || in_array($this->user->id, LoHelper::parentsAssessorIds($this->db->get(), $lo->id, null, $learner->legacyId))
            || in_array($this->user->id, LoHelper::parentsAuthorIds($this->db->get(), $lo->id));

        if (!$validUser && $takenPortalName) {
            $actorPortalAccount = $this->accessChecker->validAccount($req, $takenPortalName);
            $isManager = ($actorPortalAccount && !empty($learner->account))
                ? $this->userDomainHelper->isManager($takenPortalName, $actorPortalAccount->id, $learner->account->legacyId)
                : false;

            if (!$isManager) {
                return Error::jr403('Only manager or course assessor can re-calculate enrolment progress.');
            }
        }

        return null;
    }
}
