<?php

namespace go1\core\learning_record\plan;

use go1\core\group\group_schema\v1\repository\GroupAssignmentRepository;
use go1\core\group\group_schema\v1\repository\GroupRepository;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\services\PortalService;
use go1\util\plan\event_publishing\PlanCreateEventEmbedder;
use go1\util\plan\event_publishing\PlanDeleteEventEmbedder;
use go1\util\plan\event_publishing\PlanUpdateEventEmbedder;
use go1\util\plan\PlanRepository;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\BootableProviderInterface;
use Silex\Application;

class PlanServiceProvider implements ServiceProviderInterface, BootableProviderInterface
{
    public function register(Container $c)
    {
        $c[PlanRepository::class] = function (Container $c) {
            return new PlanRepository(
                $c['lazy_wrapper']['go1']->get(),
                $c['go1.client.mq'],
                $c[PlanCreateEventEmbedder::class],
                $c[PlanUpdateEventEmbedder::class],
                $c[PlanDeleteEventEmbedder::class]
            );
        };

        $c[PlanCreateEventEmbedder::class] = function (Container $c) {
            return new PlanCreateEventEmbedder($c['lazy_wrapper']['go1']->get());
        };

        $c[PlanUpdateEventEmbedder::class] = function (Container $c) {
            return new PlanUpdateEventEmbedder($c['lazy_wrapper']['go1']->get());
        };

        $c[PlanDeleteEventEmbedder::class] = function (Container $c) {
            return new PlanDeleteEventEmbedder($c['lazy_wrapper']['go1']->get());
        };

        $c[PlanArchiveController::class] = function (Container $c) {
            return new PlanArchiveController(
                $c['lazy_wrapper']['go1']->get(),
                $c['lazy_wrapper']['social']->get(),
                $c[PlanRepository::class],
                $c[EnrolmentRepository::class],
                $c['go1.client.mq'],
                $c['access_checker'],
                $c[UserDomainHelper::class],
                $c[PortalService::class]
            );
        };

        $c[PlanCreateController::class] = function (Container $c) {
            return new PlanCreateController(
                $c['lazy_wrapper']['go1']->get(),
                $c['lazy_wrapper']['social']->get(),
                $c[PlanRepository::class],
                $c[EnrolmentRepository::class],
                $c['go1.client.mq'],
                $c['access_checker'],
                $c['lo_checker'],
                $c['go1.client.portal'],
                $c[ContentSubscriptionService::class],
                $c[UserDomainHelper::class],
                $c['logger'],
                $c[PortalService::class]
            );
        };

        $c[PlanUpdateController::class] = function (Container $c) {
            return new PlanUpdateController(
                $c[PlanRepository::class],
                $c['access_checker'],
                $c[UserDomainHelper::class],
                $c['logger'],
                $c[PortalService::class]
            );
        };

        $c[PlanReassignController::class] = function (Container $c) {
            return new PlanReassignController(
                $c['lazy_wrapper']['go1']->get(),
                $c[PlanRepository::class],
                $c[EnrolmentRepository::class],
                $c['access_checker'],
                $c[UserDomainHelper::class],
                $c['logger'],
                $c['go1.client.mq'],
                $c[PortalService::class]
            );
        };

        $c[PlanBrowsingController::class] = function (Container $c) {
            return new PlanBrowsingController(
                $c['lazy_wrapper']['go1']->get(),
                $c['lazy_wrapper']['social']->get(),
                $c['accounts_name'],
                $c[EnrolmentRepository::class],
                $c[GroupRepository::class],
                $c[GroupAssignmentRepository::class],
                $c['access_checker'],
                $c[UserDomainHelper::class],
                $c[PortalService::class]
            );
        };

        $c[PlanController::class] = function (Container $c) {
            return new PlanController(
                $c['access_checker'],
                $c[UserDomainHelper::class],
                $c['go1.client.federation_api.v1'],
                $c['logger']
            );
        };
    }

    public function boot(Application $app)
    {
        $app->get('/plans/{planId}', PlanController::class . ':get')
            ->assert('planId', '\d+');
        $app->get('/plan/{instanceId}', PlanBrowsingController::class . ':get');
        $app->get('/plan-entity/{groupId}', PlanBrowsingController::class . ':getEntity')
            ->assert('groupId', '\d+');
        $app->post('/plan/{instanceId}/{loId}/user/{userId}', PlanCreateController::class . ':postUser')
            ->assert('loId', '\d+');
        $app->post('/plan/{instanceId}/{loId}/group/{groupId}', PlanCreateController::class . ':postGroup')
            ->assert('loId', '\d+')
            ->assert('groupId', '\d+');
        $app->delete('/plan/{instanceId}/{loId}/group/{groupId}', PlanArchiveController::class . ':deleteGroup')
            ->assert('groupId', '\d+');
        $app->delete('/plan/{planId}', PlanArchiveController::class . ':delete')
            ->assert('planId', '\d+');
        $app->put('/plan/{planId}', PlanUpdateController::class . ':put')
            ->assert('planId', '\d+');
        $app->post('/plan/re-assign', PlanReassignController::class . ':post');
        //Should not be available to be called by other services. For use by AAA team only (or team that currently owns this service) for correcting mistakes.
        $app->post('/plan/{planId}/update-assigned-date', PlanUpdateController::class . ':updateAssignedDate')
            ->assert('planId', '\d+');
    }
}
