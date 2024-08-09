<?php

namespace go1\enrolment\content_learning;

use go1\enrolment\exceptions\ErrorWithErrorCode;
use go1\util\Error;
use Symfony\Component\HttpFoundation\JsonResponse;

class ErrorMessageCodes
{
    public const ENROLLMENT_VALIDATION_ERRORS = 'enrollment_validation_errors';
    public const ENROLLMENT_USER_ACCOUNT_NOT_FOUND = 'enrollment_user_account_not_found';
    public const ENROLLMENT_EXISTS = 'enrollment_exists';
    public const ENROLLMENT_SERVER_ERROR = 'enrollment_server_error';
    public const ENROLLMENT_RESOURCE_NOT_FOUND = 'enrollment_resource_not_found';
    public const ENROLLMENT_RE_ENROL_DISABLED = 'enrollment_re_enrolling_disabled';
    public const ENROLLMENT_OPERATION_NOT_PERMITTED = 'enrollment_operation_not_permitted';
    public const ENROLLMENT_ENROLLMENT_NOT_FOUND = 'enrollment_enrollment_not_found';
    public const ENROLLMENT_INVALID_JWT = 'enrollment_invalid_jwt';
    public const ENROLLMENT_LEARNING_OBJECT_NOT_FOUND = 'enrollment_learning_object_not_found';
    public const ENROLLMENT_PORTAL_NOT_FOUND = 'enrollment_portal_not_found';
    public const ENROLLMENT_CONTENT_PROVIDER_PORTAL_NOT_FOUND = 'enrollment_learning_object_provider_portal_not_found';
    public const ENROLLMENT_NOT_ALLOWED_TO_CHANGE_STATUS = 'enrollment_not_allowed_to_change_status';
    public const ENROLLMENT_NOT_ALLOWED_TO_CHANGE_STATUS_TO_PREVIOUS_STATUS = 'enrollment_not_allowed_to_change_status_to_previous_status';
    public const ENROLLMENT_NOT_ALLOWED_TO_CHANGE_ENROLLMENT_TYPE_TO_SELF_DIRECTED = 'enrollment_not_allowed_to_change_enrollment_type_to_self_directed';
    public const ENROLLMENT_ASSIGN_TIME_LATER_THAN_DUE_DATE_NOT_ALLOWED = 'enrollment_assign_time_later_than_due_date_not_allowed';
    public const ENROLLMENT_INVALID_DATE = 'enrollment_invalid_date';
    public const ENROLLMENT_MISSING_ENROLLMENT_TYPE = 'enrollment_missing_enrollment_type';
    public const ENROLLMENT_LO_ACCESS_DENIED = 'enrollment_lo_access_denied';
    public const ENROLLMENT_UN_ENROLLED_USER_NOT_FOUND = 'enrollment_un_enrolled_user_not_found';

    public static function createError(ErrorWithErrorCode $e): JsonResponse
    {
        $originalError = $e->getHttpException();
        $errorData = [
            'message' => $originalError->getMessage(),
            'error_code' => $e->getErrorCode()
        ];
        return Error::createMultipleErrorsJsonResponse($errorData, null, $originalError->getStatusCode());
    }
}
