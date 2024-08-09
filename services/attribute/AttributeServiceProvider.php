<?php

namespace go1\core\learning_record\attribute;

use go1\core\learning_record\attribute\utils\client\DimensionsClient;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\BootableProviderInterface;
use Silex\Application;

class AttributeServiceProvider implements BootableProviderInterface, ServiceProviderInterface
{
    public function register(Container $c)
    {
        $c[EnrolmentAttributeRepository::class] = function (Container $c) {
            return new EnrolmentAttributeRepository(
                $c['lazy_wrapper']['enrolment'],
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c['go1.client.mq']
            );
        };

        $c[EnrolmentAttributeController::class] = function (Container $c) {
            return new EnrolmentAttributeController(
                $c['logger'],
                $c[EnrolmentAttributeRepository::class],
                $c['access_checker'],
                $c[DimensionsClient::class],
                $c[UserDomainHelper::class],
                $c[EnrolmentRepository::class],
                $c[PortalService::class]
            );
        };
    }

    public function boot(Application $app)
    {
        $app->post('/enrolment/{enrolmentId}/attributes', EnrolmentAttributeController::class . ':post')
            ->assert('enrolmentId', '\d+');
        $app->put('/enrolment/{enrolmentId}/attributes', EnrolmentAttributeController::class . ':put')
            ->assert('enrolmentId', '\d+');
    }
}
