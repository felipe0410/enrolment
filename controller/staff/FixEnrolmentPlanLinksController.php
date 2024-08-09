<?php

namespace go1\enrolment\controller\staff;

use Assert\Assert;
use Assert\LazyAssertionException;
use Exception;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\util\AccessChecker;
use go1\util\DB;
use go1\util\Error;
use go1\util\plan\PlanTypes;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class FixEnrolmentPlanLinksController
{
    private AccessChecker       $accessChecker;
    private ConnectionWrapper   $go1;
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

    public function postAddMissingLinks(Request $req, int $offset): JsonResponse
    {
        if (!$this->accessChecker->isAccountsAdmin($req)) {
            return Error::jr403('Permission denied.');
        }

        $fix = (bool) $req->get('fix', 0);

        try {
            Assert::lazy()
                  ->that($offset, 'offset')->numeric()
                  ->verifyNow();

            $results = $this->findMissingEnrolmentPlanLinks($offset);
            $maxPlanId = $offset;
            foreach ($results as $result) {
                $maxPlanId = max($maxPlanId, (int) $result->plan_id);
            }
            if ($fix) {
                foreach ($results as $result) {
                    $this->repository->linkPlan((int) $result->plan_id, (int) $result->enrolment_id, false);
                }
            }
            return new JsonResponse(['new_offset' => $maxPlanId, 'count' => count($results)], 200);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            return Error::jr500('Failed to add enrolment plan links');
        }
    }

    /**
     * @return stdClass[]
     */
    private function findMissingEnrolmentPlanLinks(int $offset, int $limit = 100): array
    {
        $badPlansSql = <<<SQL
            SELECT 
                p.id as `plan_id`, 
                e.id as `enrolment_id`
            FROM gc_plan AS p 
            INNER JOIN gc_enrolment AS e ON p.user_id = e.user_id AND p.instance_id = e.taken_instance_id AND p.entity_id = e.lo_id 
            LEFT JOIN gc_enrolment_plans AS ep ON ep.plan_id = p.id AND ep.enrolment_id = e.id
            WHERE p.entity_type = ? AND ep.id IS NULL AND p.id > ? AND p.id < ? AND e.parent_enrolment_id = 0
            ORDER BY p.id ASC
            LIMIT ?
        SQL;
        return $this->go1->get()
            ->executeQuery(
                $badPlansSql,
                [PlanTypes::ENTITY_LO, $offset, $offset + 100000, $limit],
                [DB::STRING, DB::INTEGER, DB::INTEGER, DB::INTEGER, DB::INTEGER]
            )
            ->fetchAll(DB::OBJ);
    }

    public function postRemoveExtraLinks(Request $req, int $offset): JsonResponse
    {
        if (!$this->accessChecker->isAccountsAdmin($req)) {
            return Error::jr403('Permission denied.');
        }

        $fix = (bool) $req->get('fix', 0);

        try {
            Assert::lazy()
                  ->that($offset, 'offset')->numeric()
                  ->verifyNow();

            $results = $this->findExtraEnrolmentPlanLinks($offset);
            $maxPlanId = $offset;
            foreach ($results as $result) {
                $maxPlanId = max($maxPlanId, (int) $result->plan_id);
            }

            if ($fix) {
                foreach ($results as $result) {
                    $this->repository->unlinkPlan((int) $result->plan_id, (int) $result->enrolment_id);
                }
            }

            return new JsonResponse(['new_offset' => $maxPlanId, 'count' => count($results)], 200);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            return Error::jr500('Failed to remove enrolment plan links');
        }
    }

    /**
     * @return stdClass[]
     */
    private function findExtraEnrolmentPlanLinks(int $offset, int $limit = 100): array
    {
        $badPlansSql = <<<SQL
            SELECT 
                p.id as `plan_id`, 
                e.id as `enrolment_id`
            FROM gc_plan AS p 
            INNER JOIN gc_enrolment AS e ON p.user_id = e.user_id AND p.instance_id = e.taken_instance_id AND p.entity_id = e.lo_id 
            INNER JOIN gc_enrolment_plans AS ep ON ep.plan_id = p.id AND ep.enrolment_id = e.id
            WHERE p.entity_type = ? AND p.id > ? AND p.id < ? AND e.parent_enrolment_id > 0
            ORDER BY p.id ASC
            LIMIT ?
        SQL;
        return $this->go1->get()
            ->executeQuery(
                $badPlansSql,
                [PlanTypes::ENTITY_LO, $offset, $offset + 100000, $limit],
                [DB::STRING, DB::INTEGER, DB::INTEGER, DB::INTEGER, DB::INTEGER]
            )
            ->fetchAll(DB::OBJ);
    }
}
