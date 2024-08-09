<?php

namespace go1\enrolment\content_learning;

use go1\domain_users\clients\user_management\lib\Model\AccountUserDto;
use go1\enrolment\controller\ContentLearningController;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\UserService;
use go1\util\DB;
use go1\util\enrolment\EnrolmentStatuses;

class ContentLearningQuery
{
    public const NOT_PASSED = 'not-passed';
    private ConnectionWrapper       $db;
    private bool             $useUserService;
    private UserService      $userService;

    public function __construct(ConnectionWrapper $db, bool $useUserService, UserService $userService)
    {
        $this->db = $db;
        $this->useUserService = $useUserService;
        $this->userService = $userService;
    }

    private function getUsersOfPlans(ContentLearningFilterOptions $o, bool $firstPageOnly = false): DbResult
    {
        $learningPlanUsersQuery = $this->db->get()
            ->createQueryBuilder()
            ->select('p.user_id')
            ->from('gc_plan', 'p')
            ->leftJoin('p', 'gc_enrolment_plans', 'ep', 'ep.plan_id = p.id')
            ->leftJoin('ep', 'gc_enrolment', 'e', 'ep.enrolment_id = e.id')
            ->where('p.entity_type = "lo" AND p.entity_id = :loId AND p.instance_id = :portalId')
            ->setParameter(':loId', (int) $o->loId, DB::INTEGER)
            ->setParameter(':portalId', (int) $o->portal->id, DB::INTEGER);

        if ($firstPageOnly) {
            if (!empty($o->sort)) {
                ContentLearningSort::addSortFields($learningPlanUsersQuery);
                ContentLearningSort::addSort($learningPlanUsersQuery, $o);
            }
            $learningPlanUsersQuery->setMaxResults(UserService::USER_MANAGEMENT_PAGE_SIZE);
        }

        ContentLearningFilters::addAssignerFilter($learningPlanUsersQuery, $o);
        ContentLearningFilters::addDateFieldsFilter($learningPlanUsersQuery, $o);
        ContentLearningFilters::addStatusAndPassFilter($learningPlanUsersQuery, $o);
        ContentLearningFilters::addUsersFilter($learningPlanUsersQuery, $o, 'p');
        return new DbResult($learningPlanUsersQuery);
    }

    private function getUsersOfEnrolments(ContentLearningFilterOptions $o, array $statuses = null, bool $firstPageOnly = false): DbResult
    {
        $learningEnrolmentUsersQuery = $this->db->get()
            ->createQueryBuilder()
            ->select('e.user_id')
            ->from('gc_enrolment', 'e')
            ->leftJoin('e', 'gc_enrolment_plans', 'ep', 'ep.enrolment_id = e.id')
            ->leftJoin('ep', 'gc_plan', 'p', 'p.id = ep.plan_id')
            ->where('e.lo_id = :loId AND e.taken_instance_id = :portalId')
            ->andWhere('e.parent_enrolment_id = 0')
            ->setParameter(':loId', (int) $o->loId, DB::INTEGER)
            ->setParameter(':portalId', (int) $o->portal->id, DB::INTEGER);

        if ($firstPageOnly) {
            if (!empty($o->sort)) {
                ContentLearningSort::addSortFields($learningEnrolmentUsersQuery);
                ContentLearningSort::addSort($learningEnrolmentUsersQuery, $o);
            }
            $learningEnrolmentUsersQuery->setMaxResults(UserService::USER_MANAGEMENT_PAGE_SIZE);
        }

        ContentLearningFilters::addAssignerFilter($learningEnrolmentUsersQuery, $o);
        ContentLearningFilters::addDateFieldsFilter($learningEnrolmentUsersQuery, $o);
        ContentLearningFilters::addStatusAndPassFilter($learningEnrolmentUsersQuery, $o, $statuses);
        ContentLearningFilters::addUsersFilter($learningEnrolmentUsersQuery, $o, 'e');
        return new DbResult($learningEnrolmentUsersQuery);
    }

    /**
     * @param AccountUserDto[] $accounts
     * @return int[]
     */
    private function userIdsFromAccounts(array $accounts, bool $filterActive): array
    {
        $userIds = [];
        foreach ($accounts as $account) {
            if (!$filterActive || $account->getStatus()) {
                $userIds[] = (int) $account->getUser()->getGcUserId();
            }
        }
        return $userIds;
    }

