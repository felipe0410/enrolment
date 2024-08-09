<?php

namespace go1\enrolment\tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Schema as DBSchema;
use go1\app\DomainService;
use go1\app\tests\DomainServiceTestCase;
use go1\clients\portal\Portal;
use go1\clients\portal\PortalBasic;
use go1\clients\PortalClient;
use go1\core\group\group_schema\v1\schema\GroupSchema;
use go1\core\learning_record\attribute\utils\client\DimensionsClient;
use go1\core\util\client\federation_api\v1\Marshaller;
use go1\core\util\client\federation_api\v1\schema\object\PortalAccount;
use go1\core\util\client\federation_api\v1\schema\object\User;
use go1\core\util\client\UserDomainHelper;
use go1\domain_users\clients\user_management\lib\Model\AccountUserDto;
use go1\enrolment\content_learning\ErrorMessageCodes;
use go1\enrolment\controller\create\LoAccessClient;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\services\UserService;
use go1\util\DateTime;
use go1\util\DB;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\lo\LiTypes;
use go1\util\lo\LoHelper;
use go1\util\lo\LoTypes;
use go1\util\plan\Plan;
use go1\util\plan\PlanHelper;
use go1\util\policy\Realm;
use go1\util\portal\PortalHelper;
use go1\util\schema\InstallTrait;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\tests\QueueMockTrait;
use go1\util\user\UserHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use PDO;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use ReflectionObject;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

use function count;
use function explode;
use function json_encode;
use function parse_url;
use function strpos;
use function trim;

abstract class EnrolmentTestCase extends DomainServiceTestCase
{
    use InstallTrait;
    use QueueMockTrait;
    use ProphecyTrait;

    protected $timestamp;
    protected $mockMqClient = true;
    protected $queueMessages = [];
    protected $loAccessList = [];
    protected $loAccessDefaultValue = Realm::VIEW;
    protected Marshaller $marshaller;
    protected UserService $userService;

    protected function getDatabases(DomainService $app)
    {
        $connectUrl = 'sqlite://sqlite::memory:';
        $dbs = [];
        foreach ($app['dbs.options'] as $name => $options) {
            $dbs[$name] = DriverManager::getConnection(['url' => $connectUrl]);
        }

        return $dbs;
    }

