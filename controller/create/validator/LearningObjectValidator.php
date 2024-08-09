<?php

namespace go1\enrolment\controller\create\validator;

use Assert\Assert;
use Assert\LazyAssertionException;
use Doctrine\DBAL\Connection;
use Exception;
use go1\clients\LoClient;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\controller\create\LoAccessClient;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\lo\LiTypes;
use go1\util\lo\LoChecker;
use go1\util\lo\LoHelper;
use go1\util\lo\LoStatuses;
use go1\util\lo\LoTypes;
use go1\util\policy\Realm;
use go1\util\portal\PortalChecker;
use go1\util\Text;
use go1\util\user\UserHelper;
use PDO;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

class LearningObjectValidator
{
    private ConnectionWrapper $db;
    private AccessChecker $accessChecker;
    private LoChecker $loChecker;
    private LoClient $loClient;
    private LoAccessClient $loAccessClient;
    private PortalChecker $portalChecker;
    private ContentSubscriptionService $contentSubscriptionService;
    private EnrolmentRepository $repository;
    private UserDomainHelper $userDomainHelper;
    private PortalService $portalService;
    private LoggerInterface $logger;

    public function __construct(
        ConnectionWrapper                 $db,
        AccessChecker              $accessChecker,
        PortalChecker              $portalChecker,
        LoChecker                  $loChecker,
        LoClient                   $loClient,
        LoAccessClient             $loAccessClient,
        ContentSubscriptionService $contentSubscriptionService,
        EnrolmentRepository        $repository,
        UserDomainHelper           $userDomainHelper,
        PortalService              $portalService,
        LoggerInterface            $logger
    ) {
        $this->db = $db;
        $this->accessChecker = $accessChecker;
        $this->portalChecker = $portalChecker;
        $this->loChecker = $loChecker;
        $this->loClient = $loClient;
        $this->loAccessClient = $loAccessClient;
        $this->contentSubscriptionService = $contentSubscriptionService;
        $this->repository = $repository;
        $this->userDomainHelper = $userDomainHelper;
        $this->portalService = $portalService;
        $this->logger = $logger;
    }

