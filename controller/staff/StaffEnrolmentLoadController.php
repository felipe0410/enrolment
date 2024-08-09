<?php

namespace go1\enrolment\controller\staff;

use Assert\Assert;
use Assert\LazyAssertionException;
use Doctrine\DBAL\Exception;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\controller\EnrolmentLoadController;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\EnrolmentRevisionRepository;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\lo\LoHelper;
use go1\util\portal\PortalChecker;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class StaffEnrolmentLoadController extends EnrolmentLoadController
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
    protected LoggerInterface $logger;

    public function __construct(
        ConnectionWrapper           $dbReadWrapper,
        ConnectionWrapper           $dbWriterWrapper,
        EnrolmentRepository         $repository,
        EnrolmentRevisionRepository $rRevision,
        AccessChecker               $accessChecker,
        PortalChecker               $portalChecker,
        string                      $accountsName,
        Client                      $client,
        UserDomainHelper            $userDomainHelper,
        string                      $achievementExploreUrl,
        PortalService               $portalService,
        LoggerInterface             $logger
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
        $this->logger = $logger;
    }

    public function getByLearningObjectForStaff(int $loId, Request $req): JsonResponse
    {
        $readDb = $this->go1ReadDbWrapper->get();

        if (!$this->accessChecker->isAccountsAdmin($req)) {
            // access is only for 'Admin on Accounts' JWT
            return new JsonResponse(['message' => 'Invalid or missing JWT.'], 403);
        }

        if (!is_numeric($loId) || !$lo = LoHelper::load($readDb, $loId)) {
            return Error::jr404("Learning object not found $loId");
        }

        try {
            $status = $req->query->get('status');
            $statuses = [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED, EnrolmentStatuses::NOT_STARTED];
            Assert::lazy()
                ->that($status, 'status')->nullOr()->string()->inArray($statuses)
                ->verifyNow();

            $enrolments = $this->repository->loadAllByLoAndStatus($loId, $status, 1);
            if (!$enrolments || count($enrolments) <= 0) {
                return Error::jr404('Enrolment not found.');
            }
            // returns the first enrolment by default. Can support returning all in the future.
            $enrolment = $enrolments[0];
            return $this->get($enrolment->id, $req);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error("Unable to get enrolments for LO", [
                'loId'    => $loId,
                'message' => $e->getMessage()
            ]);
            return Error::jr500("Unable to get enrolments for LO: {$loId}");
        }
    }
}