    private function loadAllUserIdsFromResultSets(array $allUserIdsResultSets): array
    {
        $userIdsFromResults = array_map(fn (DbResult $result): array => $result->getItems(DB::COL), $allUserIdsResultSets);
        $mergedUserIds = array_merge(...$userIdsFromResults);
        $userIds = array_values(array_unique($mergedUserIds));
        return array_map(fn (string $userId): int => (int) $userId, $userIds);
    }

    private function mergeUserIdResults(int $portalId, array $allUserIdsResultSets, ?array $chosenUserIds, ?int $managerAccountId, bool $activePortalAccountsOnly): ?array
    {
        if (!$managerAccountId && !$activePortalAccountsOnly) {
            return $chosenUserIds;
        }

        if ($chosenUserIds == null) {
            $chosenUserIds = $this->loadAllUserIdsFromResultSets($allUserIdsResultSets);
        }

        $accounts = array_values($this->userService->loadAccountsFromUserIdsWithPaging($portalId, $chosenUserIds, $managerAccountId));

        return $this->userIdsFromAccounts($accounts, $activePortalAccountsOnly);
    }


    private static function getMinimumResultsToFetch(ContentLearningFilterOptions $o): int
    {
        return ($o->offset ?? 0) + ($o->limit ?? 20);
    }

