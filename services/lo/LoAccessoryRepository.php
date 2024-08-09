<?php

namespace go1\enrolment\services\lo;

use stdClass;
use Exception;
use Doctrine\DBAL\Connection;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\EnrolmentCreateOption;
use go1\core\learning_record\attribute\EnrolmentAttributeRepository;

class LoAccessoryRepository
{
    private ConnectionWrapper $write;
    private EnrolmentRepository $repository;
    private EnrolmentAttributeRepository $enrolmentAttribute;

    public function __construct(
        ConnectionWrapper $write,
        EnrolmentRepository $repository,
        EnrolmentAttributeRepository $enrolmentAttribute
    ) {
        $this->write = $write;
        $this->repository = $repository;
        $this->enrolmentAttribute = $enrolmentAttribute;
    }

    /**
     * @ref GO1P-7915
     * Add tutor to module enrolment
     *
     * @throws Exception
     */
    public function addEnrolmentInstructor(
        int $enrolmentId,
        int $moduleId,
        int $courseId
    ): void {
        $roId = $this->write->get()
            ->executeQuery(
                'SELECT id FROM gc_ro WHERE type IN (?) AND source_id = ?
                    AND target_id = ?',
                [
                    [EdgeTypes::HAS_ELECTIVE_LO, EdgeTypes::HAS_MODULE],
                    $courseId, $moduleId
                ],
                [Connection::PARAM_INT_ARRAY]
            )
            ->fetchColumn();

        if ($roId) {
            $tutorId = $this->write->get()
                ->fetchColumn(
                    "SELECT target_id FROM gc_ro WHERE type = ? AND source_id = ?",
                    [EdgeTypes::HAS_TUTOR_EDGE, $roId]
                );

            if ($tutorId) {
                $this->repository->addInstructor($enrolmentId, $tutorId);
            }
        }
    }

    /**
     * @ref GO1P-8609
     * If the related HAS_LO edge has expire time, create expiring record,
     * so that #rules can set the enrolment to be expired in future.
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function attachExpiringSchedule(
        EnrolmentCreateOption $option,
        int $enrolmentId
    ): void {
        $hasLoEdgeId = $this->write->get()
            ->executeQuery(
                'SELECT id FROM gc_ro WHERE type IN (?)
                    AND source_id = ? AND target_id = ?',
                [
                    EdgeTypes::LO_HAS_LO,
                    $option->parentLearningObjectId,
                    $option->learningObject->id
                ],
                [DB::INTEGERS, DB::INTEGER, DB::INTEGER]
            )
            ->fetchColumn();

        if ($hasLoEdgeId) {
            $cnf = $this->write->get()
                ->executeQuery(
                    'SELECT data FROM gc_ro WHERE type = ? AND source_id = ?
                        AND target_id = ?',
                    [
                        EdgeTypes::HAS_ENROLMENT_EXPIRATION,
                        $hasLoEdgeId,
                        $hasLoEdgeId
                    ]
                )
                ->fetchColumn();

            if ($cnf) {
                if ($cnf = json_decode($cnf)) {
                    $this->write->get()->insert('gc_ro', [
                        'type' => EdgeTypes::SCHEDULE_EXPIRE_ENROLMENT,
                        'source_id' => $enrolmentId,
                        'target_id' => strtotime($cnf->expiration),
                        'weight' => 0,
                    ]);
                }
            }
        }
    }

    /**
     * @ref GO1P-17419
     * Map transaction with new created enrolment if needed
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function addEnrolmentTransaction(
        int $enrolmentId,
        stdClass $transaction
    ): void {
        if (isset($transaction->id) && isset($transaction->payment_method)) {
            $this->write->get()->insert(
                'gc_enrolment_transaction',
                [
                    'enrolment_id' => $enrolmentId,
                    'transaction_id' => $transaction->id,
                    'payment_method' => $transaction->payment_method,
                ]
            );
        }
    }

    /**
     * @ref GO1P-30317
     * Support attributes when creating manual enrolment
     */
    public function createEnrolmentAttributes(int $enrolmentId, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            $attribute->enrolmentId = $enrolmentId;
            $this->enrolmentAttribute->create($attribute);
        }
    }
}