    public function validate(
        $user,
        stdClass &$learningObject,
        array &$learningObjectIds,
        array &$parentEnrolmentIds,
        array &$enrolmentStatuses,
        array &$dueDates,
        array &$startDates,
        array &$errors,
        array $parentLoIds,
        array $authorIds,
        array $allAuthorIds,
        $takenInInstance,
        Request $req,
        ?int $studentAccountId = null
    ): void {
        if ($studentUser = $req->attributes->get('studentUser')) {
            $studentAccount = $this->userDomainHelper->loadUser($studentUser->id, $takenInInstance->title)->account ?? null;
        } else {
            $studentAccount = $this->accessChecker->validUser($req, $takenInInstance->title);
        }

        if (LoTypes::COURSE === $learningObject->type) {
            if (empty($learningObject->marketplace)) {
                if (!$studentAccount) {
                    $errors[$learningObject->id][403][] = 'User must have account in enrolling portal.';

                    return;
                }
            }
        }

        $this->validateParentEnrolment($learningObject, $learningObjectIds, $parentEnrolmentIds, $takenInInstance, $user, $errors, $parentLoIds, $req);

        // If we are using a derivative of the ROOT_JWT (like from EXIM), it will trigger 'invalid JWT' on LO-ACCESS service
        // however if we use ROOT_JWT all the time then it will grant access to LO's that user shouldn't have access to.
        // We need a better long term solution to this
        $jwt = $req->attributes->get('jwt.raw');
        $payload = Text::jwtContent($jwt);
        if (isset($payload->object) && isset($payload->object->content) && $payload->object->content->id === 1) {
            $jwt = UserHelper::ROOT_JWT;
        }
        $bearerToken = "Bearer $jwt";

        $this->loAccessClient->setAuthorization($bearerToken);
        $learningObject->realm = $learningObject->realm ?? $this->loAccessClient->realm($learningObject->id, $user->id, $takenInInstance->id);
        $hasAccessPolicy = Realm::ACCESS == $learningObject->realm;

        if ($hasAccessPolicy) {
            $req->attributes->set("access-policy:{$learningObject->id}", true);
        }

        // enrolment creation is not allowed when learning object is not published
        if ($learningObject->published != LoStatuses::PUBLISHED) {
            $errors[$learningObject->id][403][] = 'Learning Object is not published.';
        }

        if (empty($learningObject->realm)) {
            if (!$this->contentSubscriptionService->hasSubscription($learningObject, $user->id, $takenInInstance->id)) {
                $errors[$learningObject->id][403][] = 'Learning Object is private.';
            }
        }

        if (!$portal = $this->portalService->loadByLoId($learningObject->id)) {
            $errors[$learningObject->id][404][] = 'Portal not found.';
            return;
        }

        // Return true if current user is portal admin or lo author
        $isLoManager = $this->isLoManager($learningObject, $allAuthorIds, $takenInInstance->title, $user, $req, $studentAccountId);

        /**
         * Checking lo.data.allow_enrolment
         * Only 'allow_enrolment = allow' value is valid
         * Will be bypassed with portal admin or lo author or premium student
         */
        if (
            !$isLoManager
            && !$hasAccessPolicy
            && ($learningObject->type == LoTypes::COURSE)
            && ($this->loChecker->allowEnrolment($learningObject) != LoHelper::ENROLMENT_ALLOW_DEFAULT)
        ) {
            $errors[$learningObject->id][406][] = 'Learning is not enrollable.';
        }

        # Student must have account on portal of current learning object IF it's a legacy LO
        if ($this->portalChecker->isLegacy($portal)) {
            if ($error = $this->validateHasAccount($user, $learningObject, $portal)) {
                $errors[$learningObject->id][$error->getStatusCode()][] = $error->getContent();
            }
        }

        $enrolmentStatuses[$learningObject->id] = $this->enrolmentStatus($learningObject->id, $req);
        $dueDates[$learningObject->id] = $this->dueDate($learningObject->id, $req);
        $startDates[$learningObject->id] = $this->startDate($learningObject->id, $req);
        $validInstance = (bool) $takenInInstance;
        if ($error = $this->validateParams($user, $learningObject->id, $validInstance, $enrolmentStatuses[$learningObject->id], $dueDates[$learningObject->id], $startDates[$learningObject->id])) {
            $errors[$learningObject->id][$error->getStatusCode()][] = $error->getContent();
        }

        # Check event conditions
        # Will be bypassed with portal admin or lo author
        if (($learningObject->type == LoTypes::COURSE) && !empty($learningObject->event) && !$isLoManager) {
            // Check available event dates
            if (!empty($learningObject->event->end) && $end = $learningObject->event->end) {
                $end = is_numeric($end) ? $end : strtotime($end);
                if ($end && (time() > $end)) {
                    $errors[$learningObject->id][406][] = 'Enrolment was expired.';
                }
            }
        }

        # Check available seats.
        if (!$isLoManager && LiTypes::EVENT === $learningObject->type) {
            $eventId = 'SELECT target_id FROM gc_ro WHERE type = ? AND source_id = ?';
            $eventId = $this->db->get()->fetchColumn($eventId, [EdgeTypes::HAS_EVENT_EDGE, $learningObject->id]);
            if (!$eventId) {
                # TODO: Should we raise this error?
                # $errors[$learningObject->id][404][] = 'Event not found.';
            } else {
                $availableSeats = $this->loClient->eventAvailableSeat($eventId);
                if (false === $availableSeats) {
                    $errors[$learningObject->id][500][] = 'Failed to get available seats.';
                } elseif (-1 != $availableSeats) {
                    if (1 > $availableSeats) {
                        $errors[$learningObject->id][406][] = 'No more available seat.';
                    }
                }
            }
        }

        if ($error = $this->repository->validateStatusPermission($learningObject, $portal->title, $takenInInstance->title, $enrolmentStatuses[$learningObject->id], $authorIds, $req)) {
            $res = json_decode($error->getContent(), true);
            $errors[$learningObject->id][$error->getStatusCode()][] = $res['message'];
        }

        if ((int) $learningObject->origin_id > 0) {
            $original = LoHelper::load($this->db->get(), $learningObject->origin_id);
            if ($original && ($original->instance_id == $takenInInstance->id)) {
                $errors[$learningObject->id][400][] = 'Cannot enrol into shared course on origin portal.';
            }
        }
    }

