<?php

namespace go1\enrolment\controller\create\validator;

use stdClass;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentCreateOption;
use go1\enrolment\services\ContentSubscriptionService;
use go1\util\DateTime;
use go1\util\AccessChecker;
use go1\util\Error;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LoChecker;
use go1\util\lo\SubscriptionAccessTypes;
use go1\util\model\Enrolment;
use go1\clients\PortalClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EnrolmentCreateValidator
{
    private ConnectionWrapper                 $db;
    private ContentSubscriptionService $contentSubscriptionService;
    private AccessChecker              $accessChecker;
    private LoChecker                  $loChecker;
    private PortalClient               $portalClient;

    public function __construct(
        ConnectionWrapper $db,
        AccessChecker $accessChecker,
        LoChecker $loChecker,
        ContentSubscriptionService   $contentSubscriptionService,
        PortalClient                 $portalClient
    ) {
        $this->db = $db;
        $this->accessChecker = $accessChecker;
        $this->loChecker = $loChecker;
        $this->contentSubscriptionService = $contentSubscriptionService;
        $this->portalClient = $portalClient;
    }

    public function validateCreatePermission($learningObject, $status, $instance)
    {
        if (in_array($status, [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED])) {
            if ($learningObject->instance_id != $instance) {
                throw new AccessDeniedHttpException(
                    'Permission denied. Cannot create in-progress or completed enrollments on content that you do not own.'
                );
            }
        }
        return true;
    }

    public function validateLo(Request $req, stdClass $learningObject, stdClass $loPortal, stdClass $portal, stdClass $student, int $loId, stdClass $user)
    {
        if (!$this->isLoAuthor($req, $loPortal->title, $loId, $user->id)) {
            if ($error = $this->studentRequiresSubscription($portal, $student, $learningObject)) {
                return $error;
            } elseif (!empty($learningObject->pricing->price) && empty($learningObject->enrolment)) {
                if (!$req->attributes->get('transaction')) {
                    if (!$req->attributes->has("access-policy:{$learningObject->id}")) {
                        if (!$this->contentSubscriptionService->hasSubscription($learningObject, $student->id, $portal->id)) {
                            return Error::jr403('Require transaction to create enrolment.');
                        }
                    }
                }
            }
        }
        return null;
    }

    public function isLoAuthor(Request $req, string $instanceName, int $loId, int $userId): bool
    {
        return $this->accessChecker->isPortalManager($req, $instanceName) || $this->loChecker->isAuthor($this->db->get(), $loId, $userId);
    }

    private function studentRequiresSubscription(stdClass $portal, stdClass $student, stdClass $learningObject): ?JsonResponse
    {
        $conf = (
            $this->portalClient->configuration($portal->title, 'GO1', 'individual_licensing', 0) ||
            $this->portalClient->configuration($portal->title, 'GO1', 'portal_licensing', 0)
        );

        // IF the portal does not turn on `individual_licensing` or `portal_licensing` then always return null
        if (!$conf) {
            return null;
        }

        // Otherwise will check the subscription licensed
        if (!$this->contentSubscriptionService->hasSubscription($learningObject, $student->id, $portal->id)) {
            if ($this->contentSubscriptionService->getSubscriptionStatus($student->id, $portal->id, $learningObject->id) !== SubscriptionAccessTypes::LICENSED) {
                return Error::jr403('Failed to claim a license for the subscription.');
            }
        }

        return null;
    }

    /**
     * @param DateTime | bool $dueDate
     * @param DateTime | bool $startDate
     * @param DateTime | bool $endDate
     * @param DateTime | bool $assignDate
     * @param stdClass|Enrolment|null $parentEnrolment
     */
    public function setEnrolmentOption(
        stdClass $student,
        stdClass $learningObject,
        ?int $parentLoId,
        string $status,
        int $portalId,
        bool $reEnrol,
        int $loId,
        $dueDate,
        $startDate,
        $endDate,
        $assignDate,
        int $actorUserId,
        int $pass,
        bool $reCalculate,
        array $data,
        ?stdClass $transaction,
        $parentEnrolment,
        ?array $enrolmentAttributes,
        bool $notify,
        ?stdClass $assigner,
        ?int $enrolType,
        ?int $result
    ): EnrolmentCreateOption {
        $option = EnrolmentCreateOption::create();
        $option->profileId = $student->profile_id;
        $option->userId = $student->id;
        $option->learningObject = $learningObject;
        $option->parentLearningObjectId = $parentLoId;
        $option->parentEnrolmentId = $parentEnrolment->id ?? 0;
        $option->status = $status;
        $option->portalId = $portalId;
        $option->reEnrol = $reEnrol;
        $option->transaction = $transaction;
        $option->assigner = $assigner;
        $option->dueDate = $dueDate;
        $option->startDate = $startDate;
        $option->endDate = $endDate;
        $option->assignDate = $assignDate;
        $option->actorUserId = $actorUserId;
        $option->pass = $pass;
        $option->reCalculate = $reCalculate;
        $option->data = $data;
        $option->notify = $notify;
        $option->enrolmentType = $enrolType;
        $option->result = $result;

        if (!empty($enrolmentAttributes[$loId])) {
            $option->status = EnrolmentStatuses::COMPLETED;
            $option->pass = 1;

            if ($enrolmentAttributes[$loId]['date']) {
                $option->startDate = $option->endDate =
                    DateTime::create($enrolmentAttributes[$loId]['date'])->format(DATE_ISO8601);
            }

            unset($enrolmentAttributes[$loId]['date']);
            $option->attributes = $enrolmentAttributes[$loId];
        }

        return $option;
    }

    public function learningObject(int $loId, Request $req): ?stdClass
    {
        foreach ($req->attributes->get('learningObjects', []) as $_) {
            if ($loId == $_->id) {
                return $_;
            }
        }

        return null;
    }
}
