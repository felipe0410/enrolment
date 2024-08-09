<?php

namespace go1\enrolment\services;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Types\Types;
use Exception;
use go1\core\util\DateTime;
use go1\enrolment\controller\create\LTIConsumerClient;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\lo\LoService;
use go1\util\DateTime as DateTimeHelper;
use go1\util\DB;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\lo\LoHelper;
use go1\util\lo\LoTypes;
use go1\util\model\Enrolment;
use go1\util\AccessChecker;
use go1\util\plan\PlanTypes;
use GuzzleHttp\Exception\GuzzleException;
use PDO;
use stdClass;

/**
 * @property ConnectionWrapper $read
 * @property LoService $loService
 * @property UserService $userService
 * @property LTIConsumerClient $ltiConsumerClient
 * @property AccessChecker $accessChecker
 */
trait EnrolmentQueryServiceTrait
{
    public function load(int $id, bool $loadPlan = false): ?stdClass
    {
        $enrolment = $this
            ->read->get()
            ->executeQuery('SELECT * FROM gc_enrolment WHERE id = ?', [$id])
            ->fetch(DB::OBJ);
        return $enrolment ? $this->formatEnrolment($enrolment, $loadPlan) : null;
    }

    public function loadEnrolmentTree(stdClass $enrolment, int $includeLTIRegistrations = 0): stdClass
    {
        $enrolment->items = [];
        $this->formatEnrolment($enrolment);

        if ($enrolment->lo_type != LoTypes::COURSE) {
            return $this->_loadEnrolmentTree($enrolment, $includeLTIRegistrations);
        }

        $childEnrolments = $this->getChildEnrolments($enrolment->id);

        $liEnrolments = [];
        foreach ($childEnrolments as $childEnrolment) {
            // put module-enrolments into $enrolment->items
            if ($childEnrolment->parent_enrolment_id == $enrolment->id) {
                $enrolment->items[] = $childEnrolment;
            } else {
                $liEnrolments[] = $childEnrolment;
            }
        }

        // load due-date, lo_type and original enrolment edge before format, so that we can avoid N+1 query there
        {
            // Get all enrolment ids
            $enrolmentIds = array_unique(array_merge(
                array_map(fn ($item) => (int) $item->id, $enrolment->items ?? []),
                array_map(fn ($item) => (int) $item->id, $liEnrolments ?? [])
            ));

            $enrolmentLoIds = array_unique(array_merge(
                array_map(fn ($item) => (int) $item->lo_id, $enrolment->items ?? []),
                array_map(fn ($item) => (int) $item->lo_id, $liEnrolments ?? [])
            ));

            $dueDatesForEnrolments = $this->getDueDatesForEnrolments($enrolmentIds);
            $loTypes = $this->getLoTypes($enrolmentLoIds);
            $originalEnrolmentIds = $this->getOriginalEnrolmentIdsForEnrolments($enrolmentIds);

            foreach ($enrolment->items as &$item) {
                $item->due_date = $dueDatesForEnrolments[$item->id] ?? null;
                $item->lo_type = $loTypes[$item->lo_id] ?? null;
                $item->original_enrolment_id = $originalEnrolmentIds[$item->id] ?? null;
            }

            foreach ($liEnrolments as &$item) {
                $item->due_date = $dueDatesForEnrolments[$item->id] ?? null;
                $item->lo_type = $loTypes[$item->lo_id] ?? null;
                $item->original_enrolment_id = $originalEnrolmentIds[$item->id] ?? null;
            }
        }

        // format enrolments
        foreach ($enrolment->items as &$item) {
            $this->formatEnrolment($item);
        }

        foreach ($liEnrolments as &$item) {
            $this->formatEnrolment($item);
        }

        // Put LI enrolments into $enrolment->items[x]->items
        $liEnrolmentsFromModuleId = [];
        foreach ($liEnrolments as &$liEnrolment) {
            if ($liEnrolment->lo_type == LiTypes::LTI) {
                if ($includeLTIRegistrations) {
                    // LTI registration -- @TODO: Batch loading
                    $registrations = $this->ltiConsumerClient->getLTIRegistration($liEnrolment);
                    if ($registrations->getStatusCode() === 200) {
                        $liEnrolment->registrations = json_decode($registrations->getContent());
                    }
                }
            }
            $moduleId = $liEnrolment->parent_enrolment_id;
            if (empty($liEnrolmentsFromModuleId[$moduleId])) {
                $liEnrolmentsFromModuleId[$moduleId] = [];
            }
            $liEnrolmentsFromModuleId[$moduleId][] = $liEnrolment;
        }

        foreach (array_keys($enrolment->items) as $i) {
            $moduleId = $enrolment->items[$i]->id;
            $enrolment->items[$i]->items = $liEnrolmentsFromModuleId[$moduleId] ?? [];
        }

        return $enrolment;
    }