    public function findPlansAndLearningRecords(ContentLearningFilterOptions $o, int $expectedCount): ContentLearningQueryResult
    {
        $learningPlanQuery = $this->db->get()
            ->createQueryBuilder()
            ->select(
                'DISTINCT p.id as planId',
                'e.id as enrolmentId',
                'e.status as status'
            )
            ->from('gc_plan', 'p')
            ->leftJoin('p', 'gc_enrolment_plans', 'ep', 'ep.plan_id = p.id')
            ->leftJoin('ep', 'gc_enrolment', 'e', 'ep.enrolment_id = e.id')
            ->where('p.entity_type = "lo" AND p.entity_id = :loId AND p.instance_id = :portalId')
            ->andWhere('e.user_id IS NULL OR e.user_id = p.user_id');

        $learningEnrolmentQuery = $this->db->get()
            ->createQueryBuilder()
            ->select(
                'DISTINCT ep.plan_id as planId',
                'e.id as enrolmentId',
                'e.status as status'
            )
            ->from('gc_enrolment', 'e')
            ->leftJoin('e', 'gc_enrolment_plans', 'ep', 'ep.enrolment_id = e.id')
            ->leftJoin('ep', 'gc_plan', 'p', 'p.id = ep.plan_id')
            ->where('e.lo_id = :loId AND e.taken_instance_id = :portalId')
            ->andWhere('e.parent_enrolment_id = 0')
            ->andWhere('p.user_id IS NULL OR p.user_id = e.user_id');

        ContentLearningFilters::addAssignerFilter($learningPlanQuery, $o);
        ContentLearningFilters::addAssignerFilter($learningEnrolmentQuery, $o);
        ContentLearningFilters::addDateFieldsFilter($learningPlanQuery, $o);
        ContentLearningFilters::addDateFieldsFilter($learningEnrolmentQuery, $o);
        ContentLearningFilters::addStatusAndPassFilter($learningPlanQuery, $o);
        ContentLearningFilters::addStatusAndPassFilter($learningEnrolmentQuery, $o);

        if ($this->useUserService) {
            $userIds = $this->mergeUserIdResults(
                $o->portal->id,
                [
                    $this->getUsersOfPlans($o, true),
                    $this->getUsersOfEnrolments($o, null, true)
                ],
                $o->userIds,
                $o->managerAccount->id ?? null,
                $o->accountStatus == 1
            );

            if ($expectedCount > UserService::USER_MANAGEMENT_PAGE_SIZE && count($userIds) < self::getMinimumResultsToFetch($o)) {
                $userIds = $this->mergeUserIdResults(
                    $o->portal->id,
                    [
                        $this->getUsersOfPlans($o),
                        $this->getUsersOfEnrolments($o)
                    ],
                    $o->userIds,
                    $o->managerAccount->id ?? null,
                    $o->accountStatus == 1
                );
            }
            if ($userIds !== null) {
                $learningPlanQuery
                    ->andWhere("p.user_id IN (:userIds)")
                    ->setParameter(':userIds', $userIds, DB::INTEGERS);
                $learningEnrolmentQuery
                    ->andWhere("e.user_id IN (:userIds)")
                    ->setParameter(':userIds', $userIds, DB::INTEGERS);
            }
        } else {
            ContentLearningFilters::addUsersFilter($learningEnrolmentQuery, $o, 'e');
            ContentLearningFilters::addUsersFilter($learningPlanQuery, $o, 'p');
            ContentLearningFilters::addActiveAccountFilter($learningEnrolmentQuery, $o, 'e');
            ContentLearningFilters::addActiveAccountFilter($learningPlanQuery, $o, 'p');
            ContentLearningFilters::addManagerOfUserFilter($learningEnrolmentQuery, $o, 'e');
            ContentLearningFilters::addManagerOfUserFilter($learningPlanQuery, $o, 'p');
        }

        if (!empty($o->sort)) {
            ContentLearningSort::addSortFields($learningPlanQuery);
            ContentLearningSort::addSortFields($learningEnrolmentQuery);
        }

        $countExpression = 'COUNT(*)';
        if (ContentLearningController::ACTIVITY_TYPE_SELF_DIRECTED == $o->activityType) {
            $query = $learningEnrolmentQuery;
            $query->andWhere('ep.plan_id IS NULL');

            $query
                ->setParameter(':loId', (int) $o->loId, DB::INTEGER)
                ->setParameter(':portalId', (int) $o->portal->id, DB::INTEGER);

            $countExpression = 'COUNT(DISTINCT e.id)';
        } else {
            $query = $this->db->get()
                ->createQueryBuilder()
                ->select('planId', 'enrolmentId')
                ->from("({$learningPlanQuery->getSQL()} UNION {$learningEnrolmentQuery->getSQL()})", 'e')
                ->setParameters($learningPlanQuery->getParameters(), $learningPlanQuery->getParameterTypes())
                ->setParameters($learningEnrolmentQuery->getParameters(), $learningEnrolmentQuery->getParameterTypes())
                ->setParameter(':loId', (int) $o->loId, DB::INTEGER)
                ->setParameter(':portalId', (int) $o->portal->id, DB::INTEGER);
        }

        $counter = clone $query;

        ContentLearningSort::addSort($query, $o);

        $query
            ->setFirstResult($o->offset)
            ->setMaxResults($o->limit);

        return new ContentLearningQueryResult(
            fn () => $query->execute()->fetchAll(DB::OBJ),
            fn () => $counter->select($countExpression)->execute()->fetchColumn(),
            fn () => $counter
                ->select(
                    'e.status as status',
                    "$countExpression as total",
                    'e.pass as pass'
                )
                ->groupBy('e.status, e.pass')
                ->execute()
                ->fetchAll(DB::OBJ)
        );
    }

