<?php

namespace go1\enrolment\tests\update;

use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\plan\Plan;
use go1\util\plan\PlanStatuses;
use go1\util\plan\PlanTypes;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentDueDateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $portalName    = 'foo.com';
    private $portalPublicKey;
    private $userId;
    private $userProfileId = 33;
    private $loId;
    private $loId2;
    private $moduleAId;
    private $enrolmentId;
    private $enrolmentId2;
    private $moduleEnrolmentId;
    private $sharedPortalId;
    private $sharedEnrolmentId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->sharedPortalId = $this->createPortal($go1, ['title' => 'shared.mygo1.com']);
        $this->portalPublicKey = $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);
        $this->loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->loId2 = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->moduleAId = $this->createModule($go1, ['instance_id' => $this->portalId]);
        $this->link($go1, EdgeTypes::HAS_MODULE, $this->loId2, $this->moduleAId);
        $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $mail = 'foo@foo.com', 'profile_id' => $this->userProfileId]);
        $accountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $mail, 'profile_id' => 10000]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $accountId);
        $sharedAccountId = $this->createUser($go1, ['instance' => 'shared.mygo1.com', 'mail' => $mail, 'profile_id' => 10000]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $sharedAccountId);

        $this->enrolmentId = $this->createEnrolment($go1, [
            'profile_id'        => $this->userProfileId,
            'user_id'           => $this->userId,
            'lo_id'             => $this->loId,
            'taken_instance_id' => $this->portalId,
        ]);

        $this->sharedEnrolmentId = $this->createEnrolment($go1, [
            'profile_id'        => $this->userProfileId,
            'user_id'           => $this->userId,
            'lo_id'             => $this->loId,
            'instance_id'       => $this->portalId,
            'taken_instance_id' => $this->sharedPortalId,
        ]);

        $this->enrolmentId2 = $this->createEnrolment($go1, [
            'profile_id'        => $this->userProfileId,
            'user_id'           => $this->userId,
            'lo_id'             => $this->loId2,
            'taken_instance_id' => $this->portalId,
        ]);

        $this->moduleEnrolmentId = $this->createEnrolment($go1, [
            'profile_id'        => $this->userProfileId,
            'user_id'           => $this->userId,
            'lo_id'             => $this->moduleAId,
            'parent_enrolment_id' => $this->enrolmentId2,
            'taken_instance_id' => $this->portalId,
        ]);
    }

    public function testWithoutPlan()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'status'  => EnrolmentStatuses::COMPLETED,
            'dueDate' => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_CREATE][0]);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(1, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(Plan::TYPE_LO, $plan->entityType);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(PlanStatuses::SCHEDULED, $plan->status);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);

        return $app;
    }

    public function testWithoutPlanNull()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'status'  => EnrolmentStatuses::COMPLETED,
            'dueDate' => null,
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertArrayNotHasKey(Queue::PLAN_CREATE, $this->queueMessages);
    }

    public function testWithPlan()
    {
        $app = $this->testWithoutPlan();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'dueDate' => $dueDate = (new \DateTime('+0 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_UPDATE]);
        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_UPDATE][0]);
        $this->assertEquals($this->enrolmentId, $plan->id);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
    }

    public function testWithExpectedCompletionDatePlan()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'expectedCompletionDate' => $expectedCompletionDate = (new \DateTime('+0 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_CREATE][0]);
        $this->assertEquals($this->enrolmentId, $plan->id);
        $this->assertEquals(DateTime::create($expectedCompletionDate), $plan->due);

        return $app;
    }

    public function testWithExpectedCompletionDatePlanNull()
    {
        $app = $this->testWithExpectedCompletionDatePlan();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'expectedCompletionDate' => null,
        ]);
        $this->queueMessages = [];
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_UPDATE]);
        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_UPDATE][0]);
        $this->assertEquals($this->enrolmentId, $plan->id);
        $this->assertEquals(null, $plan->due);
    }

    public function testNotCreatePlanForChildLOWithDueDate()
    {
        $app = $this->testWithExpectedCompletionDatePlan();
        $req = Request::create("/enrolment/{$this->moduleEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'dueDate' => (new \DateTime('+0 day'))->format(DATE_ISO8601),
        ]);
        $this->queueMessages = [];
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $plan = EnrolmentHelper::loadUserPlanIdByEntity($app['dbs']['go1'], $this->portalId, $this->userId, $this->moduleAId);
        $this->assertFalse($plan);
    }

    public function testCreatePlanForCourseWithDueDate()
    {
        $app = $this->testWithExpectedCompletionDatePlan();
        $req = Request::create("/enrolment/{$this->enrolmentId2}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'dueDate' => (new \DateTime('+0 day'))->format(DATE_ISO8601),
        ]);
        $this->queueMessages = [];
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $planId = EnrolmentHelper::loadUserPlanIdByEntity($app['dbs']['go1'], $this->portalId, $this->userId, $this->loId2);
        $this->assertNotEmpty($planId);
    }

    public function testWithPlanNull()
    {
        $app = $this->testWithoutPlan();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'dueDate' => null,
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_UPDATE]);
        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_UPDATE][0]);
        $this->assertEquals($this->enrolmentId, $plan->id);
        $this->assertEquals(null, $plan->due);
    }

    public function testFail()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'status'  => EnrolmentStatuses::COMPLETED,
            'dueDate' => 'foo',
        ]);

        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('invalid or does not match format', $res->getContent());
    }

    public function testSharedCourse()
    {
        $app = $this->getApp();

        $req = Request::create("/enrolment/{$this->sharedEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'expectedCompletionDate' => $expectedCompletionDate = (new \DateTime('+0 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_UPDATE]);

        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_CREATE][0]);
        $this->assertEquals(DateTime::create($expectedCompletionDate), $plan->due);

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $this->assertTrue($repository->foundLink($plan->id, $this->sharedEnrolmentId));
    }

    public function testSharedSLI()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $loId = $this->createVideo($db, ['instance_id' => $this->portalId]);
        $sharedEnrolmentId = $this->createEnrolment($db, [
            'profile_id'        => $this->userProfileId,
            'user_id'           => $this->userId,
            'lo_id'             => $loId,
            'instance_id'       => $this->portalId,
            'taken_instance_id' => $this->sharedPortalId,
        ]);

        $jwt = $this->jwtForUser($app['dbs']['go1'], $this->userId);
        $req = Request::create("/enrolment/{$sharedEnrolmentId}?jwt=" . $jwt, 'PUT');
        $req->request->replace(['status' => EnrolmentStatuses::COMPLETED]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_UPDATE]);

        $message = $this->queueMessages[Queue::ENROLMENT_UPDATE][0];
        $this->assertEquals($sharedEnrolmentId, $message['id']);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $message['status']);
    }

    public function testSharedByAdminOnAccount()
    {
        $app = $this->getApp();

        $req = Request::create("/enrolment/{$this->sharedEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => EnrolmentStatuses::COMPLETED]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $this->queueMessages[Queue::ENROLMENT_UPDATE][0]['status']);
    }

    public function testSharedByPortalAdmin()
    {
        $this->loAccessDefaultValue = 0;
        $app = $this->getApp();

        $go1 = $app['dbs']['go1'];
        $sharedPortal = 'shared.mygo1.com';
        $adminUserId = $this->createUser($go1, ['profile_id' => $adminProfileId = 20000, 'instance' => $app['accounts_name'], 'mail' => $adminMail = 'admin2000@foo.com']);
        $adminAccountId = $this->createUser($go1, ['profile_id' => $adminProfileId, 'instance' => $sharedPortal, 'mail' => $adminMail]);
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $sharedPortal]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $adminUserId, $adminAccountId);
        $jwt = $this->jwtForUser($go1, $adminUserId, $sharedPortal);
        $req = Request::create("/enrolment/{$this->sharedEnrolmentId}?jwt=" . $jwt, Request::METHOD_PUT);
        $req->request->replace(['status' => EnrolmentStatuses::COMPLETED]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $this->queueMessages[Queue::ENROLMENT_UPDATE][0]['status']);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $this->queueMessages[Queue::ENROLMENT_UPDATE][0]['original']['status']);
    }

    public function testUpdatePlanLink()
    {
        $app = $this->getApp();

        $req = Request::create("/enrolment/{$this->sharedEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace([
            'expectedCompletionDate' => $expectedCompletionDate = (new \DateTime('+0 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_UPDATE]);
        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_CREATE][0]);
        $this->assertEquals(DateTime::create($expectedCompletionDate), $plan->due);
        $this->assertEquals(PlanTypes::ASSIGN, $plan->type);

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $this->assertTrue($repository->foundLink($plan->id, $this->sharedEnrolmentId));
    }
}