    /**
     * @param stdClass $enrolment
     * @param int      $includeLTIRegistrations
     * @deprecated
     */
    private function _loadEnrolmentTree(stdClass $enrolment, int $includeLTIRegistrations = 0)
    {
        $q = $this->read->get()->executeQuery('SELECT * FROM gc_enrolment WHERE parent_enrolment_id = ?', [$enrolment->id]);

        if ($enrolment->lo_type == LiTypes::LTI) {
            if ($includeLTIRegistrations) {
                if ($enrolment->lo_type === LiTypes::LTI) {
                    $registrations = $this->ltiConsumerClient->getLTIRegistration($enrolment);
                    if ($registrations->getStatusCode() === 200) {
                        $enrolment->registrations = json_decode($registrations->getContent());
                    }
                }
            }
        }

        while ($raw = $q->fetch(DB::OBJ)) {
            $childEnrolment = $this->loadEnrolmentTree($raw, $includeLTIRegistrations);
            if ($childEnrolment) {
                $enrolment->items[] = $childEnrolment;
            }
        }

        return $enrolment;
    }

    /**
     * @throws GuzzleException
     */
    public function formatSlimEnrollmentResponse(?stdClass $enrolment): ?stdClass
    {
        if ($enrolment) {
            $responseData = new stdClass();
            $responseData->id = (string) $enrolment->id;
            $responseData->enrollment_type = $enrolment->enrollment_type;
            if (!isset($enrolment->user_account_id)) {
                $account = $this->userService->findAccountWithPortalAndUser($enrolment->taken_instance_id, $enrolment->user_id);
                $responseData->user_account_id = (string) $account->data[0]->_gc_user_account_id;
            } else {
                $responseData->user_account_id = (string) $enrolment->user_account_id;
            }
            $responseData->lo_id = (string) $enrolment->lo_id;
            if ($enrolment->parent_enrolment_id) {
                $responseData->parent_enrollment_id = (string) $enrolment->parent_enrolment_id;
            }
            if (isset($enrolment->assigner_user_id)) {
                $assignerAccount = $this->userService->findAccountWithPortalAndUser($enrolment->taken_instance_id, $enrolment->assigner_user_id);
                // If assigner still exists in the portal then only returns
                if (!empty($assignerAccount->data)) {
                    $responseData->assigner_account_id = (string) $assignerAccount->data[0]->_gc_user_account_id;
                }
            }
            if (isset($enrolment->assign_date)) {
                $responseData->assign_date = DateTimeHelper::atom($enrolment->assign_date);
            }
            $responseData->created_time = DateTimeHelper::atom($enrolment->timestamp);
            $responseData->updated_time = isset($enrolment->changed) ? DateTimeHelper::atom($enrolment->changed) : null;
            $responseData->status = $enrolment->status;
            $responseData->result = (int)$enrolment->result;
            $responseData->pass = (bool)$enrolment->pass;

            if (isset($enrolment->start_date)) {
                $responseData->start_date = DateTimeHelper::atom($enrolment->start_date);
            }
            if (isset($enrolment->end_date)) {
                $responseData->end_date = DateTimeHelper::atom($enrolment->end_date);
            }
            if (isset($enrolment->due_date)) {
                $responseData->due_date = DateTimeHelper::atom($enrolment->due_date);
            }
            return $responseData;
        }
        return $enrolment;
    }

