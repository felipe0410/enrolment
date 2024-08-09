<?php

namespace go1\enrolment\domain\etc;

use go1\enrolment\domain\ConnectionWrapper;
use go1\util\DB;

class EnrolmentPlanRepository
{
    private ConnectionWrapper $db;

    public function __construct(ConnectionWrapper $db)
    {
        $this->db = $db;
    }

    public function create(int $enrolmentId, int $planId): void
    {
        $this->db->get()->insert('gc_enrolment_plans', [
            'enrolment_id' => $enrolmentId,
            'plan_id'      => $planId,
        ]);
    }

    public function has(int $enrolmentId, int $planId): bool
    {
        $ok = $this->db->get()->createQueryBuilder()
            ->select('1')
            ->from('gc_enrolment_plans')
            ->where('enrolment_id = :enrolmentId')
            ->andWhere('plan_id = :planId')
            ->setParameter(':enrolmentId', $enrolmentId)
            ->setParameter(':planId', $planId)
            ->execute()
            ->fetch(DB::COL);

        return boolval($ok);
    }
}
