<?php

namespace go1\enrolment\content_learning;

use Doctrine\DBAL\Query\QueryBuilder;
use go1\util\DB;

class DbResult
{
    private QueryBuilder $queryBuilder;

    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    public function getItems(int $fetchMode): array
    {
        return $this->queryBuilder
            ->execute()
            ->fetchAll($fetchMode);
    }

    public function getCount(): int
    {
        $q = clone $this->queryBuilder;
        return $q
            ->select('COUNT(*)')
            ->execute()
            ->fetch(DB::COL);
    }
}