    protected function mockUserService(DomainService $app)
    {
        $app->extend(UserService::class, function () use ($app) {
            $testClient = $this
                ->getMockBuilder(UserService::class)
                ->disableOriginalConstructor()
                ->setMethods(['get', 'findAccountWithPortalAndUser', 'load', 'loadAccountsFromUserIdsWithPaging', 'loadAccountsWithPaging'])
                ->getMock();

            $testClient
                ->expects($this->any())
                ->method('get')
                ->willReturnCallback(
                    function (string $id, string $type, string $jwt = null) use ($app): ?object {
                        if ($id === '999999') {
                            throw new \Exception();
                        }
                        if ($id === '8888888') {
                            throw new ClientException(
                                '',
                                $this->prophesize(RequestInterface::class)->reveal(),
                                new Response(
                                    404,
                                    [],
                                    json_encode([
                                        "error_code" => 'USER_ACCOUNT_NOT_FOUND',
                                        "message" => "User account not found."
                                    ])
                                )
                            );
                        }

                        $go1 = $app['dbs']['go1'];
                        if ($type === 'accounts') {
                            $user = $go1
                                ->executeQuery('SELECT * FROM gc_user WHERE id = ?', [$id])
                                ->fetchAll(DB::OBJ)[0];

                            $portal = $go1
                                ->executeQuery('SELECT * FROM gc_instance WHERE title = ?', [$user->instance])
                                ->fetchAll(DB::OBJ)[0];

                            if (count(json_decode($user->data, true)) > 0) {
                                $roles = json_decode($user->data, false)->roles;
                                $roles = array_map(fn ($v) => (object)['role' => $v], $roles ?? []);
                            }

                            $response = [
                                'user' => (object) ['_gc_user_id' => $user->user_id, 'email' => $user->mail],
                                'portal_id' => $portal->id,
                                'roles' => $roles ?? []
                            ];
                        } else {
                            $user = $go1
                                ->executeQuery('SELECT * FROM gc_user WHERE id = ?', [$id])
                                ->fetchAll(DB::OBJ)[0];

                            $response = [
                                'family_name' => $user->last_name,
                                'given_name' => $user->first_name,
                                'username' => 'foo bar',
                                'email' => $user->mail,
                                'picture' => '',
                                'status' => $user->status,
                                'created_at' => $user->created,
                                'updated_at' => '',
                                'user_guid' => $user->uuid,
                                'region' => '',
                                '_gc_user_id' => $id
                            ];
                        }

                        return (object)$response;
                    }
                );

            $testClient
                ->expects($this->any())
                ->method('findAccountWithPortalAndUser')
                ->willReturnCallback(
                    function (string $portalId, string $userId, string $jwt = null) use ($app): ?object {
                        $go1 = $app['dbs']['go1'];
                        $user = $go1
                            ->executeQuery('SELECT u.* FROM gc_user u INNER JOIN gc_instance i ON i.title = u.instance WHERE u.user_id = ? AND i.id = ?', [$userId, $portalId])
                            ->fetchAll(DB::OBJ)[0];
                        $response = [
                            'total' => 1,
                            'data' => [
                                (object)[
                                    'user_guid' => '',
                                    '_gc_user_account_id' => $user->id,
                                    "portal_id" => $portalId,
                                    "roles" => [
                                        [
                                            "guid" => '',
                                            "role" => "manager"
                                        ]
                                    ],
                                    "status" => $user->status,
                                    "locale" => '',
                                    "created_at" => $user->created,
                                    "updated_at" => '',
                                ]
                            ]
                        ];
                        return (object)$response;
                    }
                );


            $testClient
                ->expects($this->any())
                ->method('loadAccountsFromUserIdsWithPaging')
                ->willReturnCallback(function (int $portalId, array $userIds) use ($app): array {
                    $go1 = $app['dbs']['go1'];
                    $accounts = $go1
                        ->executeQuery(
                            'SELECT u.user_id, u.id, u.status 
                            FROM gc_user as u 
                            INNER JOIN gc_instance as i ON i.title = u.instance
                            WHERE u.user_id IN (?) AND i.id = ?',
                            [$userIds, $portalId],
                            [DB::INTEGERS, DB::INTEGER, DB::INTEGER]
                        )
                        ->fetchAll(DB::OBJ);
                    $data = [];
                    foreach ($accounts as $account) {
                        $data[$account->user_id] = new AccountUserDto([
                            'user' => [
                                '_gc_user_id' => $account->user_id,
                            ],
                            '_gc_user_account_id' => $account->id,
                            'status' => $account->status,
                        ]);
                    }
                    return $data;
                });

            $testClient
                ->expects($this->any())
                ->method('loadAccountsWithPaging')
                ->willReturnCallback(function (int $portalId, array $userIds) use ($app): array {
                    $go1 = $app['dbs']['go1'];
                    $accounts = $go1
                        ->executeQuery(
                            'SELECT u.user_id, u.id, u.status 
                            FROM gc_user as u 
                            INNER JOIN gc_instance as i ON i.title = u.instance
                            WHERE u.id IN (?) AND i.id = ?',
                            [$userIds, $portalId],
                            [DB::INTEGERS, DB::INTEGER, DB::INTEGER]
                        )
                        ->fetchAll(DB::OBJ);
                    $data = [];
                    foreach ($accounts as $account) {
                        $data[$account->id] = new AccountUserDto([
                            'user' => [
                                '_gc_user_id' => $account->user_id,
                            ],
                            '_gc_user_account_id' => $account->id,
                            'status' => $account->status,
                        ]);
                    }
                    return $data;
                });
            return $testClient;
        });
    }