    public function findPlans(ContentLearningFilterOptions $o, int $expectedCount): ContentLearningQueryResult
    {
        $q = $this->db->get()
            ->createQueryBuilder()
            ->select(
                'DISTINCT p.id as planId',
                'e.id as enrolmentId'
            )
            ->from('gc_plan', 'p')
            ->leftJoin('p', 'gc_enrolment_plans', 'ep', 'ep.plan_id = p.id')
            ->where('p.entity_type = "lo" AND p.entity_id = :loId AND p.instance_id = :portalId')
            ->setParameters([
                ':loId'     => (int) $o->loId,
                ':portalId' => (int) $o->portal->id,
            ]);

        $innerJoinWithEnrolment = !is_null($o->startedAt) || !is_null($o->endedAt);

        if ($innerJoinWithEnrolment) {
            $q->innerJoin('ep', 'gc_enrolment', 'e', 'ep.enrolment_id = e.id');
        } else {
            $q->leftJoin('ep', 'gc_enrolment', 'e', 'ep.enrolment_id = e.id');
        }

        $q->andWhere('e.user_id IS NULL OR e.user_id = p.user_id');
        ContentLearningFilters::addAssignerFilter($q, $o);
        ContentLearningFilters::addDateFieldsFilter($q, $o);
        ContentLearningFilters::addStatusAndPassFilter($q, $o);

        if ($this->useUserService) {
            $userIds = $this->mergeUserIdResults(
                $o->portal->id,
                [$this->getUsersOfPlans($o, true)],
                $o->userIds,
                $o->managerAccount->id ?? null,
                $o->accountStatus == 1
            );
            if ($expectedCount > UserService::USER_MANAGEMENT_PAGE_SIZE && count($userIds) < self::getMinimumResultsToFetch($o)) {
                $userIds = $this->mergeUserIdResults(
                    $o->portal->id,
                    [
                        $this->getUsersOfPlans($o),
                    ],
                    $o->userIds,
                    $o->managerAccount->id ?? null,
                    $o->accountStatus == 1
                );
            }
            if ($userIds !== null) {
                $q
                    ->andWhere("p.user_id IN (:userIds)")
                    ->setParameter(':userIds', $userIds, DB::INTEGERS);
            }
        } else {
            ContentLearningFilters::addUsersFilter($q, $o, 'p');
            ContentLearningFilters::addActiveAccountFilter($q, $o, 'p');
            ContentLearningFilters::addManagerOfUserFilter($q, $o, 'p');
        }

        $counter = clone $q;
        if (!empty($o->sort)) {
            ContentLearningSort::addSortFields($q);
            ContentLearningSort::addSort($q, $o);
        }

        $q
            ->setFirstResult($o->offset)
            ->setMaxResults($o->limit);

        return new ContentLearningQueryResult(
            fn () => $q
                ->execute()
                ->fetchAll(DB::OBJ),
            fn () => $counter
                ->select('COUNT(DISTINCT p.id)')
                ->execute()
                ->fetchColumn(),
            fn () => $counter
                ->select(
                    'e.status as status',
                    'COUNT(DISTINCT p.id) as total',
                    'e.pass as pass'
                )
                ->groupBy('e.status, e.pass')
                ->execute()
                ->fetchAll(DB::OBJ)
        );
    }

    public function findLearningRecords(ContentLearningFilterOptions $o, int $expectedCount, array $statuses = null): ContentLearningQueryResult
    {
        $learningEnrolmentQuery = $this->db->get()
            ->createQueryBuilder()
            ->select(
                'DISTINCT ep.plan_id as planId',
                'e.id as enrolmentId',
                'e.status as status'
            )
            ->from('gc_enrolment', 'e')
            ->leftJoin('e', 'gc_enrolment_plans', 'ep', 'ep.enrolment_id = e.id')
            ->where('e.lo_id = :loId AND e.taken_instance_id = :portalId')
            ->andWhere('e.parent_enrolment_id = 0')
            ->setParameter(':loId', (int) $o->loId, DB::INTEGER)
            ->setParameter(':portalId', (int) $o->portal->id, DB::INTEGER);

        if ($o->activityType !== ContentLearningController::ACTIVITY_TYPE_SELF_DIRECTED) {
            $learningEnrolmentQuery
                ->leftJoin('ep', 'gc_plan', 'p', 'p.id = ep.plan_id')
                ->andWhere('p.user_id IS NULL OR p.user_id = e.user_id');
            ContentLearningFilters::addAssignerFilter($learningEnrolmentQuery, $o);
        }

        ContentLearningFilters::addDateFieldsFilter($learningEnrolmentQuery, $o);
        ContentLearningFilters::addStatusAndPassFilter($learningEnrolmentQuery, $o, $statuses);

        if ($this->useUserService) {
            $userIds = $this->mergeUserIdResults(
                $o->portal->id,
                [$this->getUsersOfEnrolments($o, $statuses, true)],
                $o->userIds,
                $o->managerAccount->id ?? null,
                $o->accountStatus == 1
            );
            if ($expectedCount > UserService::USER_MANAGEMENT_PAGE_SIZE && count($userIds) < self::getMinimumResultsToFetch($o)) {
                $userIds = $this->mergeUserIdResults(
                    $o->portal->id,
                    [$this->getUsersOfEnrolments($o, $statuses)],
                    $o->userIds,
                    $o->managerAccount->id ?? null,
                    $o->accountStatus == 1
                );
            }
            if ($userIds !== null) {
                $learningEnrolmentQuery
                    ->andWhere("e.user_id IN (:userIds)")
                    ->setParameter(':userIds', $userIds, DB::INTEGERS);
            }
        } else {
            ContentLearningFilters::addUsersFilter($learningEnrolmentQuery, $o, 'e');
            ContentLearningFilters::addActiveAccountFilter($learningEnrolmentQuery, $o, 'e');
            ContentLearningFilters::addManagerOfUserFilter($learningEnrolmentQuery, $o, 'e');
        }

        $counter = clone $learningEnrolmentQuery;

        if (!empty($o->sort)) {
            ContentLearningSort::addSortFields($learningEnrolmentQuery);
            ContentLearningSort::addSort($learningEnrolmentQuery, $o);
        }

        $learningEnrolmentQuery
            ->setFirstResult($o->offset)
            ->setMaxResults($o->limit);

        $countExpression = 'COUNT(*)';
        return new ContentLearningQueryResult(
            fn () => $learningEnrolmentQuery
                ->execute()
                ->fetchAll(DB::OBJ),
            fn () => $counter
                ->select($countExpression)
                ->execute()
                ->fetchColumn(),
            fn () => $counter
                ->select(
                    'e.status as status',
                    "$countExpression as total",
                    'e.pass as pass'
                )
                ->groupBy('e.status, e.pass')
                ->execute()
                ->fetchAll(DB::OBJ)
        );
    }

