<?php

namespace go1\enrolment\controller;

use Assert\Assert;
use Assert\LazyAssertionException;
use DateTime;
use go1\core\util\DateTime as UtilDateTime;
use go1\enrolment\Constants;
use go1\enrolment\content_learning\ErrorMessageCodes;
use go1\enrolment\controller\create\validator\EnrolmentCreateV3Validator;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\exceptions\ErrorWithErrorCode;
use go1\enrolment\services\PortalService;
use go1\enrolment\services\UserService;
use go1\util\AccessChecker;
use go1\util\DateTime as DateTimeHelper;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\lo\LoChecker;
use go1\util\lo\LoTypes;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Exception;
use go1\util\lo\LoHelper;
use stdClass;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnrolmentUpdateController
{
    private AccessChecker $accessChecker;
    private ConnectionWrapper $db;
    private EnrolmentCreateV3Validator $enrolmentCreateV3Validator;
    private EnrolmentRepository $repository;
    private LoggerInterface $logger;
    private PortalService $portalService;
    private UserService $userService;

    public function __construct(
        ConnectionWrapper $db,
        LoggerInterface $logger,
        EnrolmentRepository $repository,
        AccessChecker $accessChecker,
        PortalService $portalService,
        EnrolmentCreateV3Validator $enrolmentCreateV3Validator,
        UserService $userService
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->repository = $repository;
        $this->accessChecker = $accessChecker;
        $this->portalService = $portalService;
        $this->enrolmentCreateV3Validator = $enrolmentCreateV3Validator;
        $this->userService = $userService;
    }

    public function put(int $enrolmentId, Request $req): JsonResponse
    {
        $validatedResponse = $this->validateRequest($enrolmentId, $req);
        if ($validatedResponse instanceof JsonResponse) {
            return $validatedResponse;
        }
        list($enrolment, $user, $learningObject, $portal, $takenInPortal) = $validatedResponse;

        try {
            $startDate = $req->request->has('startDate') ? $req->request->get('startDate') : null;
            $endDate = $req->request->has('endDate') ? $req->request->get('endDate') : null;
            $dueDate = $req->request->has('dueDate') ? $req->request->get('dueDate') : false;
            $expectedCompletionDate = $req->request->has('expectedCompletionDate') ? $req->request->get('expectedCompletionDate') : false;
            $assertDueDate = (false === $dueDate) ? null : $dueDate;
            $assertExpectedDueDate = (false === $expectedCompletionDate) ? null : $expectedCompletionDate;
            $status = $req->request->has('status') ? $req->request->get('status') : null;
            $result = $req->request->has('result') ? $req->request->get('result') : null;
            $pass = $req->request->has('pass') ? $req->request->get('pass') : null;
            $duration = $req->request->has('duration') ? $req->request->get('duration') : null;
            $note = $req->request->has('note') ? $req->request->get('note') : null;
            $reCalculate = $req->get('reCalculate') ? true : false;

            $enrolmentStatuses = EnrolmentStatuses::all();
            if ($this->accessChecker->isAccountsAdmin($req)) {
                $enrolmentStatuses = array_merge($enrolmentStatuses, [EnrolmentStatuses::EXPIRED]);
            }

            Assert::lazy()
                ->that($startDate, 'startDate')->nullOr()->date(DATE_ISO8601)
                ->that($endDate, 'endDate')->nullOr()->date(DATE_ISO8601)
                ->that($assertDueDate, 'dueDate')->nullOr()->date(DATE_ISO8601)
                ->that($assertExpectedDueDate, 'expectedCompletionDate')->nullOr()->date(DATE_ISO8601)
                ->that($result, 'result')->nullOr()->numeric()
                ->that($status, 'status')->nullOr()->string()->inArray($enrolmentStatuses)
                ->that($pass, 'pass')->nullOr()->numeric()->inArray([EnrolmentStatuses::PASSED, EnrolmentStatuses::FAILED, EnrolmentStatuses::PENDING_REVIEW])
                ->that($duration, 'duration')->nullOr()->integer()
                ->that($note, 'note')->nullOr()->string()
                ->verifyNow();

            $this->addEnrolmentHistory($user, $enrolment, $status, $pass, $duration);

            return $this->doPut($enrolment, $startDate, $endDate, $dueDate, $expectedCompletionDate, $status, $result, $pass, $note, $user, $learningObject, $portal, $takenInPortal, $reCalculate, $req);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error('Failed to update enrolment', [
                'id'         => $enrolmentId,
                'exception'  => $e,
                'controller' => __CLASS__,
                'method'     => __METHOD__,
            ]);

            return Error::jr500('Internal server error');
        }
    }

    public function putProperties(int $enrolmentId, Request $req): JsonResponse
    {
        if (!$enrolment = $this->repository->load($enrolmentId)) {
            return Error::jr404('Enrolment not found.');
        }

        if (!$user = $this->accessChecker->isAccountsAdmin($req)) {
            return Error::jr403("Only accounts admin can update enrollment's data");
        }

        if (!$learningObject = $this->repository->loService()->load($enrolment->lo_id)) {
            return Error::simpleErrorJsonResponse('Learning object does not exist.');
        }

        try {
            $customCertificate = $req->request->has('custom_certificate') ? $req->request->get('custom_certificate') : null;
            $duration = $req->request->has('duration') ? $req->request->get('duration') : null;

            $claim = Assert::lazy()
                ->that($duration, 'duration')->nullOr()->integer()
                ->that($customCertificate, 'customCertificate')->nullOr()->url();

            if ($customCertificate) {
                $claim
                    ->that($enrolment->status, 'enrolment')->eq(EnrolmentStatuses::COMPLETED)
                    ->that($learningObject->type, 'learningObject')->eq(LoTypes::COURSE);
            }

            $claim->verifyNow();

            $data = empty($enrolment->data) ? (object) [] : (is_scalar($enrolment->data) ? json_decode($enrolment->data) : $enrolment->data);
            $history = [
                'action'    => 'updated',
                'actorId'   => $user->id,
                'timestamp' => time(),
            ];

            foreach (['duration', 'custom_certificate'] as $key) {
                if ($req->request->has($key)) {
                    $history[$key] = $req->request->get($key);
                    $history["original_$key"] = $data->{$key} ?? null;
                    $data->{$key} = $req->request->get($key);
                }
            }
            $data->history[] = $history;

            $this->repository->update($enrolment->id, ['data' => json_encode($data)]);

            return new JsonResponse(null, 204);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error('Failed to update enrolment', [
                'id'         => $enrolmentId,
                'exception'  => $e,
                'controller' => __CLASS__,
                'method'     => __METHOD__,
            ]);

            return Error::jr500('Internal server error');
        }
    }

    /**
     * @throws GuzzleException
     */
    public function patchSlimEnrollment(int $enrolmentId, Request $req): JsonResponse
    {
        try {
            list($enrolment, $user, $learningObject, $portal, $takenInPortal) = $this->validatePatchV3Request($enrolmentId, $req);
            list($status, $result, $pass, $startDate, $endDate, $dueDate, $assigner, $assignDate, $enrollmentType) = $this->validateEnrolmentParameters($req, $enrolment, $user, $takenInPortal->title);
            $applyDateValidation = false;
            if ($startDate || $dueDate || $assignDate) {
                $applyDateValidation = true;
            }
            list($status, $pass, $assignerId, $assignDate, $dueDate, $endDate, $startDate) = $this->loadExistingData($enrolment, $req, $status, $enrollmentType, $assignDate, $dueDate, $assigner, $pass, $endDate, $startDate);

            try {
                $currentDate = (new DateTime())->format(DATE_ATOM);
                if ($applyDateValidation) {
                    $this->enrolmentCreateV3Validator->validateDate($assignDate, $dueDate, $startDate, $endDate, $currentDate, $status);
                }
            } catch (BadRequestHttpException $e) {
                throw new ErrorWithErrorCode(
                    ErrorMessageCodes::ENROLLMENT_INVALID_DATE,
                    $e
                );
            }
            $this->validateStatusChangePermission($enrolment, $learningObject, $status);
            $this->addEnrolmentHistory($user, $enrolment, $status, $pass);
            $updateResponse = $this->doPut($enrolment, $startDate, $endDate, $dueDate, false, $status, $result, $pass, null, $user, $learningObject, $portal, $takenInPortal, false, $req, $assignerId, $assignDate, true);
            if ($updateResponse->getStatusCode() === 204) {
                $enrolment = $this->repository->load($enrolmentId, true);
                $responseData = $this->repository->formatSlimEnrollmentResponse($enrolment);
                return new JsonResponse($responseData);
            }
            return $updateResponse;
        } catch (LazyAssertionException $e) {
            return Error::createMultipleErrorsJsonResponse(null, $e);
        } catch (ErrorWithErrorCode $e) {
            return ErrorMessageCodes::createError($e);
        } catch (ClientException $e) {
            $bodyContent = json_decode($e->getResponse()->getBody()->getContents());
            if ($bodyContent->error_code == 'USER_ACCOUNT_NOT_FOUND') {
                $errorData = [
                    'message' => $bodyContent->message,
                    'error_code' => $bodyContent->error_code
                ];
                return Error::createMultipleErrorsJsonResponse($errorData, null, Error::NOT_FOUND);
            }
            throw $e;
        } catch (Exception | GuzzleException $e) {
            $this->logger->error('Failed to update enrolment', [
                'id'         => $enrolmentId,
                'exception'  => $e,
                'controller' => __CLASS__,
                'method'     => __METHOD__,
            ]);
            throw $e;
        }
    }


    private function loadExistingData(stdClass $enrolment, Request $req, ?string $status, ?string $enrollmentType, ?string $assignDate, $dueDate, ?stdClass $assigner, ?int $pass, ?string $endDate, ?string $startDate): array
    {
        if (!$status) {
            $status = $enrolment->status;
        }
        if (!$req->request->has('pass')) {
            $pass = $enrolment->pass;
        }
        if (!$enrollmentType) {
            $enrollmentType = $enrolment->enrollment_type;
        }
        $assignerId = null;
        if (!$enrolment->parent_enrolment_id && $enrollmentType === EnrolmentOriginalTypes::ASSIGNED) {
            if (!$assignDate && isset($enrolment->assign_date)) {
                $assignDate = $enrolment->assign_date;
            }
            if (!$assignDate) {
                $assignDate = time();
            }
            if (!$dueDate && !empty($enrolment->due_date)) {
                $dueDate = $enrolment->due_date;
            };
            $assignerId = $enrolment->assigner_user_id ?? null;
            if ($assigner) {
                $assignerId = $assigner->id;
            }
        }
        if (!$startDate) {
            $startDate = $enrolment->start_date;
        }
        // If status is completed but the user has not provided the end_date then use the existing end_date, the end_date will be handled by the doPut function
        if (EnrolmentStatuses::COMPLETED == $status && !$req->request->has('end_date')) {
            $endDate = $enrolment->end_date;
        }
        return [$status, $pass, $assignerId, $assignDate, $dueDate, $endDate, $startDate];
    }

    private function addEnrolmentHistory($user, $enrolment, $status, $pass, $duration = null)
    {
        if ($user->id != $enrolment->user_id) {
            $enrolment->data = empty($enrolment->data) ? (object) [] : (is_scalar($enrolment->data) ? json_decode($enrolment->data) : $enrolment->data);
            if ($duration) {
                $enrolment->data->duration = $duration;
            }

            $enrolment->data->history[] = [
                'action'          => 'updated',
                'actorId'         => $user->id,
                'status'          => $status,
                'original_status' => $enrolment->status,
                'pass'            => $pass,
                'original_pass'   => $enrolment->pass,
                'timestamp'       => time(),
            ];
        }
    }

    /**
     * @throws ErrorWithErrorCode
     */
    private function validateStatusChangePermission($enrolment, $learningObject, $status)
    {
        if ($status && $status !== $enrolment->status) {
            if (in_array($status, [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED])) {
                if ($learningObject->instance_id !== $enrolment->taken_instance_id) {
                    throw new ErrorWithErrorCode(
                        ErrorMessageCodes::ENROLLMENT_NOT_ALLOWED_TO_CHANGE_STATUS,
                        new AccessDeniedHttpException('Permission denied. Cannot create in-progress or completed enrollments on content that you do not own.')
                    );
                }
            }
            if ($enrolment->status === EnrolmentStatuses::COMPLETED || ($enrolment->status === EnrolmentStatuses::IN_PROGRESS && $status === EnrolmentStatuses::NOT_STARTED)) {
                throw new ErrorWithErrorCode(
                    ErrorMessageCodes::ENROLLMENT_NOT_ALLOWED_TO_CHANGE_STATUS_TO_PREVIOUS_STATUS,
                    new AccessDeniedHttpException('Permission denied. Enrollment status can not be updated to a previous status.')
                );
            }
        }
    }

    /**
     * @throws ErrorWithErrorCode
     * @throws GuzzleException
     */
    private function validateEnrolmentParameters(Request $req, stdClass $enrolment, stdClass $actor, string $instance): array
    {
        $status = $req->request->get('status', null);
        $enrollmentType = $req->request->get('enrollment_type', null);
        $result = $req->request->get('result', null);
        $pass = $req->request->get('pass', null);
        $startDate = $req->request->get('start_date', null);
        $endDate = $req->request->get('end_date', null);
        $enrolmentStatuses = EnrolmentStatuses::all();
        $pass = $pass ? 1 : 0;

        $assertStartDate = UtilDateTime::replaceLastDigitZ($startDate);
        $assertEndDate   = UtilDateTime::replaceLastDigitZ($endDate);

        Assert::lazy()
            ->that($assertStartDate, 'start_date')->nullOr()->notEmpty()->date(DATE_ATOM)
            ->that($assertEndDate, 'end_date')->nullOr()->notEmpty()->date(DATE_ATOM)
            ->that($result, 'result')->nullOr()->numeric()->between(0, 100)
            ->that($status, 'status')->nullOr()->string()->inArray($enrolmentStatuses)
            ->that($pass, 'pass')->nullOr()->numeric()->inArray([EnrolmentStatuses::PASSED, EnrolmentStatuses::FAILED])
            ->that($enrollmentType, 'enrollment_type')->nullOr()->string()->inArray(EnrolmentOriginalTypes::all())
            ->verifyNow();

        if ($enrolment->enrollment_type === EnrolmentOriginalTypes::ASSIGNED && $enrollmentType === EnrolmentOriginalTypes::SELF_DIRECTED) {
            throw new ErrorWithErrorCode(
                ErrorMessageCodes::ENROLLMENT_NOT_ALLOWED_TO_CHANGE_ENROLLMENT_TYPE_TO_SELF_DIRECTED,
                new BadRequestHttpException('Changing the enrollment from "assigned" to "self-directed" is not permitted.')
            );
        }
        list($dueDate, $assigner, $assignDate) = $this->validateAssignTypeFields($req, $enrolment, $actor, $instance, $enrollmentType);

        return [$status, $result, $pass, $startDate, $endDate, $dueDate, $assigner, $assignDate, $enrollmentType];
    }

    /**
     * @throws ErrorWithErrorCode
     * @throws GuzzleException
     */
    private function validateAssignTypeFields(Request $req, stdClass $enrolment, stdClass $actor, string $instance, ?string $enrollmentType): array
    {
        $assigner = null;
        $dueDate = false;
        $assignDate = null;
        $request = $req->request;
        if (
            !$enrolment->parent_enrolment_id &&
            $enrollmentType !== EnrolmentOriginalTypes::ASSIGNED &&
            $enrolment->enrollment_type === EnrolmentOriginalTypes::SELF_DIRECTED &&
            ($request->has('due_date') || $request->has('assign_date') || $request->has('assigner_account_id'))
        ) {
            throw new ErrorWithErrorCode(
                ErrorMessageCodes::ENROLLMENT_MISSING_ENROLLMENT_TYPE,
                new BadRequestHttpException('The enrollment type must be specified as "assigned" in order to update the due date, assign date, or assigner account ID.')
            );
        }
        if (!$enrolment->parent_enrolment_id && ($enrollmentType === EnrolmentOriginalTypes::ASSIGNED || $enrolment->enrollment_type === EnrolmentOriginalTypes::ASSIGNED)) {
            $dueDate = $req->request->get('due_date', false);
            $assignDate = $req->request->get('assign_date', null);
            $assignerAccountId = $req->request->get('assigner_account_id', null);
            $assertDueDate = (false === $dueDate) ? null : $dueDate;

            $assertDueDate   = UtilDateTime::replaceLastDigitZ($assertDueDate);
            $assertAssignDate   = UtilDateTime::replaceLastDigitZ($assignDate);

            Assert::lazy()
                ->that($assignerAccountId, 'assigner_account_id')->nullOr()->numeric()
                ->that($assertAssignDate, 'assign_date')->nullOr()->notEmpty()->date(DATE_ATOM)
                ->that($assertDueDate, 'due_date')->nullOr()->notEmpty()->date(DATE_ATOM)
                ->verifyNow();

            if ($assertDueDate && $assertAssignDate && $assertDueDate < $assertAssignDate) {
                throw new ErrorWithErrorCode(
                    ErrorMessageCodes::ENROLLMENT_ASSIGN_TIME_LATER_THAN_DUE_DATE_NOT_ALLOWED,
                    new BadRequestHttpException('Assign date/time should not be later than due date/time.')
                );
            }
            if ($assignerAccountId || ($enrolment->enrollment_type === EnrolmentOriginalTypes::SELF_DIRECTED && $enrollmentType === EnrolmentOriginalTypes::ASSIGNED)) {
                $assigner = $this->enrolmentCreateV3Validator->getAssigner($req, $actor, $assignerAccountId);
                if (!isset($enrolment->assigner_user_id) || $assigner->id !== $enrolment->assigner_user_id) {
                    $student = $this->userService->findAccountWithPortalAndUser($enrolment->taken_instance_id, $enrolment->user_id);
                    // validateAssignerPermission function expect the account_id
                    $student->account_id = $student->data[0]->_gc_user_account_id;
                    try {
                        $this->enrolmentCreateV3Validator->validateAssignerPermission($req, $instance, $actor, $student, $assigner);
                    } catch (AccessDeniedHttpException $e) {
                        throw new ErrorWithErrorCode(ErrorMessageCodes::ENROLLMENT_OPERATION_NOT_PERMITTED, $e);
                    }
                }
            }
        }

        return [$dueDate, $assigner, $assignDate];
    }

    /**
     * @throws ErrorWithErrorCode
     */
    private function validatePatchV3Request(int $enrolmentId, Request $req): array
    {
        if (!$actor = $this->accessChecker->validUser($req)) {
            throw new ErrorWithErrorCode(
                ErrorMessageCodes::ENROLLMENT_INVALID_JWT,
                new AccessDeniedHttpException('Invalid or missing JWT.')
            );
        }

        if (!$enrolment = $this->repository->load($enrolmentId, true)) {
            throw new ErrorWithErrorCode(
                ErrorMessageCodes::ENROLLMENT_ENROLLMENT_NOT_FOUND,
                new NotFoundHttpException('Enrollment not found.')
            );
        }

        if (!$portal = $this->portalService->loadBasicByLoId($enrolment->lo_id)) {
            throw new ErrorWithErrorCode(
                ErrorMessageCodes::ENROLLMENT_CONTENT_PROVIDER_PORTAL_NOT_FOUND,
                new NotFoundHttpException('Learning object provider portal not found.')
            );
        }

        if (!$takenInPortal = $this->portalService->loadBasicById($enrolment->taken_instance_id)) {
            throw new ErrorWithErrorCode(
                ErrorMessageCodes::ENROLLMENT_PORTAL_NOT_FOUND,
                new NotFoundHttpException('Portal not found.')
            );
        }

        if (!$actor->account = $this->accessChecker->validAccount($req, $takenInPortal->title)) {
            throw new ErrorWithErrorCode(
                ErrorMessageCodes::ENROLLMENT_INVALID_JWT,
                new AccessDeniedHttpException('Invalid or missing JWT.')
            );
        }

        if (!$learningObject = $this->repository->loService()->load($enrolment->lo_id)) {
            throw new ErrorWithErrorCode(
                ErrorMessageCodes::ENROLLMENT_LEARNING_OBJECT_NOT_FOUND,
                new NotFoundHttpException('Learning object(lo_id) not found.')
            );
        }

        return [$enrolment, $actor, $learningObject, $portal, $takenInPortal];
    }

    private function validateRequest(int $enrolmentId, Request $req)
    {
        if (!$enrolment = $this->repository->load($enrolmentId)) {
            return Error::jr404('Enrolment not found.');
        }

        if (!$user = $this->accessChecker->validUser($req)) {
            return Error::jr403('Invalid or missing JWT.');
        }

        if (!$learningObject = $this->repository->loService()->load($enrolment->lo_id)) {
            return Error::simpleErrorJsonResponse('Learning object does not exist.');
        }

        if (!$portal = $this->portalService->loadBasicByLoId($enrolment->lo_id)) {
            return Error::simpleErrorJsonResponse('Portal not found.');
        }

        if (!$takenInPortal = $this->portalService->loadBasicById($enrolment->taken_instance_id)) {
            return Error::simpleErrorJsonResponse('TakenIn Portal not found.');
        }

        return [$enrolment, $user, $learningObject, $portal, $takenInPortal];
    }

    private function doPut(stdClass $enrolment, $startDate, $endDate, $dueDate, $expectedCompletionDate, $status, $result, $pass, $note, $actor, $learningObject, $portal, $takenInPortal, bool $reCalculate, Request $req, ?int $assignerId = null, ?string $assignDate = null, bool $apiUpliftV3 = false): JsonResponse
    {
        $isRootUser = $this->accessChecker->isAccountsAdmin($req);

        $actorId = $actor->id;
        $authorIds = LoHelper::authorIds($this->db->get(), $learningObject->id);
        if ($error = $this->repository->validateStatusPermission($learningObject, $portal->title, $takenInPortal->title, $status, $authorIds, $req, $enrolment)) {
            return $error;
        }

        if ($error = $this->repository->validateDueDatePermission($dueDate, $enrolment, $learningObject, $req)) {
            return $error;
        }

        if (($actor->id != $enrolment->user_id) && ($portal->id != $takenInPortal->id)) {
            if (!$this->accessChecker->isContentAdministrator($req, $takenInPortal->title)) {
                if (!$req->attributes->get('isManager', false)) {
                    return Error::jr403('Only administer can edit enrolment of others.');
                }
            }
        }

        // Full logic is here https://go1web.atlassian.net/wiki/spaces/GP/pages/194412667/Allow+Assessors+Admin+to+edit+unlock+enrolments
        $isNewStatus = ($enrolment->status != $status);
        if ($isNewStatus) {
            if (EnrolmentStatuses::IN_PROGRESS == $status) {
                if ($isRootUser) {
                    $startDate = $startDate ?: ($enrolment->start_date ?? 'now');
                    $endDate = $endDate ?: ($enrolment->end_date ?? null);
                } else {
                    $startDate = $enrolment->start_date ?? ($startDate ?: 'now');
                    $endDate = $enrolment->end_date ?? ($endDate ?: null);
                }
            } elseif (EnrolmentStatuses::COMPLETED == $status) {
                if ($isRootUser) {
                    $startDate = $startDate ?: ($enrolment->start_date ?? 'now');
                } else {
                    $startDate = $enrolment->start_date ?? ($startDate ?: 'now');
                }
                $endDate = $endDate ?? 'now';
            }
        } else {
            if ($isRootUser) {
                $startDate = $startDate ?: $enrolment->start_date;
            } else {
                $startDate = $enrolment->start_date;
            }

            if (EnrolmentStatuses::COMPLETED == $status) {
                $endDate = $endDate ?? 'now';
            } else {
                $endDate = $enrolment->end_date;
            }
        }

        if (EnrolmentStatuses::NOT_STARTED == $status) {
            $startDate = null;
            $endDate = null;
        }

        $passRate = LoChecker::passRate($learningObject);
        if ($result && $passRate) {
            if ($enrolment->status == EnrolmentStatuses::IN_PROGRESS) {
                $status = EnrolmentStatuses::COMPLETED;
            } elseif ($enrolment->status == EnrolmentStatuses::COMPLETED) {
                $status = null;
            }

            $pass = ($result >= $passRate) ? EnrolmentStatuses::PASSED : EnrolmentStatuses::FAILED;
        }

        $data = array_filter(
            [
                'start_date'               => $startDate ? DateTimeHelper::atom($startDate, Constants::DATE_MYSQL) : null,
                'end_date'                 => $endDate ? DateTimeHelper::atom($endDate, Constants::DATE_MYSQL) : null,
                'due_date'                 => $dueDate ? DateTimeHelper::atom($dueDate, Constants::DATE_MYSQL) : $dueDate, // @TODO need verify this logic with @Nikk
                'expected_completion_date' => $expectedCompletionDate ? DateTimeHelper::atom($expectedCompletionDate, Constants::DATE_MYSQL) : $expectedCompletionDate,
                'status'                   => $status,
                'result'                   => $result,
                'pass'                     => $pass,
                'note'                     => $note,
                'actor_id'                 => $actorId,
                'assigner_id'              => $assignerId,
                'assign_date'              => $assignDate ? DateTimeHelper::atom($assignDate, Constants::DATE_MYSQL) : null,
                'changed'                  => (new DateTime())->format(Constants::DATE_MYSQL),
                'data'                     => json_encode($enrolment->data),
            ],
            function ($value, $key) {
                if (in_array($key, ['end_date', 'due_date', 'expected_completion_date'])) {
                    return true;
                }

                return $value !== null;
            },
            ARRAY_FILTER_USE_BOTH
        );

        $this->repository->update($enrolment->id, $data, true, $reCalculate, [], $apiUpliftV3);

        return new JsonResponse(null, 204);
    }
}
