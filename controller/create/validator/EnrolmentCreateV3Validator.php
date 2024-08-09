<?php

namespace go1\enrolment\controller\create\validator;

use go1\util\lo\LoTypes;
use stdClass;
use DateTime;
use Assert\Assert;
use go1\util\AccessChecker;
use go1\util\user\Roles;
use go1\util\lo\LoChecker;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\core\learning_record\plan\util\PlanReference;
use go1\core\util\DateTime as UtilDateTime;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\services\PortalService;
use go1\enrolment\services\UserService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnrolmentCreateV3Validator
{
    private AccessChecker $accessChecker;
    private UserDomainHelper $userDomainHelper;
    private EnrolmentCreateValidator $enrolmentCreateValidator;
    private UserService $userService;
    private LoChecker $loChecker;
    private PortalService $portalService;

    public function __construct(
        AccessChecker $accessChecker,
        UserDomainHelper $userDomainHelper,
        EnrolmentCreateValidator $enrolmentCreateValidator,
        UserService $userService,
        LoChecker $loChecker,
        PortalService $portalService
    ) {
        $this->accessChecker = $accessChecker;
        $this->userDomainHelper = $userDomainHelper;
        $this->enrolmentCreateValidator = $enrolmentCreateValidator;
        $this->userService = $userService;
        $this->loChecker = $loChecker;
        $this->portalService = $portalService;
    }

    public function validateParameters(Request $req): array
    {
        if (!$actor = $this->accessChecker->validUser($req)) {
            throw new AccessDeniedHttpException('Permission denied. Missing or invalid jwt.');
        }

        $reEnrol = $req->attributes->get('reEnrol');
        $enrolType = $req->request->get('enrollment_type');
        $student = $req->attributes->get('studentUserV3');
        $loId = $req->request->get('lo_id');
        $parentEnrolment = $req->attributes->get('parentEnrolment');
        $assignerAccountId = $req->request->get('assigner_account_id');
        $status = $req->request->get('status');
        $startDate = $req->request->get('start_date');
        $endDate = $req->request->get('end_date');
        $dueDate = $req->request->get('due_date');
        $assignDate = $req->request->get('assign_date');
        $result = $req->request->get('result');
        $pass = $req->request->get('pass');
        $transaction = $req->attributes->get('transaction');

        $claim = Assert::lazy();
        if (!$parentEnrolment) {
            $claim->that($enrolType, 'enrollment_type')->string()->inArray(EnrolmentOriginalTypes::all());
        }

        $currentDate = (new DateTime())->format(DATE_ATOM);
        $assertStartDate = UtilDateTime::replaceLastDigitZ($startDate);
        $assertendDate = UtilDateTime::replaceLastDigitZ($endDate);
        $assertDueDate = UtilDateTime::replaceLastDigitZ($dueDate);

        $claim->that($reEnrol, 're-enroll')->boolean()
            ->that($loId, 'lo_id')->numeric()
            ->that($status, 'status')->string()->inArray(EnrolmentStatuses::all())
            ->that($assertDueDate, 'due_date')->nullOr()->date(DATE_ATOM)
            ->verifyNow();

        $claim = Assert::lazy();
        if ($status == 'completed') {
            $claim->that($result, 'result')->integer()
                ->that($pass, 'pass')->boolean()
                ->that($assertStartDate, 'start_date')->nullOr()->date(DATE_ATOM)
                ->that($assertendDate, 'end_date')->nullOr()->date(DATE_ATOM);
        } elseif ($status == 'in-progress') {
            $claim->that($result, 'result')->null()
                ->that($pass, 'pass')->null()
                ->that($assertStartDate, 'start_date')->nullOr()->date(DATE_ATOM)
                ->that($endDate, 'end_date')->null();
        } else {
            $claim->that($result, 'result')->null()
                ->that($pass, 'pass')->null()
                ->that($startDate, 'start_date')->null()
                ->that($endDate, 'end_date')->null();
        }

        $enrolTypeInt = null;
        if (!$parentEnrolment) {
            $enrolTypeInt = EnrolmentOriginalTypes::toNumeric($enrolType);
            if ($enrolTypeInt == EnrolmentOriginalTypes::I_SELF_DIRECTED) {
                $claim->that($assignerAccountId, 'assigner_account_id')->null()
                    ->that($assignDate, 'assign_date')->null();
            } else {
                $assignDate = $assignDate ?? $currentDate;
                $assertAssignDate = UtilDateTime::replaceLastDigitZ($assignDate);
                $claim->that($assignerAccountId, 'assigner_account_id')->nullOr()->numeric()
                    ->that($assertAssignDate, 'assign_date')->date(DATE_ATOM);
            }
        }
        $claim->verifyNow();

        list($startDate, $endDate) = $this->validateDate(
            $assignDate,
            $dueDate,
            $startDate,
            $endDate,
            $currentDate,
            $status
        );

        if (!$student) {
            throw new NotFoundHttpException('User account not found.');
        }

        if (!$lo = $this->enrolmentCreateValidator->learningObject($loId, $req)) {
            throw new NotFoundHttpException('Learning object not found.');
        }

        if ($reEnrol && !$this->loChecker->allowReEnrol($lo)) {
            throw new NotAcceptableHttpException('Re-enrolling is disabled.');
        }

        if (!$portal = $this->portalService->loadBasicById($student->portal_id)) {
            throw new NotFoundHttpException('Portal not found.');
        }

        if (!$actor->account = $this->accessChecker->validAccount($req, $portal->title)) {
            throw new AccessDeniedHttpException('Permission denied. Missing or invalid jwt.');
        }

        $planRef = null;
        if ($enrolTypeInt === EnrolmentOriginalTypes::I_ASSIGNED) {
            $planRef = PlanReference::create($req);
        }

        $assigner = $this->getAssigner($req, $actor, $assignerAccountId);
        if ($enrolTypeInt === EnrolmentOriginalTypes::I_ASSIGNED) {
            $this->validateAssignerPermission(
                $req,
                $portal->title,
                $actor,
                $student,
                $assigner
            );
        }

        $this->enrolmentCreateValidator
            ->validateCreatePermission($lo, $status, $student->portal_id);

        if ($lo->type === LoTypes::GROUP || $lo->type === LoTypes::AWARD) {
            throw new BadRequestHttpException('Award and Group enrolment is not supported.');
        }

        // Make sure we can claim license, or block the request if payment required
        // For now we will block any status, but we will need to change this later
        $error = $this->enrolmentCreateValidator->validateLo(
            $req,
            $lo,
            $portal,
            $portal,
            $student,
            $loId,
            $actor->account
        );

        if ($error) {
            $msg = json_decode($error->getContent())->message;
            throw new AccessDeniedHttpException($msg);
        }

        return [
            $reEnrol,
            $enrolTypeInt,
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
        ];
    }

    /**
     * @throws BadRequestHttpException
     */
    public function validateDate(
        $assignDate,
        $dueDate,
        $startDate,
        $endDate,
        $currentDate,
        $status
    ): array {
        // making all dates in the same format as it needs to be same format for comparing
        $startDate = $startDate ? UtilDateTime::atom($startDate) : $startDate;
        $assignDate = $assignDate ? UtilDateTime::atom($assignDate) : $assignDate;
        $dueDate = $dueDate ? UtilDateTime::atom($dueDate) : $dueDate;
        $endDate = $endDate ? UtilDateTime::atom($endDate) : $endDate;

        if ($assignDate && $assignDate > $currentDate) {
            throw new BadRequestHttpException('Assign date/time should not be later than current date/time.');
        } elseif ($dueDate && $assignDate && $assignDate > $dueDate) {
            throw new BadRequestHttpException('Assign date/time should not be later than due date/time.');
        } elseif ($status == 'completed') {
            $startDate = $startDate ?? $currentDate;
            $endDate = $endDate ?? $currentDate;
            if ($startDate > $endDate) {
                throw new BadRequestHttpException('Start date/time should not be later than end date/time.');
            } elseif ($assignDate && $startDate < $assignDate) {
                throw new BadRequestHttpException('Assign date/time should not be later than start date/time.');
            }
        } elseif ($status == 'in-progress') {
            $startDate = $startDate ?? $currentDate;
            if ($assignDate && $startDate < $assignDate) {
                throw new BadRequestHttpException('Assign date/time should not be later than start date/time.');
            }
        }
        return [$startDate, $endDate];
    }

    public function getAssigner($req, stdClass $actor, ?int $assignerAccountId): stdClass
    {
        $assigner = new stdClass();
        $assigner->accountId = $assignerAccountId;
        if ($assigner->accountId) {
            if ($assigner->accountId == $actor->account->id) {
                $assigner->id = $actor->id;
                $assigner->roles = $actor->account->roles;
            } else {
                $account = $this->userService->get(
                    $assigner->accountId,
                    'accounts',
                    $this->accessChecker->jwt($req)
                );
                $assigner->id = $account->user->_gc_user_id;
                $assigner->roles = array_map(fn ($v) => $v->role, $account->roles);
            }
        } else {
            $assigner->id = $actor->id;
        }

        return $assigner;
    }

    public function validateAssignerPermission(
        $req,
        string $instance,
        stdClass $actor,
        stdClass $student,
        stdClass $assigner
    ): void {
        $isAdmin = (
            $assigner->accountId
            && array_intersect(
                [Roles::ROOT, Roles::ADMIN, Roles::ADMIN_CONTENT],
                $assigner->roles
            )
        ) || (
            !$assigner->accountId
                && $this->accessChecker->isContentAdministrator($req, $instance)
        );
        if ($isAdmin) {
            return;
        }

        $isManager = (
            $assigner->accountId
            && $this->userDomainHelper->isManager(
                $instance,
                $assigner->accountId,
                $student->account_id
            )
        ) || (
            !$assigner->accountId
            && $this->userDomainHelper->isManager(
                $instance,
                $actor->account->id,
                $student->account_id
            )
        );
        if ($isManager) {
            return;
        }

        throw new AccessDeniedHttpException(
            'Permission denied. Only manager or admin could assign enrollments.'
        );
    }
}
