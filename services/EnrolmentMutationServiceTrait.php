<?php

namespace go1\enrolment\services;

use go1\clients\MqClient;
use go1\enrolment\Constants;
use go1\enrolment\domain\ConnectionWrapper;
use go1\util\DateTime as DateTimeHelper;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\model\Enrolment;
use go1\util\queue\Queue;
use RuntimeException;
use stdClass;
use Swaggest\JsonDiff\JsonDiff;

use function is_scalar;
use function json_encode;
use function time;

/**
 * @property ConnectionWrapper $write
 * @property MqClient $queue
 */
trait EnrolmentMutationServiceTrait
{
    public function addInstructor($sourceId, $targetId)
    {
        $this->write->get()->insert('gc_ro', [
            'type'      => EdgeTypes::HAS_TUTOR_ENROLMENT_EDGE,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'weight'    => 0,
        ]);
    }

    public function addRevision(Enrolment $enrolment, string $note)
    {
        $this->createRevision($enrolment, null, $note);
    }

    private function createRevision(Enrolment $enrolment, Enrolment $original = null, string $note = '', bool $batch = false)
    {
        $hasModified = false;
        if (empty($original)) {
            $hasModified = true;
        } else {
            $properties = ['profileId', 'parentLoId', 'loId', 'instanceId', 'takenPortalId', 'startDate', 'endDate', 'status', 'result', 'pass'];
            foreach ($properties as $property) {
                if ($enrolment->{$property} != $original->{$property}) {
                    $hasModified = true;
                    break;
                }
            }
            $enrolment = $original;
        }

        if ($hasModified) {
            // Check if queueing service is available
            $queueAvailable = $this->queue->isAvailable();
            if (!$queueAvailable) {
                throw new RuntimeException("Queue not available");
            }
            $this->write->get()->insert(
                'gc_enrolment_revision',
                $enrolmentRevision = [
                    'enrolment_id'        => $enrolment->id,
                    'profile_id'          => $enrolment->profileId,
                    'user_id'             => $enrolment->userId,
                    'parent_lo_id'        => $enrolment->parentLoId,
                    'lo_id'               => $enrolment->loId,
                    'instance_id'         => $enrolment->instanceId,
                    'taken_instance_id'   => $enrolment->takenPortalId,
                    'start_date'          => isset($enrolment->startDate) ? DateTimeHelper::atom($enrolment->startDate, Constants::DATE_MYSQL) : null,
                    'end_date'            => isset($enrolment->endDate) ? DateTimeHelper::atom($enrolment->endDate, Constants::DATE_MYSQL) : null,
                    'status'              => $enrolment->status,
                    'result'              => $enrolment->result,
                    'pass'                => $enrolment->pass,
                    'data'                => is_scalar($enrolment->data) ? $enrolment->data : json_encode($enrolment->data),
                    'note'                => $note,
                    'parent_enrolment_id' => $enrolment->parentEnrolmentId,
                    'timestamp'           => time(),
                ]
            );

            $enrolmentRevision['id'] = $this->write->get()->lastInsertId('gc_enrolment_revision');
            $embedded = $this->publishingService->enrolmentEventEmbedder()->embedded((object) $enrolmentRevision);
            $this->publishingService->embedPortalAccount($enrolment->userId, $embedded);

            $enrolmentRevision['embedded'] = $embedded;
            $this->queue->batchAdd($enrolmentRevision, Queue::ENROLMENT_REVISION_CREATE, [
                MqClient::CONTEXT_PORTAL_NAME => $enrolmentRevision['taken_instance_id'],
                MqClient::CONTEXT_ENTITY_TYPE => 'enrolment_revision',
            ]);
        }

        if (!$batch) {
            $this->queue->batchDone();
        }
    }

    public function createHasOriginalEnrolment(int $originalEnrolmentId, int $cloneEnrolmentId)
    {
        if (!EdgeHelper::hasLink($this->write->get(), EdgeTypes::HAS_ORIGINAL_ENROLMENT, $cloneEnrolmentId, $originalEnrolmentId)) {
            $this->write->get()->insert('gc_ro', [
                'type'      => EdgeTypes::HAS_ORIGINAL_ENROLMENT,
                'source_id' => $cloneEnrolmentId,
                'target_id' => $originalEnrolmentId,
                'weight'    => 0,
            ]);
        }
    }

    private function commit(Enrolment $origin, Enrolment $revision, int $actorId)
    {
        # commit changes into stream table
        # ---------------------
        $new = $revision->jsonSerialize();
        if (isset($new['data']->history)) {
            unset($new['data']->history);
        }
        $diff = new JsonDiff($origin->jsonSerialize(), $new);
        $diff = array_map(
            function (stdClass $patch) {
                if (in_array($patch->path, ['/changed'])) {
                    return null;
                }

                if (0 === strpos($patch->path, '/data/history')) {
                    return null;
                }

                return $patch;
            },
            $diff->getPatch()->jsonSerialize()
        );

        $diff = array_values(array_filter($diff));
        if ($diff) {
            $this->write->get()->insert('enrolment_stream', [
                'portal_id' => $origin->takenPortalId,
                'enrolment_id' => $origin->id,
                'created' => time(),
                'action' => 'update',
                'payload' => json_encode($diff),
                'actor_id' => $actorId
            ]);
        }
    }

    public function linkPlan(int $planId, int $enrolmentId, bool $isPublished = true, bool $batch = false)
    {
        $this->write->get()->insert('gc_enrolment_plans', [
            'enrolment_id' => $enrolmentId,
            'plan_id'      => $planId,
        ]);

        if ($isPublished) {
            // Check if queueing service is available
            $queueAvailable = $this->queue->isAvailable();
            if (!$queueAvailable) {
                throw new RuntimeException("Queue not available");
            }
            $body = [
                'id'        => time(),
                'type'      => EdgeTypes::HAS_PLAN,
                'source_id' => $enrolmentId,
                'target_id' => $planId,
                'weight'    => 0,
                'data'      => '{}',
            ];

            if ($batch) {
                $this->queue->batchAdd($body, Queue::RO_CREATE);
            } else {
                $this->queue->publish($body, Queue::RO_CREATE);
            }
        }
    }

    public function unlinkPlan(int $planId, int $enrolmentId)
    {
        // Check if queueing service is available
        $queueAvailable = $this->queue->isAvailable();
        if (!$queueAvailable) {
            throw new RuntimeException("Queue not available");
        }
        $this->write->get()->delete('gc_enrolment_plans', [
            'enrolment_id' => $enrolmentId,
            'plan_id'      => $planId,
        ]);

        $this->queue->publish([
            'id'        => time(),
            'type'      => EdgeTypes::HAS_PLAN,
            'source_id' => $enrolmentId,
            'target_id' => $planId,
            'weight'    => 0,
            'data'      => '{}',
        ], Queue::RO_DELETE);
    }

    public function linkAssign($assigner, $enrolmentId)
    {
        $assignerId = $assigner->id ?? $assigner;

        if (!EdgeHelper::hasLink($this->write->get(), EdgeTypes::HAS_ASSIGN, $assignerId, $enrolmentId)) {
            $this->write->get()->insert('gc_ro', [
                'type'      => EdgeTypes::HAS_ASSIGN,
                'source_id' => $assignerId,
                'target_id' => $enrolmentId,
                'weight'    => 0,
            ]);
        }
    }
}
