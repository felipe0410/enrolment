<?php

namespace go1\enrolment\content_learning;

use DateTime;
use Doctrine\DBAL\Query\QueryBuilder;
use go1\enrolment\Constants;
use go1\enrolment\content_learning\ContentLearningFilterOptions;
use go1\util\DB;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\user\UserStatus;

class ContentLearningFilters
{
    public static function addActiveAccountFilter(QueryBuilder $q, ContentLearningFilterOptions $o, string $tableAlias): void
    {
        if ($o->accountStatus !== null) {
            $q
                ->innerjoin($tableAlias, 'gc_user', 'u', "$tableAlias.user_id = u.user_id AND u.status = :status AND u.instance = :portalName")
                ->setParameter(':portalName', $o->portal->title, DB::STRING)
                ->setParameter(':status', $o->accountStatus, DB::INTEGER);
        }
    }

    public static function addManagerOfUserFilter(QueryBuilder $q, ContentLearningFilterOptions $o, string $tableAlias): void
    {
        if ($o->managerAccount) {
            $q
                ->innerJoin($tableAlias, 'gc_user', 'account', "$tableAlias.user_id = account.user_id")
                ->leftJoin('account', 'gc_account_managers', 'level_1', 'account.id = level_1.account_id')
                ->leftJoin('level_1', 'gc_account_managers', 'level_2', 'level_1.manager_account_id = level_2.account_id')
                ->leftJoin('level_2', 'gc_account_managers', 'level_3', 'level_2.manager_account_id = level_3.account_id')
                ->leftJoin('level_3', 'gc_account_managers', 'level_4', 'level_3.manager_account_id = level_4.account_id');

            $id = $o->managerAccount->id;
            $cond = "$id IN (level_1.manager_account_id, level_2.manager_account_id, level_3.manager_account_id, level_4.manager_account_id)";
            $cond = "$cond OR (account.id = $id)";

            $q->andWhere($cond);
        }
    }

    public static function addStatusAndPassFilter(QueryBuilder $q, ContentLearningFilterOptions $o, ?array $statuses = null): void
    {
        if ($statuses !== null) {
            $q
                ->andWhere('e.status IN (:statuses)')
                ->setParameter(':statuses', $statuses, DB::STRINGS);
        } else {
            if ($o->status) {
                if ($o->status == EnrolmentStatuses::NOT_STARTED) {
                    $q
                        ->andWhere('(e.status = :enrolmentStatus OR e.status IS NULL)')
                        ->setParameter(':enrolmentStatus', $o->status);
                } else {
                    $q
                        ->andWhere('e.status = :enrolmentStatus')
                        ->setParameter(':enrolmentStatus', $o->status);
                }

                if (isset($o->passed)) {
                    $q
                        ->andWhere('e.pass = :pass')
                        ->setParameter(':pass', $o->passed);
                }
            }
        }
    }

    public static function addDateFieldsFilter(QueryBuilder $q, ContentLearningFilterOptions $o): void
    {
        $dateFilters = [
            'e.start_date'   => $o->startedAt,
            'e.end_date'     => $o->endedAt,
            'p.created_date' => $o->assignedAt,
            'p.due_date'     => $o->dueAt,
        ];

        /**
         * @var ?DateTimeFilter $value
         */
        foreach ($dateFilters as $columnName => $value) {
            $prefix = str_replace('.', '__', $columnName);

            if ($value) {
                if ($value->from && $value->to) {
                    $q
                        ->andWhere("$columnName BETWEEN :{$prefix}From AND :{$prefix}To")
                        ->setParameter(":{$prefix}From", $value->from->format(Constants::DATE_MYSQL))
                        ->setParameter(":{$prefix}To", $value->to->format(Constants::DATE_MYSQL));
                } elseif ($value->from) {
                    $q
                        ->andWhere("$columnName >= :{$prefix}From")
                        ->setParameter(":{$prefix}From", $value->from->format(Constants::DATE_MYSQL));
                } elseif ($value->to) {
                    $q
                        ->andWhere("$columnName <= :{$prefix}To")
                        ->setParameter(":{$prefix}To", $value->to->format(Constants::DATE_MYSQL));
                }
            }
        }

        # Don't count completed enrolment
        if ($o->overdue) {
            $q
                ->andWhere('p.due_date <= :dueDate AND (e.id IS NULL OR e.status <> :enrolmentStatus)')
                ->setParameter(':dueDate', (new DateTime())->format(Constants::DATE_MYSQL))
                ->setParameter(':enrolmentStatus', EnrolmentStatuses::COMPLETED);
        }
    }

    public static function addAssignerFilter(QueryBuilder $q, ContentLearningFilterOptions $o): void
    {
        if ($o->assignerIds) {
            $q
                ->andWhere('p.assigner_id IN (:assignerIds)')
                ->setParameter(':assignerIds', $o->assignerIds, DB::INTEGERS);
        }

        if ($o->groupId) {
            $q
                ->innerjoin('p', 'gc_plan_reference', 'r', 'p.id = r.plan_id')
                ->andWhere('r.source_type = :sourceType')
                ->andWhere('r.source_id = :sourceId')
                ->setParameter(':sourceType', 'group', DB::STRING)
                ->setParameter(':sourceId', $o->groupId, DB::INTEGER);
        }
    }

    public static function addUsersFilter(QueryBuilder $q, ContentLearningFilterOptions $o, string $tableAlias): void
    {
        if ($o->userIds) {
            $q
                ->andWhere("$tableAlias.user_id IN (:userIds)")
                ->setParameter(':userIds', $o->userIds, DB::INTEGERS);
        }
    }
}
