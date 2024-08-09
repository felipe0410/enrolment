<?php

namespace go1\enrolment\controller\staff;

use Assert\Assert;
use Assert\LazyAssertionException;
use Exception;
use go1\enrolment\domain\ConnectionWrapper;
use go1\util\AccessChecker;
use go1\util\DB;
use go1\util\Error;
use go1\util\plan\PlanHelper;
use go1\util\plan\PlanTypes;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class FixPlansController
{
    private AccessChecker       $accessChecker;
    private ConnectionWrapper   $go1;

    public function __construct(
        AccessChecker $accessChecker,
        ConnectionWrapper $go1
    ) {
        $this->accessChecker = $accessChecker;
        $this->go1 = $go1;
    }

    public function postFixUserIds(Request $req, int $offset): JsonResponse
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

            $results = $this->findPlansWithDifferentUserId($offset);
            $maxPlanId = $offset;
            foreach ($results as $result) {
                $maxPlanId = max($maxPlanId, (int) $result->plan_id);
            }

            if ($fix) {
                foreach ($results as $result) {
                    $fixed += $this->updateUserIdOfPlan((int) $result->plan_id, (int) $result->enrolment_user_id);
                }
            }

            return new JsonResponse(['new_offset' => $maxPlanId, 'count' => count($results), 'fixed' => $fixed], 200);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        } catch (Exception $e) {
            return Error::jr500('Failed to adjust plan user id');
        }
    }


    /**
     * @return stdClass[]
     */
    private function findPlansWithDifferentUserId(int $offset, int $limit = 100): array
    {
        $badPlansSql = <<<SQL
            SELECT 
                p.id as `plan_id`, 
                p.user_id as `plan_user_id`,
                e.user_id as `enrolment_user_id`
            FROM gc_plan AS p 
            INNER JOIN gc_enrolment_plans AS ep ON ep.plan_id = p.id
            INNER JOIN gc_enrolment AS e ON e.id = ep.enrolment_id
            WHERE p.entity_type = ? AND p.id > ? AND p.id < ? AND e.user_id <> p.user_id
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

    private function updateUserIdOfPlan(int $id, int $newUserId): int
    {
        $plan = PlanHelper::load($this->go1->get(), $id);
        $existingPlan = PlanHelper::loadByEntityAndUserAndPortal($this->go1->get(), PlanTypes::ENTITY_LO, $plan->entity_id, $plan->instance_id, $newUserId);
        if (!$existingPlan) {
            return $this->go1->get()->update('gc_plan', ['user_id' => $newUserId], ['id' => $id]);
        }
        return 0;
    }
}