    protected function mockUserDomainHelper(DomainService $app)
    {
        /*** @var Connection $go1 ; */
        $go1 = $app['dbs']['go1'];
        $legacyToUserDomainFormat = function ($legacyUserOrPortalAccount) use ($app): object {
            $json = json_decode($legacyUserOrPortalAccount->data, true);

            $data = (object)[
                'firstName' => $legacyUserOrPortalAccount->first_name,
                'lastName' => $legacyUserOrPortalAccount->last_name,
                'id' => base64_encode("go1:User:{$legacyUserOrPortalAccount->uuid}"),
                'status' => $legacyUserOrPortalAccount->status ? 'ACTIVE' : 'DEACTIVATE',
                'roles' => array_map(
                    fn (string $roleString) => (object)['name' => strtoupper($roleString)],
                    $json['roles'] ?? []
                ),
                'uuid' => $legacyUserOrPortalAccount->uuid,
                'legacyId' => $legacyUserOrPortalAccount->id,
                'profileId' => $legacyUserOrPortalAccount->profile_id,
                'createdAt' => DateTime::atom(time()),
            ];

            if ($legacyUserOrPortalAccount->instance == $app['accounts_name']) {
                $data->email = $legacyUserOrPortalAccount->mail;
            }

            return $data;
        };

        $userDomainHelper = $this->prophesize(UserDomainHelper::class);

        $userDomainHelper
            ->loadUser(Argument::any(), Argument::any())
            ->will(function ($args) use ($go1, $app, $legacyToUserDomainFormat) {
                $userId = $args[0];
                $portalName = $args[1] ?? null;
                $userId = (int) $userId;

                $user = UserHelper::load($go1, $userId);
                if (!$user || ($user->instance !== $app['accounts_name'])) {
                    return null;
                }

                $data = $legacyToUserDomainFormat($user);
                if ($user && $portalName) {
                    if ($portalAccount = UserHelper::loadByEmail($go1, $portalName, $user->mail)) {
                        $data->account = $legacyToUserDomainFormat($portalAccount);
                    }
                }

                return (new Marshaller())->parse($data, new User());
            });

        $testCase = $this;
        $userDomainHelper
            ->loadUserByEmail(Argument::cetera())
            ->will(function ($args) use ($go1, $legacyToUserDomainFormat, $app, $testCase) {
                $mail = $args[0];
                $instance = $args[1] ?? null;
                $user = $go1
                    ->executeQuery('SELECT * FROM gc_user WHERE instance = ? AND mail = ?', [$app['accounts_name'], $mail])
                    ->fetch(DB::OBJ);


                $rawUser = $user ? $legacyToUserDomainFormat($user) : null;
                if ($instance !== null) {
                    $portalAccount = $go1
                        ->executeQuery('SELECT * FROM gc_user WHERE instance = ? AND mail = ?', [$instance, $mail])
                        ->fetch(DB::OBJ);
                    $rawPortalAccount = $portalAccount ? $legacyToUserDomainFormat($portalAccount) : null;
                    if ($rawPortalAccount) {
                        $rawUser->account = $rawPortalAccount;
                    }
                }
                return $rawUser ? $testCase->marshaller->parse($rawUser, new User()) : null;
            });


        $userDomainHelper
            ->loadPortalAccountByEmail(Argument::any(), Argument::any())
            ->will(function ($args) use ($go1, $legacyToUserDomainFormat, $testCase) {
                $mail = $args[0];
                $instance = $args[1];
                $portalAccount = $go1
                    ->executeQuery('SELECT * FROM gc_user WHERE instance = ? AND mail = ?', [$instance, $mail])
                    ->fetch(DB::OBJ);
                $rawPortalAccount = $portalAccount ? $legacyToUserDomainFormat($portalAccount) : null;
                return $rawPortalAccount ? $testCase->marshaller->parse($rawPortalAccount, new PortalAccount()) : null;
            });

        $userDomainHelper
            ->loadPortalAccount(Argument::any(), Argument::any(), Argument::any())
            ->will(
                function ($args) use ($app, $go1) {
                    [$id, $instance, $loadUser] = $args;
                    if (!is_numeric($id)) {
                        $uuid = explode(':', base64_decode($id))[2];
                        $legacyAccount = $go1->executeQuery('SELECT * FROM gc_user WHERE uuid = ?', [$uuid])->fetch(DB::OBJ);
                    } else {
                        $legacyAccount = UserHelper::load($go1, $id);
                    }

                    if (!$legacyAccount) {
                        return null;
                    }

                    $legacyUser = !$loadUser ? null : UserHelper::loadByEmail($go1, $app['accounts_name'], $legacyAccount->mail);

                    return (new Marshaller())->parse(
                        (object) [
                            'id'        => md5($legacyAccount->uuid),
                            'legacyId'  => $legacyAccount->id,
                            'profileId' => $legacyAccount->profile_id,
                            'email'     => $legacyAccount->mail,
                            'firstName' => $legacyAccount->first_name,
                            'lastName'  => $legacyAccount->last_name,
                            'status'    => $legacyAccount->status ? 'ACTIVE' : 'INACTIVE',
                            'createdAt' => DateTime::formatDate($legacyAccount->created),
                            'user'      => !$legacyUser ? null : (object) [
                                'id'        => md5($legacyUser->uuid),
                                'legacyId'  => $legacyUser->id,
                                'profileId' => $legacyUser->profile_id,
                                'email'     => $legacyUser->mail,
                                'firstName' => $legacyUser->first_name,
                                'lastName'  => $legacyUser->last_name,
                                'status'    => $legacyUser->status ? 'ACTIVE' : 'INACTIVE',
                            ],
                        ],
                        new PortalAccount()
                    );
                }
            );

        $userDomainHelper
            ->isManager(Argument::any(), Argument::any(), Argument::any(), Argument::any())
            ->will(function ($args) use ($go1, $app) {
                [$portalName, $managerPortalAccountId, $portalAccountId] = $args;

                $managerPortalAccount = UserHelper::load($go1, $managerPortalAccountId);
                $managerUser = UserHelper::loadByEmail($go1, $app['accounts_name'], $managerPortalAccount->mail);

                return $managerUser
                    ? EdgeHelper::hasLink($go1, EdgeTypes::HAS_MANAGER, $portalAccountId, $managerUser->id)
                    : false;
            });

        return $userDomainHelper;
    }

