<?php

namespace go1\core\learning_record\enquiry;

use Assert\Assert;
use Assert\LazyAssertionException;
use go1\core\util\client\federation_api\v1\UserMapper;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\lo\LiTypes;
use go1\util\lo\LoChecker;
use go1\util\lo\LoHelper;
use go1\util\model\Edge;
use go1\util\portal\PortalStatuses;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnquiryController
{
    private ConnectionWrapper $db;
    private string $accountsName;
    private EnquiryRepository $repository;
    private AccessChecker $access;
    private LoChecker $loChecker;
    private UserDomainHelper $userDomainHelper;
    private PortalService $portalService;

    public function __construct(
        ConnectionWrapper $db,
        string $accountsName,
        EnquiryRepository $repository,
        AccessChecker $accessChecker,
        LoChecker $loChecker,
        UserDomainHelper $userDomainHelper,
        PortalService $portalService
    ) {
        $this->db = $db;
        $this->accountsName = $accountsName;
        $this->repository = $repository;
        $this->access = $accessChecker;
        $this->loChecker = $loChecker;
        $this->userDomainHelper = $userDomainHelper;
        $this->portalService = $portalService;
    }

    public function get(int $loId, string $mail, Request $req)
    {
        if (!$this->access->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$user = $this->userDomainHelper->loadUserByEmail($mail)) {
            return Error::jr('Invalid enquiry mail.');
        }

        $edge = $this->repository->findEnquiry($loId, $user->legacyId);

        $reEnquiry = $req->get('re_enquiry');
        if ($edge && in_array($reEnquiry, [1, true]) && $this->isAcceptedEnquiry($edge)) {
            $edge = null;
        }

        return $edge
            ? new JsonResponse($edge, 200)
            : new JsonResponse([], 404);
    }

    public function delete(int $loId, string $studentMail, Request $req)
    {
        if (!$user = $this->access->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$lo = LoHelper::load($this->db->get(), $loId)) {
            return new JsonResponse(['message' => 'Invalid learning object.'], 400);
        }

        if (($lo->type != 'course') || ($this->loChecker->allowEnrolment($lo) != LoHelper::ENROLMENT_ALLOW_ENQUIRY)) {
            return new JsonResponse(['message' => 'This learning object is not available for enquiring action.'], 406);
        }

        if (!($portal = $this->portalService->load($lo->instance_id)) || ($portal->status != PortalStatuses::ENABLED)) {
            return new JsonResponse(['message' => 'Invalid portal.'], 400);
        }

        if (!$student = $this->userDomainHelper->loadUserByEmail($studentMail, $portal->title)) {
            return new JsonResponse(['message' => 'Invalid enquiry mail.'], 400);
        }

        if (!$this->access->isPortalManager($req, $portal->title)) {
            $actorPortalAccount = $this->access->validAccount($req, $portal->title);
            $isManager = ($actorPortalAccount && !empty($student->account))
                ? $this->userDomainHelper->isManager($portal->title, $actorPortalAccount->id, $student->account->legacyId)
                : false;

            if (!$isManager) {
                return new JsonResponse(['message' => 'Only portal\'s admin or student\'s manager can delete enquiry request'], 403);
            }
        }

        $enquiryEdge = $this->repository->findEnquiry($loId, $student->legacyId);
        if (!$enquiryEdge) {
            return new JsonResponse(['message' => 'Invalid enquiry.'], 400);
        }

        return $this->doDelete($enquiryEdge);
    }

    public function deleteById(int $id, Request $req)
    {
        if (!$this->access->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if ($portalName = $req->get('instance')) {
            if (is_numeric($portalName)) {
                $portal = $this->portalService->loadBasicById((int) $portalName);
            } else {
                $portal = $this->portalService->loadBasicByTitle($portalName);
            }
            if (!$portal) {
                return new JsonResponse(['message' => 'Invalid portal.'], 400);
            }

            if (!$this->access->isPortalManager($req, $portal->title)) {
                return new JsonResponse(['message' => 'Only portal\'s admin or student\'s manager can delete enquiry request'], 403);
            }
        } else {
            if (!$this->access->isAccountsAdmin($req)) {
                return new JsonResponse(['message' => 'Only portal\'s admin or student\'s manager can delete enquiry request'], 403);
            }
        }

        if (!$enquiryEdge = EdgeHelper::load($this->db->get(), $id)) {
            return new JsonResponse(['message' => 'Invalid enquiry.'], 400);
        }

        return $this->doDelete($enquiryEdge);
    }

    private function doDelete(Edge $enquiryEdge): JsonResponse
    {
        if ($enquiryEdge->type != EdgeTypes::HAS_ENQUIRY) {
            return new JsonResponse(['message' => 'Invalid enquiry.'], 400);
        }

        EdgeHelper::remove($this->db->get(), $this->repository->queue(), $enquiryEdge);

        return new JsonResponse(null, 204);
    }

    public function post(int $loId, string $mail, Request $req)
    {
        $firstName = $req->get('enquireFirstName');
        $lastName = $req->get('enquireLastName');
        $phone = $req->get('enquirePhone');
        $message = $req->get('enquireMessage');
        $liEventId = $req->get('enquireEvent');

        try {
            Assert::lazy()
                  ->that($loId, 'loId')->numeric()
                  ->that($mail, 'mail')->email()
                  ->that($firstName, 'enquireFirstName')->string()
                  ->that($lastName, 'enquireLastName')->string()
                  ->that($phone, 'enquirePhone')->nullOr()->string()
                  ->that($message, 'enquireMessage')->string()
                  ->that($liEventId, 'enquireEvent')->nullOr()->numeric()
                  ->verifyNow();
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }

        if (!$student = $this->access->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if ($student->mail != $mail) {
            return new JsonResponse(['message' => 'Invalid enquiry mail.'], 400);
        }

        if (!$lo = LoHelper::load($this->db->get(), $loId)) {
            return new JsonResponse(['message' => 'Invalid learning object.'], 400);
        }

        if (($lo->type != 'course') || ($this->loChecker->allowEnrolment($lo) != LoHelper::ENROLMENT_ALLOW_ENQUIRY)) {
            return new JsonResponse(['message' => 'This learning object is not available for enquiring action.'], 406);
        }

        if (!($portal = $this->portalService->load($lo->instance_id)) || ($portal->status != PortalStatuses::ENABLED)) {
            return new JsonResponse(['message' => 'Invalid portal.'], 400);
        }

        if ($liEventId && !$this->isLiEvent($loId, $liEventId)) {
            return new JsonResponse(['message' => 'Invalid attached event.'], 400);
        }

        $doReEnquiry = false;
        $enquiryEdge = $this->repository->findEnquiry($lo->id, $student->id);
        if ($enquiryEdge) {
            $reEnquiry = $req->request->get('reEnquiry');

            if (in_array($reEnquiry, [1, true]) && $this->isValidReEnquiryRequest($enquiryEdge, $student, $loId, $portal->id)) {
                // Archive existing accepted enquiry edge and create a new one
                // when learner re-enquiry on a completed course
                EdgeHelper::remove($this->db->get(), $this->repository->queue(), $enquiryEdge);
                $doReEnquiry = true;
            } else {
                return new JsonResponse(['id' => $enquiryEdge->id]);
            }
        }

        $edgeId = $this->repository->create($lo, $student, $firstName, $lastName, $phone, $message, $doReEnquiry, $liEventId);

        return $edgeId ? new JsonResponse(['id' => $edgeId]) : new JsonResponse(['message' => 'Failed to make enquiry request.'], 500);
    }

    private function isLiEvent(int $loId, int $liEventId): bool
    {
        $liEvent = LoHelper::load($this->db->get(), $liEventId);
        if ($liEvent && (LiTypes::EVENT == $liEvent->type)) {
            $hasLiEventRo = EdgeHelper::edges($this->db->get(), [$loId], [$liEventId], [EdgeTypes::HAS_LI]);
            if ($hasLiEventRo) {
                $hasEventRo = EdgeHelper::edgesFromSource($this->db->get(), $liEventId, [EdgeTypes::HAS_EVENT_EDGE]);
                if ($hasEventRo) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isAcceptedEnquiry(Edge $enquiryEdge): bool
    {
        return isset($enquiryEdge->data->status) && (EnquiryServiceProvider::ENQUIRY_ACCEPTED == $enquiryEdge->data->status);
    }

    private function getEnquiryUser(stdClass $requestUser)
    {
        if (!isset($requestUser->profile_id) || !isset($requestUser->instance) || ($this->accountsName != $requestUser->instance)) {
            $user = $this->userDomainHelper->loadUserByEmail($requestUser->mail);
            return $user ? (object)UserMapper::toLegacyStandardFormat($this->accountsName, $user) : null;
        }

        return $requestUser;
    }

    private function isValidReEnquiryRequest(Edge $enquiryEdge, stdClass &$learner, int $loId, int $portalId): bool
    {
        if ($this->isAcceptedEnquiry($enquiryEdge)) {
            if ($learner = $this->getEnquiryUser($learner)) {
                $completedEnrolment = EnrolmentHelper::findEnrolment($this->db->get(), $portalId, $learner->id, $loId);
                if ($completedEnrolment && (EnrolmentStatuses::COMPLETED == $completedEnrolment->status)) {
                    return true;
                }
            }
        }

        return false;
    }
}
