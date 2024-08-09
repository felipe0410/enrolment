<?php

namespace go1\enrolment\controller;

use Exception;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\EnrolmentRevisionRepository;
use go1\util\AccessChecker;
use go1\util\Error;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentRevisionRestoreController
{
    protected EnrolmentRepository $repository;
    protected EnrolmentRevisionRepository $revisionRepository;
    protected AccessChecker $accessChecker;
    protected LoggerInterface $logger;

    public function __construct(
        EnrolmentRepository $repository,
        EnrolmentRevisionRepository $revisionRepository,
        LoggerInterface $logger,
        AccessChecker $accessChecker
    ) {
        $this->logger = $logger;
        $this->repository = $repository;
        $this->accessChecker = $accessChecker;
        $this->revisionRepository = $revisionRepository;
    }

    public function post(int $revisionId, Request $req): JsonResponse
    {
        try {
            if (!$this->accessChecker->isAccountsAdmin($req)) {
                return Error::jr403('Internal resource.');
            }

            $revision = $this->revisionRepository->load($revisionId);
            if (!$revision) {
                return Error::jr404('Enrolment revision not found.');
            }

            if (!empty($revision->parent_enrolment_id)) {
                return Error::jr406('Only course and single LI enrolment revision are supported.');
            }

            $enrolment = $this->repository->loadByLoAndUserAndTakenInstanceId($revision->lo_id, $revision->user_id, $revision->taken_instance_id);
            if ($enrolment) {
                return Error::jr406('Enrolment already exists.');
            }

            $actor = $this->accessChecker->validUser($req);
            $this->revisionRepository->restore($revisionId, $actor->id);
            return new JsonResponse(204);
        } catch (Exception $e) {
            $this->logger->error("Errors occurred when restoring enrolment revision", [
                'revisionId' => $revisionId,
                'exception'  => $e
            ]);
            return Error::jr500('Internal error.');
        }
    }
}