    public function getFacet(ContentLearningFilterOptions $o): array
    {
        $allOption = clone $o;
        $allOption->status = null;
        $allOption->overdue = null;

        $overDueOption = clone $o;
        $overDueOption->overdue = true;
        $overDueOption->status = null;
        $overDueOption->passed = null;

        $assignedOption = clone $o;
        $assignedOption->activityType = ContentLearningController::ACTIVITY_TYPE_ASSIGNED;
        $assignedOption->status = null;
        $assignedOption->passed = null;

        $selfDirectedOption = clone $o;
        $selfDirectedOption->activityType = ContentLearningController::ACTIVITY_TYPE_SELF_DIRECTED;
        $selfDirectedOption->status = null;
        $selfDirectedOption->passed = null;

        $notStartedOption = clone $o;
        $notStartedOption->status = EnrolmentStatuses::NOT_STARTED;
        $notStartedOption->passed = null;

        $statistic = [
            'all'                          => $this->findPlansAndLearningRecords($allOption, UserService::USER_MANAGEMENT_PAGE_SIZE + 1)->getCount(),
            'overdue'                      => $this->findPlans($overDueOption, UserService::USER_MANAGEMENT_PAGE_SIZE + 1)->getCount(),
            'assigned'                     => $this->findPlans($assignedOption, UserService::USER_MANAGEMENT_PAGE_SIZE + 1)->getCount(),
            'self-directed'                => $this->findPlansAndLearningRecords($selfDirectedOption, UserService::USER_MANAGEMENT_PAGE_SIZE + 1)->getCount(),
            EnrolmentStatuses::NOT_STARTED => $this->findPlansAndLearningRecords($notStartedOption, UserService::USER_MANAGEMENT_PAGE_SIZE + 1)->getCount(),
            EnrolmentStatuses::IN_PROGRESS => 0,
            EnrolmentStatuses::COMPLETED   => 0,
            self::NOT_PASSED               => 0,
        ];

        $statuses = [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED];
        $enrolmentCountResult = $this->findLearningRecords($o, UserService::USER_MANAGEMENT_PAGE_SIZE + 1, $statuses)->getFacetCount();
        foreach ($enrolmentCountResult as $item) {
            if ($item->status == EnrolmentStatuses::COMPLETED && $item->pass == EnrolmentStatuses::FAILED) {
                $statistic[ContentLearningQuery::NOT_PASSED] = (int) $item->total;
            }
            $statistic[$item->status] += (int) $item->total;
        }

        return $statistic;
    }
}
