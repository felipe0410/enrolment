<?php

use Psr\Log\LogLevel;
use go1\clients\UtilCoreClientServiceProvider;
use go1\core\learning_record\attribute\AttributeServiceProvider;
use go1\core\learning_record\enquiry\EnquiryServiceProvider;
use go1\core\learning_record\manual_record\ManualRecordServiceProvider;
use go1\core\learning_record\plan\PlanServiceProvider;
use go1\core\learning_record\revision\RevisionServiceProvider;
use go1\enrolment\MicroService;
use go1\util\DB;
use go1\util\Service;
use go1\util\UtilCoreServiceProvider;
use go1\util\UtilServiceProvider;
use PHPUnit\Framework\TestCase;

return call_user_func(function () {
    if (!defined('SERVICE_NAME')) {
        define('SERVICE_NAME', 'enrolment');
        define('SERVICE_VERSION', 'v22.7.8.1');
    }

    return [
            'env' => $env = getenv('ENV') ?: 'dev',
            'debug' => 'production' !== $env,
            'contentLearningUserMigration' => (bool) getenv('CONTENT_LEARNING_USER_MIGRATION'),
            'accounts_name' => Service::accountsName($env),
            'clientOptions' => [],
            'cacheOptions' => Service::cacheOptions(dirname(__DIR__)),
            'dbOptions' => [
                'go1' => ['go1', false, false],
                'go1.writer' => ['go1', false, true],
                'go1.reader' => ['go1', true, false],
                'enrolment' => ['enrolment', false, false],
                'social' => ['social', false, false],
                'group' => ['group', false, false]
            ],
            'esOptions' => [
                'endpoint' => getenv('ES_URL') ?: '',
                'credential' => getenv('ES_CREDENTIAL') ?: false,
            ],
            'logOptions' => ['name' => SERVICE_NAME, 'level' => LogLevel::WARNING],
            'queueOptions' => Service::queueOptions(),
            'legacy' => (getenv('LEGACY') !== false) ? (int) getenv('LEGACY') : 0,
            'serviceProviders' => [
                new AttributeServiceProvider(),
                new RevisionServiceProvider(),
                new MicroService(),
                new EnquiryServiceProvider(),
                new ManualRecordServiceProvider(),
                new PlanServiceProvider(),
                new UtilServiceProvider(),
                new UtilCoreServiceProvider(),
                new UtilCoreClientServiceProvider()
            ],
        ] + Service::urls([
            'entity', // @todo: To be removed the logic related to entity service.
            'lo',
            'lo-access',
            'payment',
            'portal',
            'user',
            'credit',
            'content-subscription',
            'achievement-explore',
            'dimensions',
            'user-graphql-gateway',
            'graphql-gateway',
            'lti-consumer',
            'scheduler',
            'iam',
            'user-management',
            'report-data',
        ], $env, getenv('SERVICE_URL_PATTERN') ?: null);
});
