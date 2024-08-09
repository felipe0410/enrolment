<?php

namespace go1\enrolment\controller;

use Assert\Assert;
use Assert\LazyAssertionException;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\EnrolmentRevisionRepository;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\Error;
use go1\util\lo\LoHelper;
use go1\util\lo\LoTypes;
use go1\util\model\Enrolment;
use go1\util\portal\PortalChecker;
use go1\util\user\UserHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentLoadController
{
    protected ConnectionWrapper $go1ReadDbWrapper;
    protected ConnectionWrapper $go1WriteDbWrapper;
    protected EnrolmentRepository $repository;
    protected EnrolmentRevisionRepository $rRevision;
    protected AccessChecker $accessChecker;
    protected PortalChecker $portalChecker;
    protected string $accountsName;
    protected Client $client;
    protected string $achievementExploreUrl;
    protected UserDomainHelper $userDomainHelper;
    protected PortalService $portalService;

    public function __construct(
        ConnectionWrapper $dbReadWrapper,
        ConnectionWrapper $dbWriterWrapper,
        EnrolmentRepository $repository,
        EnrolmentRevisionRepository $rRevision,
        AccessChecker $accessChecker,
        PortalChecker $portalChecker,
        string $accountsName,
        Client $client,
        UserDomainHelper $userDomainHelper,
        string $achievementExploreUrl,
        PortalService $portalService
    ) {
        $this->go1ReadDbWrapper = $dbReadWrapper;
        $this->go1WriteDbWrapper = $dbWriterWrapper;
        $this->repository = $repository;
        $this->rRevision = $rRevision;
        $this->accessChecker = $accessChecker;
        $this->portalChecker = $portalChecker;
        $this->accountsName = $accountsName;
        $this->client = $client;
        $this->userDomainHelper = $userDomainHelper;
        $this->achievementExploreUrl = $achievementExploreUrl;
        $this->portalService = $portalService;
    }

    public function get(int $id, Request $req): JsonResponse
    {
        $tree = $req->get('tree', 0);
        $includeLTIRegistrations = $req->get('includeLTIRegistrations', 0);
        if (($enrolment = $this->repository->load($id)) && $tree) {
            return $this->response($req, $this->repository->loadEnrolmentTree($enrolment, (int) $includeLTIRegistrations));
        }

        return $this->response($req, $enrolment);
    }

    public function getSlimEnrollment(int $id, Request $req): JsonResponse
    {
        $enrolment = $this->repository->load($id, true);
        $response = $this->response($req, $enrolment);
        if ($response->getStatusCode() === 200) {
            $responseData = $this->repository->formatSlimEnrollmentResponse($enrolment);
            return new JsonResponse($responseData);
        }
        return $response;
    }

    public function getRevision(int $id, Request $req): JsonResponse
    {
        $tree = $req->get('tree', 0);

        return $this->response($req, $tree ? $this->rRevision->loadEnrolmentRevisionTree($id) : $this->rRevision->load($id));
    }

    public function getByLearningObject(int $loId, Request $req): JsonResponse
    {
        if (!$user = $this->accessChecker->validUser($req)) {
            return new JsonResponse(['message' => 'Invalid or missing JWT.'], 403);
        }

        if (!is_numeric($loId) || !$lo = LoHelper::load($this->go1WriteDbWrapper->get(), $loId)) {
            return Error::jr404('Learning object not found');
        }

        try {
            $courseId = $req->query->get('courseId');
            $moduleId = $req->query->get('moduleId');
            $portalId = $req->query->get('portalId');

            Assert::lazy()
                ->that($courseId, 'courseId')->nullOr()->numeric()
                ->that($moduleId, 'moduleId')->nullOr()->numeric()
                ->that($portalId, 'portalId')->nullOr()->numeric()
                ->verifyNow();

            if ($portalId && $courseId) {
                if (!$portal = $this->portalService->loadBasicById($portalId)) {
                    return Error::jr('Invalid portal id');
                }
                if (!$this->accessChecker->validUser($req, $portal->title)) {
                    return new JsonResponse(['message' => 'Missing account.'], 400);
                }

                $courseEnrolment = EnrolmentHelper::findEnrolment($this->go1ReadDbWrapper->get(), $portal->id, $user->id, $courseId, 0);
                $moduleEnrolment = $courseEnrolment ? EnrolmentHelper::findEnrolment($this->go1ReadDbWrapper->get(), $portal->id, $user->id, $moduleId, $courseEnrolment->id) : null;
                $enrolment = $moduleEnrolment ? EnrolmentHelper::findEnrolment($this->go1ReadDbWrapper->get(), $portal->id, $user->id, $lo->id, $moduleEnrolment->id) : null;
                if (!$enrolment) {
                    return Error::jr404('Enrolment not found.');
                }

                return $this->get($enrolment->id, $req);
            }

            $takenInstanceId = $portalId ?: $lo->instance_id;
            $enrolment = EnrolmentHelper::findEnrolment($this->go1ReadDbWrapper->get(), $takenInstanceId, $user->id, $loId, 0);
            if (!$enrolment) {
                return Error::jr404('Enrolment not found.');
            }

            return $this->get($enrolment->id, $req);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }
    }

    public function getByRemoteLearningObject(int $instanceId, string $type, int $remoteLoId, Request $req): JsonResponse
    {
        if (!$loId = $this->repository->loService()->findId($instanceId, $type, $remoteLoId)) {
            return new JsonResponse(['message' => 'Learning object not found.'], 404);
        }

        return $this->getByLearningObject($loId, $req);
    }

    private function response(Request $req, ?stdClass $enrolment): JsonResponse
    {
        if (!$actor = $this->accessChecker->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$enrolment) {
            return Error::jr404('Enrolment not found.');
        }

        if (!$portal = $this->portalService->loadByLoId($enrolment->lo_id)) {
            return Error::jr404('Portal not found.');
        }

        if (!$takenPortal = $this->portalService->loadBasicById($enrolment->taken_instance_id)) {
            return Error::jr404('Portal not found.');
        }

        $user = null;

        $access = function () use ($req, $enrolment, $portal, &$user, $takenPortal, $actor) {
            if ($this->accessChecker->isOwner($req, $enrolment->user_id, 'id')) {
                return true;
            }

            if ($this->accessChecker->isPortalAdmin($req, $portal->title)) {
                return true;
            }

            if ($this->accessChecker->isPortalAdmin($req, $takenPortal->title)) {
                return true;
            }

            $lo = LoHelper::load($this->go1WriteDbWrapper->get(), $enrolment->lo_id);
            $courseId = ($lo && LoTypes::COURSE == $lo->type) ? $lo->id : false;
            if (!$courseId) {
                $courseEnrolment = $this->repository->findParentEnrolment($enrolment);
                $courseId = $courseEnrolment ? $courseEnrolment->loId : false;
            }

            if ($courseId && AccessChecker::isAssessor($this->go1ReadDbWrapper->get(), $courseId, $actor->id, $enrolment->user_id, $req)) {
                return true;
            }

            if (!$user = $this->userDomainHelper->loadUser($enrolment->user_id, $takenPortal->title)) {
                return false;
            }
            $actorPortalAccount = $this->accessChecker->validAccount($req, $takenPortal->title);
            $isManager = ($actorPortalAccount && !empty($user->account))
                ? $this->userDomainHelper->isManager($takenPortal->title, $actorPortalAccount->id, $user->account->legacyId)
                : false;

            if ($isManager) {
                return true;
            }

            return false;
        };

        if (!$access()) {
            return new JsonResponse(null, 403);
        }

        # TODO: Drop legacy support.
        if (!$this->portalChecker->isVirtual($portal)) {
            if (!isset($user)) {
                if (!$user = $this->userDomainHelper->loadUser($enrolment->user_id, $takenPortal->title)) {
                    return Error::jr404('User not found.');
                }
            }
        }

        $this->attachAssigner($enrolment);
        $this->attachDueDate($enrolment);
        if (isset($enrolment->lo_type) && ($enrolment->lo_type == LoTypes::ACHIEVEMENT)) {
            $this->attachAchievement($enrolment);
        }

        return new JsonResponse($enrolment);
    }

    protected function attachAssigner(stdClass &$enrolment): void
    {
        $assigner = $this->repository->getAssigner($enrolment->id);
        if ($assigner) {
            $enrolment->assigner = [
                'id'         => $assigner->_gc_user_id,
                'first_name' => $assigner->given_name,
                'last_name'  => $assigner->family_name,
                'mail'       => $assigner->email,
                // job_title is not yet available, pending add by IAM team
                'job_title'  => '',
                'avatar'     => $assigner->picture ?: '',
            ];

            if (isset($enrolment->assigner['avatar']->email)) {
                unset($enrolment->assigner['avatar']->email);
            }
        }
    }

    private function attachDueDate(stdClass &$enrolment): void
    {
        $plan = $this->repository->loadPlanByEnrolmentLegacy($enrolment->id);
        if ($plan && ($plan->due)) {
            $enrolment->due_date = $plan->due->format(DATE_ISO8601);
        }
    }

    private function attachAchievement(stdClass &$enrolment): void
    {
        try {
            $res = $this->client->get("{$this->achievementExploreUrl}/enrolment/{$enrolment->id}?build=true", [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . UserHelper::ROOT_JWT,
                ],
            ]);

            $achievementEnrolment = json_decode($res->getBody()->getContents());
            $enrolment->award = $achievementEnrolment->award;
        } catch (BadResponseException $e) {
            $enrolment->award = $e->getMessage();
        }
    }

    public function single(int $loId, int $takenPortalId, Request $req): JsonResponse
    {
        try {
            if (!$actor = $this->accessChecker->validUser($req)) {
                return Error::createMissingOrInvalidJWT();
            }

            $userId = $req->query->get('userId', null);
            $parentLoId = $req->query->get('parentLoId', null);

            Assert::lazy()
                ->that($userId, 'userId')->nullOr()->numeric()
                ->that($parentLoId, 'parentLoId')->nullOr()->numeric()
                ->verifyNow();

            if ($userId) {
                if (!$this->accessChecker->isAccountsAdmin($req) && $this->accessChecker->isPortalAdmin($req, $takenPortalId)) {
                    return Error::jr403('Internal resource');
                }

                if (!$this->userDomainHelper->loadUser($userId)) {
                    return Error::jr('Invalid user');
                }
            }

            $uid = $userId ?: $actor->id;
            $enrolment = $this->repository->loadEnrolment($this->go1ReadDbWrapper->get(), $takenPortalId, $uid, $loId, $parentLoId);
            if (!$enrolment) {
                // had a revision of achievement and revision status = completed
                $los = LoHelper::loadMultipleFieldsOnly($this->go1WriteDbWrapper->get(), [$loId], ['type']);
                if ($los && ($los[0]->type == LoTypes::ACHIEVEMENT)) {
                    $completedRevision = $this->rRevision->loadLastCompletedRevision($takenPortalId, $uid, $loId);
                    if ($completedRevision) {
                        $enrolment = Enrolment::create($completedRevision);
                        $enrolment->id = $completedRevision->enrolment_id;
                    }
                }

                if (!$enrolment) {
                    return Error::jr404('Enrolment not found');
                }
            }

            $enrolment = (object) $enrolment->jsonSerialize();
            $this->repository->formatEnrolment($enrolment);

            return new JsonResponse($enrolment);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }
    }
}
