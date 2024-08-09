<?php

namespace go1\enrolment\domain\etc;

use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\EnrolmentCreateService;
use go1\util\DB;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LoHelper;
use go1\util\model\Enrolment;
use stdClass;

/**
 * Calculate all the enrollments when the course structure changes
 * - The LI moved to other modules
 * - The LI removed from the module
 *
 * Class EnrolmentCalculator
 *
 * @package go1\enrolment\domain\etc
 */
class EnrolmentCalculator
{
    private ConnectionWrapper      $go1;
    private EnrolmentRepository    $repository;
    private EnrolmentCreateService $enrolmentCreateService;
    private array $course;

    public function __construct(
        ConnectionWrapper      $go1,
        EnrolmentRepository    $repository,
        EnrolmentCreateService $enrolmentCreateService
    ) {
        $this->go1 = $go1;
        $this->repository = $repository;
        $this->enrolmentCreateService = $enrolmentCreateService;
    }

    /**
     * LI enrolments are changed the parent_lo_id
     * LI enrolments are orphan
     *
     * @param int $loId
     * @return stdClass[]
     */
    public function getInvalidEnrolments(int $loId): array
    {
        $this->course = $this->buildCourse($loId);
        if (empty($this->course['module']) || empty($this->course['li'])) {
            return [];
        }

        // LI enrolments are changed the parent_lo_id or parent_enrolment_id is 0
        foreach ($this->course['module'] as $moduleId => $liIds) {
            $result = $this->go1->get()->executeQuery(
                'SELECT * FROM gc_enrolment WHERE lo_id IN (?) AND parent_lo_id <> ? AND parent_lo_id <> 0 LIMIT 50',
                [$liIds, $moduleId],
                [DB::INTEGERS, DB::INTEGER]
            );
            if ($records = $result->fetchAll(DB::OBJ)) {
                return array_filter($records, function ($record) use ($loId) {
                    $_ = Enrolment::create($record);
                    if ($courseEnrolment = EnrolmentHelper::parentEnrolment($this->go1->get(), $_)) {
                        return $courseEnrolment->loId == $loId;
                    }

                    return false;
                });
            }
        }

        // LI enrolments are orphan
        $result = $this->go1->get()->executeQuery(
            'SELECT * FROM gc_enrolment WHERE parent_lo_id IN (?) AND lo_id NOT IN (?) LIMIT 50',
            [array_keys($this->course['module']), $this->course['li']],
            [DB::INTEGERS, DB::INTEGERS]
        );
        if ($records = $result->fetchAll(DB::OBJ)) {
            return array_map(function (stdClass $record) {
                $record->archive = true;
                return $record;
            }, $records);
        }

        return [];
    }

    private function buildCourse(int $loId): array
    {
        $course = [
            'module' => [],
            'li'     => [],
        ];

        $moduleIds = LoHelper::moduleIds($this->go1->get(), $loId);
        foreach ($moduleIds as $moduleId) {
            $moduleId = intval($moduleId);
            $course['module'][$moduleId] = LoHelper::childIds($this->go1->get(), $moduleId);
            $course['li'] = array_merge($course['li'], $course['module'][$moduleId]);
        }
        return $course;
    }

    /**
     * Fixing the parent_lo_id enrolments
     * Fixing the orphan enrolments
     */
    public function fixInvalidEnrolments(array $enrolments): void
    {
        $db = $this->go1->get();
        foreach ($enrolments as $enrolment) {
            // Archive the orphan enrolments
            if (isset($enrolment->archive)) {
                $parentEnrolment = EnrolmentHelper::findEnrolment($db, $enrolment->taken_instance_id, $enrolment->user_id, $enrolment->parent_lo_id);
                if ($parentEnrolment) {
                    $this->updateParentEnrolmentStatus($parentEnrolment);
                }
                $this->repository->deleteEnrolment(Enrolment::create($enrolment), 0, false);

                continue;
            }

            // Update parent_lo_id, parent_enrolment_id
            if ($newModuleId = $this->findParentLoId($enrolment->lo_id)) {
                $oldModuleEnrolment = EnrolmentHelper::findEnrolment($db, $enrolment->taken_instance_id, $enrolment->user_id, $enrolment->parent_lo_id);
                if ($oldModuleEnrolment) {
                    $newModuleEnrolment = EnrolmentHelper::findEnrolment($db, $enrolment->taken_instance_id, $enrolment->user_id, $newModuleId);
                    $newModuleEnrolmentId = $newModuleEnrolment->id ?? 0;
                    if (!$newModuleEnrolmentId) {
                        // Create module enrolment
                        $newModuleEnrolment = clone $oldModuleEnrolment;
                        $newModuleEnrolment->id = null;
                        $newModuleEnrolment->loId = $newModuleId;
                        $newModuleEnrolment->status = ($enrolment->status == EnrolmentStatuses::COMPLETED) ? EnrolmentStatuses::IN_PROGRESS : $enrolment->status;
                        $newParentEnrolment = $this->enrolmentCreateService->create($newModuleEnrolment);
                        $newModuleEnrolmentId = $newParentEnrolment->enrolment->id;
                    } else {
                        // Archive the legacy duplicated enrolments
                        $newEnrolment = EnrolmentHelper::findEnrolment($db, $enrolment->taken_instance_id, $enrolment->user_id, $enrolment->lo_id, $newModuleEnrolmentId);
                        if ($newEnrolment) {
                            $this->repository->deleteEnrolment(Enrolment::create($enrolment), 0, false);
                            continue;
                        }
                    }

                    $this->repository->update(
                        $enrolment->id,
                        [
                            'parent_lo_id' => $newModuleId,
                            'parent_enrolment_id' => $newModuleEnrolmentId,
                            'note' => '#ETC LI moving re-calculation'
                        ],
                        false
                    );

                    // Update old module enrolment status
                    $this->updateParentEnrolmentStatus($oldModuleEnrolment);

                    // Update new module enrolment status
                    $newModuleEnrolment = EnrolmentHelper::load($db, $newModuleEnrolmentId);
                    if ($newModuleEnrolment) {
                        $this->updateParentEnrolmentStatus(Enrolment::create($newModuleEnrolment));
                    }
                }
            }
        }
    }

    private function findParentLoId(int $liId): ?int
    {
        foreach ($this->course['module'] as $moduleId => $item) {
            if (in_array($liId, $item)) {
                return $moduleId;
            }
        }
        return null;
    }

    private function updateParentEnrolmentStatus(Enrolment $enrolment): void
    {
        if (($enrolment->status != EnrolmentStatuses::COMPLETED) && $this->repository->childrenCompleted($enrolment)) {
            $this->repository->changeStatus(
                $enrolment,
                EnrolmentStatuses::COMPLETED,
                [
                    'action' => 'update-parent-enrolment',
                ]
            );
        }
    }
}
