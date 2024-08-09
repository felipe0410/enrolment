<?php

namespace go1\enrolment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception;
use go1\clients\MqClient;
use go1\util\DateTime as DateTimeHelper;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LoHelper;
use go1\util\lo\LoTypes;
use go1\util\queue\Queue;
use stdClass;
use DateTime;

class EnrolmentRevisionRepository extends EnrolmentRepository
{
    public function delete(int $id): bool
    {
        $numRows = $this->write->get()->delete('gc_enrolment_revision', ['id' => $id]);
        return $numRows > 0;
    }

    public function deleteByEnrolmentId(int $enrolmentId): bool
    {
        $numRows = $this->write->get()->delete('gc_enrolment_revision', ['enrolment_id' => $enrolmentId]);
        return $numRows > 0;
    }

    public function load(int $id, bool $loadPlan = false): ?stdClass
    {
        $enrolment = $this
            ->read->get()
            ->executeQuery('SELECT * FROM gc_enrolment_revision WHERE id = ?', [$id])
            ->fetch(DB::OBJ);

        return $enrolment ? $this->formatEnrolment($enrolment) : null;
    }

    public function loadByEnrolmentId(int $enrolmentId): array
    {
        return $this
            ->read->get()
            ->executeQuery('SELECT * FROM gc_enrolment_revision WHERE enrolment_id = ?', [$enrolmentId])
            ->fetchAll(DB::OBJ);
    }

    public function loadEnrolmentRevisionTree(int $id, string $status = EnrolmentStatuses::COMPLETED): ?stdClass
    {
        $enrolment = $this->read->get()
            ->executeQuery('SELECT * FROM gc_enrolment_revision WHERE id = ? AND status = ?', [$id, $status])
            ->fetch(DB::OBJ);

        if (!$enrolment) {
            return null;
        }

        $enrolmentDueDate = EnrolmentHelper::dueDate($this->read->get(), $enrolment->id);
        $enrolment->due_date = $enrolmentDueDate ? $enrolmentDueDate->format(DATE_ISO8601) : null;
        $loType = $enrolment->lo_type = $this->read->get()->fetchColumn('SELECT type FROM gc_lo WHERE id = ?', [$enrolment->lo_id]);
        if ($loType && (LoTypes::COURSE == $loType)) {
            # Find course.modules
            # ---------------------
            $moduleIds = $this->read->get()
                ->executeQuery(
                    'SELECT target_id FROM gc_ro WHERE (type = ? OR type = ?) AND source_id = ? ORDER BY weight',
                    [EdgeTypes::HAS_MODULE, EdgeTypes::HAS_ELECTIVE_LO, $enrolment->lo_id]
                )
                ->fetchAll(DB::COL);
            $moduleIds = array_map(fn (string $id) => (int) $id, $moduleIds);

            if ($moduleIds) {
                # Put into enrolment.items
                # ---------------------
                $q = $this->read->get()->executeQuery(
                    "SELECT * FROM gc_enrolment_revision WHERE user_id = ? AND taken_instance_id = ? AND status = ? AND lo_id IN (?) AND parent_enrolment_id = ?",
                    [$enrolment->user_id, $enrolment->taken_instance_id, $status, $moduleIds, $enrolment->enrolment_id],
                    [DB::INTEGER, DB::INTEGER, DB::STRING, DB::INTEGERS, DB::INTEGER]
                );
                while ($row = $q->fetch(DB::OBJ)) {
                    $moduleDueDate = EnrolmentHelper::dueDate($this->read->get(), $row->id);
                    $row->due_date = $moduleDueDate ? $moduleDueDate->format(DATE_ISO8601) : null;
                    $enrolment->items[] = $this->formatEnrolment($row);
                    $moduleEnrolmentIds[] = $row->enrolment_id;
                }
                $moduleEnrolmentIds = array_map(fn (string $id) => (int) $id, $moduleEnrolmentIds ?? []);

                # Find course.module.li
                # ---------------------
                $hasLiEdges = $this->read->get()
                    ->executeQuery(
                        'SELECT source_id, target_id FROM gc_ro WHERE (type = ? OR type = ?) AND source_id IN (?)',
                        [EdgeTypes::HAS_LI, EdgeTypes::HAS_ELECTIVE_LI, $moduleIds],
                        [DB::INTEGER, DB::INTEGER, DB::INTEGERS]
                    )
                    ->fetchAll(DB::OBJ);
                $liIds = array_map(
                    function ($edge) {
                        return (int) $edge->target_id;
                    },
                    $hasLiEdges
                );

                if ($liIds) {
                    # Put into enrolment.items[x].items
                    # ---------------------
                    $q = $this->read->get()->executeQuery(
                        "SELECT * FROM gc_enrolment_revision WHERE user_id = ? AND taken_instance_id = ? AND status = ? AND lo_id IN (?) AND parent_enrolment_id IN (?)",
                        [$enrolment->user_id, $enrolment->taken_instance_id, $status, $liIds, $moduleEnrolmentIds],
                        [DB::INTEGER, DB::INTEGER, DB::STRING, DB::INTEGERS, DB::INTEGERS]
                    );

                    if (isset($enrolment->items)) {
                        $revisionFromLiId = [];
                        while ($revision = $q->fetch(DB::OBJ)) {
                            $revisionFromLiId[$revision->lo_id] = $revision;
                        }

                        $liIdsFromModuleId = [];
                        foreach ($hasLiEdges as $hasLiEdge) {
                            $moduleId = $hasLiEdge->source_id;
                            if (empty($liIdsFromModuleId[$moduleId])) {
                                $liIdsFromModuleId[$moduleId] = [];
                            }
                            $liIdsFromModuleId[$moduleId][] = $hasLiEdge->target_id;
                        }
                        foreach ($enrolment->items as &$moduleEnrolment) {
                            $moduleId = $moduleEnrolment->lo_id;
                            if (isset($liIdsFromModuleId[$moduleId])) {
                                foreach ($liIdsFromModuleId[$moduleId] as $liId) {
                                    if (isset($revisionFromLiId[$liId])) {
                                        $revision = $revisionFromLiId[$liId];
                                        $liDueDate = EnrolmentHelper::dueDate($this->read->get(), $revision->id);
                                        $revision->due_date = $liDueDate ? $liDueDate->format(DATE_ISO8601) : null;
                                        $moduleEnrolment->items[] = $this->formatEnrolment($revision);
                                    }
                                }
                            }
                        }
                    }
                }

                if (isset($enrolment->items)) {
                    # Put lo_type into enrolment.items[x] & enrolment.items[x].items[x]
                    # ---------------------
                    $q = $this->read->get()->executeQuery('SELECT id, type FROM gc_lo WHERE id IN (?)', [array_merge($moduleIds, $liIds)], [DB::INTEGERS]);
                    while ($row = $q->fetch(DB::OBJ)) {
                        $learningObjectTypes[$row->id] = $row->type;
                    }

                    foreach ($enrolment->items as &$moduleEnrolment) {
                        $moduleEnrolment->lo_type = $learningObjectTypes[$moduleEnrolment->lo_id] ?? null;
                        if (isset($moduleEnrolment->items)) {
                            foreach ($moduleEnrolment->items as &$liEnrolment) {
                                $liEnrolment->lo_type = $learningObjectTypes[$liEnrolment->lo_id] ?? null;
                            }
                        }
                    }
                }
            }
        }

        return $enrolment;
    }


