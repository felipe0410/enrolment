<?php

namespace go1\enrolment\consumer;

use go1\clients\MqClient;
use go1\enrolment\controller\staff\MergeAccountController;
use go1\enrolment\domain\etc\EnrolmentMergeAccount;
use go1\util\contract\ServiceConsumerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

use function is_numeric;

class MergeAccountConsumer implements ServiceConsumerInterface
{
    private EnrolmentMergeAccount $mergeAccount;
    private MqClient              $queue;
    private LoggerInterface       $logger;

    public function __construct(EnrolmentMergeAccount $mergeAccount, MqClient $queue, LoggerInterface $logger)
    {
        $this->mergeAccount = $mergeAccount;
        $this->queue = $queue;
        $this->logger = $logger;
    }

    public function aware(): array
    {
        return [
            EnrolmentMergeAccount::DO_ETC_MERGE_ACCOUNT => 'Merge account enrolments',
        ];
    }

    public function consume(string $routingKey, stdClass $body, stdClass $context = null): bool
    {
        if ($routingKey == EnrolmentMergeAccount::DO_ETC_MERGE_ACCOUNT) {
            switch ($body->action) {
                case MergeAccountController::TASK:
                    $portalId = $body->portal_id;
                    if (!is_numeric($portalId)) {
                        return true;
                    }

                    $this->merge($portalId, $body->from, $body->to, $context ? (array) $context : []);
                    break;

                case EnrolmentMergeAccount::MERGE_ACCOUNT_ACTION_ENROLMENT:
                    $this->mergeAccount->update($body, $context);
                    break;

                case EnrolmentMergeAccount::MERGE_ACCOUNT_ACTION_ENROLMENT_REVISION:
                    $this->logger->info('merge enrolment-revisions', [
                        'fromEmail' => $body->from,
                        'toEmail'   => $body->to,
                        'portalId'  => $body->portal_id,
                        'ctx'       => (array) $context,
                    ]);

                    $this->mergeAccount->updateRevision($body->from, $body->to, $body->portal_id);
                    break;
            }
        }

        return true;
    }

    private function merge(int $portalId, string $fromEmail, string $toEmail, array $ctx = null): void
    {
        $this->logger->info('merge start', [
            'fromEmail' => $fromEmail,
            'toEmail'   => $toEmail,
            'ctx'       => $ctx,
        ]);

        $enrolments = $this->mergeAccount->getEnrolments($fromEmail, $toEmail, $portalId);
        if ($enrolments) {
            foreach ($enrolments as $payload) {
                $this->logger->info('change enrolment ownership', [
                    'merge_action' => $payload->merge_action,
                    'loId'         => $payload->lo_id,
                    'userId'       => $payload->user_id,
                    'newUserId'    => $payload->new_user_id ?? null,
                    'ctx'          => $ctx,
                ]);

                $payload->action = EnrolmentMergeAccount::MERGE_ACCOUNT_ACTION_ENROLMENT;
                $this->queue->batchAdd($payload, EnrolmentMergeAccount::DO_ETC_MERGE_ACCOUNT, $ctx);
            }

            $this->queue->batchDone();
        }

        $task = [
            'action'    => EnrolmentMergeAccount::MERGE_ACCOUNT_ACTION_ENROLMENT_REVISION,
            'from'      => $fromEmail,
            'to'        => $toEmail,
            'portal_id' => $portalId,
        ];

        $this->queue->publish($task, EnrolmentMergeAccount::DO_ETC_MERGE_ACCOUNT, $ctx);
    }
}
