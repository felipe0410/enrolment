<?php

namespace go1\enrolment\controller;

use Exception;
use go1\enrolment\EnrolmentRevisionRepository;
use go1\util\AccessChecker;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentDeleteController
{
    private LoggerInterface     $logger;
    private EnrolmentRevisionRepository $repository;
    private AccessChecker       $accessChecker;

    public function __construct(
        LoggerInterface     $logger,
        EnrolmentRevisionRepository $repository,
        AccessChecker       $accessChecker
    ) {
        $this->logger = $logger;
        $this->repository = $repository;
        $this->accessChecker = $accessChecker;
    }

    public function deleteRevision(int $id, Request $req): JsonResponse
    {
        if (!$actor = $this->accessChecker->isAccountsAdmin($req)) {
            return $this->response('Delete enrolment revision', $id, ['message' => 'Missing or invalid JWT.'], 403);
        }

        $enrolmentRevision = $this->repository->load($id);
        if (!$enrolmentRevision) {
            return $this->response('Delete enrolment revision', $id, ['message' => 'Enrolment revision not found'], 404, $actor->id);
        }

        try {
            $deleted = $this->repository->delete($id);
            return $this->response('Delete enrolment revision', $id, [], 200, $actor->id, $deleted);
        } catch (Exception $e) {
            $this->logger->error('Failed to delete enrolment revision', [
                'id' => $id,
                'actor_id' => $actor->id,
                'exception' => $e
            ]);
            return $this->response('Delete enrolment revision', $id, ['message' => 'Internal server error'], 500, $actor->id);
        }
    }

    public function deleteRevisionsByEnrolmentId(int $enrolmentId, Request $req): JsonResponse
    {
        if (!$actor = $this->accessChecker->isAccountsAdmin($req)) {
            return $this->response('Delete enrolment revisions', $enrolmentId, ['message' => 'Missing or invalid JWT.'], 403);
        }

        $enrolmentRevisions = $this->repository->loadByEnrolmentId($enrolmentId);
        if (!$enrolmentRevisions) {
            return $this->response('Delete enrolment revisions', $enrolmentId, ['message' => 'No enrolment revisions found'], 404, $actor->id);
        }

        try {
            $deleted = $this->repository->deleteByEnrolmentId($enrolmentId);
            return $this->response('Delete enrolment revisions', $enrolmentId, [], 200, $actor->id, $deleted);
        } catch (Exception $e) {
            $this->logger->error('Failed to delete enrolment revisions', [
                'enrolment_id' => $enrolmentId,
                'actor_id' => $actor->id,
                'exception' => $e
            ]);
            return $this->response('Delete enrolment revisions', $enrolmentId, ['message' => 'Internal server error'], 500, $actor->id);
        }
    }

    private function response(string $logMessage, int $id, array $jsonResponse, int $httpCode, ?int $actorId = null, bool $deleted = false): JsonResponse
    {
        $this->logger->info(
            $logMessage,
            [
                'id' => $id,
                'response' => $jsonResponse,
                'deleted' => $deleted,
                'actor_id' => $actorId
            ]
        );
        return new JsonResponse($jsonResponse, $httpCode);
    }
}