    protected function getApp($contentSubscriptionReturn = ['hasLicense' => false]): DomainService
    {
        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__));
        }

        /** @var DomainService $app */
        $app = parent::getApp();

        $app->extend(ContentSubscriptionService::class, function (ContentSubscriptionService $payment) use ($app, $contentSubscriptionReturn) {
            $rPayment = new ReflectionObject($payment);
            $client = $this
                ->getMockBuilder(Client::class)
                ->setMethods(['post'])
                ->getMock();
            $rHttp = $rPayment->getProperty('client');
            $rLogger = $rPayment->getProperty('logger');
            $rHttp->setAccessible(true);

            $rHttp->setValue($payment, $client);

            $client
                ->expects($this->any())
                ->method('post')
                ->willReturn(new Response(200, [], json_encode($contentSubscriptionReturn)));

            $rLogger->setAccessible(true);
            $rLogger->setValue($payment, $logger = $this->getMockBuilder(NullLogger::class)->setMethods(['error'])->getMock());

            $logger
                ->expects($this->any())
                ->method('error')
                ->with(
                    $this->any(),
                    $this->any()
                )
                ->willReturn(null);

            return $payment;
        });

        $this->mockLoAccessClient($app);
        $this->mockDimensionsClient($app);

        DB::cache(PortalHelper::class . '::loadFromLoId', null, true);

        return $app;
    }

    protected function appInstall(DomainService $app)
    {
        $app['logger'] = new NullLogger();

        $this->marshaller = new Marshaller();
        $app->extend(UserDomainHelper::class, fn () => $this->mockUserDomainHelper($app)->reveal());
        $this->timestamp = time();
        $this->installGo1Schema($app['dbs']['go1']);
        $this->installEnrolmentPlanTables($app['dbs']['go1']);
        $this->mockLazyWrapper($app);
        $this->mockLegacy($app);
        $this->mockUserService($app);
        $this->mockPortalClient($app);
    }

    private function installEnrolmentPlanTables(Connection $go1)
    {
        DB::install(
            $go1,
            [
                function (Schema $schema) {
                    if (!$schema->hasTable('gc_enrolment_plans')) {
                        // create table `gc_enrolment_plans`
                        $table = $schema->createTable('gc_enrolment_plans');
                        $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
                        $table->addColumn('enrolment_id', Types::INTEGER, ['unsigned' => true]);
                        $table->addColumn('plan_id', Types::INTEGER, ['unsigned' => true]);
                        $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP']);
                        $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP', 'notnull' => false]);
                        $table->setPrimaryKey(['id']);
                        $table->addForeignKeyConstraint('gc_enrolment', ['enrolment_id'], ['id']);
                        $table->addForeignKeyConstraint('gc_plan', ['plan_id'], ['id']);
                        $table->addUniqueIndex(['plan_id', 'enrolment_id']);
                    }

                    if (!$schema->hasTable('gc_plan_reference')) {
                        // create table `gc_plan_reference`
                        $table = $schema->createTable('gc_plan_reference');
                        $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
                        $table->addColumn('plan_id', Types::INTEGER, ['unsigned' => true]);
                        $table->addColumn('source_type', Types::STRING);
                        $table->addColumn('source_id', Types::INTEGER);
                        $table->addColumn('status', Types::SMALLINT);
                        $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP']);
                        $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['length' => 6, 'default' => 'CURRENT_TIMESTAMP', 'notnull' => false]);
                        $table->setPrimaryKey(['id']);
                        $table->addIndex(['plan_id', 'source_type', 'source_id']);
                        $table->addIndex(['source_type', 'source_id', 'status']);
                        $table->addIndex(['plan_id']);
                    }
                },
            ]
        );
    }

    private function mockLazyWrapper(DomainService $app)
    {
        $app->extend('lazy_wrapper', function () use ($app) {
            $dbs = [];
            foreach ($app['dbs.options'] as $name => $options) {
                $wrapper = new ConnectionWrapper([], null, null, $app['dbs'][$options[0]]);
                $dbs[$name] = $wrapper;
            }

            return $dbs;
        });
    }

    protected function mockHttpPostRequest(DomainService $app, string $url, array $options)
    {
        if ($url == $app['entity_url'] . '/bump/simple/enrollment') {
            $id = $app['dbs']['go1']->fetchColumn('SELECT MAX(id) FROM gc_enrolment') + 1;

            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['id' => $id])
            );
        }
    }

    protected function mockHttpClient(DomainService $app)
    {
        $client = $this
            ->getMockBuilder(Client::class)
            ->onlyMethods(['post', 'get'])
            ->getMock();

        $client
            ->expects($this->any())
            ->method('post')
            ->willReturnCallback(
                function ($url, $options = []) use ($app) {
                    return $this->mockHttpPostRequest($app, $url, $options);
                }
            );

        $client
            ->expects($this->any())
            ->method('get')
            ->willReturnCallback(
                function ($url) use ($app) {
                    # http://user.dev.go1.service/account/masquerade/az.mygo1.com/x@x?jwt=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJhZG1pbiI6dHJ1ZX0.9EmN944M2kmPYSfYFc7VRy63XM60XQtbf2CIQau6xUE
                    if (!!strpos($url, '/account/masquerade')) {
                        $path = explode('/', parse_url($url)['path']);
                        if (5 == count($path)) {
                            [, , , $portalName, $email] = $path;

                            return new Response(
                                200,
                                ['Content-Type' => 'application/json'],
                                json_encode([
                                    'uuid' => $app['dbs']['go1']->fetchColumn(
                                        'SELECT uuid FROM gc_user WHERE instance = ? AND mail = ?',
                                        [$portalName, $email]
                                    ),
                                ])
                            );
                        }
                    }

                    if (false !== strpos($url, $app['portal_url'])) {
                        $portalId = trim(parse_url($url)['path'], '/');

                        if ($portal = PortalHelper::load($app['dbs']['go1'], $portalId)) {
                            if ($u0 = UserHelper::loadByEmail($app['dbs']['go1'], $portal->title, "user.0@{$portal->title}")) {
                                $portal->data->public_key = $u0->uuid;
                            }

                            return new Response(200, ['Content-Type' => 'application/json'], json_encode($portal));
                        }
                    }
                }
            );

        return $client;
    }

    protected function mockLegacy(DomainService $app)
    {
        $app->extend('client', function () use ($app) {
            return $this->mockHttpClient($app);
        });
    }

    protected function mockLoAccessClient(DomainService $app)
    {
        $app->extend(LoAccessClient::class, function () use ($app) {
            $loAccessClient = $this
                ->getMockBuilder(LoAccessClient::class)
                ->disableOriginalConstructor()
                ->setMethods(['realm'])
                ->getMock();

            $loAccessClient
                ->expects($this->any())
                ->method('realm')
                ->willReturnCallback(function (int $loId, int $userId = 0, int $portalId = 0) use ($app): ?int {
                    $lo = LoHelper::load($app['dbs']['go1'], $loId);
                    $defaultValue = (in_array($lo->type, [LoTypes::COURSE, LoTypes::LEARNING_PATHWAY, LiTypes::EVENT]) || LoHelper::isSingleLi($lo))
                        ? $this->loAccessDefaultValue
                        : 0;

                    $cacheId = "{$loId}:{$userId}:{$portalId}";

                    return $this->loAccessList[$cacheId] ?? $defaultValue;
                });

            return $loAccessClient;
        });
    }

    protected function loAccessGrant(int $loId, int $userId, int $portalId, int $realm)
    {
        $cacheId = "{$loId}:{$userId}:{$portalId}";
        $this->loAccessList[$cacheId] = $realm;

        return $this;
    }

    protected function mockPortalClient(DomainService $app)
    {
        $app->extend('go1.client.portal', function (PortalClient $ctrl) use ($app) {
            $mockCtrl = $this
                ->getMockBuilder(PortalClient::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['load', 'loadBasic'])
                ->getMock();

            $mockCtrl
                ->expects($this->any())
                ->method('load')
                ->willReturnCallback(
                    function ($nameOrId) use ($app) {
                        $portal = PortalHelper::load($app['dbs']['go1'], $nameOrId);
                        if (!$portal) {
                            return null;
                        }
                        $mockPortal = $this
                            ->getMockBuilder(Portal::class)
                            ->disableOriginalConstructor()
                            ->onlyMethods(['toObject'])
                            ->getMock();
                        $mockPortal->expects($this->any())
                            ->method('toObject')
                            ->willReturn($portal);
                        return $mockPortal;
                    }
                );

            $mockCtrl
                ->expects($this->any())
                ->method('loadBasic')
                ->willReturnCallback(
                    function ($nameOrId) use ($app) {
                        $portal = PortalHelper::load($app['dbs']['go1'], $nameOrId);
                        if (!$portal) {
                            return null;
                        }
                        return PortalBasic::create((object) [
                            'id' => $portal->id,
                            'title' => $portal->title,
                            'data_residency_region' => null,
                            'partner_portal_id' => 0
                        ]);
                    }
                );

            $rClient = new ReflectionProperty(PortalClient::class, 'client');
            $rClient->setAccessible(true);
            $rClient->setValue($mockCtrl, $client = $this->getMockBuilder(Client::class)->setMethods(['get'])->getMock());
            $client
                ->expects($this->any())
                ->method('get')
                ->willReturnCallback(
                    function ($url) {
                        if (strpos($url, "portal-licensing.mygo1.com") !== false) {
                            return new Response(200, [], json_encode(['data' => 1]));
                        }

                        return new Response(404, [], null);
                    }
                );
            $rClient->setAccessible(true);
            $rClient->setValue($mockCtrl, $client);

            $rCtrl = new ReflectionObject($ctrl);
            $rPortalServiceUrl = $rCtrl->getProperty('portalServiceUrl');
            $rPortalServiceUrl->setAccessible(true);
            $rMockPortalServiceUrl = new ReflectionProperty(PortalClient::class, 'portalServiceUrl');
            $rMockPortalServiceUrl->setAccessible(true);
            $rMockPortalServiceUrl->setValue($mockCtrl, $rPortalServiceUrl);

            return $mockCtrl;
        });
    }

    protected function mockContentSubscription(DomainService $app, int $status)
    {
        $app->extend(ContentSubscriptionService::class, function (ContentSubscriptionService $ctrl) use ($app, $status) {
            $rCtrl = new ReflectionObject($ctrl);
            $rClient = $rCtrl->getProperty('client');
            $rClient->setAccessible(true);
            $rClient->setValue($ctrl, $client = $this->getMockBuilder(Client::class)->setMethods(['get', 'post'])->getMock());
            $client
                ->expects($this->any())
                ->method('get')
                ->willReturnCallback(
                    function ($url) use ($status) {
                        if (strpos($url, 'check_status') !== false) {
                            return new Response(200, [], json_encode(['status' => $status]));
                        }

                        return new Response(404, [], null);
                    }
                );

            $client
                ->expects($this->any())
                ->method('post')
                ->willReturnCallback(
                    function ($url) use ($status) {
                        if ($status < 3) {
                            return new Response(404, [], "");
                        }

                        return new Response(200, [], json_encode([
                            "status" => "OK",
                            "result" => [
                                [
                                    "id" => "1",
                                    "account_id" => null,
                                    "user_id" => null,
                                    "status" => "1",
                                    "content_subscription_subscription_id" => "1",
                                    "assigned_entity_user" => "user",
                                    "assigned_entity_id" => "1",
                                ],
                            ],
                        ]));
                    }
                );

            return $ctrl;
        });
    }

    protected function mockDimensionsClient(DomainService $app)
    {
        $app->extend(DimensionsClient::class, function () use ($app) {
            $dimensionsClient = $this
                ->getMockBuilder(DimensionsClient::class)
                ->disableOriginalConstructor()
                ->setMethods(['getDimensions'])
                ->getMock();

            $dimensionsClient
                ->expects($this->any())
                ->method('getDimensions')
                ->willReturn(
                    ["TRAINING", "COMMITTEE", "VIDEO", "CONFERENCE", "WEBINAR", "EDUCATION", "WORKSHOP", "EVENT", "EXAM", "MENTORING", "PODCAST", "READING", "SEMINAR", "STUDY"]
                );

            return $dimensionsClient;
        });
    }

    protected function loadPlanByEnrolmentId(Connection $db, int $enrolmentId): ?Plan
    {
        $planId = $db->fetchColumn('SELECT plan_id FROM gc_enrolment_plans WHERE enrolment_id = ?', [$enrolmentId]);
        $raw = PlanHelper::load($db, $planId);

        return $raw ? Plan::create($raw) : null;
    }
}
