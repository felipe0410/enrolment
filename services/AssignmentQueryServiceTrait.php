<?php

namespace go1\enrolment\services;

use Doctrine\DBAL\Driver\Exception;
use go1\core\learning_record\plan\PlanBrowsingOptions;
use go1\core\learning_record\plan\util\PlanReference;
use go1\enrolment\domain\ConnectionWrapper;
use go1\util\DB;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanTypes;
use PDO;

/**
 * @property ConnectionWrapper $read
 * @property PlanRepository $rPlan
 */
trait AssignmentQueryServiceTrait
{
    public function loadPlanByEnrolmentLegacy(int $enrolmentId)
    {
        // if there are multiple plans, returns plan which having type is suggested
        $result = null;

        $edges = $this
            ->read->get()
            ->executeQuery('SELECT * FROM gc_enrolment_plans WHERE enrolment_id = ?', [$enrolmentId]);

        while ($edge = $edges->fetch(PDO::FETCH_OBJ)) {
            $plan = $this->rPlan->load($edge->plan_id);

            if ($plan->due && (PlanTypes::SUGGESTED == $plan->type)) {
                $result = $plan;
            }

            if ($plan->due) {
                $result = $plan;
            }
        }

        return $result;
    }

    public function loadPlansByEnrolment(int $enrolmentId)
    {
        $result = [];
        $edges = $this->read->get()
            ->executeQuery('SELECT * FROM gc_enrolment_plans WHERE enrolment_id = ?', [$enrolmentId]);

        while ($edge = $edges->fetch(PDO::FETCH_OBJ)) {
            $plan = $this->rPlan->load($edge->plan_id);
            $result [] = $plan;
        }

        return $result;
    }

    public function findPlan(PlanBrowsingOptions $o)
    {
        $q = $this->read->get()->createQueryBuilder();

        $q->select('*')
            ->from('gc_plan');

        if ($o->type) {
            $q
                ->andWhere('entity_type = :entityType')
                ->setParameter(':entityType', $o->type);
        }

        if ($o->portalId) {
            $q
                ->andWhere('instance_id = :instanceId')
                ->setParameter(':instanceId', $o->portalId);
        }

        if ($o->userId) {
            $q
                ->andWhere('user_id = :userId')
                ->setParameter(':userId', $o->userId);
        }

        if ($o->entityId) {
            $q
                ->andWhere($q->expr()->in('entity_id', ':entityIds'))
                ->setParameter(':entityIds', $o->entityId, DB::INTEGERS);
        }

        if ($o->id) {
            $q
                ->andWhere($q->expr()->in('id', ':ids'))
                ->setParameter(':ids', $o->id, DB::INTEGERS);
        }

        if (is_bool($o->dueDate)) {
            $q
                ->andWhere(
                    $o->dueDate
                        ? $q->expr()->andX()->addMultiple([
                        $q->expr()->isNotNull('due_date'),
                        $q->expr()->neq('due_date', ':emptyDueDate'),
                    ])
                        : $q->expr()->orX()->addMultiple([
                        $q->expr()->isNull('due_date'),
                        $q->expr()->eq('due_date', ':emptyDueDate'),
                    ])
                )
                ->setParameter(':emptyDueDate', '0000-00-00 00:00:00');
        }

        if ($o->sort) {
            $q->orderBy($o->sort, $o->direction);
        }

        return $q
            ->setFirstResult($o->offset)
            ->setMaxResults($o->limit)
            ->execute()
            ->fetchAll(DB::OBJ);
    }

    public function findPlanReference(int $planId, string $sourceType, int $sourceId)
    {
        $found = $this->read->get()->fetchColumn(
            'SELECT 1 FROM gc_plan_reference WHERE plan_id = ? AND source_type = ? AND source_id = ?',
            [$planId, $sourceType, $sourceId]
        );

        return !!$found;
    }

    public function loadPlanReference(PlanReference $planRef): ?PlanReference
    {
        $ref = $this->read->get()
          ->createQueryBuilder()
          ->select('*')
          ->from('gc_plan_reference', 'r')
          ->where('plan_id = :planId')
          ->setParameter(':planId', $planRef->planId);

        if ($planRef->sourceType) {
            $ref->andWhere('source_type = :sourceType')
                ->setParameter(':sourceType', $planRef->sourceType);
        }

        if ($planRef->sourceId) {
            $ref->andWhere('source_id = :sourceId')
                ->setParameter(':sourceId', $planRef->sourceId);
        }

        $result = $ref->execute()
            ->fetch(DB::OBJ);

        return $result ? PlanReference::createFromRecord($result) : null;
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function findSourceIdByPlanId(int $planId, string $sourceType): ?int
    {
        $res = $this->read->get()
            ->executeQuery("SELECT source_id FROM gc_plan_reference WHERE plan_id = ? AND source_type = ?", [$planId, $sourceType])
            ->fetchOne();

        return $res ?? null;
    }
}
