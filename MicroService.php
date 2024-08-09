<?php

namespace go1\enrolment;

use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\DependencyFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use go1\app\DomainService;
use go1\core\group\group_schema\v1\repository\GroupAssignmentRepository;
use go1\core\group\group_schema\v1\repository\GroupRepository;
use go1\core\learning_record\attribute\utils\client\DimensionsClient;
use go1\core\util\client\federation_api\v1\GraphQLClient;
use go1\core\util\client\federation_api\v1\Marshaller;
use go1\core\util\client\UserDomainHelper;
use go1\domain_users\clients\user_management\lib\Api\SearchApi;
use go1\domain_users\clients\user_management\lib\Configuration as UserManagementConfiguration;
use go1\domain_users\clients\user_management\lib\Api\StaffApi;
use go1\enrolment\content_learning\ContentLearningQuery;
use go1\enrolment\controller\ContentLearningController;
use go1\enrolment\controller\create\CreateMiddleware;
use go1\enrolment\controller\create\FindStudentMiddleware;
use go1\enrolment\controller\create\LegacyPaymentClient;
use go1\enrolment\controller\create\LoAccessClient;
use go1\enrolment\controller\create\LTIConsumerClient;
use go1\enrolment\controller\create\ParamValidateMiddleware;
use go1\enrolment\controller\create\PaymentMiddleware;
use go1\enrolment\controller\create\PortalMiddleware;
use go1\enrolment\controller\create\validator\EnrolmentCreateValidator;
use go1\enrolment\controller\create\validator\EnrolmentArchiveV3Validator;
use go1\enrolment\controller\create\validator\LearningObjectValidator;
use go1\enrolment\controller\create\validator\EnrolmentTrackingValidator;
use go1\enrolment\controller\create\validator\EnrolmentCreateV3Validator;
use go1\enrolment\controller\EnrolmentArchiveController;
use go1\enrolment\controller\EnrolmentArchiveControllerV3;
use go1\enrolment\controller\EnrolmentCreateController;
use go1\enrolment\controller\EnrolmentDeleteController;
use go1\enrolment\controller\EnrolmentHistoryController;
use go1\enrolment\controller\EnrolmentLoadController;
use go1\enrolment\controller\EnrolmentReCalculateController;
use go1\enrolment\controller\EnrolmentRevisionRestoreController;
use go1\enrolment\controller\EnrolmentReuseController;
use go1\enrolment\controller\EnrolmentUpdateController;
use go1\enrolment\controller\InstallController;
use go1\enrolment\controller\ManualPaymentBulkController;
use go1\enrolment\controller\ManualPaymentController;
use go1\enrolment\controller\NonEnrolledController;
use go1\enrolment\controller\staff\StaffEnrolmentLoadController;
use go1\enrolment\controller\UnenrolController;
use go1\enrolment\controller\UserLearningController;
use go1\enrolment\controller\EnrolmentCreateControllerV3;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\domain\PDOWrapper;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\services\EnrolmentDueService;
use go1\enrolment\services\EnrolmentEventPublishingService;
use go1\enrolment\services\lo\LoService;
use go1\enrolment\services\lo\LoAccessoryRepository;
use go1\enrolment\services\PortalService;
use go1\enrolment\services\ReportDataService;
use go1\enrolment\services\UserService;
use go1\util\DB;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\enrolment\event_publishing\EnrolmentEventsEmbedder;
use go1\beamphp\src\Enrolment\BeamMiddleware;
use go1\core\learning_record\attribute\EnrolmentAttributeRepository;
use go1\core\learning_record\enquiry\EnquiryRepository;
use go1\enrolment\consumer\DoEnrolmentConsumer;
use go1\enrolment\consumer\DoEnrolmentCronConsumer;
use go1\enrolment\consumer\EnrolmentConsumer;
use go1\enrolment\consumer\EnrolmentCreateConsumer;
use go1\enrolment\consumer\EnrolmentPlanConsumer;
use go1\enrolment\consumer\GroupConsumer;
use go1\enrolment\consumer\LoConsumer;
use go1\enrolment\consumer\MergeAccountConsumer;
use go1\enrolment\controller\CronController;
use go1\enrolment\controller\staff\FixEnrolmentController;
use go1\enrolment\controller\staff\FixEnrolmentPlanLinksController;
use go1\enrolment\controller\staff\FixEnrolmentRevisionController;
use go1\enrolment\controller\staff\FixPlansController;
use go1\enrolment\controller\staff\MergeAccountController;
use go1\enrolment\domain\etc\EnrolmentCalculator;
use go1\enrolment\domain\etc\EnrolmentMergeAccount;
use go1\enrolment\domain\etc\EnrolmentPlanRepository;
use go1\enrolment\domain\etc\SuggestedCompletionCalculator;
use go1\util\contract\ServiceConsumeController;
use go1\util\plan\event_publishing\PlanCreateEventEmbedder;
use go1\util\plan\event_publishing\PlanUpdateEventEmbedder;
use go1\util\plan\PlanRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\BootableProviderInterface;
use Silex\Application;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MicroService implements ServiceProviderInterface, BootableProviderInterface
{
    public function register(Container $c)
    {
        $c[UserService::class] = fn (Container $c) =>
            new UserService(
                $c[UserDomainHelper::class],
                $c['client'],
                $c['logger'],
                $c[StaffApi::class]
            );

        $c[LoService::class] = fn (Container $c) => new LoService($c['lazy_wrapper']['go1.reader']);

        $c[ReportDataService::class] = fn (Container $c) => new ReportDataService($c['client'], $c['report-data_url'], $c['logger']);

        $c[PortalService::class] = fn (Container $c) =>
            new PortalService(
                $c['lazy_wrapper']['go1'],
                $c['go1.client.portal']
            );

        $c[EnrolmentEventPublishingService::class] = fn (Container $c) =>
            new EnrolmentEventPublishingService(
                $c[UserService::class],
                $c[EnrolmentEventsEmbedder::class],
                $c[PlanCreateEventEmbedder::class],
                $c[PlanUpdateEventEmbedder::class]
            );

        $c[EnrolmentCreateService::class] = fn (Container $c) =>
            new EnrolmentCreateService(
                $c['logger'],
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentEventPublishingService::class],
                $c[EnrolmentRepository::class],
                $c[PlanRepository::class],
                $c['go1.client.mq'],
                $c[EnrolmentDueService::class],
                $c[LoAccessoryRepository::class]
            );

        $c[EnrolmentDueService::class] = fn (Container $c) =>
            new EnrolmentDueService(
                $c[EnrolmentRepository::class],
                $c[LoService::class]
            );

        $c[LoAccessoryRepository::class] = fn (Container $c) =>
            new LoAccessoryRepository(
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c[EnrolmentAttributeRepository::class]
            );

        $c[EnrolmentRepository::class] = function (Container $c) {
            return new EnrolmentRepository(
                $c['logger'],
                $c['lazy_wrapper']['go1'],
                $c['lazy_wrapper']['go1'],
                $c['lazy_wrapper']['enrolment'],
                $c[PlanRepository::class],
                $c['access_checker'],
                $c['go1.client.mq'],
                $c['portal_checker'],
                $c[UserDomainHelper::class],
                $c[LTIConsumerClient::class],
                $c[LoService::class],
                $c[UserService::class],
                $c[EnrolmentEventPublishingService::class]
            );
        };

        $c[EnrolmentEventsEmbedder::class] = function (Container $c) {
            return new EnrolmentEventsEmbedder($c['lazy_wrapper']['go1']->get(), $c['access_checker'], $c[UserDomainHelper::class]);
        };

        $c[EnrolmentRevisionRepository::class] = function (Container $c) {
            return new EnrolmentRevisionRepository(
                $c['logger'],
                $c['lazy_wrapper']['go1'],
                $c['lazy_wrapper']['go1'],
                $c['lazy_wrapper']['enrolment'],
                $c[PlanRepository::class],
                $c['access_checker'],
                $c['go1.client.mq'],
                $c['portal_checker'],
                $c[UserDomainHelper::class],
                $c[LTIConsumerClient::class],
                $c[LoService::class],
                $c[UserService::class],
                $c[EnrolmentEventPublishingService::class]
            );
        };

        $c[EnrolmentCreateValidator::class] = function (Container $c) {
            return new EnrolmentCreateValidator(
                $c['lazy_wrapper']['go1'],
                $c['access_checker'],
                $c['lo_checker'],
                $c[ContentSubscriptionService::class],
                $c['go1.client.portal']
            );
        };

        $c[EnrolmentTrackingValidator::class] = function (Container $c) {
            return new EnrolmentTrackingValidator(
                $c['access_checker']
            );
        };

        $c[EnrolmentCreateV3Validator::class] = function (Container $c) {
            return new EnrolmentCreateV3Validator(
                $c['access_checker'],
                $c[UserDomainHelper::class],
                $c[EnrolmentCreateValidator::class],
                $c[UserService::class],
                $c['lo_checker'],
                $c[PortalService::class]
            );
        };

        $c[EnrolmentArchiveV3Validator::class] = function (Container $c) {
            return new EnrolmentArchiveV3Validator(
                $c['access_checker'],
                $c[UserDomainHelper::class],
                $c[EnrolmentRepository::class],
                $c[PortalService::class],
            );
        };

        $c[EnrolmentCreateController::class] = function (Container $c) {
            return new EnrolmentCreateController(
                $c['logger'],
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c[EnrolmentCreateService::class],
                $c['accounts_name'],
                $c['access_checker'],
                $c['lo_checker'],
                $c['portal_checker'],
                $c[ContentSubscriptionService::class],
                $c['go1.client.portal'],
                $c[EnrolmentCreateValidator::class],
                $c[EnrolmentTrackingValidator::class],
                $c[UserDomainHelper::class],
                $c[PortalService::class]
            );
        };

        $c[EnrolmentRevisionRestoreController::class] = function (Container $c) {
            return new EnrolmentRevisionRestoreController(
                $c[EnrolmentRepository::class],
                $c[EnrolmentRevisionRepository::class],
                $c['logger'],
                $c['access_checker']
            );
        };

        $c[PortalMiddleware::class] = function (Container $c) {
            return new PortalMiddleware($c[LoService::class], $c['cache'], $c['client'], $c['portal_url']);
        };

        $c[PaymentMiddleware::class] = function (Container $c) {
            return new PaymentMiddleware(
                $c[LegacyPaymentClient::class],
                $c['logger'],
                $c['lazy_wrapper']['go1'],
                $c['client'],
                $c[EnrolmentRepository::class],
                $c['payment_url'],
                $c['accounts_name'],
                $c[ContentSubscriptionService::class]
            );
        };

        $c[LegacyPaymentClient::class] = function (Container $c) {
            return new LegacyPaymentClient(
                $c['logger'],
                $c[LoService::class],
                $c['client'],
                $c[UserDomainHelper::class],
                $c['entity_url'],
                $c['accounts_name']
            );
        };

        $c[EnrolmentUpdateController::class] = function (Container $c) {
            return new EnrolmentUpdateController(
                $c['lazy_wrapper']['go1'],
                $c['logger'],
                $c[EnrolmentRepository::class],
                $c['access_checker'],
                $c[PortalService::class],
                $c[EnrolmentCreateV3Validator::class],
                $c[UserService::class],
            );
        };

        $c[EnrolmentArchiveController::class] = function (Container $c) {
            return new EnrolmentArchiveController(
                $c['lazy_wrapper']['go1'],
                $c[UserDomainHelper::class],
                $c['logger'],
                $c[EnrolmentRepository::class],
                $c['access_checker'],
                $c[PortalService::class]
            );
        };

        $c[EnrolmentDeleteController::class] = function (Container $c) {
            return new EnrolmentDeleteController(
                $c['logger'],
                $c[EnrolmentRevisionRepository::class],
                $c['access_checker']
            );
        };

        $c[UnenrolController::class] = function (Container $c) {
            return new UnenrolController(
                $c[EnrolmentRepository::class],
                $c['access_checker'],
                $c[PortalService::class],
            );
        };

        $c[InstallController::class] = function (Container $c) {
            return new InstallController(
                $c['lazy_wrapper']['go1']->get(),
                $c['lazy_wrapper']['enrolment']->get(),
                $c[DependencyFactory::class],
            );
        };

        $c[DependencyFactory::class] = function (Container $c) {
            $config = new Configuration($c['lazy_wrapper']['enrolment']->get());
            $config->setMigrationsTableName('enrolment_migrations');
            $config->setMigrationsNamespace('go1\enrolment\migrations');
            $config->setMigrationsDirectory(__DIR__ . '/migrations');

            return new DependencyFactory($config);
        };

        $c[EnrolmentLoadController::class] = function (Container $c) {
            return new EnrolmentLoadController(
                $c['lazy_wrapper']['go1.reader'],
                $c['lazy_wrapper']['go1.writer'],
                $c[EnrolmentRepository::class],
                $c[EnrolmentRevisionRepository::class],
                $c['access_checker'],
                $c['portal_checker'],
                $c['accounts_name'],
                $c['client'],
                $c[UserDomainHelper::class],
                $c['achievement-explore_url'],
                $c[PortalService::class]
            );
        };

        $c[StaffEnrolmentLoadController::class] = function (Container $c) {
            return new StaffEnrolmentLoadController(
                $c['lazy_wrapper']['go1.reader'],
                $c['lazy_wrapper']['go1.writer'],
                $c[EnrolmentRepository::class],
                $c[EnrolmentRevisionRepository::class],
                $c['access_checker'],
                $c['portal_checker'],
                $c['accounts_name'],
                $c['client'],
                $c[UserDomainHelper::class],
                $c['achievement-explore_url'],
                $c[PortalService::class],
                $c['logger']
            );
        };

        $c[EnrolmentHistoryController::class] = function (Container $c) {
            return new EnrolmentHistoryController(
                $c['lazy_wrapper']['go1.reader'],
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c[EnrolmentRevisionRepository::class],
                $c['access_checker'],
                $c['portal_checker'],
                $c['accounts_name'],
                $c['client'],
                $c[UserDomainHelper::class],
                $c['achievement-explore_url'],
                $c[PortalService::class]
            );
        };

        $c[ManualPaymentController::class] = function (Container $c) {
            return new ManualPaymentController(
                $c['logger'],
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c['access_checker'],
                $c[PaymentMiddleware::class]
            );
        };

        $c[ManualPaymentBulkController::class] = function (Container $c) {
            return new ManualPaymentBulkController(
                $c['logger'],
                $c['lazy_wrapper']['go1'],
                $c['go1.client.mq'],
                $c['access_checker'],
                $c['client'],
                $c['credit_url'],
                $c['go1.client.payment'],
                $c[UserDomainHelper::class],
                $c[PortalService::class]
            );
        };

        $c[LearningObjectValidator::class] = function (Container $c) {
            return new LearningObjectValidator(
                $c['lazy_wrapper']['go1.reader'],
                $c['access_checker'],
                $c['portal_checker'],
                $c['lo_checker'],
                $c['go1.client.lo'],
                $c[LoAccessClient::class],
                $c[ContentSubscriptionService::class],
                $c[EnrolmentRepository::class],
                $c[UserDomainHelper::class],
                $c[PortalService::class],
                $c['logger']
            );
        };

        $c[ParamValidateMiddleware::class] = function (Container $c) {
            return new ParamValidateMiddleware(
                $c['lazy_wrapper']['go1'],
                $c['access_checker'],
                $c[LearningObjectValidator::class],
                $c[DimensionsClient::class],
                $c[UserDomainHelper::class],
                $c[PortalService::class]
            );
        };

        $c[GroupRepository::class] = function (Container $c) {
            return new GroupRepository($c['lazy_wrapper']['group']->get());
        };

        $c[GroupAssignmentRepository::class] = function (Container $c) {
            return new GroupAssignmentRepository($c['lazy_wrapper']['group']->get());
        };

        $c[EnrolmentReCalculateController::class] = function (Container $c) {
            return new EnrolmentReCalculateController(
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c[UserDomainHelper::class],
                $c['access_checker'],
                $c['logger'],
                $c[PortalService::class]
            );
        };

        $c[EnrolmentReuseController::class] = function (Container $c) {
            return new EnrolmentReuseController(
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c[EnrolmentCreateService::class],
                $c['accounts_name'],
                $c['access_checker'],
                $c['lo_checker'],
                $c['portal_checker'],
                $c[ContentSubscriptionService::class],
                $c['go1.client.portal'],
                $c[EnrolmentCreateValidator::class],
                $c[EnrolmentTrackingValidator::class],
                $c[UserDomainHelper::class],
                $c['logger'],
                $c[PortalService::class]
            );
        };

        $c[EnrolmentCreateControllerV3::class] = function (Container $c) {
            return new EnrolmentCreateControllerV3(
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c[EnrolmentCreateService::class],
                $c['accounts_name'],
                $c['access_checker'],
                $c['lo_checker'],
                $c['portal_checker'],
                $c[ContentSubscriptionService::class],
                $c['go1.client.portal'],
                $c[EnrolmentCreateValidator::class],
                $c[EnrolmentTrackingValidator::class],
                $c[EnrolmentCreateV3Validator::class],
                $c[UserDomainHelper::class],
                $c[UserService::class],
                $c['logger'],
                $c[PortalService::class]
            );
        };

        $c[EnrolmentArchiveControllerV3::class] = function (Container $c) {
            return new EnrolmentArchiveControllerV3(
                $c[EnrolmentRepository::class],
                $c[PlanRepository::class],
                $c[EnrolmentArchiveV3Validator::class],
                $c['logger']
            );
        };

        $c[ContentSubscriptionService::class] = function (Container $c) {
            return new ContentSubscriptionService($c['client'], $c['content-subscription_url'], $c['logger']);
        };

        $c[LoAccessClient::class] = function (Container $c) {
            return new LoAccessClient(
                $c['client'],
                $c['logger'],
                $c['lo-access_url']
            );
        };

        $c[DimensionsClient::class] = function (Container $c) {
            return new DimensionsClient(
                $c['dimensions_url'],
                $c['logger'],
                $c['client']
            );
        };

        $c[LTIConsumerClient::class] = function (Container $c) {
            return new LTIConsumerClient(
                $c['client'],
                $c['logger'],
                $c['lti-consumer_url']
            );
        };

        $c[CreateMiddleware::class] = function (Container $c) {
            return new CreateMiddleware();
        };

        $c[Psr18Client::class] = function () {
            $options['headers']['User-Agent'] = $_SERVER['HTTP_USER_AGENT'] ?? DomainService::VERSION;
            $raw = HttpClient::create($options);

            return new Psr18Client($raw);
        };

        $c['go1.client.federation_api.v1'] = fn (Container $c) => new GraphQLClient(
            $c[Psr18Client::class],
            new Psr17Factory(),
            new Marshaller(),
            $c['graphql-gateway_url'],
            SERVICE_NAME,
            SERVICE_VERSION,
        );

        $c['go1.client.federation_api.v1.user'] = fn (Container $c) => new GraphQLClient(
            $c[Psr18Client::class],
            new Psr17Factory(),
            new Marshaller(),
            $c['user-graphql-gateway_url'],
            SERVICE_NAME,
            SERVICE_VERSION,
        );

        $c[UserDomainHelper::class] = fn (Container $c) => new UserDomainHelper(
            $c['go1.client.federation_api.v1.user'],
            new Marshaller(),
        );

        $c[FindStudentMiddleware::class] = fn (Container $c) => new FindStudentMiddleware(
            $c[UserDomainHelper::class],
            $c['accounts_name'],
            $c[UserService::class],
            $c['access_checker'],
        );

        $c[UserLearningController::class] = fn (Container $c) => new UserLearningController(
            $c['access_checker'],
            $c['go1.client.federation_api.v1'],
            new Marshaller(),
            $c['logger']
        );

        $c[ContentLearningQuery::class] = fn (Container $c) => new ContentLearningQuery(
            $c['lazy_wrapper']['go1.reader'],
            $c['contentLearningUserMigration'],
            $c[UserService::class]
        );

        $c[ContentLearningController::class] = fn (Container $c) => new ContentLearningController(
            $c['access_checker'],
            $c['go1.client.federation_api.v1'],
            new Marshaller(),
            $c['logger'],
            $c[ContentLearningQuery::class],
            $c[PortalService::class],
            $c[ReportDataService::class],
            $c['contentLearningUserMigration']
        );

        $c[UserManagementConfiguration::class] = function (Container $c) {
            return (new UserManagementConfiguration())->setHost($c['user-management_url']);
        };

        $c[StaffApi::class] = fn (Container $c) => new StaffApi(
            $c['client'],
            $c[UserManagementConfiguration::class]
        );

        $c[SearchApi::class] = fn (Container $c) => new SearchApi(
            $c['client'],
            $c[UserManagementConfiguration::class]
        );

        $c[NonEnrolledController::class] = fn (Container $c) => new NonEnrolledController(
            $c['access_checker'],
            $c['logger'],
            $c[SearchApi::class],
            $c[EnrolmentRepository::class]
        );

        $c[CronController::class] = function (Container $c) {
            return new CronController(
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c['go1.client.mq']
            );
        };

        $c[MergeAccountController::class] = function (Container $c) {
            return new MergeAccountController($c['go1.client.mq'], $c['access_checker']);
        };

        $c[FixEnrolmentController::class] = function (Container $c) {
            return new FixEnrolmentController(
                $c['access_checker'],
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class]
            );
        };

        $c[FixEnrolmentPlanLinksController::class] = function (Container $c) {
            return new FixEnrolmentPlanLinksController(
                $c['access_checker'],
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class]
            );
        };

        $c[FixPlansController::class] = function (Container $c) {
            return new FixPlansController(
                $c['access_checker'],
                $c['lazy_wrapper']['go1']
            );
        };

        $c[FixEnrolmentRevisionController::class] = function (Container $c) {
            return new FixEnrolmentRevisionController(
                $c['access_checker'],
                $c['lazy_wrapper']['go1']
            );
        };

        $c[EnrolmentCreateConsumer::class] = function (Container $c) {
            return new EnrolmentCreateConsumer(
                $c['lazy_wrapper']['go1'],
                $c[PlanRepository::class],
                $c[SuggestedCompletionCalculator::class],
                $c[EnrolmentRepository::class]
            );
        };

        $c[EnrolmentConsumer::class] = function (Container $c) {
            return new EnrolmentConsumer(
                $c['lazy_wrapper']['go1'],
                $c['go1.client.mq'],
                $c[EnrolmentRepository::class],
                $c[EnquiryRepository::class],
                $c[PlanRepository::class]
            );
        };

        $c[GroupConsumer::class] = function (Container $c) {
            return new GroupConsumer(
                $c['lazy_wrapper']['social'],
                $c['go1.client.mq'],
                $c[UserDomainHelper::class]
            );
        };

        $c[EnrolmentPlanConsumer::class] = function (Container $c) {
            return new EnrolmentPlanConsumer(
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentPlanRepository::class],
                $c[PlanRepository::class]
            );
        };

        $c[DoEnrolmentConsumer::class] = function (Container $c) {
            return new DoEnrolmentConsumer(
                $c['lazy_wrapper']['go1'],
                $c['go1.client.mq'],
                $c[EnrolmentRepository::class],
                $c[PlanRepository::class],
                $c[UserDomainHelper::class],
                $c['logger']
            );
        };

        $c[DoEnrolmentCronConsumer::class] = function (Container $c) {
            return new DoEnrolmentCronConsumer($c[CronController::class]);
        };

        $c[LoConsumer::class] = function (Container $c) {
            return new LoConsumer($c[EnrolmentCalculator::class], $c['go1.client.mq']);
        };

        $c[MergeAccountConsumer::class] = function (Container $c) {
            return new MergeAccountConsumer($c[EnrolmentMergeAccount::class], $c['go1.client.mq'], $c['logger']);
        };

        $c[EnrolmentPlanRepository::class] = function (Container $c) {
            return new EnrolmentPlanRepository(
                $c['lazy_wrapper']['go1']
            );
        };

        $c[ServiceConsumeController::class] = function (Container $c) {
            return new ServiceConsumeController($c['consumers'], $c['logger']);
        };

        $c['consumers'] = function (Container $c) {
            return [
                $c[EnrolmentCreateConsumer::class],
                $c[EnrolmentConsumer::class],
                $c[GroupConsumer::class],
                $c[EnrolmentPlanConsumer::class],
                $c[DoEnrolmentConsumer::class],
                $c[DoEnrolmentCronConsumer::class],
                $c[LoConsumer::class],
                $c[MergeAccountConsumer::class],
            ];
        };

        $c[SuggestedCompletionCalculator::class] = function (Container $c) {
            return new SuggestedCompletionCalculator($c['lazy_wrapper']['go1']);
        };

        $c[EnrolmentCalculator::class] = function (Container $c) {
            return new EnrolmentCalculator(
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c[EnrolmentCreateService::class]
            );
        };

        $c[EnrolmentMergeAccount::class] = function (Container $c) {
            return new EnrolmentMergeAccount(
                $c['lazy_wrapper']['go1'],
                $c['lazy_wrapper']['go1.writer'],
                $c[EnrolmentRepository::class],
                $c['go1.client.mq'],
                $c[UserDomainHelper::class],
                $c['logger'],
                $c[PlanRepository::class]
            );
        };

        $c[CronController::class] = function (Container $c) {
            return new CronController(
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class],
                $c['go1.client.mq']
            );
        };

        $c[MergeAccountController::class] = function (Container $c) {
            return new MergeAccountController($c['go1.client.mq'], $c['access_checker']);
        };

        $c[FixEnrolmentController::class] = function (Container $c) {
            return new FixEnrolmentController(
                $c['access_checker'],
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class]
            );
        };

        $c[FixEnrolmentPlanLinksController::class] = function (Container $c) {
            return new FixEnrolmentPlanLinksController(
                $c['access_checker'],
                $c['lazy_wrapper']['go1'],
                $c[EnrolmentRepository::class]
            );
        };

        $c[FixPlansController::class] = function (Container $c) {
            return new FixPlansController(
                $c['access_checker'],
                $c['lazy_wrapper']['go1']
            );
        };

        $c[FixEnrolmentRevisionController::class] = function (Container $c) {
            return new FixEnrolmentRevisionController(
                $c['access_checker'],
                $c['lazy_wrapper']['go1']
            );
        };

        $c['lazy_wrapper'] = function (Container $c) {
            $config = $c['db.config'];
            $manager = $c['db.event_manager'];

            $dbs = new Container();
            foreach ($c['dbs.options'] as $name => $options) {
                if (class_exists(TestCase::class, false)) {
                    $o = DB::connectionOptions($options[0]);
                } else {
                    $o = DB::connectionPoolOptions(
                        $options[0],
                        $options[1],
                        $options[2],
                        PDOWrapper::class
                    );
                }
                $wrapper = new ConnectionWrapper($o, $config, $manager);
                $dbs[$name] = function ($dbs) use ($wrapper) {
                    return $wrapper;
                };
            }

            return $dbs;
        };
    }

    public function boot(Application $app)
    {
        $beamMiddleware = new BeamMiddleware();
        $trackBeam = [$beamMiddleware, 'withEventType'];
        $app->post('/install', InstallController::class . ':install');

        $handleInternalData = function (Request $req) use ($app) {
            $encoded = $req->get('internal_data');
            if (!$encoded) {
                return;
            }
            $privateKey = $req->headers->get('JWT-Private-Key', $_SERVER['JWT-Private-Key'] ?? '');
            try {
                $data = (array) JWT::decode($encoded, new Key($privateKey, 'HS256'));
                foreach ($data as $key => $value) {
                    $req->attributes->set('internal_data.' . $key, $value);
                }
            } catch (\Exception $e) {
            }
        };

        $handleCreateData = function (Request $req) use ($app) {
            $app[CreateMiddleware::class]($req);
        };

        $beforeEnrol = function (Request $req) use ($app, $handleInternalData) {
            $handleInternalData($req);
            $error = $app[FindStudentMiddleware::class]($req);
            if ($error) {
                return $error;
            }

            return $app[ParamValidateMiddleware::class]($req)
                    ?: $app[PortalMiddleware::class]($req)
                        ?: $app[PaymentMiddleware::class]($req);
        };

        $app->post('/{instance}/enrolment', EnrolmentCreateController::class . ':postMultiple')
            ->before($beforeEnrol);

        $app->post('/{instance}/enrolment/{studentMail}', EnrolmentCreateController::class . ':postMultipleForStudent')
            ->before($beforeEnrol);

        $app->post('/{instance}/{parentLoId}/{loId}/enrolment/{status}', EnrolmentCreateController::class . ':post')
            ->assert('parentLoId', '\d+')
            ->assert('loId', '\d+')
            ->value('status', EnrolmentStatuses::IN_PROGRESS)
            ->before($beforeEnrol);

        $app->post('/{instance}/{parentLoId}/{loId}/enrolment/{studentMail}/{status}', EnrolmentCreateController::class . ':postForStudent')
            ->assert('parentLoId', '\d+')
            ->assert('loId', '\d+')
            ->value('status', EnrolmentStatuses::NOT_STARTED)
            ->before($beforeEnrol);

        $app->post('/enrollments', EnrolmentCreateControllerV3::class.':postV3')
            ->before($handleCreateData)
            ->before($beforeEnrol)
            ->after($trackBeam('enrollment.single.create'));

        $app->post('/{portalIdOrName}/reuse-enrolment', EnrolmentReuseController::class . ':postReuse');

        $app->put('/enrolment/{enrolmentId}', EnrolmentUpdateController::class . ':put')
            ->assert('enrolmentId', '\d+')
            ->before($handleInternalData);
        $app->patch('/enrollments/{enrolmentId}', EnrolmentUpdateController::class . ':patchSlimEnrollment')
            ->assert('enrolmentId', '\d+')
            ->before($handleInternalData)
            ->after($trackBeam('enrollment.single.update'));
        $app->put('/enrolment/{enrolmentId}/properties', EnrolmentUpdateController::class . ':putProperties')
            ->assert('enrolmentId', '\d+');

        // Enrolment loading
        $app->get('/user-learning/{portalId}', UserLearningController::class . ':get')
            ->assert('portalId', '\d+');
        $app->get('/content-learning/{portalId}/{loId}', ContentLearningController::class . ':get')
            ->assert('portalId', '\d+')
            ->assert('loId', '\d+');
        $app->get('/{id}', EnrolmentLoadController::class . ':get')
            ->assert('id', '\d+');
        $app->get('/enrollments/{id}', EnrolmentLoadController::class . ':getSlimEnrollment')
            ->assert('id', '\d+')
            ->after($trackBeam('enrollment.single.view'));
        $app->get('/revision/{id}', EnrolmentLoadController::class . ':getRevision')
            ->assert('id', '\d+');
        $app->get('/lo/{loId}', EnrolmentLoadController::class . ':getByLearningObject')
            ->assert('loId', '\d+');
        $app->get('/lo/{loId}/history/{userId}', EnrolmentHistoryController::class . ':getHistory')
            ->assert('loId', '\d+')
            ->assert('userId', '\d+')
            ->value('userId', 0);
        $app->get('/lo/{instanceId}/{type}/{remoteLoId}', EnrolmentLoadController::class . ':getByRemoteLearningObject')
            ->assert('instanceId', '\d+')
            ->assert('remoteLoId', '-?\d+');
        $app->get('/lo/{loId}/{takenPortalId}', EnrolmentLoadController::class . ':single')
            ->assert('loId', '\d+')
            ->assert('takenPortalId', '\d+');
        $app->get('/learning-objects/{loId}/non-enrolled', NonEnrolledController::class . ':get')
            ->assert('loId', '\d+');
        $app->get('/staff/lo/{loId}', StaffEnrolmentLoadController::class . ':getByLearningObjectForStaff')
            ->assert('loId', '\d+');

        // TODO: Move to etc?
        $app->post('/enrolment/re-calculate/{enrolmentId}', EnrolmentReCalculateController::class . ':post')
            ->assert('enrolmentId', '\d+');

        // Enrolment archive/delete
        $app->delete('/{loId}', UnenrolController::class . ':delete')
            ->assert('loId', '\d+');
        $app->delete('/staff/revision/{id}', EnrolmentDeleteController::class . ':deleteRevision')
            ->assert('id', '\d+');
        $app->delete('/staff/enrolment-revisions/{enrolmentId}', EnrolmentDeleteController::class . ':deleteRevisionsByEnrolmentId')
            ->assert('enrolmentId', '\d+');
        $app->delete('/enrolment/{enrolmentId}', EnrolmentArchiveController::class . ':archive')
            ->assert('enrolmentId', '\d+');

        $app->delete('/enrollments/{id}', EnrolmentArchiveControllerV3::class.':archive')
            ->assert('id', '\d+')
            ->after($trackBeam('enrollment.single.delete'));

        $app->post('/staff/enrolment-revisions/{revisionId}/restore', EnrolmentRevisionRestoreController::class . ':post')
            ->assert('id', '\d+');

        // Manual payment
        $app->post('/enrolment/manual-payment/accept/{enrolmentId}', ManualPaymentController::class . ':accept')
            ->assert('enrolmentId', '\d+');
        $app->post('/enrolment/manual-payment/reject/{enrolmentId}', ManualPaymentController::class . ':reject')
            ->assert('enrolmentId', '\d+');
        $app->post('/manual-payment/bulk/{loId}/{quantity}/{creditType}', ManualPaymentBulkController::class . ':post')
            ->assert('loId', '\d+')
            ->assert('quantity', '\d+')
            ->assert('creditType', '\d+');
        $app->post('/manual-payment/bulk/{roId}/accept', ManualPaymentBulkController::class . ':accept')
            ->assert('roId', '\d+');
        $app->post('/manual-payment/bulk/{roId}/reject', ManualPaymentBulkController::class . ':reject')
            ->assert('roId', '\d+');

        $sendBeamTracking = function (Request $req, Response $res) {
            if ($beam = $req->attributes->get('BeamMiddleware')) {
                $beam->sendBeamTracking($req, $res);
            }
        };
        $app->finish($sendBeamTracking);

        $app->post('/consume', ServiceConsumeController::class . ':post');
        $app->get('/consume', ServiceConsumeController::class . ':get');

        // Cron endpoint to update enrolments regularly
        $app->post('/cron', CronController::class . ':post');

        // Endpoint to move enrolments from one account to another
        $app->post('/staff/merge/{portalId}/{from}/{to}', MergeAccountController::class . ':post')
            ->assert('portalId', '\d+');

        // Adhoc endpoint to fix enrolment parent_enrolment_id (only for staff member use)
        $app->post('/staff/fix/{id}', FixEnrolmentController::class . ':post');

        // Adhoc endpoints to fix enrolment plan links (only for staff member use)
        $app->post('/staff/add-missing-enrolment-plan-links/{offset}', FixEnrolmentPlanLinksController::class . ':postAddMissingLinks');
        $app->post('/staff/remove-extra-enrolment-plan-links/{offset}', FixEnrolmentPlanLinksController::class . ':postRemoveExtraLinks');
        $app->post('/staff/fix-plan-user-ids/{offset}', FixPlansController::class . ':postFixUserIds');
        $app->post('/staff/fix-enrolment-parent-lo/{offset}', FixEnrolmentController::class . ':postFixEnrolmentParentLoId');
        $app->post('/staff/fix-enrolment-revision-parent-lo/{offset}', FixEnrolmentRevisionController::class . ':postFixEnrolmentRevisionParentLoId');
        $app->post('/staff/fix-data-01', FixEnrolmentRevisionController::class . ':restoreRevisions');

    }
}
