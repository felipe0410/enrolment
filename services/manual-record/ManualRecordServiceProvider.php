<?php

namespace go1\core\learning_record\manual_record;

use go1\core\util\client\UserDomainHelper;
use go1\enrolment\services\PortalService;
use go1\util\enrolment\ManualRecordRepository;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\BootableProviderInterface;
use Silex\Application;

class ManualRecordServiceProvider implements BootableProviderInterface, ServiceProviderInterface
{
    public function register(Container $c)
    {
        $c[ManualRecordRepository::class] = function (Container $c) {
            return new ManualRecordRepository($c['lazy_wrapper']['enrolment']->get(), $c['go1.client.mq']);
        };

        $c[ManualRecordController::class] = function (Container $c) {
            return new ManualRecordController(
                $c['lazy_wrapper']['go1'],
                $c[ManualRecordRepository::class],
                $c['html'],
                $c['access_checker'],
                $c['lo_checker'],
                $c[UserDomainHelper::class],
                $c[PortalService::class]
            );
        };
    }

    public function boot(Application $app)
    {
        $app->post('/manual-record/{instance}/{entityType}/{entityId}', ManualRecordController::class . ':post');
        $app->put('/manual-record/{id}/verify/{value}', ManualRecordController::class . ':putVerify')
            ->value('value', true)
            ->assert('id', '\d+');
        $app->put('/manual-record/{id}', ManualRecordController::class . ':put')
            ->assert('id', '\d+');
        $app->delete('/manual-record/{id}', ManualRecordController::class . ':delete')
            ->assert('id', '\d+');
        $app->get('/manual-record/{instance}/lo/{loId}', ManualRecordController::class . ':get')
            ->assert('loId', '\d+');
        $app
            ->get('/manual-record/{instance}/{userId}/{limit}/{offset}', ManualRecordController::class . ':browse')
            ->value('userId', 'me')
            ->value('limit', 50)
            ->value('offset', 0);
    }
}
