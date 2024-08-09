<?php

namespace go1\core\learning_record\plan\tests;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\edge\EdgeTypes;
use go1\util\plan\Plan;
use go1\util\queue\Queue;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;
use Firebase\JWT\JWT;

class PlanUpdateControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use PlanMockTrait;

    private int    $portalId;
    private int    $userId;
    private int    $accountId;
    private string $portalName = 'foo.com';
    private string $ownerJwt;
    private int    $managerId;
    private string $managerJwt;
    private int    $adminId;
    private string $adminJwt;
    private string $rootJwt;
    private int    $planId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $db = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $loId = $this->createCourse($db, ['instance_id' => $this->portalId]);

        $this->adminId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => 'admin@go1.com']);
        $adminAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'admin@go1.com']);
        $this->link($db, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($db, ['instance' => $this->portalName]));
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->adminId, $adminAccountId);
        $this->adminJwt = $this->jwtForUser($db, $this->adminId, $this->portalName);

        $this->managerId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => 'manager@go1.com']);
        $managerAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'manager@go1.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->managerId, $managerAccountId);
        $this->managerJwt = $this->jwtForUser($db, $this->managerId, $this->portalName);

        $this->userId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => 'learner@go1.com']);
        $this->accountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'learner@go1.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);
        $this->link($db, EdgeTypes::HAS_MANAGER, $this->accountId, $this->managerId);
        $this->ownerJwt = $this->jwtForUser($db, $this->userId, $this->portalName);

        $this->rootJwt = JWT::encode((array) $this->getRootPayload(), 'INTERNAL', 'HS256');

        $this->planId = $this->createPlan($db, [
            'user_id'     => $this->userId,
            'assigner_id' => $this->userId,
            'entity_id'   => $loId,
            'instance_id' => $this->portalId,
        ]);
    }

    public function test403()
    {
        # No JWT
        {
            $app = $this->getApp();

            $req = Request::create("/plan/{$this->planId}", 'PUT');
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
        }

        # Learner can not update others
        {
            $app = $this->getApp();
            /** @var Connection $go1 */
            $go1 = $app['dbs']['go1'];

            $userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'learner-02@go1.com']);
            $accountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'learner-02@go1.com']);
            $this->link($go1, EdgeTypes::HAS_ACCOUNT, $userId, $accountId);
            $learnerJWT = $this->jwtForUser($go1, $userId, $this->portalName);

            $req = Request::create("/plan/{$this->planId}?jwt=$learnerJWT", 'PUT');
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
            $this->assertStringContainsString("Only portal administrator and user's manager can update assign learning.", json_decode($res->getContent())->message);
        }
    }

    public function test404()
    {
        $app = $this->getApp();

        $req = Request::create("/plan/404?jwt=$this->adminJwt", 'PUT');
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
        $this->assertEquals('Plan object is not found.', json_decode($res->getContent())->message);
    }

    public function testValidateInput()
    {
        $app = $this->getApp();

        # Must be a unix timestamp
        {
            $req = Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'PUT');
            $req->request->replace([
                'due_date' => 'foo',
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('Due date must be unix timestamp value', json_decode($res->getContent())->message);
        }

        # can not be in the past
        {
            $req = Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'PUT');
            $req->request->replace([
                'due_date' => (new \DateTime("now -1 days"))->getTimestamp(),
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('Due date can not be in the past', json_decode($res->getContent())->message);
        }

        # Check field name
        {
            $req = Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'PUT');
            $req->request->replace([
                'due_date_invalid' => (new \DateTime('+1 day'))->getTimestamp(),
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString("Unknown field 'due_date_invalid'", json_decode($res->getContent())->message);
        }
    }

    public function testBadData()
    {
        # Associate portal is not found.
        {
            $app = $this->getApp();
            /** @var Connection $go1 */
            $go1 = $app['dbs']['go1'];
            $go1->delete('gc_instance', ['id' => $this->portalId]);

            $req = Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'PUT');
            $req->request->replace([
                'due_date' => (new \DateTime('+1 day'))->getTimestamp(),
            ]);

            $res = $app->handle($req);
            $this->assertStringContainsString('The portal connected to this plan does not exist.', json_decode($res->getContent())->message);
        }

        # Associate user is not found.
        {
            $app = $this->getApp();
            /** @var Connection $go1 */
            $go1 = $app['dbs']['go1'];
            $go1->delete('gc_user', ['id' => $this->userId]);

            $req = Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'PUT');
            $req->request->replace([
                'due_date' => (new \DateTime('+1 day'))->getTimestamp(),
            ]);

            $res = $app->handle($req);
            $this->assertStringContainsString('The account connected to this plan does not exist.', json_decode($res->getContent())->message);
        }
    }

    public function testDueDateCanBeNull()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'PUT');
        $req->request->replace([
            'due_date' => null,
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertArrayHasKey(Queue::PLAN_UPDATE, $this->queueMessages);
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_UPDATE]);
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_UPDATE][0]));
        $plan = Plan::create($msg);
        $this->assertNull($plan->due);
        $this->assertTrue($msg->notify);
    }

    public function testPortalAdminCanUpdate()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $req = Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'PUT');
        $req->request->replace([
            'due_date' => $dueDate = (new \DateTime('+1 day'))->getTimestamp(),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertArrayHasKey(Queue::PLAN_UPDATE, $this->queueMessages);
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_UPDATE]);
        $this->assertEquals($this->adminId, $db->fetchColumn('SELECT assigner_id FROM gc_plan WHERE id = ?', [$this->planId]));
        $this->assertEquals($this->userId, $db->fetchColumn('SELECT assigner_id FROM gc_plan_revision WHERE id = ?', [$this->planId]));
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_UPDATE][0]));
        $plan = Plan::create($msg);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
        $this->assertNotEquals($msg->assigner_id, $msg->original->assigner_id);
        $this->assertTrue($msg->notify);
    }

    public function testManagerCanUpdate()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $req = Request::create("/plan/{$this->planId}?jwt={$this->managerJwt}", 'PUT');
        $req->request->replace([
            'due_date' => (new \DateTime('+1 day'))->getTimestamp(),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($this->managerId, $db->fetchColumn('SELECT assigner_id FROM gc_plan WHERE id = ?', [$this->planId]));
        $this->assertEquals($this->userId, $db->fetchColumn('SELECT assigner_id FROM gc_plan_revision WHERE id = ?', [$this->planId]));
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_UPDATE][0]));
        $this->assertNotEquals($msg->assigner_id, $msg->original->assigner_id);
        $this->assertTrue($msg->notify);
    }

    public function testOwnerCanUpdate()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $req = Request::create("/plan/{$this->planId}?jwt={$this->ownerJwt}", 'PUT');
        $req->request->replace([
            'due_date' => (new \DateTime('+1 day'))->getTimestamp(),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($this->userId, $db->fetchColumn('SELECT assigner_id FROM gc_plan WHERE id = ?', [$this->planId]));
        $this->assertEquals($this->userId, $db->fetchColumn('SELECT assigner_id FROM gc_plan_revision WHERE id = ?', [$this->planId]));
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_UPDATE][0]));
        $this->assertEquals($msg->assigner_id, $msg->original->assigner_id);
        $this->assertTrue($msg->notify);
    }

    public function testDeactivatedPlanOwner()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $db->update('gc_user', ['status' => 0], ['id' => $this->accountId]);

        $req = Request::create("/plan/{$this->planId}?jwt={$this->ownerJwt}", 'PUT');
        $req->request->replace([
            'due_date' => (new \DateTime('+1 day'))->getTimestamp(),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());

        $error = json_decode($res->getContent(), true);
        $this->assertEquals("The account connected to this plan is deactivated.", $error['message']);
    }

    public function testGo1StaffCanUpdateWithAssignerId()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $req = Request::create("/plan/{$this->planId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'due_date'    => (new \DateTime('+1 day'))->getTimestamp(),
            'assigner_id' => $this->managerId
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($this->managerId, $db->fetchColumn('SELECT assigner_id FROM gc_plan WHERE id = ?', [$this->planId]));
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_UPDATE][0]));
        $this->assertEquals($this->managerId, $msg->assigner_id);
        $this->assertTrue($msg->notify);
    }

    public function testUpdateAssignedDate204()
    {
        $app = $this->getApp();
        $requestData = [
          'assigned_date' => $assignedDate = (new \DateTime('+1 day'))->getTimestamp(),
    ];
        $req = Request::create("/plan/{$this->planId}/update-assigned-date?jwt={$this->rootJwt}", 'POST', $requestData);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertArrayHasKey(Queue::PLAN_UPDATE, $this->queueMessages);
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_UPDATE]);
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_UPDATE][0]));
        $plan = Plan::create($msg);
        $this->assertEquals(DateTime::create($assignedDate), $plan->created);
        $this->assertFalse($msg->notify);
    }

    public function testUpdateAssignedDate403()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->planId}/update-assigned-date?jwt={$this->adminJwt}", 'POST');
        $req->request->replace([
            'assigned_date ' => (new \DateTime('+1 day'))->getTimestamp(),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString("Permission Denied. This is only accessible to Go1 staff.", json_decode($res->getContent())->message);
    }
}
