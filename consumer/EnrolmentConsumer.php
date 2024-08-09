<?php

namespace go1\enrolment\consumer;

use go1\enrolment\domain\ConnectionWrapper;
use go1\clients\MqClient;
use go1\core\learning_record\enquiry\EnquiryRepository;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\Context;
use go1\util\contract\ServiceConsumerInterface;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\lo\LoHelper;
use go1\util\lo\LiTypes;
use go1\util\lo\LoTypes;
use go1\util\plan\Plan;
use go1\util\plan\PlanHelper;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanStatuses;
use go1\util\queue\Queue;
use stdClass;

class EnrolmentConsumer implements ServiceConsumerInterface
{
    private ConnectionWrapper   $db;
    private MqClient            $queue;
    private EnrolmentRepository $rEnrolment;
    private EnquiryRepository   $rEnquiry;
    private PlanRepository      $rPlan;

    public function __construct(
        ConnectionWrapper   $db,
        MqClient            $queue,
        EnrolmentRepository $rEnrolment,
        EnquiryRepository   $rEnquiry,
        PlanRepository      $rPlan
    ) {
        $this->db = $db;
        $this->queue = $queue;
        $this->rEnrolment = $rEnrolment;
        $this->rEnquiry = $rEnquiry;
        $this->rPlan = $rPlan;
    }

    public function aware(): array
    {
        return [
            Queue::ENROLMENT_CREATE => 'Create scheduled plan if suggested completion time specified in lo. Link plans to activate enrolment',
            Queue::ENROLMENT_UPDATE => "When module enrolment is completed, change status of related modules's enrolment to 'in-progress' if user completed all dependencies",
            Queue::ENROLMENT_DELETE => 'Archive related enquiries RO when archive the parent enrolment',
        ];
    }

    public function consume(string $routingKey, stdClass $body, ?stdClass $context = null): bool
    {
        switch ($routingKey) {
            case Queue::ENROLMENT_CREATE:
                $this->onCreate($body, $context);
                break;

            case Queue::ENROLMENT_UPDATE:
                $this->onUpdate($body);
                break;

            case Queue::ENROLMENT_DELETE:
                $this->onDelete($body);
                break;
        }

        return true;
    }

    private function onCreate(stdClass $enrolment, ?stdClass $context): void
    {
        if (
            ($lo = LoHelper::load($this->db->get(), $enrolment->lo_id))
            && isset($lo->data->{LoHelper::SUGGESTED_COMPLETION_TIME})
            && isset($lo->data->{LoHelper::SUGGESTED_COMPLETION_UNIT})
        ) {
            $assigneeId = $enrolment->user_id;
            $existingPlan = PlanHelper::loadByEntityAndUser($this->db->get(), Plan::TYPE_LO, $enrolment->lo_id, $assigneeId);
            if (!$existingPlan) {
                $dueDate = strtotime($lo->data->{LoHelper::SUGGESTED_COMPLETION_TIME} . ' ' . $lo->data->{LoHelper::SUGGESTED_COMPLETION_UNIT});
                if ($dueDate) {
                    $plan = Plan::create((object) [
                        'user_id'      => $assigneeId,
                        'assigner_id'  => $context->{MqClient::CONTEXT_ACTOR_ID} ?? null,
                        'instance_id'  => $enrolment->taken_instance_id,
                        'entity_type'  => Plan::TYPE_LO,
                        'entity_id'    => $enrolment->lo_id,
                        'status'       => PlanStatuses::SCHEDULED,
                        'due_date'     => $dueDate,
                        'created_date' => time(),
                        'data'         => null,
                    ]);

                    $plan = $this->rEnrolment->mergePlan(new Context('plan.create'), $plan);
                    $this->rEnrolment->linkPlan($plan->id, $enrolment->id);
                }
            }
        }

        // enrolment-plan edge created inside enrolment service.
    }

    private function onUpdate(stdClass $enrolment): void
    {
        $original = $enrolment->original;
        unset($enrolment->original);

        # When user complete a module-enrolment
        #   And the module is dependency of other modules
        #   We loop through related modules, if user has "pending" enrolments there
        #   On each module, if user already completed all dependencies.
        #   We change status to "in-progress".
        #   But, the number of module can be large, we queue messages to be processed later.
        if ($original && EnrolmentHelper::becomeCompleted($enrolment, $original, true)) {
            $lo = LoHelper::load($this->db->get(), $enrolment->lo_id);
            $loType = $lo ? $lo->type : null;
            if (LoTypes::MODULE == $loType) {
                $relatedModuleIds = $this->db->get()->executeQuery(
                    'SELECT source_id FROM gc_ro WHERE type = ? AND target_id = ?',
                    [EdgeTypes::HAS_MODULE_DEPENDENCY, $enrolment->lo_id],
                    [DB::INTEGER, DB::INTEGER]
                )->fetchAll(DB::COL);

                foreach ($relatedModuleIds as $relatedModuleId) {
                    $this->queue->publish(
                        [
                            'moduleId' => $relatedModuleId,
                            'userId' => $enrolment->user_id
                        ],
                        Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENTS
                    );
                }
            }
        }
    }

    private function onDelete(stdClass $enrolment): void
    {
        # Archive related enquiries RO when archive the parent enrolment
        $enquiries = $this->rEnquiry->findEnquiry($enrolment->lo_id, $enrolment->user_id, true);
        foreach ($enquiries as $enquiry) {
            $this->rEnquiry->archive($enquiry->id, $enrolment->id);
        }

        $this->db->get()->transactional(
            function () use ($enrolment) {
                $planIds = $this->rEnrolment->linkedPlanIds($enrolment->id);
                foreach ($planIds as $planId) {
                    $this->rPlan->archive($planId);
                    $this->rEnrolment->unlinkPlan($planId, $enrolment->id);
                }
            }
        );

        $lo = LoHelper::load($this->db->get(), $enrolment->lo_id);
        $liType = $lo ? $lo->type : null;
        if ($liType == LiTypes::QUESTION) {
            $this->queue->publish([
                'loId' => $enrolment->lo_id,
                'userId' => $enrolment->user_id
            ], Queue::QUIZ_QUESTION_RESULT_DELETE);
        }
    }
}
