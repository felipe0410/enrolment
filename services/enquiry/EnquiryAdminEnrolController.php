<?php

namespace go1\core\learning_record\enquiry;

use go1\clients\PortalClient;
use go1\core\util\client\federation_api\v1\schema\object\User;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\controller\create\validator\EnrolmentCreateValidator;
use go1\enrolment\controller\create\validator\EnrolmentTrackingValidator;
use go1\enrolment\controller\EnrolmentCreateController;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentCreateOption;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\lo\LoChecker;
use go1\util\lo\LoHelper;
use go1\util\lo\LoTypes;
use go1\util\model\Edge;
use go1\util\portal\PortalChecker;
use go1\util\portal\PortalStatuses;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnquiryAdminEnrolController extends EnrolmentCreateController
{
    private EnquiryRepository $enquiryRepository;

    public function __construct(
        ConnectionWrapper $go1ReadDb,
        EnrolmentRepository $repository,
        EnrolmentCreateService $enrolmentCreateService,
        string $accountsName,
        AccessChecker $accessChecker,
        LoChecker $loChecker,
        LoggerInterface $logger,
        EnquiryRepository $enquiryRepository,
        PortalClient $portalClient,
        PortalChecker $portalChecker,
        ContentSubscriptionService $contentSubscriptionService,
        UserDomainHelper $userDomainHelper,
        EnrolmentCreateValidator $enrolmentCreateValidator,
        EnrolmentTrackingValidator $enrolmentTrackingValidator,
        PortalService $portalService
    ) {
        parent::__construct(
            $logger,
            $go1ReadDb,
            $repository,
            $enrolmentCreateService,
            $accountsName,
            $accessChecker,
            $loChecker,
            $portalChecker,
            $contentSubscriptionService,
            $portalClient,
            $enrolmentCreateValidator,
            $enrolmentTrackingValidator,
            $userDomainHelper,
            $portalService
        );

        $this->enquiryRepository = $enquiryRepository;
    }

    public function review(int $loId, string $mail, Request $req)
    {
        if (!$manager = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$lo = LoHelper::load($this->go1ReadDb->get(), $loId)) {
            return new JsonResponse(['message' => 'Invalid learning object.'], 400);
        }

        if (($lo->type != LoTypes::COURSE) ||
            ($this->loChecker->allowEnrolment($lo) !=
                LoHelper::ENROLMENT_ALLOW_ENQUIRY)) {
            return new JsonResponse(
                ['message' => 'This learning object is not available for enquiring action.'],
                406
            );
        }

        if (!($portal = $this->portalService->load($lo->instance_id)) ||
                ($portal->status != PortalStatuses::ENABLED)) {
            return new JsonResponse(['message' => 'Invalid portal.'], 400);
        }

        if (!$student = $this->userDomainHelper->loadUserByEmail($mail, $portal->title)) {
            return new JsonResponse(['message' => 'Invalid enquiry mail.'], 400);
        }

        if (!$this->accessChecker->isPortalManager($req, $portal->title)) {
            if (!$this->loChecker->isAuthor($this->go1ReadDb->get(), $lo->id, $manager->id)) {
                $actorPortalAccount = $this->accessChecker->validAccount($req, $portal->title);
                $isManager = ($actorPortalAccount && !empty($student->account))
                    ? $this->userDomainHelper->isManager(
                        $portal->title,
                        $actorPortalAccount->id,
                        $student->account->legacyId
                    ) : false;

                if (!$isManager) {
                    $error = "Only portal's admin, student's manager or learning object's author can review enquiry request.";
                    return Error::jr403($error);
                }
            }
        }

        $status = $req->request->get('status');
        if (!in_array($status, [
            EnquiryServiceProvider::ENQUIRY_ACCEPTED,
            EnquiryServiceProvider::ENQUIRY_REJECTED
        ])) {
            return Error::jr("Status must be accepted or rejected.");
        }

        $takenPortal = $req->request->get('instance');
        if (is_numeric($takenPortal)) {
            $takenPortal = $this->portalService->loadBasicById((int) $takenPortal);
        } else {
            $takenPortal = $this->portalService->loadBasicByTitle($takenPortal);
        }
        if (!$takenPortal) {
            return new JsonResponse(['message' => 'Invalid taken portal.'], 400);
        }

        $enquiryEdge = $this->enquiryRepository->findEnquiry($lo->id, $student->legacyId);
        if (!$enquiryEdge) {
            return new JsonResponse(
                ['message' => 'Student did not enquire to this course.'],
                406
            );
        }

        if (empty($enquiryEdge->data)) {
            // This is because old enquiries we saved did not contains data.
            return new JsonResponse(['message' => 'Missing data for enquiry.'], 406);
        }

        if (!isset($enquiryEdge->data->status) || $enquiryEdge->data->status !==
            EnquiryServiceProvider::ENQUIRY_PENDING) {
            return new JsonResponse(
                [
                'message' => 'Can not accept or reject if enquiry is not pending.'],
                406
            );
        }

        // Checking is this review request for re-enquiry step
        $reEnquiry = (bool) ($enquiryEdge->data->re_enquiry ?? null);

        return $this->doReview(
            $enquiryEdge,
            $lo,
            $student,
            $manager,
            $status,
            $reEnquiry,
            $takenPortal->id
        );
    }

    private function doReview(
        Edge $enquiryEdge,
        stdClass $lo,
        User $student,
        stdClass $manager,
        string $status,
        bool $reEnquiry,
        int $takenPortalId
    ): JsonResponse {
        if ($status === EnquiryServiceProvider::ENQUIRY_ACCEPTED) {
            $data['history'][] = [
                'action' => 'enquiry_accepted',
                'actorId' => $manager->id,
                'status' => EnrolmentStatuses::IN_PROGRESS,
                'timestamp' => time(),
            ];

            $option = EnrolmentCreateOption::create();
            $option->profileId = $student->profileId;
            $option->userId = $student->legacyId;
            $option->learningObject = $lo;
            $option->status = EnrolmentStatuses::IN_PROGRESS;
            $option->portalId = $takenPortalId;
            $option->reEnrol = $reEnquiry;
            $option->data = $data;
            $option->actorUserId = $manager->id ?? 0;
            $response = $this->doPost($option);

            if (!empty($enquiryEdge->data->event) &&
                ($event = LoHelper::load($this->go1ReadDb->get(), $enquiryEdge->data->event))) {
                $option->learningObject = $event;
                $option->parentLearningObjectId = $lo->id;
                $option->parentEnrolmentId = json_decode($response->getContent())->id ?? 0;
                $this->doPost($option);
            }
        }

        if ($this->enquiryRepository->update($enquiryEdge->id, $status, $manager)) {
            return new JsonResponse(null, 204);
        }

        $message = 'Failed to !status enquiry !id for student !mail';
        $this->logger->error($message, [
            '!status' => $status,
            '!id' => $enquiryEdge->id,
            '!mail' => $student->email
        ]);

        return new JsonResponse(['message' => 'Failed to update enquiry request.'], 500);
    }
}