    public function formatEnrolment(stdClass &$enrolment, bool $loadPlan = false): stdClass
    {
        $enrolment->start_date = $enrolment->start_date ? DateTimeHelper::atom($enrolment->start_date, DATE_ISO8601) : null;
        $enrolment->end_date = $enrolment->end_date ? DateTimeHelper::atom($enrolment->end_date, DATE_ISO8601) : null;
        $enrolment->changed = $enrolment->changed ? DateTimeHelper::atom($enrolment->changed, DATE_ISO8601) : null;
        $enrolment->enrollment_type = EnrolmentOriginalTypes::SELF_DIRECTED;

        // $enrolment->due_date can be null -- isset() & empty() can't check that.
        // @see https://3v4l.org/1J54W
        if (!property_exists($enrolment, 'due_date')) {
            if ($loadPlan) {
                $this->addPlanDetails($enrolment);
            } else {
                $dueDate = EnrolmentHelper::dueDate($this->read->get(), $enrolment->id);
                $enrolment->due_date = $dueDate ? $dueDate->format(DATE_ISO8601) : null;
            }
        }

        if (!property_exists($enrolment, 'lo_type')) {
            $enrolment->lo_type = $this->read->get()->fetchColumn('SELECT type FROM gc_lo WHERE id = ?', [$enrolment->lo_id]) ?: null;
        }

        if (!property_exists($enrolment, 'original_enrolment_id')) {
            $edge = EdgeHelper::edgesFromSource($this->read->get(), $enrolment->id, [EdgeTypes::HAS_ORIGINAL_ENROLMENT]);
            $enrolment->original_enrolment_id = $edge ? $edge[0]->target_id : null;
        }

        if (!empty($enrolment->data) && is_scalar($enrolment->data)) {
            $enrolment->data = json_decode($enrolment->data);
        }
        // we keep it empty for child enrolments
        if ($enrolment->parent_enrolment_id) {
            $enrolment->enrollment_type = '';
        }

        return $enrolment;
    }

    /*
     * Attach plan details to the provided enrolments
     */
    private function addPlanDetails(stdClass $enrolment)
    {
        $q = $this->read->get()->createQueryBuilder();
        $plan = $q
            ->select('gp.due_date, gp.assigner_id, gp.created_date')
            ->from('gc_enrolment_plans', 'gcp')
            ->join('gcp', 'gc_plan', 'gp', 'gp.id = gcp.plan_id')
            ->where('gcp.enrolment_id = :id')->setParameter(':id', $enrolment->id, DB::INTEGER)
            ->orderBy('gp.type')
            ->execute()
            ->fetch(DB::OBJ);
        if ($plan) {
            $enrolment->due_date = $plan->due_date ? DateTimeHelper::create($plan->due_date)->format(DATE_ISO8601) : null;
            $enrolment->assigner_user_id = $plan->assigner_id ?? $enrolment->user_id;
            $enrolment->assign_date = DateTimeHelper::create($plan->created_date)->format(DATE_ISO8601);
            $enrolment->enrollment_type = EnrolmentOriginalTypes::ASSIGNED;
        } else {
            $enrolment->due_date = null;
        }
    }

