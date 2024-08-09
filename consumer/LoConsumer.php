<?php

namespace go1\enrolment\consumer;

use go1\clients\MqClient;
use go1\enrolment\domain\etc\EnrolmentCalculator;
use go1\util\contract\ServiceConsumerInterface;
use go1\util\lo\LoTypes;
use go1\util\queue\Queue;
use stdClass;

class LoConsumer implements ServiceConsumerInterface
{
    private EnrolmentCalculator $calculator;
    private MqClient            $queue;
    private const LI_LINK_CHANGE = 'li_link_change';

    public function __construct(
        EnrolmentCalculator $calculator,
        MqClient $queue
    ) {
        $this->calculator = $calculator;
        $this->queue = $queue;
    }

    public function aware(): array
    {
        return [
            Queue::LO_UPDATE    => 'Fix invalid enrolments',
            Queue::DO_ENROLMENT => 'Fix invalid enrolments',
        ];
    }

    public function consume(string $routingKey, stdClass $lo, stdClass $context = null): bool
    {
        $doNext = false;
        switch ($routingKey) {
            case Queue::LO_UPDATE:
                if (
                    $lo->type == LoTypes::COURSE &&
                    isset($lo->original) &&
                    $this->isLoPublishing($lo, $lo->original) &&
                    ($enrolments = $this->calculator->getInvalidEnrolments($lo->id))
                ) {
                    $this->calculator->fixInvalidEnrolments($enrolments);
                    $doNext = true;
                }
                break;

            case Queue::DO_ENROLMENT:
                if (
                    ($lo->action == self::LI_LINK_CHANGE) &&
                    ($enrolments = $this->calculator->getInvalidEnrolments($lo->id))
                ) {
                    $this->calculator->fixInvalidEnrolments($enrolments);
                    $doNext = true;
                }
                break;
        }

        if ($doNext) {
            $event = [
                'action' => self::LI_LINK_CHANGE,
                'id'     => $lo->id,
            ];
            $this->queue->publish($event, Queue::DO_ENROLMENT);
        }

        return true;
    }

    private function isLoPublishing(stdClass $lo, stdClass $originalLo): bool
    {
        return !$originalLo->published && $lo->published;
    }
}
