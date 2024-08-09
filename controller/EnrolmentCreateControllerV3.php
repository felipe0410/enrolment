<?php

namespace go1\enrolment\controller;

use stdClass;
use Exception;
use Assert\Assert;
use Assert\LazyAssertionException;
use go1\clients\PortalClient;
use go1\core\util\client\UserDomainHelper;
use go1\util\AccessChecker;
use go1\util\Error;
use go1\util\lo\LoChecker;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\util\model\Enrolment;
use go1\util\portal\PortalChecker;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\UserService;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\controller\create\validator\EnrolmentCreateValidator;
use go1\enrolment\controller\create\validator\EnrolmentTrackingValidator;
use go1\enrolment\controller\create\validator\EnrolmentCreateV3Validator;
use go1\enrolment\content_learning\ErrorMessageCodes;
use go1\enrolment\exceptions\ResourceAlreadyExistsException;
use go1\enrolment\services\PortalService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnrolmentCreateControllerV3 extends EnrolmentCreateController
{
    private UserService $userService;
    private EnrolmentCreateV3Validator $enrolmentCreateV3Validator;

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
        EnrolmentCreateV3Validator $enrolmentCreateV3Validator,
        UserDomainHelper $userDomainHelper,
        UserService $userService,
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

        $this->userService = $userService;
        $this->enrolmentCreateV3Validator = $enrolmentCreateV3Validator;
    }

    public function postV3(Request $req): JsonResponse
    {
        try {
            [
                $reEnrol,
                $enrolType,
                $actor,
                $student,
                $assigner,
                $portal,
                $lo,
                $parentEnrolment,
                $status,
                $result,
                $pass,
                $startDate,
                $endDate,
                $dueDate,
                $assignDate,
                $planRef,
                $transaction
            ] = $this->enrolmentCreateV3Validator->validateParameters($req);

            $option = $this->enrolmentCreateValidator->setEnrolmentOption(
                (object) ['id' => $student->id, 'profile_id' => 0],
                $lo,
                $parentEnrolment->lo_id ?? null,
                $status,
                $portal->id,
                $reEnrol,
                $lo->id,
                $dueDate,
                $startDate,
                $endDate,
                $assignDate,
                $actor->id,
                $pass ?? 0,
                false, // $reCalculate
                [],
                $transaction,
                $parentEnrolment,
                [],
                false, // $notify
                $assigner,
                $enrolType,
                $result
            );

            $existEnrolment = $this->repository
                ->loadByLoAndUserAndTakenInstanceId(
                    $lo->id,
                    $student->id,
                    $portal->id,
                    $parentEnrolment->id ?? 0
                );

            $this->licenseCheck($portal, $actor, $lo);

            if (isset($option->transaction->id)) {
                $option->data['transaction'] = $option->transaction;
            }
            $option->data['actor_user_id'] = $option->actorUserId ?? 0;

            $response = $this->enrolmentCreateService
                ->create(
                    $option->createEnrolment(),
                    $existEnrolment,
                    $option->enrolmentType,
                    $option->reEnrol,
                    $option->reCalculate,
                    $option->dueDate,
                    $option->assigner->id ?? null,
                    $option->assignDate,
                    $option->notify,
                    true,
                    $planRef
                );

            if ($response->code < 400) {
                // only store enrolment tracking info for top-level enrolment
                if ($option->parentEnrolmentId == 0) {
                    $this->enrolmentCreateService
                        ->postProcessEnrolmentTracking(
                            $response->enrolment->id,
                            $option->enrolmentType,
                            $option->actorUserId
                        );
                }
            } else {
                throw new Exception($response->message, $response->code);
            }

            $enrolment = (object) [
                'id' => $response->enrolment->id,
                'user_id' => $student->id,
                'taken_instance_id' => $portal->id,
                'enrollment_type' => $option->parentEnrolmentId
                    ? null : EnrolmentOriginalTypes::toString($option->enrolmentType),
                'user_account_id' => $student->account_id,
                'lo_id' => $lo->id,
                'parent_enrolment_id' => $option->parentEnrolmentId ?? 0,
                'assigner_account_id' => $assigner->accountId,
                'status' => $status,
                'result' => $result,
                'pass' => $pass,
                'assign_date' => $assignDate,
                'due_date' => $dueDate,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'timestamp' => $response->enrolment->timestamp,
                'changed' => $response->enrolment->changed
            ];
            $response = $this->repository->formatSlimEnrollmentResponse($enrolment);

            return new JsonResponse($response, 201);
        } catch (LazyAssertionException $e) {
            return Error::createMultipleErrorsJsonResponse(null, $e);
        } catch (NotFoundHttpException $e) {
            $errorData = [
                'message' => $e->getMessage(),
                'error_code' => ErrorMessageCodes::ENROLLMENT_RESOURCE_NOT_FOUND
            ];
            return Error::createMultipleErrorsJsonResponse($errorData, null, $e->getStatusCode());
        } catch (NotAcceptableHttpException $e) {
            $errorData = [
                'message' => $e->getMessage(),
                'error_code' => ErrorMessageCodes::ENROLLMENT_RE_ENROL_DISABLED
            ];
            return Error::createMultipleErrorsJsonResponse($errorData, null, $e->getStatusCode());
        } catch (AccessDeniedHttpException $e) {
            $errorData = [
                'message' => $e->getMessage(),
                'error_code' => ErrorMessageCodes::ENROLLMENT_OPERATION_NOT_PERMITTED
            ];
            return Error::createMultipleErrorsJsonResponse($errorData, null, $e->getStatusCode());
        } catch (BadRequestHttpException $e) {
            $errorData = [
                'message' => $e->getMessage(),
                'error_code' => ErrorMessageCodes::ENROLLMENT_VALIDATION_ERRORS
            ];
            return Error::createMultipleErrorsJsonResponse($errorData, null, $e->getStatusCode());
        } catch (Exception $e) {
            if ($e->getCode() == 409) {
                $errorData = [
                    'ref' => $response->enrolment->id,
                    'message' => $e->getMessage(),
                    'error_code' => ErrorMessageCodes::ENROLLMENT_EXISTS
                ];
                return Error::createMultipleErrorsJsonResponse($errorData, null, $e->getCode());
            }

            $this->logger->error("Failed to create enrolment", [
                'exception' => $e
            ]);

            return Error::jr500('Internal server error.');
        }
    }
}