    private function validateParentEnrolment(
        stdClass &$learningObject,
        $learningObjectIds,
        $parentEnrolmentIds,
        stdClass $takenInInstance,
        stdClass $user,
        array    &$errors,
        array    $parentLoIds,
        Request  $req
    ): void {
        if (!in_array($learningObject->type, array_merge([LoTypes::MODULE], LiTypes::all()))) {
            return;
        }

        try {
            $parentEnrolmentId = $req->get('parentEnrolmentId');

            # If `parentEnrolmentId` is not provided then we try to finding parent learning object base on learning object structure.
            # However we can not determine right parent LO if a stand alone learning item is being re-used in multiple learning object.
            if (empty($parentEnrolmentId) && LoHelper::isSingleLi($learningObject)) {
                return;
            }

            $parentLoId = $this->parentLearningObjectId($learningObject, $req, $user, $parentLoIds);
            if (!$parentLoId || in_array($parentLoId, $learningObjectIds)) {
                return;
            }

            $isSlimEnrolment = $req->attributes->get('isSlimEnrolment');

            if ($takenInInstance && !$this->portalChecker->isLegacy($takenInInstance)) {
                // If parent enrolment Id is not provided but the parent LO Id is provided then figure out the parentEnrolmentId
                if (!$parentEnrolmentId && $isSlimEnrolment) {
                    if ($foundParentEnrolment = EnrolmentHelper::findEnrolment($this->db->get(), $takenInInstance->id, $user->id, $parentLoId)) {
                        $parentEnrolmentId = $foundParentEnrolment->id;
                    }
                    $req->attributes->set('parentEnrolmentId', $parentEnrolmentId);
                }

                $parentEnrolment = is_numeric($parentEnrolmentId) ? $this->repository->load($parentEnrolmentId) : false;
                $valid = $parentEnrolment
                    && ($user->id == $parentEnrolment->user_id)
                    && ($takenInInstance->id == $parentEnrolment->taken_instance_id)
                    && ($parentLoId == $parentEnrolment->lo_id);

                $req->attributes->set('parentEnrolment', $parentEnrolment);
                $valid && $parentEnrolmentIds[$learningObject->id] = $parentEnrolment->id;
            } else {
                // Check validity of enrolment on parent learning object if current learning object is a module or a li
                // Use parentLoId/parentEnrolmentId first if exist, otherwise use relationship from gc_ro
                if (!$parentEnrolment = (is_numeric($parentEnrolmentId) ? $this->repository->load($parentEnrolmentId) : false)) {
                    $parentEnrolment = EnrolmentHelper::findEnrolment($this->db->get(), $takenInInstance->id, $user->id, $parentLoId);
                }

                $valid = $parentEnrolment && in_array($parentEnrolment->status, [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED]);

                if ($parentEnrolment) {
                    $req->attributes->set('parentEnrolment', $parentEnrolment);
                }
            }

            if ($valid) {
                $parentLearningObjectIds[$learningObject->id] = $parentLoId;
                $learningObject->realm = Realm::ACCESS;
            } else {
                if ($isSlimEnrolment) {
                    $errors[$learningObject->id][400][] = 'Invalid request. To create an enrollment using a child learning object ID, the user must also be enrolled in the parent learning object.';
                } else {
                    $errors[$learningObject->id][406][] = 'You must first enroll into parent learning object.';
                }
            }
        } catch (Exception $e) {
            $errors[$learningObject->id][406][] = $e->getMessage();
        }
    }

    private function isLoManager(stdClass $learningObject, array $parentAuthorIds, string $portalName, stdClass $user, Request $req, ?int $studentAccountId = null): bool
    {
        if ($this->accessChecker->isPortalManager($req, $portalName)) {
            return true;
        }

        $actorPortalAccount = $this->accessChecker->validAccount($req, $portalName);
        if ($actorPortalAccount && ($studentAccountId !== null)) {
            if ($this->userDomainHelper->isManager($portalName, $actorPortalAccount->id, $studentAccountId)) {
                return true;
            }
        }

        if ($this->loChecker->isAuthor($this->db->get(), $learningObject->id, $user->id)) {
            return true;
        }

        if (in_array($user->id, $parentAuthorIds)) {
            return true;
        }

        # TODO: Check assessor

        return false;
    }

