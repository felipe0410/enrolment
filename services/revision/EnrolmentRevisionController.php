<?php

namespace go1\core\learning_record\revision;

use Assert\Assert;
use Assert\LazyAssertionException;
use go1\core\util\client\UserDomainHelper;
use go1\core\util\DateTime;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\Error;
use go1\util\model\Enrolment;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Exception;

class EnrolmentRevisionController
{
    protected ConnectionWrapper $go1;
    protected UserDomainHelper $userDomainHelper;
    private LoggerInterface $logger;
    private EnrolmentRepository $repository;
    private AccessChecker $accessChecker;

    public function __construct(
        ConnectionWrapper $go1,
        UserDomainHelper $userDomainHelper,
        LoggerInterface $logger,
        EnrolmentRepository $repository,
        AccessChecker $accessChecker
    ) {
        $this->go1 = $go1;
        $this->userDomainHelper = $userDomainHelper;
        $this->logger = $logger;
        $this->repository = $repository;
        $this->accessChecker = $accessChecker;
    }

    public function post(Request $req)
    {
        $enrolment = $this->validate($req);
        if ($enrolment instanceof JsonResponse) {
            return $enrolment;
        }

        try {
            $note = $req->request->get('note', '');
            $this->repository->addRevision($enrolment, $note);

            return new JsonResponse($enrolment, 201);
        } catch (Exception $e) {
            $this->logger->error('Failed to create new enrolment revision', ['exception' => $e]);
        }

        return Error::jr500('Failed to create new enrolment revision.');
    }

    private function validate(Request $req)
    {
        if (!$this->accessChecker->isAccountsAdmin($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        try {
            $enrolmentId = $req->request->get('enrolment_id');
            $startDate = $req->request->get('start_date', 0);
            $endDate = $req->request->get('end_date', 0);
            $status = $req->request->get('status', EnrolmentStatuses::IN_PROGRESS);
            $result = $req->request->get('result', 0);
            $pass = $req->request->get('pass', 0);
            $data = $req->request->get('data');
            $note = $req->request->get('note', '');

            Assert::lazy()
                ->that($enrolmentId, 'enrolment_id')->integerish()
                ->that($startDate, 'start_date')->integerish()
                ->that($endDate, 'end_date')->integerish()
                ->that($endDate, 'end_date')->greaterOrEqualThan($startDate)
                ->that($status, 'status')->inArray([EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED])
                ->that($result, 'result')->integer()->min(0)->max(100)
                ->that($pass, 'pass')->inArray([0, 1])
                ->that($note, 'note')->nullOr()->string()
                ->verifyNow();
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }

        if ($this->repository->load($enrolmentId)) {
            return Error::jr('Could not create revision since there is an active enrolment.');
        }

        $revisions = $this->repository->loadRevisions($enrolmentId);
        if (empty($revisions)) {
            return Error::jr404('Revision not found.');
        }

        $row = clone $revisions[0];

        if (!$row->data) {
            $row->data = (object) ['history' => []];
        }

        $user = null;
        if (empty($data->actor_user_id)) {
            $user = $this->accessChecker->validUser($req);
        }
        $actorId = $data->actor_user_id ?? ($user ? $user->id : null);

        $row->data->history[] = [
            'action'          => 'updated',
            'actorId'         => $actorId,
            'status'          => $status,
            'original_status' => $row->status,
            'pass'            => $pass,
            'original_pass'   => $row->pass,
            'timestamp'       => time(),
            'app'             => $data->app ?? ''
        ];

        $row->start_date = $startDate ? DateTime::atom($startDate) : $row->start_date;
        $row->end_date = $endDate ? DateTime::atom($endDate) : $row->end_date;
        $row->result = $result;
        $row->status = $status;
        $row->pass = $pass;

        $enrolment = Enrolment::create($row);
        $enrolment->id = $enrolmentId;

        return $enrolment;
    }
}
