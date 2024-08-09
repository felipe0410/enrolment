<?php

namespace go1\core\learning_record\enquiry;

use go1\core\util\client\UserDomainHelper;
use go1\enrolment\controller\create\validator\EnrolmentCreateValidator;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\controller\create\validator\EnrolmentTrackingValidator;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\PortalService;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\BootableProviderInterface;
use Silex\Application;

class EnquiryServiceProvider implements ServiceProviderInterface, BootableProviderInterface
{
    public const ENQUIRY_PENDING = 'pending';
    public const ENQUIRY_ACCEPTED = 'accepted';
    public const ENQUIRY_REJECTED = 'rejected';

    public function register(Container $c)
    {
        $c[EnquiryRepository::class] = function (Container $c) {
            return new EnquiryRepository($c['lazy_wrapper']['go1'], $c['go1.client.mq']);
        };

        $c[EnquiryController::class] = function (Container $c) {
            return new EnquiryController(
                $c['lazy_wrapper']['go1'],
                $c['accounts_name'],
                $c[EnquiryRepository::class],
                $c['access_checker'],
                $c['lo_checker'],
                $c[UserDomainHelper::class],
                $c[PortalService::class]
            );
        };

        $c[ContentSubscriptionService::class] = function (Container $c) {
            return new ContentSubscriptionService($c['client'], $c['content-subscription_url'], $c['logger']);
        };

        $c[EnquiryAdminEnrolController::class] = function (Container $c) {
            return new EnquiryAdminEnrolController(
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c[EnrolmentCreateService::class],
                $c['accounts_name'],
                $c['access_checker'],
                $c['lo_checker'],
                $c['logger'],
                $c[EnquiryRepository::class],
                $c['go1.client.portal'],
                $c['portal_checker'],
                $c[ContentSubscriptionService::class],
                $c[UserDomainHelper::class],
                $c[EnrolmentCreateValidator::class],
                $c[EnrolmentTrackingValidator::class],
                $c[PortalService::class]
            );
        };
    }

    public function boot(Application $app)
    {
        $app->get('/enquiry/{loId}/{mail}', EnquiryController::class . ':get')
            ->assert('loId', '\d+');
        $app->post('/enquiry/{loId}/{mail}', EnquiryController::class . ':post')
            ->assert('loId', '\d+');
        $app->delete('/enquiry/{id}', EnquiryController::class . ':deleteById')
            ->assert('id', '\d+');
        $app->delete('/enquiry/{loId}/student/{studentMail}', EnquiryController::class . ':delete')
            ->assert('loId', '\d+');
        $app->post('/admin/enquiry/{loId}/{mail}', EnquiryAdminEnrolController::class . ':review')
            ->assert('loId', '\d+');
    }
}