    /**
     * The enrolment status of LO can only be completed if all the children are completed.
     *
     * @param Enrolment $enrolment
     * @return bool
     * @throws Exception
     */
    public function childrenCompleted(Enrolment $enrolment): bool
    {
        $loId = $enrolment->loId;
        if (!$children = $this->loService->children($loId)) {
            return false;
        }

        $electiveNumber = $this->electiveNumber($loId);
        $completedNonElective = 0;
        $completedElective = 0;
        $childrenIds = $this->childrenIds($children);
        $completedEnrolments = $this->completedEnrolments($childrenIds, $enrolment);
        $completedEvent = [];

        foreach ($completedEnrolments as $completedLoId) {
            if (LiTypes::EVENT === $completedLoId['type']) {
                $completedEvent[] = $completedLoId;
            } else {
                $this->countElectiveAndNonElectiveLo($completedLoId['lo_id'], $children, $completedNonElective, $completedElective);
            }
        }

        $isCompleted = ($completedElective >= $electiveNumber);
        if (!empty($children['non_elective'])) {
            $isCompleted &= ($completedNonElective === count($children['non_elective']));
        }

        if ((count($children['events']) > 0) && count($completedEvent) < 1) {
            $isCompleted = false;
        }

        // new event requirement
        $lo = LoHelper::load($this->read->get(), $enrolment->loId);
        $totalEvents = count($children['events']);
        if (LoTypes::MODULE === $lo->type && ($totalEvents > 0) && ($totalEvents > count($completedEvent))) {
            $isCompleted = false;
        }

        return $isCompleted;
    }

    private function getChildEnrolments(int $enrolmentId): array
    {
        return $this->read->get()->executeQuery(
            'SELECT moduleEnrolment.*, gc_lo.type as lo_type, gc_lo.published as lo_published
            FROM gc_enrolment moduleEnrolment
            INNER JOIN gc_lo ON moduleEnrolment.lo_id = gc_lo.id  
            WHERE moduleEnrolment.parent_enrolment_id = ?
            UNION 
            SELECT liEnrolment.*, gc_lo.type as lo_type, gc_lo.published as lo_published
            FROM gc_enrolment liEnrolment
            INNER JOIN gc_lo ON liEnrolment.lo_id = gc_lo.id
            INNER JOIN gc_enrolment moduleEnrolment ON liEnrolment.parent_enrolment_id = moduleEnrolment.id
            WHERE moduleEnrolment.parent_enrolment_id = ?',
            [$enrolmentId, $enrolmentId],
            [DB::INTEGER, DB::INTEGER]
        )->fetchAll(DB::OBJ);
    }

    private function getLoTypes(array $loIds): array
    {
        if (!$loIds) {
            return [];
        }

        $q = $this->read->get()->createQueryBuilder();
        $results = $q
            ->select('id', 'type')
            ->from('gc_lo')
            ->where($q->expr()->in('id', ':loIds'))
            ->setParameter('loIds', $loIds, DB::INTEGERS)
            ->execute()
            ->fetchAll(DB::OBJ);

        $loTypes = [];
        foreach ($results as $lo) {
            $loTypes[$lo->id] = $lo->type;
        }
        return $loTypes;
    }

    private function getDueDatesForEnrolments(array $enrolmentIds): array
    {
        if (!$enrolmentIds) {
            return [];
        }

        $q = $this->read->get()->createQueryBuilder();
        $expr = $q->expr();
        $plans = $q
            ->select(
                'ep.enrolment_id as `enrolment_id`',
                'p.type as `type`',
                'p.due_date as `due_date`'
            )
            ->from('gc_plan', 'p')
            ->innerJoin('p', 'gc_enrolment_plans', 'ep', $expr->eq('p.id', 'ep.plan_id'))
            ->where($expr->in('ep.enrolment_id', ':enrolmentIds'))
            ->setParameter('enrolmentIds', $enrolmentIds, DB::INTEGERS)
            ->execute()
            ->fetchAll(DB::OBJ);

        $assignedDueDates = [];
        $defaultDueDates = [];
        $enrolmentDueDates = [];

        foreach ($plans as $plan) {
            $dueDate = !$plan->due_date ? null : DateTime::atom($plan->due_date, DATE_ISO8601);
            if ($dueDate) {
                if (((int) $plan->type) === PlanTypes::ASSIGN) {
                    $assignedDueDates[$plan->enrolment_id] = $dueDate;
                } else {
                    $defaultDueDates[$plan->enrolment_id] = $dueDate;
                }
            }
        }
        foreach ($enrolmentIds as $enrolmentId) {
            $enrolmentDueDates[$enrolmentId] = $assignedDueDates[$enrolmentId] ?? $defaultDueDates[$enrolmentId] ?? null;
        }
        return $enrolmentDueDates;
    }

