<?php

namespace go1\enrolment\services\lo;

use Doctrine\DBAL\Connection;
use Exception;
use go1\enrolment\domain\ConnectionWrapper;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\lo\LiTypes;
use go1\util\lo\LoStatuses;
use go1\util\plan\PlanTypes;
use PDO;

class LoService
{
    private ConnectionWrapper $read;

    public function __construct(ConnectionWrapper $read)
    {
        $this->read = $read;
    }

    public function load(int $id)
    {
        return $this
            ->read->get()
            ->executeQuery('SELECT * FROM gc_lo WHERE id = ?', [$id])
            ->fetch(DB::OBJ);
    }

    public function findId(int $portalId, string $type, int $remoteId)
    {
        return $this->read->get()->fetchColumn(
            'SELECT id FROM gc_lo WHERE instance_id = ? AND type = ? AND remote_id = ?',
            [$portalId, $type, $remoteId]
        );
    }

    /**
     * @throws Exception
     */
    public function children(int $loId): array
    {
        $children = $childrenRos = [];

        // Fetch related ROs with curtain LO from gc_ro table
        $roQuery = $this->read->get()
            ->executeQuery(
                'SELECT target_id, type FROM gc_ro WHERE type IN (?) AND source_id = ?',
                [EdgeTypes::LO_HAS_CHILDREN, $loId],
                [Connection::PARAM_INT_ARRAY, PDO::PARAM_INT]
            );

        while ($childRo = $roQuery->fetch()) {
            $childrenRos[] = $childRo;
            $childrenLoIds[] = $childRo['target_id'];
        }

        if (empty($childrenRos)) {
            return [];
        }

        // Exclude unpublished|archived|orphan children by loading active LO from gc_lo table
        $activeLoStatuses = [LoStatuses::PUBLISHED];
        $childrenLoIds = $this->read->get()
            ->executeQuery(
                'SELECT id, type FROM gc_lo WHERE id IN (?) AND published IN (?)',
                [$childrenLoIds, $activeLoStatuses],
                [DB::INTEGERS, DB::INTEGERS]
            )
            ->fetchAll();

        $children['events'] = [];
        foreach ($childrenLoIds as $key => $childrenLo) {
            if (LiTypes::EVENT == $childrenLo['type']) {
                $children['events'][] = $childrenLo['id'];
                unset($childrenLoIds[$key]);
            }
        }

        $childrenLoIds = array_column($childrenLoIds, 'id');

        $edges = array_filter($childrenRos, fn ($v) => in_array($v['target_id'], $childrenLoIds));
        foreach ($edges as $edge) {
            if (in_array($edge['type'], [EdgeTypes::HAS_ELECTIVE_LO, EdgeTypes::HAS_ELECTIVE_LI])) {
                $children['elective'][] = $edge['target_id'];
            } else {
                $children['non_elective'][] = $edge['target_id'];
            }
        }

        return $children;
    }

    public function getCompletionRule(int $loId, ?int $parentLoId = null): ?CompletionRule
    {
        $edge = $this->read->get()
            ->createQueryBuilder()
            ->from('gc_ro')
            ->select('*')
            ->andWhere('type = :type AND source_id = :loId AND target_id = :edgeId')
            ->setParameters([
                ':type'   => EdgeTypes::HAS_SUGGESTED_COMPLETION,
                ':loId'   => $loId,
                ':edgeId' => (int) (
                    !$parentLoId ? 0 : $this->read->get()->fetchColumn(
                        'SELECT id FROM gc_ro WHERE type = ? AND source_id = ? AND target_id = ?',
                        [EdgeTypes::HAS_LI, $parentLoId, $loId]
                    )
                ),
            ])
            ->execute()
            ->fetch(PDO::FETCH_OBJ);

        if ($edge) {
            $raw = json_decode($edge->data);
            $rule = new CompletionRule($raw->type, $raw->value);

            if ($edge->target_id) {
                $rule
                    ->withEntityType(PlanTypes::ENTITY_RO)
                    ->withEntityId($edge->id);
            } else {
                $rule
                    ->withEntityType(PlanTypes::ENTITY_LO)
                    ->withEntityId($loId);
            }

            return $rule;
        }

        return null;
    }
}
