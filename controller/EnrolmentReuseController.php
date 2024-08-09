<?php

namespace go1\enrolment\controller;

use Assert\Assert;
use Assert\LazyAssertionException;
use Exception;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\EnrolmentCreateOption;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\controller\EnrolmentCreateController;
use go1\enrolment\controller\create\validator\EnrolmentCreateValidator;
use go1\enrolment\controller\create\validator\EnrolmentTrackingValidator;
use go1\clients\PortalClient;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\lo\LoHelper;
use go1\util\lo\LoChecker;
use go1\util\portal\PortalChecker;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentReuseController extends EnrolmentCreateController
{
    public function __construct(
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
        LoggerInterface $logger,
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
    }

    public function postReuse($portalIdOrName, Request $req): JsonResponse
    {
        if (is_numeric($portalIdOrName)) {
            $portal = $this->portalService->loadBasicById((int) $portalIdOrName);
        } else {
            $portal = $this->portalService->loadBasicByTitle($portalIdOrName);
        }

        if (!$portal) {
            return Error::jr404('Portal not found.');
        }

        if (!$user = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$this->accessChecker->validUser($req, $portal->title)) {
            return Error::jr('Missing account');
        }

        try {
            $parentEnrolmentId = $req->request->get('parentEnrolmentId');
            $reuseEnrolmentId = $req->request->get('reuseEnrolmentId');

            Assert::lazy()
                ->that($parentEnrolmentId, 'parentEnrolmentId')->integer()
                ->that($reuseEnrolmentId, 'reuseEnrolmentId')->integer()
                ->verifyNow();

            if (!$parentEnrolment =
                EnrolmentHelper::loadSingle($this->go1ReadDb->get(), $parentEnrolmentId)) {
                return Error::jr('Parent enrolment does not exist.');
            }

            if (!$reuseEnrolment =
                EnrolmentHelper::loadSingle($this->go1ReadDb->get(), $reuseEnrolmentId)) {
                return Error::jr('Reuse enrolment does not exist');
            }

            if ((EnrolmentStatuses::COMPLETED != $reuseEnrolment->status) ||
                !$reuseEnrolment->pass) {
                return Error::jr('Reuse enrolment must be completed.');
            }

            if (!$courseEnrolment =
                EnrolmentHelper::parentEnrolment($this->go1ReadDb->get(), $parentEnrolment)) {
                return Error::jr('Invalid parent enrolment.');
            }

            $course = LoHelper::load($this->go1ReadDb->get(), $courseEnrolment->loId);
            if (!$course || !LoHelper::allowReuseEnrolment($course)) {
                return Error::jr('Learning object does not allow re-use enrolment.');
            }

            $validEnrolment = ($parentEnrolment->profileId == $user->profile_id)
                && ($parentEnrolment->takenPortalId == $portal->id)
                && ($reuseEnrolment->profileId == $user->profile_id)
                && ($reuseEnrolment->takenPortalId == $portal->id);
            if (!$validEnrolment) {
                return Error::jr('Invalid parent or reuse enrolment.');
            }

            $childIds = LoHelper::childIds($this->go1ReadDb->get(), $parentEnrolment->loId);
            if (!in_array($reuseEnrolment->loId, $childIds)) {
                $error = 'Learning item from reuse enrolment not a child of learning object.';
                return Error::jr($error);
            }

            if ($enrolment = EnrolmentHelper::findEnrolment(
                $this->go1ReadDb->get(),
                $portal->id,
                $user->id,
                $reuseEnrolment->loId,
                $parentEnrolmentId
            )) {
                return new JsonResponse(['id' => $enrolment->id]);
            }
            $reuseLearningObject = LoHelper::load($this->go1ReadDb->get(), $reuseEnrolment->loId);
            $option = EnrolmentCreateOption::create();
            $option->portalId = $reuseEnrolment->takenPortalId;
            $option->profileId = $user->profile_id;
            $option->userId = $user->id;
            $option->actorUserId = $user->id;
            $option->learningObject = $reuseLearningObject;
            $option->parentEnrolmentId = $parentEnrolment->id;
            $option->status = EnrolmentStatuses::COMPLETED;
            $option->startDate = $reuseEnrolment->startDate;
            $option->endDate = $reuseEnrolment->endDate;
            $option->result = $reuseEnrolment->result;
            $option->pass = 1;

            $response = $this->doPost($option);
            $newEnrolment = $option->createEnrolment();
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getContent());
                $this->repository
                    ->createHasOriginalEnrolment($reuseEnrolment->id, $responseData->id);
                $newEnrolment->id = $responseData->id;
            }

            return new JsonResponse($newEnrolment->jsonSerialize());
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to reuse enrolment',
                ['exception' => $e]
            );
            return Error::jr500('Internal error.');
        }
    }
}