    private function getOriginalEnrolmentIdsForEnrolments(array $enrolmentIds): array
    {
        if (!$enrolmentIds) {
            return [];
        }

        $originalEnrolmentEdges = EdgeHelper::edgesFromSources($this->read->get(), $enrolmentIds, [EdgeTypes::HAS_ORIGINAL_ENROLMENT]);
        $originalEnrolmentIds = [];
        foreach ($originalEnrolmentEdges as $edge) {
            $originalEnrolmentIds[$edge->source_id] = $edge->target_id;
        }
        return $originalEnrolmentIds;
    }

    public function loadByLoAndUserId(int $loId, int $userId)
    {
        return $this
            ->read->get()
            ->executeQuery('SELECT * FROM gc_enrolment WHERE lo_id = ? AND user_id = ?', [$loId, $userId])
            ->fetch(DB::OBJ);
    }

    /**
     * @throws DBALException
     */
    public function loadAllByLoAndStatus(int $loId, ?string $status, ?int $limit = 1): array
    {
        $q = $this->read->get()->createQueryBuilder();
        $q->select(
            'id',
            'parent_lo_id',
            'parent_enrolment_id',
            'lo_id',
            'taken_instance_id',
            'start_date',
            'end_date',
            'status',
            'result',
            'pass',
            'timestamp',
            'user_id'
        )
            ->from('gc_enrolment')
            ->where('lo_id = :loId')
            ->setParameter(':loId', $loId);
        if ($status) {
            $q->andWhere('status = :status')
                ->setParameter(':status', $status);
        }
        $q->setMaxResults($limit);
        return $q->execute()->fetchAll(DB::OBJ);
    }

    /**
     * Sometime enrolment get deleted but requires enrolment for loading it dependencies like revision. This is a temporary solution until we release the unified solution
     * This function load enrolment from revision table instead of the gc_enrolment table, It must not be used for exposing the enrolment details to outside this service.
     *
     * @param int $loId
     * @param int $userId
     * @return mixed[]
     * @throws \Doctrine\DBAL\Exception
     */
    public function loadEnrolmentFromRevision(int $loId, int $userId): ?stdClass
    {
        return $this
            ->read->get()
            ->executeQuery('SELECT * FROM gc_enrolment_revision WHERE lo_id = ? AND user_id = ?', [$loId, $userId], [DB::INTEGER, DB::INTEGER])
            ->fetch(DB::OBJ) ?: null;
    }

    public function loadByLoAndUserAndTakenInstanceId(int $loId, int $userId, int $takenInstanceId, ?int $parentEnrolmentId = null)
    {
        $q = $this->read->get()->createQueryBuilder()
            ->from('gc_enrolment')
            ->select('*')
            ->andWhere('lo_id = :loId')
            ->andWhere('user_id = :userId')
            ->andWhere('taken_instance_id = :portalId')
            ->setParameters([
                ':loId'     => $loId,
                ':userId'   => $userId,
                ':portalId' => $takenInstanceId,
            ]);

        if (!is_null($parentEnrolmentId)) {
            $q
                ->andWhere('parent_enrolment_id = :parentEnrolmentId')
                ->setParameter(':parentEnrolmentId', $parentEnrolmentId);
        }

        return $q->execute()->fetch(DB::OBJ);
    }

    public function status($id)
    {
        return $this->read->get()->fetchColumn('SELECT status FROM gc_enrolment WHERE id = ?', [$id]);
    }