    private function parentLearningObjectId(stdClass &$learningObject, Request $req, stdClass $user, array $parentLoIds): ?int
    {
        if ($req->attributes->has('parentLoId')) {
            $parentId = $req->attributes->get('parentLoId');
            if (!$parentId && LoHelper::isSingleLi($learningObject)) {
                return $parentId;
            }

            $parentIdIsValid = $this->db->get()->executeQuery(
                'SELECT 1 FROM gc_ro WHERE type IN (?) AND source_id = ? AND target_id = ?',
                [EdgeTypes::LO_HAS_LO, $learningObject->id, $parentId],
                [Connection::PARAM_INT_ARRAY, PDO::PARAM_INT]
            );

            if (!$parentIdIsValid) {
                throw new RuntimeException('Invalid parent learning object id.');
            }

            $parentIds = [$parentId];
        } else {
            $parentIds = $parentLoIds;
        }

        switch (count($parentIds)) {
            case 1:
                $parentId = $parentIds[0];
                if (!$parentLearningObject = LoHelper::load($this->db->get(), $parentId)) {
                    throw new RuntimeException('Invalid parent learning object.');
                }

                if ($this->loChecker->requiredSequence($parentLearningObject)) {
                    if (!EnrolmentHelper::sequenceEnrolmentCompleted($this->db->get(), $learningObject->id, $parentLearningObject->id, $parentLearningObject->type, $user->id)) {
                        throw new RuntimeException('Invalid enrolment - sequence order.');
                    }
                }

                return $parentId;

            case 0:
                if (in_array($learningObject->type, ['learning_pathway', 'course'])) {
                    break; # That's ok if enrolment on LP & course does not have a parent.
                } elseif (in_array($learningObject->type, LiTypes::all())) {
                    if ($learningObject->marketplace) {
                        break; # User can enrol to marketplace place LI without parent.
                    } elseif ($this->loChecker->singleLi($learningObject)) {
                        break; # User can enrol to single LI without parent.
                        # @TODO this is temporary solution by Chau Pham, need to be review carefully later
                    }
                } else {
                    # No break, we need raise exception in other cases.
                }

                // no break
            default:
                if ('course' !== $learningObject->type) {
                    throw new RuntimeException('Failed to detect parent learning object.');
                }
        }
        return null;
    }

    private function validateParams($user, $loId, $validInstance, $enrolmentStatus, $dueDate, $startDate): ?JsonResponse
    {
        try {
            $assertDueDate = (false === $dueDate) ? null : $dueDate;
            $assertStartDate = (false === $startDate) ? null : $startDate;

            Assert::lazy()
                ->that($user->id, 'jwt.payload.user_id', 'Missing or invalid jwt.payload.user_id')->numeric()->min(0)
                ->that($user->mail, 'jwt.payload.email', 'Missing or invalid jwt.payload.email')->email()
                ->that($loId, 'loId')->numeric()->min(1)
                ->that($validInstance, 'instance')->nullOr()->true()
                ->that($enrolmentStatus, 'status')->inArray(EnrolmentStatuses::all())
                ->that($assertDueDate, 'dueDate')->nullOr()->date(DATE_ISO8601)
                ->that($assertStartDate, 'startDate')->nullOr()->date(DATE_ISO8601)
                ->verifyNow();
            return null;
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }
    }

    private function enrolmentStatus($learningObjectId, Request $req): ?string
    {
        if ($enrolmentStatus = $req->get('status')) {
            return $enrolmentStatus;
        }

        foreach ($req->get('items', []) as $i => $item) {
            $item = (object) $item;
            if ($learningObjectId == $item->loId) {
                return $item->status;
            }
        }

        return null;
    }

    private function dueDate($learningObjectId, Request $req)
    {
        $dueDate = $req->get('dueDate', false);

        if (false !== $dueDate) {
            return $dueDate;
        }

        foreach ($req->get('items', []) as $i => $item) {
            $item = (object) $item;
            if ($learningObjectId == $item->loId) {
                return property_exists($item, 'dueDate') ? $item->dueDate : false;
            }
        }

        return false;
    }

    /**
     * Check access permission:
     * - If the course is in marketplace -> OK
     * - If the course is not in marketplace -> Use must have account in the portal
     *
     * @return null|Response
     */
    private function validateHasAccount(stdClass $user, stdClass $learningObject, stdClass $portal)
    {
        if (!empty($learningObject->marketplace)) {
            return null; # Don't need checking if the course is in marketplace.
        }

        if ($learningObject->type == 'course') {
            if (!empty($user->accounts)) {
                foreach ($user->accounts as &$account) {
                    $actual = is_numeric($account->instance) ? (int) $portal->id : $portal->title;
                    if ($account->instance == $actual) {
                        $hasAccount = true;
                    }
                }
            }

            if (empty($hasAccount)) {
                return new Response('User must have account in portal before enrolling to its learning object.', 403);
            }
        }

        return null;
    }

    private function startDate($learningObjectId, Request $req)
    {
        $dueDate = $req->get('startDate', false);

        if (false !== $dueDate) {
            return $dueDate;
        }

        foreach ($req->get('items', []) as $i => $item) {
            $item = (object) $item;
            if ($learningObjectId == $item->loId) {
                return property_exists($item, 'startDate') ? $item->startDate : false;
            }
        }

        return false;
    }
}
