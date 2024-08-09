<?php

namespace go1\enrolment\controller\staff;

use Assert\Assert;
use Assert\LazyAssertionException;
use Exception;
use go1\enrolment\domain\ConnectionWrapper;
use go1\util\AccessChecker;
use go1\util\DB;
use go1\util\Error;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class FixEnrolmentRevisionController
{
    private AccessChecker $accessChecker;
    private ConnectionWrapper $go1;

    public function __construct(
        AccessChecker     $accessChecker,
        ConnectionWrapper $go1
    ) {
        $this->accessChecker = $accessChecker;
        $this->go1 = $go1;
    }

    public function restoreRevisions(Request $req): JsonResponse
    {
        if (!$this->accessChecker->isAccountsAdmin($req)) {
            return Error::jr403('Permission denied.');
        }

        $this->go1->get()->insert('gc_enrolment_revision', [
            "id"                  => 115396122,
            "profile_id"          => 21133502,
            "lo_id"               => 11822319,
            "instance_id"         => 0,
            "taken_instance_id"   => 20746554,
            "start_date"          => "2023-05-30 02:17:51",//"2023-05-30T02:17:51+0000"
            "end_date"            => null,
            "status"              => "in-progress",
            "result"              => 0,
            "pass"                => 0,
            "enrolment_id"        => 90227437,
            "parent_lo_id"        => 11825023,
            "note"                => "",
            "data"                => json_encode([
                "utm_source"    => "https://cdn2.dcbstatic.com",
                "utm_medium"    => "scorm",
                "history"       => [
                    [
                        "realm"     => 2,
                        "status"    => "in-progress",
                        "timestamp" => 1685413071
                    ]
                ],
                "actor_user_id" => 21133502
            ]),
            "parent_enrolment_id" => 90227435,
            "timestamp"           => 1685413387,
            "user_id"             => 21133502
        ]);

        return new JsonResponse('Ok');
    }

    public function postFixEnrolmentRevisionParentLoId(Request $req, int $offset): JsonResponse
    {
        if (!$this->accessChecker->isAccountsAdmin($req)) {
            return Error::jr403('Permission denied.');
        }

        $fixed = 0;
        $fix = (bool)$req->get('fix', 0);
        $addition = (int)$req->get('addition', 50000);

        try {
            Assert::lazy()
                ->that($offset, 'offset')->numeric()
                ->verifyNow();

            $results = $this->findEnrolmentRevisionWithWrongParentLOId($offset, 100, $addition);
            $maxEnrolmentId = $offset;
            foreach ($results as $result) {
                $maxEnrolmentId = max($maxEnrolmentId, (int)$result->id);
            }

            if ($fix) {
                foreach ($results as $result) {
                    $fixed += $this->updateEnrolmentRevisionParentLOId((int)$result->id);
                }
            }

            return new JsonResponse(['new_offset' => $maxEnrolmentId, 'count' => count($results), 'fixed' => $fixed], 200);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            return Error::jr500('Failed to adjust parent lo id');
        }
    }

    private function findEnrolmentRevisionWithWrongParentLOId(int $offset, int $limit, int $addition): array
    {
        $badEnrolmentRevisions = <<<SQL
            SELECT 
                id
            FROM 
                 gc_enrolment_revision
            WHERE
                parent_lo_id = lo_id AND id > ? AND id < ? AND enrolment_id = parent_enrolment_id
            LIMIT ?
        SQL;
        return $this->go1->get()
            ->executeQuery(
                $badEnrolmentRevisions,
                [$offset, $offset + $addition, $limit],
                [DB::INTEGER, DB::INTEGER, DB::INTEGER]
            )
            ->fetchAll(DB::OBJ);
    }

    private function updateEnrolmentRevisionParentLOId(int $enrolmentRevisionId): int
    {
        return $this->go1->get()->update('gc_enrolment_revision', ['parent_lo_id' => 0, 'parent_enrolment_id' => 0], ['id' => $enrolmentRevisionId]);
    }
}