    public function pass($id)
    {
        return $this->read->get()->fetchColumn('SELECT pass FROM gc_enrolment WHERE id = ?', [$id]);
    }

    public function getAssigner($enrolmentId)
    {
        $sql = 'SELECT source_id FROM gc_ro WHERE type = ? AND target_id = ?';
        $id = $this->read->get()->executeQuery($sql, [EdgeTypes::HAS_ASSIGN, $enrolmentId])->fetchColumn();

        if ($id) {
            return $this->userService->get($id, "users");
        }

        return false;
    }

    private function childrenIds($children)
    {
        $childrenIds = [];

        if (!empty($children['non_elective'])) {
            $childrenIds = array_merge($childrenIds, $children['non_elective']);
        }
        if (!empty($children['elective'])) {
            $childrenIds = array_merge($childrenIds, $children['elective']);
        }
        if (!empty($children['events'])) {
            $childrenIds = array_merge($childrenIds, $children['events']);
        }

        return $childrenIds;
    }

    public function loadRevisions($enrolmentId, $offset = 0, $limit = 50, $sort = 'DESC')
    {
        $q = $this->read->get()->createQueryBuilder();

        $revisions =
            $q
                ->select('*')
                ->from('gc_enrolment_revision')
                ->where('enrolment_id = :enrolment_id')
                ->setParameter('enrolment_id', $enrolmentId)
                ->orderBy('id', $sort)
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->execute()
                ->fetchAll(DB::OBJ);

        foreach ($revisions as &$revision) {
            if (!empty($revision->data)) {
                $revision->data = json_decode($revision->data);
            }
        }

        return $revisions;
    }

    public function revisions(int $loId, int $userId, int $offset = 0, int $limit = 50, $orderBy = 'start_date', $direction = 'DESC')
    {
        $q = $this->read->get()->createQueryBuilder();

        $revisions = $q
            ->select('*')
            ->from('gc_enrolment_revision')
            ->where('lo_id = :lo_id')
            ->andwhere('user_id = :user_id')
            ->andwhere('status = :status')
            ->setParameter('lo_id', $loId)
            ->setParameter('user_id', $userId)
            ->setParameter('status', EnrolmentStatuses::COMPLETED)
            ->orderBy($orderBy, $direction)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->execute()
            ->fetchAll(DB::OBJ);

        foreach ($revisions as &$revision) {
            $revision->start_date = $revision->start_date ? DateTimeHelper::atom($revision->start_date, DATE_ISO8601) : null;
            $revision->end_date = $revision->end_date ? DateTimeHelper::atom($revision->end_date, DATE_ISO8601) : null;
        }

        return $revisions;
    }

    private function completedEnrolments(array $childrenIds, Enrolment $enrolment): array
    {
        $q = $this->write->get()
            ->createQueryBuilder()
            ->select('gc_enrolment.lo_id, gc_lo.type')
            ->from('gc_enrolment')
            ->innerJoin('gc_enrolment', 'gc_lo', 'gc_lo', 'gc_enrolment.lo_id = gc_lo.id')
            ->where('gc_enrolment.lo_id IN (:ids)')->setParameter('ids', $childrenIds, DB::INTEGERS)
            ->andWhere('gc_enrolment.taken_instance_id = :takenPortalId')->setParameter('takenPortalId', $enrolment->takenPortalId, DB::INTEGER)
            ->andWhere('gc_enrolment.user_id = :user_id')->setParameter('user_id', $enrolment->userId)
            ->andWhere('gc_enrolment.status = :status')->setParameter('status', EnrolmentStatuses::COMPLETED);

        if ($enrolment->parentEnrolmentId) {
            $q
                ->andWhere('gc_enrolment.parent_enrolment_id = :parentEnrolmentId')
                ->setParameter(':parentEnrolmentId', $enrolment->id);
        }

        return $q->execute()->fetchAll();
    }

