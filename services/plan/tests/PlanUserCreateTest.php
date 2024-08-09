<?php

namespace go1\core\learning_record\plan\tests;

use go1\app\DomainService;
use go1\enrolment\services\ContentSubscriptionService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\edge\EdgeTypes;
use go1\util\lo\LoStatuses;
use go1\util\plan\Plan;
use go1\util\plan\PlanStatuses;
use go1\util\queue\Queue;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use ReflectionObject;
use Symfony\Component\HttpFoundation\Request;

class PlanUserCreateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use PlanMockTrait;

    protected $portalId;
    protected $portalName = 'foo.com';
    protected $loId;
    private $archivedLoId;
    private $unpublishedLoId;
    protected $fooUserId = 33;
    protected $fooAccountId;
    protected $fooUserJwt;
    protected $fooManagerUserId = 44;
    protected $fooManagerUserJwt;
    private $barUserId = 55;
    protected $barUserJwt;
    protected $adminUserId = 66;
    protected $adminUserJwt;
    protected $authorUserId = 77;
    protected $authorUserJwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $db                    = $app['dbs']['go1'];
        $this->portalId        = $this->createPortal($db, ['title' => $this->portalName]);
        $this->loId            = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $this->archivedLoId    = $this->createCourse($db, ['instance_id' => $this->portalId, 'published' => LoStatuses::ARCHIVED]);
        $this->unpublishedLoId = $this->createCourse($db, ['instance_id' => $this->portalId, 'published' => LoStatuses::UNPUBLISHED]);

        $this->createUser($db, ['id' => $this->adminUserId, 'instance' => $app['accounts_name'], 'mail' => 'admin@foo.com']);
        $adminAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'admin@foo.com']);
        $this->link($db, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($db, ['instance' => $this->portalName]));
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->adminUserId, $adminAccountId);
        $this->adminUserJwt = $this->jwtForUser($db, $this->adminUserId, $this->portalName);

        $this->createUser($db, ['id' => $this->fooManagerUserId, 'instance' => $app['accounts_name'], 'mail' => 'fooManager@foo.com']);
        $fooManagerAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'fooManager@foo.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->fooManagerUserId, $fooManagerAccountId);
        $this->fooManagerUserJwt = $this->jwtForUser($db, $this->fooManagerUserId, $this->portalName);

        $this->createUser($db, ['id' => $this->fooUserId, 'instance' => $app['accounts_name'], 'mail' => 'foo@foo.com']);
        $this->fooAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'foo@foo.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->fooUserId, $this->fooAccountId);
        $this->link($db, EdgeTypes::HAS_MANAGER, $this->fooAccountId, $this->fooManagerUserId);
        $this->fooUserJwt = $this->jwtForUser($db, $this->fooUserId, $this->portalName);

        $this->createUser($db, ['id' => $this->barUserId, 'instance' => $app['accounts_name'], 'mail' => 'bar@foo.com']);
        $barAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'bar@foo.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->barUserId, $barAccountId);
        $this->barUserJwt = $this->jwtForUser($db, $this->barUserId, $this->portalName);

        $this->createUser($db, ['id' => $this->authorUserId, 'instance' => $app['accounts_name'], 'mail' => 'author@foo.com']);
        $authorAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'author@foo.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->authorUserId, $authorAccountId);
        $this->link($db, EdgeTypes::HAS_AUTHOR_EDGE, $this->loId, $this->authorUserId);
        $this->authorUserJwt = $this->jwtForUser($db, $this->authorUserId, $this->portalName);
    }

    public function data200()
    {
        $this->getApp();

        return [
            [$this->fooManagerUserJwt, $this->fooManagerUserId],
            [$this->adminUserJwt, $this->adminUserId],
            [$this->authorUserJwt, $this->authorUserId],
            [$this->fooUserJwt, null],
        ];
    }

    /** @dataProvider data200 */
    public function test200($jwt, $assignerId)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$jwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
            'notify'   => true,
            'data'     => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);

        $plan = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertTrue(is_numeric($plan->id));
        $this->assertArrayHasKey(Queue::PLAN_CREATE, $this->queueMessages);
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $createdPlanMsg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $plan = Plan::create($createdPlanMsg);
        $this->assertEquals($this->fooUserId, $plan->userId);
        $this->assertEquals($plan->assignerId, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(PlanStatuses::ASSIGNED, $plan->status);
        $this->assertEquals(Plan::TYPE_LO, $plan->entityType);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(true, $createdPlanMsg->notify);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
        $this->assertEquals(true, $createdPlanMsg->_context->notify);
        $this->assertNotEmpty($createdPlanMsg->embedded->account);
        $this->assertEquals($this->fooAccountId, $createdPlanMsg->embedded->account->id);

        $req->request->set('data', ['note' => 'bar']);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals($plan->id, json_decode($res->getContent())->id);
        $this->assertArrayHasKey(Queue::PLAN_UPDATE, $this->queueMessages);
        $this->assertNotNull($this->queueMessages[Queue::PLAN_UPDATE][0]['id']);

        $message = $this->queueMessages[Queue::PLAN_UPDATE][0];
        $this->assertNotEmpty($message['embedded']['account']);
        $this->assertEquals($this->fooAccountId, $message['embedded']['account']['id']);
    }

    public function test200Self()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/self?jwt={$this->barUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => null,
            'data'     => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);

        $plan = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertTrue(is_numeric($plan->id));
        $createdPlanMsg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $plan = Plan::create($createdPlanMsg);
        $this->assertEquals($this->barUserId, $plan->userId);
        $this->assertEquals($plan->assignerId, $plan->assignerId);
        $this->assertEquals(true, $createdPlanMsg->_context->notify);
    }

    public function test200SelfDisableNotify()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/self?jwt={$this->barUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => null,
            'data'     => ['note' => 'foo'],
            'notify'   => false,
        ]);

        $res = $app->handle($req);

        $plan = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertTrue(is_numeric($plan->id));

        $createdPlanMsg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $plan = Plan::create($createdPlanMsg);
        $this->assertEquals($this->barUserId, $plan->userId);
        $this->assertEquals($plan->assignerId, $plan->assignerId);
        $this->assertEquals(false, $createdPlanMsg->_context->notify);
    }

    public function test200ExistSchedule()
    {
        $app = $this->getApp();
        $this->createPlan($app['dbs']['go1'], [
            'user_id'     => $this->fooUserId,
            'instance_id' => $this->portalId,
            'entity_type' => Plan::TYPE_LO,
            'entity_id'   => $this->loId,
            'status'      => PlanStatuses::SCHEDULED,
            'due_date'    => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
        ]);
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$this->fooUserJwt}", 'POST');
        $req->request->replace([
            'status' => PlanStatuses::ASSIGNED,
            'notify' => true,
            'data'   => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);

        $plan = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertTrue(is_numeric($plan->id));
        $this->assertArrayHasKey(Queue::PLAN_UPDATE, $this->queueMessages);
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_UPDATE]);
        $createdPlanMsg = json_decode(json_encode($this->queueMessages[Queue::PLAN_UPDATE][0]));
        $plan = Plan::create($createdPlanMsg);
        $this->assertEquals($this->fooUserId, $plan->userId);
        $this->assertEquals($plan->assignerId, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(PlanStatuses::ASSIGNED, $plan->status);
        $this->assertEquals(Plan::TYPE_LO, $plan->entityType);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(true, $createdPlanMsg->notify);
    }

    public function test403InvalidJwt()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt=xx", 'POST');

        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Missing or invalid JWT.', $res->getContent());
    }

    public function test403Access()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$this->barUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => null,
            'notify'   => true,
            'data'     => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Only user, LO author, user\'s manager or admin can assign learning.', json_decode($res->getContent())->message);
    }

    public function test403InvalidUser()
    {
        $app       = $this->getApp();
        $db        = $app['dbs']['go1'];
        $bazUserId = $this->createUser($db, ['instance' => $app['accounts_name']]);
        $bazJwt    = $this->jwtForUser($db, $bazUserId);
        $req       = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$bazJwt}", 'POST');

        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('User not belong to portal.', $res->getContent());
    }

    public function data404()
    {
        $this->getApp();

        return [
            [99, $this->loId, $this->fooUserId, 'Portal not found.'],
            [$this->portalId, 99, $this->fooUserId, 'LO not found.'],
            [$this->portalId, $this->loId, 99, 'User not found.'],
            [$this->portalId, $this->archivedLoId, $this->fooUserId, 'LO not found.'],
            [$this->portalId, $this->unpublishedLoId, $this->fooUserId, 'LO not found.'],
        ];
    }

    /** @dataProvider data404 */
    public function test404($portalId, $loId, $userId, $expectedMsg)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$portalId}/{$loId}/user/{$userId}?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => (new \DateTime('+1 day'))->format(DATE_ISO8601),
            'notify'   => true,
            'data'     => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
        $this->assertStringContainsString($expectedMsg, $res->getContent());
    }

    public function test400User()
    {
        $app    = $this->getApp();
        $userId = $this->createUser($app['dbs']['go1'], ['mail' => 'no-portal-account@go1.co', 'instance' => $app['accounts_name']]);
        $req    = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$userId}?jwt={$this->adminUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => (new \DateTime('+1 day'))->format(DATE_ISO8601),
            'notify'   => true,
            'data'     => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('User does not belong to portal.', $res->getContent());
    }

    public function data400Assert()
    {
        return [
            [['status' => 99], 'Value "99" is not an element of the valid values:'],
            [['due_date' => 0], 'Invalid due date.'],
            [['data' => 0], 'Value "0" is not an array.'],
            [['data' => []], 'The element with key "note" was not found'],
            [['data' => ['note' => 99]], 'Value "99" expected to be string, type integer given.'],
        ];
    }

    /** @dataProvider data400Assert */
    public function test400Assert($options, $expectedMsg)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$this->barUserJwt}", 'POST');
        $req->request->replace([
            'status'   => $options['status'] ?? PlanStatuses::ASSIGNED,
            'due_date' => $options['due_date'] ?? (new \DateTime('+1 day'))->format(DATE_ISO8601),
            'notify'   => true,
            'data'     => $options['data'] ?? ['note' => 'foo'],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString($expectedMsg, json_decode($res->getContent())->message);
    }

    public function testLicenseRequiredCanClaim()
    {
        $app = $this->getApp();
        $this->mockContentSubscriptionClient($app);

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->adminUserId}?jwt={$this->adminUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => (new \DateTime('+1 day'))->format(DATE_ISO8601),
            'notify'   => true,
            'data'     => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testLicenseRequiredCannotClaim()
    {
        $app = $this->getApp();
        $this->mockContentSubscriptionClient($app, false);

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->adminUserId}?jwt={$this->adminUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => (new \DateTime('+1 day'))->format(DATE_ISO8601),
            'notify'   => true,
            'data'     => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }

    protected function mockContentSubscriptionClient($app, $claimable = true)
    {
        $app->extend(ContentSubscriptionService::class, function (ContentSubscriptionService $ctrl) use ($claimable) {
            $rCtrl = new ReflectionObject($ctrl);
            $rClient = $rCtrl->getProperty('client');
            $rClient->setAccessible(true);
            $rClient->setValue($ctrl, $client = $this->getMockBuilder(Client::class)->setMethods(['post'])->getMock());

            $client
                ->expects($this->any())
                ->method('post')
                ->willReturnCallback(
                    function (string $uri, $options) use ($claimable) {
                        if ($claimable) {
                            return new Response(200, [], json_encode([
                                'status' => 'OK',
                            ]));
                        } else {
                            return new Response(400, [], null);
                        }
                    }
                );

            return $ctrl;
        });
    }

    public function test400DeactivatedLearner()
    {
        $app = $this->getApp();

        $db = $app['dbs']['go1'];
        $barUserId = $this->createUser($db, [
            'instance' => $app['accounts_name'],
            'mail'     => 'bar.deactivated@foo.com'
        ]);
        $barAccountId = $this->createUser($db, [
            'instance' => $this->portalName,
            'mail'     => 'bar.deactivated@foo.com',
            'status'   => 0
        ]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $barUserId, $barAccountId);

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$barUserId}?jwt={$this->adminUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => (new \DateTime('+1 day'))->format(DATE_ISO8601),
            'notify'   => false
        ]);

        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());

        $error = json_decode($res->getContent(), true);
        $this->assertEquals("The account connected to this plan is deactivated.", $error['message']);
    }
}
