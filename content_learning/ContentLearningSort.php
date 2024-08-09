<?php

namespace go1\enrolment\content_learning;

use Doctrine\DBAL\Query\QueryBuilder;

class ContentLearningSort
{
    public static function addSortFields(QueryBuilder $q): void
    {
        $q
            ->addSelect('e.start_date as startedAt')
            ->addSelect('e.end_date as endedAt')
            ->addSelect('e.changed as updatedAt');
    }

    public static function addSort(QueryBuilder $q, ContentLearningFilterOptions $o): void
    {
        if (!empty($o->sort)) {
            foreach ($o->sort as $column => $direction) {
                $q->orderBy($column, $direction);
            }
        }
    }
}