    public function findParentEnrolmentId(Enrolment $enrolment)
    {
        if ($enrolment->parentEnrolmentId) {
            return $enrolment->parentEnrolmentId;
        }

        if ($enrolment->parentLoId) {
            $parentEnrolment = EnrolmentHelper::findEnrolment(
                $this->read->get(),
                $enrolment->takenPortalId,
                $enrolment->userId,
                $enrolment->parentLoId
            );

            return $parentEnrolment ? $parentEnrolment->id : null;
        }

        return null;
    }

    private function countElectiveAndNonElectiveLo($loId, $children, &$nonElective, &$elective)
    {
        if (!empty($children['non_elective']) && in_array($loId, $children['non_elective'])) {
            $nonElective++;
        }

        if (!empty($children['elective']) && in_array($loId, $children['elective'])) {
            $elective++;
        }
    }

    /**
     * The enrolment result of LO can only be passed (1) if all the children are passed.
     *
     * @param Enrolment $enrolment
     * @return bool|int
     * @throws Exception
     */
    public function childrenPassed(Enrolment $enrolment)
    {
        $loId = $enrolment->loId;
        if (!$children = $this->loService->children($loId)) {
            return 0;
        }

        $electiveNumber = $this->electiveNumber($loId);
        $passedNonElective = 0;
        $passedElective = 0;
        $childrenIds = $this->childrenIds($children);
        $passedEnrolments = $this->passedEnrolments($childrenIds, $enrolment);
        $passedEvents = [];
        foreach ($passedEnrolments as $passedLoId) {
            if (LiTypes::EVENT === $passedLoId['type']) {
                $passedEvents[] = $passedLoId;
            } else {
                $this->countElectiveAndNonElectiveLo($passedLoId['lo_id'], $children, $passedNonElective, $passedElective);
            }
        }

        $isPassed = ($passedElective >= $electiveNumber);
        if (!empty($children['non_elective'])) {
            $isPassed &= ($passedNonElective === count($children['non_elective']));
        }

        $lo = LoHelper::load($this->read->get(), $enrolment->loId);
        $totalEvents = count($children['events']);
        if (LoTypes::MODULE === $lo->type && ($totalEvents > 0) && ($totalEvents > count($passedEvents))) {
            $isPassed = 0;
        }

        return $isPassed;
    }

    protected function passedEnrolments(array $childrenIds, Enrolment $enrolment): array
    {
        $q = $this->read->get()
            ->createQueryBuilder()
            ->select('gc_enrolment.lo_id, gc_lo.type')
            ->from('gc_enrolment')
            ->innerJoin('gc_enrolment', 'gc_lo', 'gc_lo', 'gc_enrolment.lo_id = gc_lo.id')
            ->where('gc_enrolment.lo_id IN (:ids)')->setParameter('ids', $childrenIds, DB::INTEGERS)
            ->andWhere('gc_enrolment.taken_instance_id = :takenPortalId')->setParameter('takenPortalId', $enrolment->takenPortalId, DB::INTEGER)
            ->andWhere('gc_enrolment.user_id = :user_id')->setParameter('user_id', $enrolment->userId)
            ->andWhere('gc_enrolment.pass = :pass')->setParameter('pass', 1);

        if ($enrolment->parentEnrolmentId) {
            $q
                ->andWhere('gc_enrolment.parent_enrolment_id = :parentEnrolmentId')
                ->setParameter(':parentEnrolmentId', $enrolment->id);
        }

        return $q->execute()->fetchAll();
    }

    protected function electiveNumber($loId)
    {
        $data = $this->read->get()->fetchColumn('SELECT data FROM gc_lo WHERE id = ?', [$loId]);
        $data = json_decode($data, true);

        return !empty($data['elective_number']) ? $data['elective_number'] : 0;
    }

