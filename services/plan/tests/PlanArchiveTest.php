<?php

namespace go1\core\learning_record\plan\tests;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\core\group\group_schema\v1\schema\GroupSchema;
use go1\core\group\group_schema\v1\schema\mock\GroupMockTrait;
use go1\core\learning_record\plan\util\PlanReference;
use go1\core\util\Roles;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\EntityTypes;
use go1\util\plan\Plan;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanStatuses;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class PlanArchiveTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use PlanMockTrait;
    use LoMockTrait;
    use GroupMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $portalName = 'foo.com';
    private $userJwt;
    private $userAccountId;
    private $adminJwt;
    private $managerUserId;
    private $managerJwt;
    private $otherJwt;
    private $assignerId;
    private $assignerAccountId;
    private $assignerJwt;
    private $planId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));
        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);

        $adminUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'admin@go1.com']);
        $adminAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'admin@go1.com']);
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $this->portalName]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $adminUserId, $adminAccountId);
        $this->adminJwt = $this->jwtForUser($go1, $adminUserId, $this->portalName);

        $managerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'manager@go1.com']);
        $this->managerUserId = $managerUserId;
        $managerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'manager@go1.com', 'data' => ['roles' => [Roles::MANAGER]]]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $managerUserId, $managerAccountId);
        $this->managerJwt = $this->jwtForUser($go1, $managerUserId, $this->portalName);

        $userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'user@user.com']);
        $this->userAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'user@user.com']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $userId, $this->userAccountId);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $this->userAccountId, $managerUserId);
        $this->userJwt = $this->jwtForUser($go1, $userId, $this->portalName);

        $otherId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'other@other.com']);
        $otherAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'other@other.com']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $otherId, $otherAccountId);
        $this->otherJwt = $this->jwtForUser($go1, $otherId, $this->portalName);

        $this->assignerId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'assigner@assigner.com']);
        $this->assignerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'assigner@assigner.com']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->assignerId, $this->assignerAccountId);
        $this->assignerJwt = $this->jwtForUser($go1, $this->assignerId, $this->portalName);

        //create plan
        $this->planId = $this->createPlan($go1, [
            'user_id'     => $userId,
            'assigner_id' => $this->assignerId,
            'instance_id' => $this->portalId,
            'entity_type' => EntityTypes::LO,
            'entity_id'   => $this->createCourse($go1, ['id' => 99]),
            'status'      => PlanStatuses::ASSIGNED,
        ]);

        $loEnrolmentId = $this->createEnrolment($go1, ['user_id' => $userId, 'profile_id' => 999, 'lo_id' => 99, 'taken_instance_id' => $this->portalId]);
        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $loEnrolmentId, 'plan_id' => $this->planId]);
    }

    public function test200PortalAdmin()
    {
        $app = $this->getApp();
        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'DELETE'));
        $this->assertEquals(204, $res->getStatusCode());

        $res = $app->handle(Request::create("/plan/{$this->portalId}?jwt={$this->adminJwt}"));
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty(json_decode($res->getContent()));
    }

    public function test200Manager()
    {
        $app = $this->getApp();
        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt={$this->managerJwt}", 'DELETE'));
        $this->assertEquals(204, $res->getStatusCode());

        $res = $app->handle(Request::create("/plan/{$this->portalId}?jwt={$this->managerJwt}"));
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty(json_decode($res->getContent()));
    }

    public function testAssignWithoutNewGroup()
    {
        $app = $this->getApp();
        DB::install($app['dbs']['group'], [function (Schema $schema) {
            GroupSchema::install($schema);
        }]);
        $res = $app->handle(Request::create("/plan/all?jwt={$this->assignerJwt}&portalId={$this->portalId}"));
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty(json_decode($res->getContent()));
    }

    public function testAssignWithNewGroup()
    {
        $app = $this->getApp();
        DB::install($app['dbs']['group'], [function (Schema $schema) {
            GroupSchema::install($schema);
        }]);
        $db = $app['dbs']['group'];
        $groupId = $this->createGroup($db, ['portal_id' => $this->portalId]);
        $this->createGroupAssignment($db, ['group_id' => $groupId, 'lo_id' => 100, 'user_id' => $this->assignerAccountId]);

        $res = $app->handle(Request::create("/plan/all?jwt={$this->assignerJwt}&portalId={$this->portalId}"));
        $this->assertEquals(200, $res->getStatusCode());
        $content = json_decode($res->getContent());
        $this->assertCount(1, $content);
        $this->assertEquals(EntityTypes::LO, $content[0]->entity_type);
        $this->assertEquals(100, $content[0]->entity_id);
        $this->assertEquals($this->assignerId, $content[0]->user_id);
        $this->assertEquals($this->assignerAccountId, $content[0]->assigner_id);

        return $app;
    }

    public function testDoNotAttachGroupAssignWhenOffsetGreaterThanZero()
    {
        $app = $this->testAssignWithNewGroup();
        $res = $app->handle(Request::create("/plan/all?jwt={$this->assignerJwt}&portalId={$this->portalId}&offset=1"));
        $this->assertEquals(200, $res->getStatusCode());
        $content = json_decode($res->getContent());
        $this->assertCount(0, $content);
    }

    public function test403Learner()
    {
        $app = $this->getApp();
        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt={$this->userJwt}", 'DELETE'));
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString("Only portal administrator and user's manager can archive learning.", json_decode($res->getContent())->message);
    }

    public function test403Assigner()
    {
        $app = $this->getApp();
        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt={$this->assignerJwt}", 'DELETE'));
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString("Only portal administrator and user's manager can archive learning.", json_decode($res->getContent())->message);
    }

    public function test404Plan()
    {
        $app = $this->getApp();

        $res = $app->handle(Request::create("/plan/99?jwt={$this->adminJwt}", 'DELETE'));

        $this->assertEquals(404, $res->getStatusCode());
        $this->assertStringContainsString('Plan not found.', $res->getContent());
    }

    public function test403()
    {
        $app = $this->getApp();

        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt={$this->otherJwt}", 'DELETE'));

        $this->assertEquals(403, $res->getStatusCode());
    }

    public function test200WithEnrolment()
    {
        $app = $this->getApp();
        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'DELETE'));
        $this->assertEquals(204, $res->getStatusCode());
        $message = $this->queueMessages[Queue::RO_DELETE];
        $this->assertCount(1, $message);
        $this->assertEquals(900, $message[0]['type']);
        $this->assertEquals($this->planId, $message[0]['target_id']);

        $deleteMessages = $this->queueMessages[Queue::PLAN_DELETE];
        $this->assertCount(1, $deleteMessages);
        $this->assertCount(1, $deleteMessages[0]['_context']);
        $this->assertNotEmpty($deleteMessages[0]['_context']['sessionId']);

        $res = $app->handle(Request::create("/plan/{$this->portalId}?jwt={$this->userJwt}"));
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty(json_decode($res->getContent()));
    }

    public function test200WithEnrolmentWithNotify()
    {
        $app = $this->getApp();
        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}&notify=1", 'DELETE'));
        $this->assertEquals(204, $res->getStatusCode());
        $message = $this->queueMessages[Queue::RO_DELETE];
        $this->assertCount(1, $message);
        $this->assertEquals(900, $message[0]['type']);
        $this->assertEquals($this->planId, $message[0]['target_id']);

        $deleteMessages = $this->queueMessages[Queue::PLAN_DELETE];
        $this->assertCount(1, $deleteMessages);
        $this->assertCount(2, $deleteMessages[0]['_context']);
        $this->assertEquals(1, $deleteMessages[0]['_context']['notify']);

        $res = $app->handle(Request::create("/plan/{$this->portalId}?jwt={$this->userJwt}"));
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty(json_decode($res->getContent()));
    }

    public function test200WithEnrolmentWithNotifyFalse()
    {
        $app = $this->getApp();
        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}&notify=0", 'DELETE'));
        $this->assertEquals(204, $res->getStatusCode());
        $message = $this->queueMessages[Queue::RO_DELETE];
        $this->assertCount(1, $message);
        $this->assertEquals(900, $message[0]['type']);
        $this->assertEquals($this->planId, $message[0]['target_id']);

        $deleteMessages = $this->queueMessages[Queue::PLAN_DELETE];
        $this->assertCount(1, $deleteMessages);
        $this->assertCount(2, $deleteMessages[0]['_context']);
        $this->assertEquals(0, $deleteMessages[0]['_context']['notify']);

        $res = $app->handle(Request::create("/plan/{$this->portalId}?jwt={$this->userJwt}"));
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty(json_decode($res->getContent()));
    }

    public function testSuccessWithDeactivatedAssignee()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $db->update('gc_user', ['status' => 0], ['id' => $this->userAccountId]);

        // delete plan
        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'DELETE'));
        $this->assertEquals(204, $res->getStatusCode());
        $message = $this->queueMessages[Queue::RO_DELETE];
        $this->assertCount(1, $message);

        // get plan
        $res = $app->handle(Request::create("/plan/{$this->portalId}?jwt={$this->userJwt}", 'GET'));
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty(json_decode($res->getContent()));
    }

    public function testManagerDeleteTheirOwn()
    {
        $app = $this->getApp();

        //  setup own plan
        {
            $go1 = $app['dbs']['go1'];
            $planId = $this->createPlan($go1, [
                'user_id'     => $this->managerUserId,
                'assigner_id' => $this->assignerId,
                'instance_id' => $this->portalId,
                'entity_type' => EntityTypes::LO,
                'entity_id'   => $this->createCourse($go1, ['id' => 9999]),
                'status'      => PlanStatuses::ASSIGNED,
            ]);
        }

        $res = $app->handle(Request::create("/plan/{$planId}?jwt={$this->managerJwt}", 'DELETE'));
        $this->assertEquals(204, $res->getStatusCode());
    }

    public function testDeleteReference()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $rEnrolment */
        $rEnrolment = $app[EnrolmentRepository::class];

        $planRef = PlanReference::createFromRecord((object) [
            'plan_id'     => $this->planId,
            'source_type' => 'group',
            'source_id'   => '1234'
        ]);
        $rEnrolment->linkPlanReference($planRef);

        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt={$this->adminJwt}", 'DELETE'));
        $this->assertEquals(204, $res->getStatusCode());

        $ref = $rEnrolment->loadPlanReference($planRef);
        $this->assertEquals(0, $ref->status);
    }

    public function testWithRootJWT()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $rEnrolment */
        $rEnrolment = $app[EnrolmentRepository::class];
        $planRef = PlanReference::createFromRecord((object) [
            'plan_id'     => $this->planId,
            'source_type' => 'group',
            'source_id'   => '1234'
        ]);
        $rEnrolment->linkPlanReference($planRef);

        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_DELETE));
        $this->assertEquals(204, $res->getStatusCode());

        $ref = $rEnrolment->loadPlanReference($planRef);
        $this->assertEquals(0, $ref->status);
    }

    public function testWithRootJWTAndDeactiveUser()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $db->update('gc_user', ['status' => 0], ['id' => $this->userAccountId]);

        $res = $app->handle(Request::create("/plan/{$this->planId}?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_DELETE));
        $this->assertEquals(204, $res->getStatusCode());

        $plan = $app[PlanRepository::class]->load($this->planId);
        $this->assertFalse($plan);
    }
}
