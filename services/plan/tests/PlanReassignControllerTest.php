<?php

namespace go1\core\learning_record\plan\tests;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\edge\EdgeTypes;
use go1\util\plan\event_publishing\PlanCreateEventEmbedder;
use go1\util\plan\event_publishing\PlanDeleteEventEmbedder;
use go1\util\plan\event_publishing\PlanUpdateEventEmbedder;
use go1\util\plan\Plan;
use go1\util\plan\PlanRepository;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;
use go1\util\user\UserHelper;

class PlanReassignControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use PlanMockTrait;
    use EnrolmentMockTrait;

    private int    $portalId;
    private int    $userId = 333333;
    private int    $accountId;
    private string $portalName = 'foo.com';
    private string $ownerJwt;
    private int    $managerId = 222222;
    private string $managerJwt;
    private int    $adminId = 111111;
    private string $adminJwt;
    private int    $planId;
    private int    $loId;
    private int    $loId2;
    private int    $loId3;
    private int    $assignerUserId = 112233;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));

        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->loId2 = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->loId3 = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->createUser($go1, ['id' => $this->adminId, 'instance' => $app['accounts_name'], 'mail' => 'admin@go1.com']);
        $adminAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'admin@go1.com']);
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $this->portalName]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->adminId, $adminAccountId);
        $this->adminJwt = $this->jwtForUser($go1, $this->adminId, $this->portalName);

        $this->createUser($go1, ['id' => $this->managerId, 'instance' => $app['accounts_name'], 'mail' => 'manager@go1.com']);
        $managerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'manager@go1.com']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->managerId, $managerAccountId);
        $this->managerJwt = $this->jwtForUser($go1, $this->managerId, $this->portalName);

        $this->createUser($go1, ['id' => $this->userId, 'instance' => $app['accounts_name'], 'mail' => 'learner@go1.com']);
        $this->accountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'learner@go1.com']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $this->accountId, $this->managerId);
        $this->ownerJwt = $this->jwtForUser($go1, $this->userId, $this->portalName);

        $this->createUser($go1, ['id' => $this->assignerUserId, 'instance' => $app['accounts_name'], 'mail' => 'assigner@go1.com']);
        $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'assigner@go1.com']);

        $this->planId = $this->createPlan($go1, [
            'user_id'     => $this->userId,
            'assigner_id' => $this->userId,
            'entity_id'   => $this->loId,
            'instance_id' => $this->portalId,
        ]);

        $enrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'profile_id' => 999, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId]);
        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $enrolmentId, 'plan_id' => $this->planId]);
        $this->createEnrolment($go1, ['user_id' => $this->userId, 'profile_id' => 999, 'lo_id' => $this->loId2, 'taken_instance_id' => $this->portalId]);
    }

    public function test403()
    {
        # No JWT
        {
            $app = $this->getApp();

            $req = Request::create("/plan/re-assign", 'POST');
            $req->request->replace([
                'plan_ids' => [$this->planId],
                'due_date' => DateTime::create('+30 days')->getTimestamp(),
            ]);
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

            $req = Request::create("/plan/re-assign?jwt=$learnerJWT", 'POST');
            $req->request->replace([
                'plan_ids' => [$this->planId],
                'due_date' => DateTime::create('+30 days')->getTimestamp(),
            ]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
            $this->assertStringContainsString("Only portal administrator and user's manager can re-assign learning.", json_decode($res->getContent())->message);
        }

        # Only accounts admin can re-assign learning
        {
            $app = $this->getApp();
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'lo_id'         => $this->loId,
                'portal_id'     => $this->portalId,
                'user_id'       => $this->userId,
                'due_date'      => DateTime::create('+35 days')->getTimestamp(),
                'reassign_date' => DateTime::create('+30 days')->getTimestamp(),
            ]);
            $res = $app->handle($req);

            $this->assertEquals(403, $res->getStatusCode());
            $this->assertStringContainsString("Only accounts admin can re-assign learning with LO id.", json_decode($res->getContent())->message);
        }
    }

    public function test404()
    {
        $app = $this->getApp();

        $req = Request::create("/plan/re-assign?jwt=$this->adminJwt", 'POST');
        $req->request->replace([
            'plan_ids' => [404],
            'due_date' => DateTime::create('+30 days')->getTimestamp(),
        ]);
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
        $this->assertEquals('Plan object is not found.', json_decode($res->getContent())->message);
    }

    public function testValidateInput()
    {
        $app = $this->getApp();

        # due_date must be a unix timestamp
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'plan_ids' => [$this->planId],
                'due_date' => 'foo',
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('Due date must be unix timestamp value', json_decode($res->getContent())->message);
        }

        # due_date can not be in the past
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'plan_ids' => [$this->planId],
                'due_date' => DateTime::create('now-1 day')->getTimestamp(),
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('Due date can not be in the past', json_decode($res->getContent())->message);
        }

        # Must be a single plan
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'plan_ids' => [$this->planId, 222],
                'due_date' => DateTime::create('+30 days')->getTimestamp(),
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('Only support a single plan for now.', json_decode($res->getContent())->message);
        }

        # Must be a single plan
        {
            $this->queueMessages = [];
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'plan_ids' => [],
                'due_date' => DateTime::create('+30 days')->getTimestamp(),
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('Only support a single plan for now.', json_decode($res->getContent())->message);
        }

        # Check field name
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'due_date_invalid' => DateTime::create('+30 days')->getTimestamp(),
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString("Unknown field 'due_date_invalid'", json_decode($res->getContent())->message);
        }

        # Check plan_ids and lo_id
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'plan_ids' => [$this->planId],
                'lo_id'    => 123,
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString("Only support either plan_ids or lo_id.", json_decode($res->getContent())->message);
        }

        # reassign_date must be a unix timestamp
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'reassign_date' => 'foo',
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('Reassign date must be unix timestamp value.', json_decode($res->getContent())->message);
        }

        # lo_id without reassign_date and due_date
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'lo_id' => 111,
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('Missing reassign date.', json_decode($res->getContent())->message);
        }

        # lo_id without reassign_date or due_date
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'lo_id'  => 111,
                'reassign_date' => DateTime::create('+30 days')->getTimestamp(),
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('Missing due date.', json_decode($res->getContent())->message);
        }

        # lo_id with reassign_date and due date
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'lo_id'  => 111,
                'due_date'      => DateTime::create('+30 days')->getTimestamp(),
                'reassign_date' => DateTime::create('+35 days')->getTimestamp(),
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('Reassign date can not be latter than Due date.', json_decode($res->getContent())->message);
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

            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'plan_ids' => [$this->planId],
                'due_date' => DateTime::create('+30 days')->getTimestamp(),
            ]);

            $res = $app->handle($req);
            $this->assertStringContainsString('The portal connected to this plan does not exist.', json_decode($res->getContent())->message);
        }

        # Associate user is not found.
        {
            $app = $this->getApp();
            /** @var Connection $go1  */
            $go1 = $app['dbs']['go1'];
            $go1->delete('gc_user', ['id' => $this->userId]);

            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'plan_ids' => [$this->planId],
                'due_date' => DateTime::create('+30 days')->getTimestamp(),
            ]);

            $res = $app->handle($req);
            $this->assertStringContainsString('The account connected to this plan does not exist.', json_decode($res->getContent())->message);
        }

        # lo_id with bad portal_id
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'lo_id'         => 111,
                'portal_id'     => 111,
                'user_id'       => 111,
                'due_date'      => DateTime::create('+50 days')->getTimestamp(),
                'reassign_date' => DateTime::create('+35 days')->getTimestamp(),
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('The portal does not exist.', json_decode($res->getContent())->message);
        }

        # lo_id with bad portal_id
        {
            $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
            $req->request->replace([
                'lo_id'         => 111,
                'portal_id'     => $this->portalId,
                'user_id'       => 111,
                'due_date'      => DateTime::create('+50 days')->getTimestamp(),
                'reassign_date' => DateTime::create('+35 days')->getTimestamp(),
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString('The account does not exist.', json_decode($res->getContent())->message);
        }
    }

    public function dataPortalAdminAssignerId(): array
    {
        return [
          [null, $this->adminId],
          [$this->assignerUserId, $this->assignerUserId],
        ];
    }

    /** @dataProvider dataPortalAdminAssignerId */
    public function testPortalAdminCanUpdate($assignerUserId, int $expectedAssignerUserId)
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
        $req->request->replace(
            array_filter([
                'plan_ids'         => [$this->planId],
                'due_date'         => $dueDate = DateTime::create('+30 days')->getTimestamp(),
                'assigner_user_id' => $assignerUserId,
            ])
        );

        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $newPlanId = json_decode($res->getContent())[0]->id;
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_DELETE]);
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$this->planId]));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$newPlanId]));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(assigner_id) FROM gc_plan WHERE assigner_id = ?', [$expectedAssignerUserId]));
        $this->assertEquals($this->userId, $go1->fetchColumn('SELECT assigner_id FROM gc_plan_revision WHERE id = ?', [$this->planId]));
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $plan = Plan::create($msg);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
        $this->assertLessThanOrEqual(1, time() - strtotime($msg->created_date));
        $this->assertEquals($this->userId, $msg->embedded->original->assigner_id);
        $this->assertEquals('reassigned', $msg->_context->action);
    }

    public function dataManagerAssignerId(): array
    {
        return [
            [null, $this->managerId],
            [$this->assignerUserId, $this->assignerUserId],
        ];
    }

    /** @dataProvider dataManagerAssignerId */
    public function testManagerCanUpdate($assignerUserId, int $expectedAssignerUserId)
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/plan/re-assign?jwt={$this->managerJwt}", 'POST');
        $req->request->replace(
            array_filter([
                'plan_ids'         => [$this->planId],
                'due_date'         => DateTime::create('+30 days')->getTimestamp(),
                'assigner_user_id' => $assignerUserId,
            ])
        );

        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $newPlanId = json_decode($res->getContent())[0]->id;
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$this->planId]));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$newPlanId]));
        $this->assertEquals($this->userId, $go1->fetchColumn('SELECT assigner_id FROM gc_plan_revision WHERE id = ?', [$this->planId]));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(assigner_id) FROM gc_plan WHERE assigner_id = ?', [$expectedAssignerUserId]));
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_DELETE]);
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $this->assertLessThanOrEqual(1, time() - strtotime($msg->created_date));
        $this->assertEquals($this->userId, $msg->embedded->original->assigner_id);
        $this->assertEquals('reassigned', $msg->_context->action);
    }

    public function dataOwnerAssignerId(): array
    {
        return [
            [null, $this->userId],
            [$this->assignerUserId, $this->assignerUserId],
        ];
    }

    /** @dataProvider dataOwnerAssignerId */
    public function testOwnerCanUpdate($assignerUserId, int $expectedAssignerUserId)
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/plan/re-assign?jwt={$this->ownerJwt}", 'POST');
        $req->request->replace(
            array_filter([
                'plan_ids'         => [$this->planId],
                'due_date'         => DateTime::create('+30 days')->getTimestamp(),
                'assigner_user_id' => $assignerUserId,
            ])
        );

        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $newPlanId = json_decode($res->getContent())[0]->id;
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$this->planId]));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$newPlanId]));
        $this->assertEquals($this->userId, $go1->fetchColumn('SELECT assigner_id FROM gc_plan_revision WHERE id = ?', [$this->planId]));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(assigner_id) FROM gc_plan WHERE assigner_id = ?', [$expectedAssignerUserId]));
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_DELETE]);
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $this->assertLessThanOrEqual(1, time() - strtotime($msg->created_date));
        $this->assertEquals($this->userId, $msg->embedded->original->assigner_id);
        $this->assertEquals('reassigned', $msg->_context->action);
    }

    public function testRollbackWhenDBHaveIssue()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $queue = $app['go1.client.mq'];

        //batchDone must be not call when rollback trigger
        $queue
            ->expects($this->never())
            ->method('batchDone');

        $app->extend(PlanRepository::class, function () use ($go1, $queue) {
            $planRepository = $this
                ->getMockBuilder(PlanRepository::class)
                ->setConstructorArgs([
                    $go1,
                    $queue,
                    new PlanCreateEventEmbedder($go1),
                    new PlanUpdateEventEmbedder($go1),
                    new PlanDeleteEventEmbedder($go1),
                ])
                ->setMethods(['create'])
                ->getMock();
            $planRepository
                ->expects($this->any())
                ->method('create')
                ->willReturnCallback(function () {
                    throw new \Exception('Database error.');
                });

            return $planRepository;
        });

        $req = Request::create("/plan/re-assign?jwt={$this->adminJwt}", 'POST');
        $req->request->replace([
            'plan_ids' => [$this->planId],
            'due_date' => DateTime::create('+30 days')->getTimestamp(),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(500, $res->getStatusCode());
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$this->planId]));
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(assigner_id) FROM gc_plan WHERE assigner_id = ?', [$this->adminId]));
    }

    public function testReassignWithDeactivatedAssignee()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $db->update('gc_user', ['status' => 0], ['id' => $this->accountId]);

        $req = Request::create("/plan/re-assign?jwt={$this->managerJwt}", 'POST');
        $req->request->replace([
            'plan_ids' => [$this->planId],
            'due_date' => DateTime::create('+30 days')->getTimestamp(),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());

        $error = json_decode($res->getContent(), true);
        $this->assertEquals("The account connected to this plan is deactivated.", $error['message']);
    }

    public function testManagerWithoutDue()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/re-assign?jwt={$this->managerJwt}", 'POST');
        $req->request->replace([
            'plan_ids' => [$this->planId],
        ]);

        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());

        $plan = $this->queueMessages[Queue::PLAN_CREATE][0];
        $this->assertNull($plan['due_date']);
    }

    public function testManagerWithDue()
    {
        $app = $this->getApp();
        $due = DateTime::create('+30 days');
        $req = Request::create("/plan/re-assign?jwt={$this->managerJwt}", 'POST');
        $req->request->replace([
            'plan_ids' => [$this->planId],
            'due_date' => $due->getTimestamp(),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());

        $plan = $this->queueMessages[Queue::PLAN_CREATE][0];
        $this->assertEquals($due->format(DATE_ISO8601), $plan['due_date']);
    }

    public function testManagerWithNullDue()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/re-assign?jwt={$this->managerJwt}", 'POST');
        $req->request->replace([
            'plan_ids' => [$this->planId],
            'due_date' => null,
        ]);
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());

        $plan = $this->queueMessages[Queue::PLAN_CREATE][0];
        $this->assertNull($plan['due_date']);
    }

    # accounts admin can re-assign learning in future day
    public function testRootWithLoIdAndReassignInFuture()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $planCount =  $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE instance_id = ? AND user_id = ? AND entity_id = ?', [$this->portalId, $this->userId, $this->loId]);
        $this->assertEquals(1, $planCount);
        $req = Request::create(("/plan/re-assign?jwt=" . UserHelper::ROOT_JWT), 'POST');
        $req->request->replace([
            'lo_id'         => $this->loId,
            'user_id'       => $this->userId,
            'portal_id'     => $this->portalId,
            'due_date'      => DateTime::create('+35 days')->getTimestamp(),
            'reassign_date' => DateTime::create('+30 days')->getTimestamp(),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());

        $newPlanId = json_decode($res->getContent())[0]->id;
        $fetchedNewPlan =  $go1->fetchColumn('SELECT id FROM gc_plan WHERE instance_id = ? AND user_id = ? AND entity_id = ?', [$this->portalId, $this->userId, $this->loId]);
        $this->assertEquals($fetchedNewPlan, $newPlanId);
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $this->assertEquals('auto-reassigned', $msg->_context->action);
    }

    # accounts admin can re-assign learning in the past day
    public function testRootWithLoIdAndReassignInThePast()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $planCount =  $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE instance_id = ? AND user_id = ? AND entity_id = ?', [$this->portalId, $this->userId, $this->loId]);
        $this->assertEquals(1, $planCount);
        $req = Request::create("/plan/re-assign?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'lo_id'         => $this->loId,
            'user_id'       => $this->userId,
            'portal_id'     => $this->portalId,
            'due_date'      => DateTime::create('+35 days')->getTimestamp(),
            'reassign_date' => DateTime::create('-20 days')->getTimestamp(),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $newPlanId = json_decode($res->getContent())[0]->id;
        $fetchedNewPlan =  $go1->fetchColumn('SELECT id FROM gc_plan WHERE instance_id = ? AND user_id = ? AND entity_id = ?', [$this->portalId, $this->userId, $this->loId]);
        $this->assertEquals($fetchedNewPlan, $newPlanId);
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $this->assertEquals('auto-reassigned', $msg->_context->action);
    }

    # accounts admin can re-assign learning with due day in the past
    public function testRootWithLoIdAndDueDateInThePast()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $planCount =  $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE instance_id = ? AND user_id = ? AND entity_id = ?', [$this->portalId, $this->userId, $this->loId]);
        $this->assertEquals(1, $planCount);
        $req = Request::create("/plan/re-assign?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'lo_id'         => $this->loId,
            'user_id'       => $this->userId,
            'portal_id'     => $this->portalId,
            'due_date'      => DateTime::create('-10 days')->getTimestamp(),
            'reassign_date' => DateTime::create('-20 days')->getTimestamp(),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $newPlanId = json_decode($res->getContent())[0]->id;
        $fetchedNewPlan =  $go1->fetchColumn('SELECT id FROM gc_plan WHERE instance_id = ? AND user_id = ? AND entity_id = ?', [$this->portalId, $this->userId, $this->loId]);
        $this->assertEquals($fetchedNewPlan, $newPlanId);
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $this->assertEquals('auto-reassigned', $msg->_context->action);
    }

    # accounts admin can re-assign learning without original plan
    public function testRootWithLoIdWithoutCurrentPlan()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/plan/re-assign?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $planCount =  $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE instance_id = ? AND user_id = ? AND entity_id = ?', [$this->portalId, $this->userId, $this->loId2]);
        $this->assertEquals(0, $planCount);

        $req->request->replace([
            'lo_id'         => $this->loId2,
            'user_id'       => $this->userId,
            'portal_id'     => $this->portalId,
            'due_date'      => DateTime::create('+35 days')->getTimestamp(),
            'reassign_date' => DateTime::create('-20 days')->getTimestamp(),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $newPlanId = json_decode($res->getContent())[0]->id;
        $fetchedNewPlan =  $go1->fetchColumn('SELECT id FROM gc_plan WHERE instance_id = ? AND user_id = ? AND entity_id = ?', [$this->portalId, $this->userId, $this->loId2]);
        $this->assertEquals($fetchedNewPlan, $newPlanId);
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $this->assertEquals('auto-reassigned', $msg->_context->action);
    }

    public function testRootWithLoIdWithoutAnyPlansOrEnrolments()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/plan/re-assign?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $planCount =  $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE instance_id = ? AND user_id = ? AND entity_id = ?', [$this->portalId, $this->userId, $this->loId3]);
        $this->assertEquals(0, $planCount);
        $enrolmentCount =  $go1->fetchColumn('SELECT count(id) FROM gc_enrolment WHERE taken_instance_id = ? AND user_id = ? AND lo_id = ?', [$this->portalId, $this->userId, $this->loId3]);
        $this->assertEquals(0, $enrolmentCount);

        $req->request->replace([
            'lo_id'         => $this->loId3,
            'user_id'       => $this->userId,
            'portal_id'     => $this->portalId,
            'due_date'      => DateTime::create('+35 days')->getTimestamp(),
            'reassign_date' => DateTime::create('-20 days')->getTimestamp(),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $newPlanId = json_decode($res->getContent())[0]->id;
        $fetchedNewPlan =  $go1->fetchColumn('SELECT id FROM gc_plan WHERE instance_id = ? AND user_id = ? AND entity_id = ?', [$this->portalId, $this->userId, $this->loId3]);
        $this->assertEquals($fetchedNewPlan, $newPlanId);
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $this->assertEquals('auto-reassigned', $msg->_context->action);
    }

    public function testReassignWithAssignerUserIdNotFound()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/re-assign?jwt={$this->managerJwt}", 'POST');
        $req->request->replace([
            'plan_ids'         => [$this->planId],
            'due_date'         => DateTime::create('+30 days')->getTimestamp(),
            'assigner_user_id' => 404404,
        ]);

        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());

        $error = json_decode($res->getContent(), true);
        $this->assertEquals("Assigner_user_id not found.", $error['message']);
    }
}
