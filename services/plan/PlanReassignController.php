<?php

namespace go1\core\learning_record\plan;

use Assert\LazyAssertionException;
use Doctrine\DBAL\Connection;
use Exception;
use go1\clients\MqClient;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\Error;
use go1\util\plan\PlanRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class PlanReassignController
{
    use ReassignWithPlanTrait;
    use ReassignWithEnrolmentTrait;

    private Connection          $go1;
    private PlanRepository      $planRepository;
    private EnrolmentRepository $enrolmentRepository;
    private AccessChecker       $accessChecker;
    private UserDomainHelper    $userDomainHelper;
    private LoggerInterface     $logger;
    private MqClient            $queue;
    private PortalService       $portalService;

    public function __construct(
        Connection          $go1,
        PlanRepository      $planRepository,
        EnrolmentRepository $enrolmentRepository,
        AccessChecker       $accessChecker,
        UserDomainHelper    $userDomainHelper,
        LoggerInterface     $logger,
        MqClient            $queue,
        PortalService       $portalService
    ) {
        $this->go1 = $go1;
        $this->planRepository = $planRepository;
        $this->enrolmentRepository = $enrolmentRepository;
        $this->accessChecker = $accessChecker;
        $this->userDomainHelper = $userDomainHelper;
        $this->logger = $logger;
        $this->queue = $queue;
        $this->portalService = $portalService;
    }

    public function post(Request $req)
    {
        try {
            if (!$currentUser = $this->accessChecker->validUser($req)) {
                return Error::createMissingOrInvalidJWT();
            }

            $o = ReassignOptions::create($req);
            if ($o->planId) {
                return $this->reassignWithPlan($req, $o, $currentUser);
            }

            return $this->reassignWithEnrolment($req, $o, $currentUser);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            $this->logger->error('Error on plan re-assign learning.', [
                'id'         => $o->planId ?? null,
                'exception'  => $e,
                'controller' => __CLASS__,
            ]);
            return Error::jr500('Internal error.');
        }
    }
}
