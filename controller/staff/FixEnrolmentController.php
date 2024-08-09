<?php

namespace go1\enrolment\controller\staff;

use Assert\Assert;
use Exception;
use Assert\LazyAssertionException;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\util\AccessChecker;
use go1\util\DB;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\Error;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class FixEnrolmentController
{
    private AccessChecker       $accessChecker;
    private ConnectionWrapper          $go1;
    private EnrolmentRepository $repository;

    public function __construct(
        AccessChecker $accessChecker,
        ConnectionWrapper $go1,
        EnrolmentRepository $repository
    ) {
        $this->accessChecker = $accessChecker;
        $this->go1 = $go1;
        $this->repository = $repository;
    }

    public function post(Request $req, int $id): JsonResponse
    {
        if (!$this->accessChecker->isAccountsAdmin($req)) {
            return Error::jr403('Permission denied.');
        }

        try {
            Assert::lazy()
                  ->that($id, 'id')->numeric()
                  ->verifyNow();

            $this->fixInvalidEnrolment($id);

            return new JsonResponse(null, 204);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            return Error::jr500('Failed to update parent_enrolment_id.');
        }
    }

    public function postFixEnrolmentParentLoId(Request $req, int $offset): JsonResponse
    {
        if (!$this->accessChecker->isAccountsAdmin($req)) {
            return Error::jr403('Permission denied.');
        }

        $fixed = 0;
        $fix = (bool) $req->get('fix', 0);

        try {
            Assert::lazy()
                ->that($offset, 'offset')->numeric()
                ->verifyNow();

            $results = $this->findEnrolmentWithWrongParentLOId($offset);
            $maxEnrolmentId = $offset;
            foreach ($results as $result) {
                $maxEnrolmentId = max($maxEnrolmentId, (int) $result->id);
            }

            if ($fix) {
                foreach ($results as $result) {
                    $fixed += $this->updateEnrolmentParentLOId((int) $result->id);
                }
            }

            return new JsonResponse(['new_offset' => $maxEnrolmentId, 'count' => count($results), 'fixed' => $fixed], 200);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            return Error::jr500('Failed to adjust parent lo id');
        }
    }


    private function findEnrolmentWithWrongParentLOId(int $offset, int $limit = 100): array
    {
        $badEnrolments = <<<SQL
            SELECT 
                ge.id
            FROM 
                 gc_enrolment ge
            inner join 
                `gc_lo` gl
            on 
                ge.lo_id = gl.id
            WHERE
                ge.parent_lo_id = ge.lo_id AND ge.id > ? AND ge.id < ? AND (gl.single_li = 1 or gl.type = 'course')
            LIMIT ?
        SQL;
        return $this->go1->get()
            ->executeQuery(
                $badEnrolments,
                [$offset, $offset + 1000000, $limit],
                [DB::INTEGER, DB::INTEGER, DB::INTEGER]
            )
            ->fetchAll(DB::OBJ);
    }

    private function updateEnrolmentParentLOId(int $enrolmentId): int
    {
        return $this->go1->get()
            ->update('gc_enrolment', ['parent_lo_id' => 0], ['id' => $enrolmentId]);
    }

    private function fixInvalidEnrolment(int $id): void
    {
        $enrolment = EnrolmentHelper::loadSingle($this->go1->get(), $id);
        $parentEnrolment = EnrolmentHelper::findEnrolment(
            $this->go1->get(),
            $enrolment->takenPortalId,
            $enrolment->userId,
            $enrolment->parentLoId
        );
        $this->repository->update($id, ['parent_enrolment_id' => $parentEnrolment->id ?? 0], false);
    }
}
