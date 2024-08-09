<?php

namespace go1\core\learning_record\revision;

use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\BootableProviderInterface;
use Silex\Application;

class RevisionServiceProvider implements BootableProviderInterface, ServiceProviderInterface
{
    public function register(Container $c)
    {
        $c[EnrolmentRevisionController::class] = function (Container $c) {
            return new EnrolmentRevisionController(
                $c['lazy_wrapper']['go1'],
                $c[UserDomainHelper::class],
                $c['logger'],
                $c[EnrolmentRepository::class],
                $c['access_checker']
            );
        };
    }

    public function boot(Application $app)
    {
        $app->post('/revision', EnrolmentRevisionController::class . ':post');
    }
}