    /**
     * @throws DBALException
     * @throws Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    private function loadRevisionTree(int $id): ?stdClass
    {
        $revision = $this->read->get()
            ->fetchAssociative('SELECT * FROM gc_enrolment_revision WHERE id = ?', [$id]);

        if (!$revision) {
            return null;
        }

        $revision = (object)$revision;
        $loType = $revision->lo_type = $this->read->get()->fetchOne('SELECT type FROM gc_lo WHERE id = ?', [$revision->lo_id]);
        if (LoTypes::COURSE == $loType) {
            # Find course.modules enrollments.
            # An enrollment can have more than one revision. When restoring data, we only need the latest revision.
            $moduleRevisionIds = $this->read->get()
                ->fetchFirstColumn('SELECT MAX(id) as id FROM gc_enrolment_revision WHERE parent_enrolment_id = ? GROUP BY enrolment_id', [$revision->enrolment_id]);

            if ($moduleRevisionIds) {
                $rows = $this->read->get()
                    ->fetchAllAssociative('SELECT * FROM gc_enrolment_revision WHERE id IN (?)', [$moduleRevisionIds], [DB::INTEGERS]);
                foreach ($rows as $row) {
                    $row = (object)$row;
                    $revision->items[$row->enrolment_id] = $row;
                }
            }

            # Find course.modules.lis enrollments
            if (!empty($revision->items)) {
                $liRevisionIds = $this->read->get()
                    ->fetchFirstColumn('SELECT MAX(id) as id FROM gc_enrolment_revision WHERE parent_enrolment_id IN (?) GROUP BY enrolment_id', [array_keys($revision->items)], [DB::INTEGERS]);

                $rows = $this->read->get()
                    ->fetchAllAssociative('SELECT * FROM gc_enrolment_revision WHERE id IN (?)', [$liRevisionIds], [DB::INTEGERS]);
                foreach ($rows as $row) {
                    $row = (object)$row;
                    $revision->items[$row->parent_enrolment_id]->items[] = $row;
                }

                $revision->items = array_values($revision->items);
            }
        }

        return $revision;
    }

    public function formatEnrolment(stdClass &$enrolment, bool $loadPlan = false): stdClass
    {
        $enrolment->start_date = $enrolment->start_date ? DateTimeHelper::atom($enrolment->start_date, DATE_ISO8601) : null;
        $enrolment->end_date = $enrolment->end_date ? DateTimeHelper::atom($enrolment->end_date, DATE_ISO8601) : null;

        if (!empty($enrolment->data) && is_string($enrolment->data)) {
            $enrolment->data = json_decode($enrolment->data);
        }

        return $enrolment;
    }

    /**
     * @throws DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function restore(int $revisionId, int $actorId): void
    {
        $revisionTree = $this->loadRevisionTree($revisionId);
        $embedded = $this->publishingService->enrolmentEventEmbedder()->embedded($revisionTree);
        $this->publishingService->embedPortalAccount($revisionTree->user_id, $embedded);
        DB::transactional($this->write->get(), function (Connection $db) use ($revisionTree, $embedded, $actorId) {
            $this->restoreRevisionTree($db, $revisionTree, $embedded, $actorId);
        });

        $this->queue->batchDone();
    }

    /**
     * @throws Exception
     */
    private function restoreRevisionTree(Connection $db, stdClass $revisionTree, array $embedded, int $actorId): void
    {
        $db->insert('gc_enrolment', $payload = [
            'id'                  => $revisionTree->enrolment_id,
            'profile_id'          => $revisionTree->profile_id,
            'user_id'             => $revisionTree->user_id,
            'parent_lo_id'        => $revisionTree->parent_lo_id,
            'parent_enrolment_id' => (int)$revisionTree->parent_enrolment_id,
            'lo_id'               => $revisionTree->lo_id,
            'instance_id'         => 0,
            'taken_instance_id'   => $revisionTree->taken_instance_id,
            'status'              => $revisionTree->status,
            'start_date'          => $revisionTree->start_date,
            'end_date'            => $revisionTree->end_date,
            'result'              => $revisionTree->result,
            'pass'                => $revisionTree->pass,
            'changed'             => DateTime::createFromFormat('U', $revisionTree->timestamp)->format(Constants::DATE_MYSQL),
            'timestamp'           => $revisionTree->timestamp,
            'data'                => $revisionTree->data ?: '',
        ]);

        $db->insert('enrolment_stream', [
            'portal_id'    => $revisionTree->taken_instance_id,
            'action'       => 'restore',
            'created'      => time(),
            'enrolment_id' => $revisionTree->enrolment_id,
            'payload'      => json_encode($payload + ['revision_id' => $revisionTree->id]),
            'actor_id'     => $actorId
        ]);

        # Publishing enrolment.create event.
        {
            $enrolment = parent::load($revisionTree->enrolment_id);
            if ($lo = LoHelper::load($db, $enrolment->lo_id)) {
                $embedded['lo'] = $lo;
            }

            $enrolment->embedded = $embedded;
            $this->queue->batchAdd(
                (array) $enrolment,
                Queue::ENROLMENT_CREATE,
                [
                    MqClient::CONTEXT_ACTOR_ID    => $actorId,
                    MqClient::CONTEXT_PORTAL_NAME => $revisionTree->taken_instance_id,
                    MqClient::CONTEXT_ENTITY_TYPE => 'enrolment',
                    'restore'                     => true,
                    'notify_email'                => false,
                ]
            );
        }

        # Restore child enrolments.
        if (!empty($revisionTree->items)) {
            foreach ($revisionTree->items as $item) {
                $this->restoreRevisionTree($db, $item, $embedded, $actorId);
            }
        }
    }

    public function loadLastCompletedRevision(int $takenPortalId, int $uid, int $loId): ?stdClass
    {
        $q = $this->read->get()
            ->createQueryBuilder()
            ->select('*')
            ->from('gc_enrolment_revision')
            ->add('orderBy', 'id DESC')
            ->setMaxResults(1)
            ->setFirstResult(0)
            ->where('lo_id = :lo_id')
            ->setParameter(':lo_id', $loId)
            ->andWhere('user_id = :user_id')
            ->setParameter(':user_id', $uid)
            ->andWhere('taken_instance_id = :portal_id')
            ->setParameter(':portal_id', $takenPortalId)
            ->andWhere('status = :status')
            ->setParameter(':status', EnrolmentStatuses::COMPLETED);

        return $q->execute()->fetch(DB::OBJ) ?: null;
    }
}
