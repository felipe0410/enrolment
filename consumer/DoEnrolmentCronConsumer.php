<?php

namespace go1\enrolment\consumer;

use go1\enrolment\controller\CronController;
use go1\util\contract\ServiceConsumerInterface;
use go1\util\queue\Queue;
use stdClass;

class DoEnrolmentCronConsumer implements ServiceConsumerInterface
{
    private CronController $cron;

    public function __construct(CronController $cron)
    {
        $this->cron = $cron;
    }

    public function aware(): array
    {
        return [
            Queue::DO_ENROLMENT_CRON => 'Check expiring enrolments. Enable pending enrolments'
        ];
    }

    public function consume(string $routingKey, stdClass $body, stdClass $context = null): bool
    {
        switch ($body->task) {
            case CronController::TASK_CHECK_EXPIRING:
                $this->cron->checkExpiringEnrolments();
                break;

            case CronController::TASK_ENABLE_PENDING:
                $this->cron->enablePendingEnrolments();
                break;
        }

        return true;
    }
}
