<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\plan\Plan;
use go1\util\plan\PlanStatuses;
use go1\util\plan\PlanTypes;
use go1\util\queue\Queue;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

use function array_shift;
use function dd;
use function json_decode;

class EnrolmentDueDateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use PlanMockTrait;
    use EnrolmentMockTrait;

    private $go1;
    private $portalId;
    private $portalName = 'qa.mygo1.com';
    private $portalPublicKey;
    private $userId;
    private $profileId  = 99;
    private $userMail   = 'learner@qa.mygo1.com';
    private $userJwt;
    private $loId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $this->go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($this->go1, ['title' => $this->portalName]);
        $this->portalPublicKey = $this->createPortalPublicKey($this->go1, ['instance' => $this->portalName]);
        $this->loId = $this->createCourse($this->go1, ['instance_id' => $this->portalId]);
        $this->userId = $this->createUser($this->go1, ['instance' => $app['accounts_name'], 'mail' => $this->userMail, 'profile_id' => $userProfileId = $this->profileId]);
        $accountId = $this->createUser($this->go1, ['instance' => $this->portalName, 'mail' => $this->userMail]);
        $this->link($this->go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $accountId);
        $this->userJwt = $this->jwtForUser($this->go1, $this->userId, $this->portalName);

        $this->loAccessGrant($this->loId, $this->userId, $this->portalId, 2);
    }

    public function testCreate()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->userJwt}", 'POST');
        $req->request->replace([
            'dueDate' => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $enrolmentId = json_decode($res->getContent())->id;
        $plan = $this->loadPlanByEnrolmentId($app['dbs']['go1'], $enrolmentId);
        $this->assertEmpty($this->queueMessages[Queue::PLAN_CREATE] ?? []);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(null, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(Plan::TYPE_LO, $plan->entityType);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(PlanStatuses::SCHEDULED, $plan->status);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
    }

    public function testCreateNull()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->userJwt}", 'POST');
        $req->request->replace(['dueDate' => null]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertArrayNotHasKey(Queue::PLAN_CREATE, $this->queueMessages);
    }

    public function testCreateForStudent()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/{$this->userMail}/in-progress?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'dueDate' => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty($this->queueMessages[Queue::PLAN_CREATE] ?? []);

        $enrolmentId = json_decode($res->getContent())->id;
        $plan = $this->loadPlanByEnrolmentId($app['dbs']['go1'], $enrolmentId);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(1, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(Plan::TYPE_LO, $plan->entityType);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(PlanStatuses::SCHEDULED, $plan->status);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
    }

    public function testCreateMultiple()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/enrolment?jwt={$this->userJwt}", 'POST');
        $req->request->replace([
            'items' => [
                (object) ['loId' => $this->loId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'dueDate' => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601)],
            ],
        ]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty($this->queueMessages[Queue::PLAN_CREATE] ?? []);

        $results = json_decode($res->getContent(), true);
        $result = array_shift($results);
        $enrolmentId = $result[200]['id'];
        $plan = $this->loadPlanByEnrolmentId($app['dbs']['go1'], $enrolmentId);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(null, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(Plan::TYPE_LO, $plan->entityType);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(PlanStatuses::SCHEDULED, $plan->status);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
    }

    public function testCreateMultipleNull()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/enrolment?jwt={$this->userJwt}", 'POST');
        $req->request->replace([
            'items' => [
                (object) ['loId' => $this->loId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'dueDate' => null],
            ],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertArrayNotHasKey(Queue::PLAN_CREATE, $this->queueMessages);
    }

    public function testCreateMultipleExist()
    {
        $app = $this->getApp();
        $this->createPlan($this->go1, [
            'user_id'     => $this->userId,
            'assigner_id' => null,
            'instance_id' => $this->portalId,
            'entity_type' => Plan::TYPE_LO,
            'entity_id'   => $this->loId,
            'status'      => PlanStatuses::ASSIGNED,
        ]);
        $req = Request::create("/{$this->portalName}/enrolment?jwt={$this->userJwt}", 'POST');
        $req->request->replace([
            'items' => [
                (object) ['loId' => $this->loId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'dueDate' => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601)],
            ],
        ]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty($this->queueMessages[Queue::PLAN_UPDATE] ?? []);

        $results = json_decode($res->getContent(), true);
        $result = array_shift($results)[200];
        $enrolmentId = $result['id'];
        $plan = $this->loadPlanByEnrolmentId($app['dbs']['go1'], $enrolmentId);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(null, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(Plan::TYPE_LO, $plan->entityType);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(PlanStatuses::ASSIGNED, $plan->status);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
    }

    public function testCreateMultipleExistNull()
    {
        $app = $this->getApp();
        $this->createPlan($this->go1, [
            'user_id'     => $this->userId,
            'assigner_id' => null,
            'instance_id' => $this->portalId,
            'entity_type' => Plan::TYPE_LO,
            'entity_id'   => $this->loId,
            'status'      => PlanStatuses::ASSIGNED,
        ]);
        $req = Request::create("/{$this->portalName}/enrolment?jwt={$this->userJwt}", 'POST');
        $req->request->replace([
            'items' => [
                (object) ['loId' => $this->loId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'dueDate' => null],
            ],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty($this->queueMessages[Queue::PLAN_UPDATE] ?? []);

        $results = json_decode($res->getContent(), true);
        $result = array_shift($results)[200];
        $enrolmentId = $result['id'];
        $plan = $this->loadPlanByEnrolmentId($app['dbs']['go1'], $enrolmentId);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(null, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(Plan::TYPE_LO, $plan->entityType);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(PlanStatuses::ASSIGNED, $plan->status);
        $this->assertEquals(null, $plan->due);
    }

    public function testCreateExistPlan()
    {
        $app = $this->getApp();
        $this->createPlan($this->go1, [
            'user_id'     => $this->userId,
            'assigner_id' => null,
            'instance_id' => $this->portalId,
            'entity_type' => Plan::TYPE_LO,
            'entity_id'   => $this->loId,
            'status'      => PlanStatuses::ASSIGNED,
        ]);
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->userJwt}", 'POST');
        $req->request->replace([
            'dueDate' => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty($this->queueMessages[Queue::PLAN_UPDATE] ?? []);

        $enrolmentId = json_decode($res->getContent(), true)['id'];
        $plan = $this->loadPlanByEnrolmentId($app['dbs']['go1'], $enrolmentId);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(null, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(Plan::TYPE_LO, $plan->entityType);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(PlanStatuses::ASSIGNED, $plan->status);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
    }

    public function testCreateExistPlanNull()
    {
        $app = $this->getApp();
        $this->createPlan($this->go1, [
            'user_id'     => $this->userId,
            'assigner_id' => null,
            'instance_id' => $this->portalId,
            'entity_type' => Plan::TYPE_LO,
            'entity_id'   => $this->loId,
            'status'      => PlanStatuses::ASSIGNED,
            'due_date'    => '2017-06-21T17:00:00.000Z',
        ]);
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->userJwt}", 'POST');
        $req->request->replace(['dueDate' => null]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty($this->queueMessages[Queue::PLAN_UPDATE] ?? []);

        $enrolmentId = json_decode($res->getContent(), true)['id'];
        $plan = $this->loadPlanByEnrolmentId($app['dbs']['go1'], $enrolmentId);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(null, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(Plan::TYPE_LO, $plan->entityType);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(PlanStatuses::ASSIGNED, $plan->status);
        $this->assertEquals(null, $plan->due);
    }

    public function testFail()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->userJwt}", 'POST');
        $req->request->replace(['dueDate' => 'foo']);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('invalid or does not match format', $res->getContent());
    }

    public function testCreateEvent()
    {
        $app = $this->getApp();

        $courseId = $this->createCourse($this->go1, ['instance_id' => $this->portalId]);
        $moduleId = $this->createModule($this->go1, ['instance_id' => $this->portalId]);
        $this->link($this->go1, EdgeTypes::HAS_MODULE, $courseId, $moduleId);
        $liEventId = $this->createLO($this->go1, ['instance_id' => $this->portalId, 'type' => LiTypes::EVENT, 'title' => 'Example event']);
        $this->link($this->go1, EdgeTypes::HAS_LI, $moduleId, $liEventId);

        $courseEnrolmentId = $this->createEnrolment($this->go1, [
            'id' => 111,
            'user_id' => $this->userId,
            'profile_id' => $this->profileId,
            'taken_instance_id' => $this->portalId,
            'lo_id' => $courseId,
            'parent_lo_id' => 0,
            'parent_enrolment_id' => 0,
        ]);

        $moduleEnrolmentId = $this->createEnrolment($this->go1, [
            'id' => 222,
            'user_id' => $this->userId,
            'profile_id' => $this->profileId,
            'taken_instance_id'   => $this->portalId,
            'lo_id' => $moduleId,
            'parent_lo_id' => $courseId,
            'parent_enrolment_id' => $courseEnrolmentId,
        ]);

        $req = Request::create("/{$this->portalName}/{$moduleId}/{$liEventId}/enrolment/in-progress?jwt={$this->userJwt}&parentEnrolmentId={$moduleEnrolmentId}", 'POST');
        $req->request->replace([
            'dueDate' => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $enrolment = $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $this->assertEquals($dueDate, $enrolment[0]['due_date']);
    }

    public function testCreatePlanLink()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->userJwt}", 'POST');
        $req->request->replace([
            'dueDate' => (new \DateTime('+1 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEmpty($this->queueMessages[Queue::PLAN_CREATE] ?? []);

        $enrolmentId = json_decode($res->getContent(), true)['id'];
        $plan = $this->loadPlanByEnrolmentId($app['dbs']['go1'], $enrolmentId);
        $this->assertEquals(PlanTypes::ASSIGN, $plan->type);
        $this->assertTrue($repository->foundLink($plan->id, $enrolmentId));
    }

    public function testCreateAndLinkEnrolmentToPlan()
    {
        $app = $this->getApp();
        $planId = $this->createPlan($this->go1, [
            'user_id'     => $this->userId,
            'assigner_id' => null,
            'instance_id' => $this->portalId,
            'entity_type' => Plan::TYPE_LO,
            'entity_id'   => $this->loId,
            'status'      => PlanStatuses::ASSIGNED,
            'due_date'    => (new \DateTime('+30 days'))->format(DATE_ISO8601),
        ]);
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->userJwt}", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());

        $res = json_decode($res->getContent());
        $this->assertArrayNotHasKey(Queue::PLAN_CREATE, $this->queueMessages);
        $this->assertArrayHasKey(Queue::ENROLMENT_CREATE, $this->queueMessages);
        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $this->assertTrue($repository->foundLink($planId, $res->id));
    }
}
