<?php

namespace go1\core\learning_record\plan\tests;

use go1\app\DomainService;
use go1\core\util\client\federation_api\v1\GraphQLClient;
use go1\core\util\client\federation_api\v1\Query;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\plan\Plan;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanStatuses;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Prophecy\Argument;
use Symfony\Component\HttpFoundation\Request;

class PlanControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use PlanMockTrait;
    use LoMockTrait;

    private int    $portalId;
    private string $portalName    = 'qa.go1.co';
    private int    $userId        = 33;
    private int    $accountId;
    private string $userJwt;
    private int    $managerUserId = 44;
    private int    $managerAccountId;
    private string $managerUserJwt;
    private int    $barUserId     = 55;
    private int    $adminUserId   = 66;
    private string $adminUserJwt;
    private int    $planId;
    private Plan   $plan;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->createUser($go1, ['id' => $this->adminUserId, 'instance' => $app['accounts_name'], 'mail' => 'admin@example.com']);
        $adminAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'admin@example.com']);
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $this->portalName]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->adminUserId, $adminAccountId);
        $this->adminUserJwt = $this->jwtForUser($go1, $this->adminUserId, $this->portalName);

        $this->createUser($go1, ['id' => $this->managerUserId, 'instance' => $app['accounts_name'], 'mail' => 'manager@example.com']);
        $this->managerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'manager@example.com']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->managerUserId, $this->managerAccountId);
        $this->managerUserJwt = $this->jwtForUser($go1, $this->managerUserId, $this->portalName);

        $this->createUser($go1, ['id' => $this->userId, 'instance' => $app['accounts_name'], 'mail' => 'student@example.com']);
        $this->accountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'student@example.com']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $this->accountId, $this->managerUserId);
        $this->userJwt = $this->jwtForUser($go1, $this->userId, $this->portalName);

        $this->planId = $this->createPlan($go1, [
            'user_id'     => $this->userId,
            'assigner_id' => $this->managerUserId,
            'instance_id' => $this->portalId,
            'entity_type' => Plan::TYPE_LO,
            'entity_id'   => $this->createCourse($go1, ['id' => 99]),
            'status'      => PlanStatuses::ASSIGNED,
        ]);
        $this->plan = $app[PlanRepository::class]->load($this->planId);

        $app->extend('go1.client.federation_api.v1', function () {
            $client = $this->prophesize(GraphQLClient::class);
            $client
                ->execute(Argument::that(function (Query $query) {
                    $vars = $query->getVariables();
                    $gql = $query->getGql();

                    $this->assertStringContainsString('getLearningPlan(id: $getLearningPlan__id) { id legacyId type dueDate createdAt updatedAt author { id uuid ulid email status firstName lastName allowPublic createdAt avatarUri locale lastLoggedInAt migrated legacyId profileId timestamp lastAccessedAt } user { id uuid ulid email status firstName lastName allowPublic createdAt avatarUri locale lastLoggedInAt migrated legacyId profileId timestamp lastAccessedAt account(portal: $account__portal) { legacyId } } space { legacyId subDomain } }', $gql);

                    return base64_encode("go1:LearningPlan:gc_plan.$this->planId") == $vars['getLearningPlan__id']['value'];
                }))
                ->willReturn(json_encode([
                    'data' => [
                        'data' => [
                            'id'        => 'xxxx',
                            'legacyId'  => $this->planId,
                            'dueDate'   => '2019-04-11T09:50:28.000Z',
                            'createdAt' => '2019-04-12T09:50:28.000Z',
                            'updatedAt' => '2019-04-12T09:50:28.000Z',
                            'space'     => [
                                'legacyId'  => $this->portalId,
                                'subDomain' => $this->portalName
                            ],
                            'state'     => [
                                'legacyId'  => 1,
                                'status'    => 'COMPLETED',
                                'passed'    => true,
                                'startedAt' => '2019-04-11T09:50:28.000Z',
                                'endedAt'   => '2019-04-12T09:50:28.000Z',
                                'updatedAt' => '2019-04-12T09:50:28.000Z',
                            ],
                            'user'      => [
                                'legacyId'  => $this->userId,
                                'firstName' => 'Joe',
                                'lastName'  => 'Doe',
                                'email'     => 'student@example.com',
                                'avatarUri' => '//a.png',
                                'status'    => 'ACTIVE',
                                'account'   => [
                                    'legacyId' => $this->accountId,
                                    'status'   => 'ACTIVE',
                                ]
                            ],
                            'author'      => [
                                'legacyId'  => $this->managerUserId,
                                'firstName' => 'Joe',
                                'lastName'  => 'Manager',
                                'email'     => 'manager@example.com',
                                'avatarUri' => '//a.png',
                                'status'    => 'ACTIVE',
                                'account'   => [
                                    'legacyId' => $this->managerAccountId,
                                    'status'   => 'ACTIVE',
                                ]
                            ]
                        ]
                    ]
                ]));

            $client
                ->execute(Argument::that(function ($query) {
                    $vars = $query->getVariables();

                    return base64_encode("go1:LearningPlan:gc_plan.0") == $vars['getLearningPlan__id']['value'];
                }))
                ->willReturn(json_encode([
                    'data' => [
                        'data' => null
                    ]
                ]));

            return $client->reveal();
        });
    }

    public function test403()
    {
        $app = $this->getApp();
        $req = Request::create("/plans/{$this->planId}");
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function test404()
    {
        $app = $this->getApp();
        $req = Request::create("/plans/0?jwt=$this->adminUserJwt");
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
        $this->assertStringContainsString('Plan object not found.', $res->getContent());
    }

    public function testCanViewByPortalAdmin()
    {
        $app = $this->getApp();
        $req = Request::create("/plans/{$this->planId}?jwt=$this->adminUserJwt");
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());

        $json = json_decode($res->getContent(), true);
        $this->assertEquals([
            'id'        => 'xxxx',
            'legacyId'  => $this->planId,
            'dueDate'   => 1554976228,
            'createdAt' => 1555062628,
            'updatedAt' => 1555062628,
            'user'      => [
                'legacyId'  => $this->userId,
                'firstName' => 'Joe',
                'lastName'  => 'Doe',
                'email'     => 'student@example.com',
                'avatarUri' => '//a.png',
                'status'    => true,
            ],
            'author'    => [
                'legacyId'  => $this->managerUserId,
                'firstName' => 'Joe',
                'lastName'  => 'Manager',
                'email'     => 'manager@example.com',
                'avatarUri' => '//a.png',
                'status'    => true,
            ],
        ], $json);
    }

    public function testCanViewByManager()
    {
        $app = $this->getApp();
        $req = Request::create("/plans/{$this->planId}?jwt=$this->managerUserJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testCanViewByOwner()
    {
        $app = $this->getApp();
        $req = Request::create("/plans/{$this->planId}?jwt=$this->userJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }
}