    protected function getAssessmentResults(stdClass $courseEnrolment, array $types = []): array
    {
        if (empty($types)) {
            return [];
        }

        $parentIds = LoHelper::childIds($this->read->get(), $courseEnrolment->lo_id, true);
        $children = $this->read->get()
            ->executeQuery('SELECT id, type FROM gc_lo WHERE type IN (?) AND id IN (?)', [$types, $parentIds], [DB::STRINGS, DB::INTEGERS])
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $q = 'SELECT id, lo_id, pass, result FROM gc_enrolment WHERE lo_id IN (?) AND user_id = ? AND parent_lo_id IN (?)';
        $progress = $this->read->get()
            ->executeQuery($q, [array_keys($children), $courseEnrolment->user_id, $parentIds], [DB::INTEGERS, DB::INTEGER, DB::INTEGERS])
            ->fetchAll(DB::ARR);

        $progressLoIds = [];
        foreach ($progress as &$p) {
            if (isset($children[$p['lo_id']])) {
                $p['type'] = $children[$p['lo_id']];
            }
            $progressLoIds[$p['lo_id']] = true;
        }

        foreach ($children as $loId => $loType) {
            if (empty($progressLoIds[$loId])) {
                $progress[] = [
                    'id'     => null,
                    'lo_id'  => $loId,
                    'type'   => $loType,
                    'pass'   => 0,
                    'result' => 0,
                ];
            }
        }

        return $progress;
    }

    public function foundLink(int $planId, int $enrolmentId)
    {
        $found = $this->read->get()->fetchColumn('SELECT 1 FROM gc_enrolment_plans WHERE enrolment_id = ? AND plan_id = ?', [$enrolmentId, $planId]);

        return !!$found;
    }

    public function linkedPlanIds(int $enrolmentId)
    {
        $edges = $this->read->get()->executeQuery('SELECT plan_id FROM gc_enrolment_plans WHERE enrolment_id = ?', [$enrolmentId]);
        while ($planId = $edges->fetchColumn()) {
            yield $planId;
        }
    }

    public function findEnrolmentIds(int $planId): array
    {
        return $this->read->get()->executeQuery('SELECT enrolment_id FROM gc_enrolment_plans WHERE plan_id = ?', [$planId])->fetchAll(DB::COL);
    }

    public function findCourseId(Enrolment $enrolment, string $loType)
    {
        // Only supporting LI for now.
        if (in_array($loType, LoTypes::all())) {
            return null;
        }

        // Enrol on single LI, no course expecting
        if (!$enrolment->parentEnrolmentId) {
            return null;
        }

        $courseLoId = $this
            ->read->get()
            ->executeQuery(
                'SELECT courseEnrolment.lo_id FROM gc_enrolment courseEnrolment'
                . ' INNER JOIN gc_enrolment moduleEnrolment ON moduleEnrolment.parent_enrolment_id = courseEnrolment.id'
                . ' WHERE moduleEnrolment.id = ?',
                [$enrolment->parentEnrolmentId],
                [Types::INTEGER]
            )
            ->fetchColumn();

        return $courseLoId;
    }

    /**
     * Returns the value of a single column of the first row of the result
     *
     * @param int    $portalId
     * @param int    $userId
     * @param int    $entityId
     * @param string $entityType
     * @return mixed|false False is returned if no rows are found.
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function loadUserPlanIdByEntity(int $portalId, int $userId, int $entityId, string $entityType = 'lo')
    {
        return $this->read->get()
            ->createQueryBuilder()
            ->select('id')
            ->from('gc_plan')
            ->where('entity_type = :entityType')
            ->andWhere('entity_id = :entityId')
            ->andWhere('user_id = :userId')
            ->andWhere('instance_id = :portalId')
            ->setParameter(':entityType', $entityType, DB::STRING)
            ->setParameter(':entityId', $entityId, DB::INTEGER)
            ->setParameter(':portalId', $portalId, DB::INTEGER)
            ->setParameter(':userId', $userId, DB::INTEGER)
            ->execute()
            ->fetchColumn();
    }
}
