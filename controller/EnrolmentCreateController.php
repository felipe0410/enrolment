<?php

namespace go1\enrolment\controller;

use Exception;
use stdClass;
use Psr\Log\LoggerInterface;
use go1\clients\PortalClient;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\controller\create\validator\EnrolmentCreateValidator;
use go1\enrolment\controller\create\validator\EnrolmentTrackingValidator;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentCreateOption;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\DB;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\lo\LoChecker;
use go1\util\lo\LoTypes;
use go1\util\payment\TransactionStatus;
use go1\util\policy\Realm;
use go1\util\portal\PortalChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentCreateController
{
    protected ConnectionWrapper $go1ReadDb;
    protected EnrolmentRepository $repository;
    protected string $accountsName;
    protected AccessChecker $accessChecker;
    protected LoChecker $loChecker;
    protected PortalChecker $portalChecker;
    protected ContentSubscriptionService $contentSubscriptionService;
    protected PortalClient $portalClient;
    protected EnrolmentCreateService $enrolmentCreateService;
    protected LoggerInterface $logger;
    protected EnrolmentCreateValidator $enrolmentCreateValidator;
    protected EnrolmentTrackingValidator $enrolmentTrackingValidator;
    protected UserDomainHelper $userDomainHelper;
    protected PortalService $portalService;

    public function __construct(
        LoggerInterface $logger,
        ConnectionWrapper $go1ReadDb,
        EnrolmentRepository $repository,
        EnrolmentCreateService $enrolmentCreateService,
        string $accountsName,
        AccessChecker $accessChecker,
        LoChecker $loChecker,
        PortalChecker $portalChecker,
        ContentSubscriptionService $contentSubscriptionService,
        PortalClient $portalClient,
        EnrolmentCreateValidator $enrolmentCreateValidator,
        EnrolmentTrackingValidator $enrolmentTrackingValidator,
        UserDomainHelper $userDomainHelper,
        PortalService $portalService
    ) {
        $this->logger = $logger;
        $this->go1ReadDb = $go1ReadDb;
        $this->repository = $repository;
        $this->enrolmentCreateService = $enrolmentCreateService;
        $this->accountsName = $accountsName;
        $this->accessChecker = $accessChecker;
        $this->loChecker = $loChecker;
        $this->portalChecker = $portalChecker;
        $this->contentSubscriptionService = $contentSubscriptionService;
        $this->portalClient = $portalClient;
        $this->enrolmentCreateValidator = $enrolmentCreateValidator;
        $this->enrolmentTrackingValidator = $enrolmentTrackingValidator;
        $this->userDomainHelper = $userDomainHelper;
        $this->portalService = $portalService;
    }

    public function postForStudent(
        string $instance,
        int $parentLoId,
        int $loId,
        string $status,
        string $studentMail,
        Request $req
    ): JsonResponse {
        if (is_numeric($instance)) {
            $portal = $this->portalService->loadBasicById((int) $instance);
        } else {
            $portal = $this->portalService->loadBasicByTitle($instance);
        }

        if (!$portal) {
            return Error::jr404('Portal not found.');
        }

        $student = $req->attributes->get('studentUser');
        if (empty($student)) {
            return Error::jr404('Student not found.');
        }

        if (!$student->profile_id) {
            return Error::jr406('Invalid student profile.');
        }

        if (!$learningObject = $this->enrolmentCreateValidator->learningObject($loId, $req)) {
            return Error::jr404('Learning object not found.');
        }

        if (!$loPortal = $this->portalService->loadBasicById($learningObject->instance_id)) {
            return Error::jr404('Learning object portal not found.');
        }

        if (!$user = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        $error = $this->enrolmentCreateValidator->validateLo(
            $req,
            $learningObject,
            $loPortal,
            $portal,
            $student,
            $loId,
            $user
        );
        if ($error !== null) {
            return $error;
        }

        $reEnrol = $req->get('reEnrol') ? true : false;
        if ($reEnrol) {
            if (!$this->loChecker->allowReEnrol($learningObject)) {
                return Error::jr406('Re-enrolling is disabled.');
            }
        }

        $transaction = $req->attributes->get('transaction');
        $parentEnrolment = $req->attributes->get('parentEnrolment');
        $enrolmentAttributes = $req->attributes->get('enrolmentAttributes');
        $dueDate = $req->get('dueDate', false);
        $startDate = $req->get('startDate', false);
        $notify = (bool) $req->get('notify', true);

        $option = $this->enrolmentCreateValidator->setEnrolmentOption(
            $student,
            $learningObject,
            $parentLoId,
            $status,
            $portal->id,
            $reEnrol,
            $loId,
            $dueDate,
            $startDate,
            false,
            false,
            $user->id,
            0,
            false,
            [],
            $transaction,
            $parentEnrolment,
            $enrolmentAttributes,
            $notify,
            $user,
            null,
            0
        );
        try {
            return $this->doPost($option);
        } catch (Exception $e) {
            $this->logger->error("Failed to create enrolment: postForStudent failed", [
                'exception' => $e
            ]);
            return Error::jr500('Internal error.');
        }
    }

    public function postMultipleForStudent(
        string $instance,
        string $studentMail,
        Request $req
    ): JsonResponse {
        return $this->postMultiple($instance, $req);
    }

    public function postMultiple(string $instance, Request $req): JsonResponse
    {
        $learningObjects = $req->attributes->get('learningObjects');
        $parentLearningObjectIds = $req->attributes->get('parentLearningObjectIds', []);
        $enrolmentStatuses = $req->attributes->get('enrolmentStatuses');
        $dueDates = $req->attributes->get('dueDates');
        $startDates = $req->attributes->get('startDates');
        $body = [];

        if ($learningObjects) {
            foreach ($learningObjects as $learningObject) {
                if (!$learningObject) {
                    continue;
                }

                DB::safeThread(
                    $this->go1ReadDb->get(),
                    "lo:{$learningObject->id}:enrolment",
                    5,
                    function () use (
                        &$instance,
                        &$learningObject,
                        &$parentLearningObjectIds,
                        &$enrolmentStatuses,
                        &$dueDates,
                        &$startDates,
                        &$req,
                        &$body
                    ) {
                        $parentLearningObjectId =
                            isset($parentLearningObjectIds[$learningObject->id]) ?
                                $parentLearningObjectIds[$learningObject->id] : null;
                        $status = isset($enrolmentStatuses[$learningObject->id]) ?
                            $enrolmentStatuses[$learningObject->id] : EnrolmentStatuses::IN_PROGRESS;
                        $req->attributes->set('dueDate', $dueDates[$learningObject->id]);
                        $req->attributes->set('startDate', $startDates[$learningObject->id]);
                        $response = $this->postSingle(
                            $instance,
                            $parentLearningObjectId,
                            $learningObject,
                            $status,
                            $req
                        );
                        $body[$learningObject->id][$response->getStatusCode()] =
                            json_decode($response->getContent());
                    }
                );
            }
        }

        return new JsonResponse($body);
    }

    protected function postSingle(
        string $instance,
        ?int $parentLoId,
        stdClass $learningObject,
        string $status,
        Request $req
    ): JsonResponse {
        if (!$portal = $req->attributes->get('takenInPortal', null)) {
            return Error::jr404('Portal not found.');
        }

        if (!$actor = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        $reEnrol = $req->get('reEnrol') ? true : false;
        if ($reEnrol && !$this->loChecker->allowReEnrol($learningObject)) {
            return Error::jr406('Re-enrolling is disabled.');
        }

        if ($studentUser = $req->attributes->get('studentUser')) {
            $user = $this->userDomainHelper->loadUser($studentUser->id, $portal->title);
            $studentId = $user->legacyId;
            $studentProfileId = $user->profileId;
        } else {
            $studentId = $actor->id;
            $studentProfileId = $actor->profile_id;
        }

        $transaction = $req->attributes->get('transaction');
        $membership = $req->attributes->get('membership');
        $parentEnrolment = $req->attributes->get('parentEnrolment');
        $data = $req->get('data', []);
        $byPassPayment = $req->get('admin') &&
            $this->enrolmentCreateValidator->isLoAuthor(
                $req,
                $portal->title,
                $learningObject->id,
                $actor->id
            );
        $byPassPayment = $byPassPayment ||
            (Realm::ACCESS == $learningObject->realm);
        $notify = (bool) $req->get('notify', true);

        if (!$transaction && !$byPassPayment && !$membership &&
            !$this->contentSubscriptionService->hasSubscription(
                $learningObject,
                $studentId,
                $portal->id
            )) {
            $enrolment = $this->repository
                ->loadByLoAndUserAndTakenInstanceId(
                    $learningObject->id,
                    $studentId,
                    $portal->id,
                    $parentEnrolment->id ?? 0
                );
            return $enrolment
                ? new JsonResponse(['id' => (int) $enrolment->id])
                    : Error::jr403('Can not enrol to commercial learning object.');
        }

        $reCalculate = $req->get('reCalculate') ? true : false;
        $dueDate = $req->get('dueDate', false);
        $startDate = $req->get('startDate', false);
        $student = (object) ['profile_id' => $studentProfileId, 'id' => $studentId];
        $endDate = $req->get('endDate', false);
        $pass = $req->get('pass', 0);
        $notify = (bool) $req->get('notify', true);

        $option = $this->enrolmentCreateValidator->setEnrolmentOption(
            $student,
            $learningObject,
            $parentLoId,
            $status,
            $portal->id,
            $reEnrol,
            0,
            $dueDate,
            $startDate,
            $endDate,
            false,
            $actor->id,
            $pass ?? 0,
            $reCalculate,
            $data,
            $transaction,
            $parentEnrolment,
            [],
            $notify,
            null,
            null,
            0
        );

        try {
            $this->licenseCheck($portal, $actor, $learningObject);

            return $this->doPost($option);
        } catch (Exception $e) {
            $this->logger->error("Failed to create enrolment: post failed", [
                'exception' => $e
            ]);
            return Error::jr500('Internal error.');
        }
    }

    protected function licenseCheck(stdClass $portal, stdClass $actor, stdClass $learningObject): void
    {
        $portalLicensing = $this->portalClient->configuration(
            $portal->title,
            "GO1",
            "portal_licensing",
            0
        );
        if ($portalLicensing !== false) {
            $this->contentSubscriptionService->checkForLicense(
                $actor->id,
                $learningObject->id,
                $portal->id
            );
        }
    }

    public function post(
        string $instance,
        int $parentLoId,
        int $loId,
        string $status,
        Request $req
    ): JsonResponse {
        if (!$learningObject = $this->enrolmentCreateValidator->learningObject(
            $loId,
            $req
        )) {
            return Error::jr404('Learning object not found.');
        }
        return $this->postSingle($instance, $parentLoId, $learningObject, $status, $req);
    }

    protected function doPost(EnrolmentCreateOption $option): JsonResponse
    {
        if ($option->learningObject->type === LoTypes::GROUP) {
            return Error::jr('Cannot enrol into group LOs.');
        }

        // Check whether enrolment exist already
        try {
            $enrolment = $this->repository
                ->loadByLoAndUserAndTakenInstanceId(
                    $option->learningObject->id,
                    $option->userId,
                    $option->portalId,
                    $option->parentEnrolmentId
                );
        } catch (Exception $e) {
            $this->logger->error("Failed to query existing enrolment", [
                'exception' => $e
            ]);
            return new JsonResponse(['message' => 'Internal server error.'], 500);
        }

        $response = $this->enrolmentCreateService->preProcessEnrolment($enrolment, $option);
        if ($response) {
            return $response;
        }

        $this->attachContextInfo($option);

        $response = $this->enrolmentCreateService
            ->create(
                $option->createEnrolment(),
                $enrolment,
                $option->enrolmentType,
                $option->reEnrol,
                $option->reCalculate,
                $option->dueDate,
                $option->assigner->id ?? null,
                $option->assignDate,
                $option->notify
            );

        if ($response->code < 400) {
            // only store enrolment tracking info for top-level enrolment
            if ($option->parentLearningObjectId == 0) {
                [$enrolmentOriginalType, $actorId] =
                    $this->enrolmentTrackingValidator->validateParams($option);

                $this->enrolmentCreateService
                    ->postProcessEnrolmentTracking(
                        $response->enrolment->id,
                        $enrolmentOriginalType,
                        $actorId
                    );
            }

            $this->enrolmentCreateService
                ->postProcessLoForEnrolment($response->enrolment->id, $option);
        }

        return new JsonResponse(
            ['id' => $response->enrolment->id],
            $response->code
        );
    }

    private function attachContextInfo(EnrolmentCreateOption &$option): void
    {
        $option->status = EnrolmentStatuses::defaultStatus(
            $this->go1ReadDb->get(),
            $option->userId,
            $option->learningObject,
            $option->status
        );

        // FORCE enrolment status to 'pending' if the transaction is not completed
        if (isset($option->transaction->status)) {
            if (TransactionStatus::COMPLETED != $option->transaction->status) {
                $option->status = EnrolmentStatuses::PENDING;
            }
        }

        if (empty($option->data['transaction']) && isset($option->transaction->id)) {
            $option->data['transaction'] = $option->transaction;
        }

        $history = [];
        if (isset($option->assigner->id)) {
            $history = [
                'action' => 'assigned',
                'actorId' => $option->assigner->id,
                'status' => $option->status,
                'timestamp' => time(),
            ];
        } elseif (isset($option->learningObject->realm)) {
            $history = [
                'realm' => $option->learningObject->realm,
                'status' => $option->status,
                'timestamp' => time(),
            ];
        }

        $option->data['history'][] = $history;
        $option->data['actor_user_id'] = $option->actorUserId ?? 0;
    }
}
