<?php

namespace go1\enrolment\tests\delete;

use Doctrine\DBAL\Schema\Schema;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\controller\create\validator\EnrolmentCreateV3Validator;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\EntityTypes;
use go1\util\plan\PlanRepository;
use go1\util\queue\Queue;
use go1\util\plan\PlanStatuses;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\EnrolmentTrackingMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\Roles;
use go1\util\user\UserHelper;
use Prophecy\Argument;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnrolmentArchiveV3Test extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use PlanMockTrait;
    use UserMockTrait;
    use EnrolmentMockTrait;
    use EnrolmentTrackingMockTrait;

    private $portalId;
    private $courseId;
    private $courseId2;
    private $courseInvalidId;
    private $moduleId;
    private $li1Id;
    private $li2Id;
    private $jwt = UserHelper::ROOT_JWT;
    private $managerJwt;
    private $managerJwtWithoutAccount;
    private $noneManagerJwt;
    private $studentJwt;
    private $student2Jwt;
    private $adminJwt;
    private $mail = 'student@mygo1.com';
    private $mail2 = 'student2@mygo1.com';
    private $studentUserId;
    private $managerId;
    private $profileId = 999;
    private $adminProfileId = 333;
    private $studentUserId2;
    private $studentAccountId;
    private $courseEnrolmentId;
    private $moduleEnrolmentId;
    private $li1EnrolmentId;
    private $li2EnrolmentId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => $portalName = 'az.mygo1.com']);
        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->courseId2 = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->courseInvalidId = $this->createCourse($go1, ['instance_id' => 500]);
        $this->moduleId = $this->createModule($go1, ['instance_id' => $this->portalId]);
        $this->li1Id = $this->createVideo($go1, ['instance_id' => $this->portalId]);
        $this->li2Id = $this->createVideo($go1, ['instance_id' => $this->portalId]);

        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleId, $this->li1Id);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleId, $this->li2Id);

        $adminUserId = $this->createUser($go1, ['profile_id' => $this->adminProfileId, 'instance' => $app['accounts_name'], 'mail' => $adminMail = 'admin@foo.com']);
        $adminAccountId = $this->createUser($go1, ['profile_id' => $this->adminProfileId, 'instance' => $portalName, 'mail' => $adminMail, 'user_id' => $adminUserId]);
        $this->studentUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->mail, 'profile_id' => $this->profileId]);
        $this->studentAccountId = $this->createUser($go1, ['user_id' => $this->studentUserId, 'instance' => $portalName, 'mail' => $this->mail, 'profile_id' => $this->profileId]);
        $this->studentUserId2 = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->mail2]);
        $base = ['profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'user_id' => $this->studentUserId];
        $this->courseEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->courseId, 'status' => EnrolmentStatuses::IN_PROGRESS]);
        $this->moduleEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->li1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->li1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleEnrolmentId]);
        $this->li2EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->li2Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleEnrolmentId]);

        $this->managerId = $this->createUser($go1, ['mail' => $managerMail = 'manager@mail.com', 'instance' => $app['accounts_name']]);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $this->studentAccountId, $this->managerId);
        $managerJwtPayload = $this->getPayload(['id' => $this->managerId, 'mail' => $managerMail, 'roles' => [Roles::MANAGER, 'instance' => $portalName]]);
        $this->managerJwt = JWT::encode((array) $managerJwtPayload, 'PRIVATE_KEY', 'HS256');
        unset($managerJwtPayload->object->content->accounts);
        $this->managerJwtWithoutAccount = JWT::encode((array) $managerJwtPayload, 'PRIVATE_KEY', 'HS256');
        $this->noneManagerJwt = JWT::encode((array) $this->getPayload(['mail' => 'none-manager@mail.com', 'roles' => [Roles::STUDENT, 'instance' => $portalName]]), 'PRIVATE_KEY', 'HS256');
        $this->studentJwt = JWT::encode((array) $this->getPayload(['id' => $this->studentUserId, 'mail' => $this->mail, 'profile_id' => $this->profileId, 'roles' => [Roles::STUDENT, 'instance' => $portalName]]), 'PRIVATE_KEY', 'HS256');
        $this->student2Jwt = JWT::encode((array) $this->getPayload(['id' => $this->studentUserId2, 'mail' => $this->mail2, 'roles' => [Roles::STUDENT, 'instance' => $portalName]]), 'PRIVATE_KEY', 'HS256');
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $portalName]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $adminUserId, $adminAccountId);
        $this->adminJwt = $this->jwtForUser($go1, $adminUserId, $portalName);

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $repository->spreadCompletionStatus($this->portalId, $this->li1Id, $this->studentUserId);
        $repository->spreadCompletionStatus($this->portalId, $this->li2Id, $this->studentUserId);

        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->loadByLoAndUserId($this->moduleId, $this->studentUserId)->status);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->loadByLoAndUserId($this->courseId, $this->studentUserId)->status);
    }

    public function test403()
    {
        $app = $this->getApp();
        $req = Request::create("/enrollments/{$this->courseEnrolmentId}", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
    }

    public function test404()
    {
        $app = $this->getApp();
        $req = Request::create("/enrollments/404?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testArchiveWithRecalculate()
    {
        $app = $this->getApp();
        $req = Request::create("/enrollments/$this->li1EnrolmentId?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        /** @var $repository \go1\enrolment\EnrolmentRepository* */
        $repository = $app[EnrolmentRepository::class];
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->loadByLoAndUserId($this->moduleId, $this->studentUserId)->status);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->loadByLoAndUserId($this->courseId, $this->studentUserId)->status);
        $this->assertEquals(204, $res->getStatusCode());
    }

    public function testArchiveChild()
    {
        $app = $this->getApp();
        $req = Request::create("/enrollments/$this->courseEnrolmentId?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        /** @var $repository \go1\enrolment\EnrolmentRepository* */
        $repository = $app[EnrolmentRepository::class];
        $this->assertNull($repository->load($this->moduleEnrolmentId));
        $this->assertNull($repository->load($this->li1EnrolmentId));
        $this->assertNull($repository->load($this->li2EnrolmentId));
    }

    public function testHookEnrolmentDeleteOnArchive()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $this->link($db, EdgeTypes::HAS_ENQUIRY, $this->courseId, $this->studentUserId, 0, ['mail' => $this->mail, 'status' => 'accepted']);

        $req = Request::create("/enrollments/$this->courseEnrolmentId?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        /** @var $repository \go1\enrolment\EnrolmentRepository* */
        $repository = $app[EnrolmentRepository::class];
        $this->assertNull($repository->load($this->courseEnrolmentId));

        // There's a ENROLMENT_DELETE message published
        $this->assertEquals(4, count($this->queueMessages[Queue::ENROLMENT_DELETE]));
        $this->assertEquals($this->courseEnrolmentId, $this->queueMessages[Queue::ENROLMENT_DELETE][0]->id);
        $this->assertEquals($this->courseId, $this->queueMessages[Queue::ENROLMENT_DELETE][0]->lo_id);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $this->queueMessages[Queue::ENROLMENT_DELETE][0]->status);
        $this->assertEquals($this->studentAccountId, $this->queueMessages[Queue::ENROLMENT_DELETE][0]->embedded['account']['id']);
    }

    public function testManagerCanArchive()
    {
        $app = $this->getApp();
        $req = Request::create("/enrollments/$this->li1EnrolmentId?jwt=$this->noneManagerJwt", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Only manager, admin or learning owner could archive enrollments.', $res->getContent());

        $req = Request::create("/enrollments/$this->li1EnrolmentId?jwt=$this->managerJwt", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var $repository \go1\enrolment\EnrolmentRepository* */
        $repository = $app[EnrolmentRepository::class];
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->loadByLoAndUserId($this->moduleId, $this->studentUserId)->status);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->loadByLoAndUserId($this->courseId, $this->studentUserId)->status);
    }

    public function testManagerJwtWithoutAccount()
    {
        $app = $this->getApp();
        $req = Request::create("/enrollments/$this->li1EnrolmentId?jwt=$this->managerJwtWithoutAccount", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Only manager, admin or learning owner could archive enrollments.', $res->getContent());
    }

    public function testOwnerCanArchive()
    {
        $app = $this->getApp();
        $req = Request::create("/enrollments/$this->courseEnrolmentId?jwt=$this->student2Jwt", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Only manager, admin or learning owner could archive enrollments.', $res->getContent());

        $req = Request::create("/enrollments/$this->courseEnrolmentId?jwt=$this->studentJwt", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $repository = $app[EnrolmentRepository::class];
        $enrolment = $repository->loadByLoAndUserId($this->courseEnrolmentId, $this->studentUserId);
        $this->assertEmpty($enrolment);
    }

    public function testSelfDir()
    {
        $app = $this->getApp();

        // create enrolment
        $req = Request::create(
            "/enrollments?jwt={$this->managerJwt}",
            'POST',
            [
                'enrollment_type' => 'self-directed',
                'user_account_id' => "$this->studentAccountId",
                'lo_id' => "$this->courseId2",
                'parent_enrollment_id' => 0,
                'status' => 'not-started'
            ]
        );
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $enrolmentId = json_decode($res->getContent())->id;

        $req = Request::create("/enrollments/$enrolmentId?jwt=$this->studentJwt", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolRepo = $app[EnrolmentRepository::class];
        $enrolment = $enrolRepo->loadByLoAndUserId($this->courseId2, $this->studentUserId);
        $this->assertEmpty($enrolment);
    }

    public function testAssignmentOnSelfDirRetain()
    {
        $app = $this->getApp();

        // create enrolment
        $req = Request::create(
            "/enrollments?jwt={$this->managerJwt}",
            'POST',
            [
                'enrollment_type' => 'self-directed',
                'user_account_id' => "$this->studentAccountId",
                'lo_id' => "$this->courseId2",
                'parent_enrollment_id' => 0,
                'status' => 'not-started'
            ]
        );
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $enrolmentId = json_decode($res->getContent())->id;

        // add assignment
        $req = Request::create(
            "/enrollments/{$enrolmentId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type' => 'assigned'
            ]
        );

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $res = json_decode($res->getContent());
        $planId = $res->id;

        // remove assignment
        $req = Request::create("/enrollments/$enrolmentId?jwt=$this->studentJwt", 'DELETE');
        $req->query->add(['retain_original' => true]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolRepo = $app[EnrolmentRepository::class];
        $enrolment = $enrolRepo->loadByLoAndUserId($this->courseId2, $this->studentUserId);
        $this->assertEquals($enrolmentId, $enrolment->id);

        $planRepo = $app[PlanRepository::class];
        $plan = $planRepo->loadUserPlanByEntity($this->portalId, $this->studentUserId, $this->courseId2);
        $this->assertEmpty($plan);
    }

    public function testAssignmentOnSelfDirNoRetain()
    {
        $app = $this->getApp();

        // create enrolment
        $req = Request::create(
            "/enrollments?jwt={$this->managerJwt}",
            'POST',
            [
                'enrollment_type' => 'self-directed',
                'user_account_id' => "$this->studentAccountId",
                'lo_id' => "$this->courseId2",
                'parent_enrollment_id' => 0,
                'status' => 'not-started'
            ]
        );
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $enrolmentId = json_decode($res->getContent())->id;

        // add assignment
        $req = Request::create(
            "/enrollments/{$enrolmentId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type' => 'assigned'
            ]
        );

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $res = json_decode($res->getContent());
        $planId = $res->id;

        // remove assignment
        $req = Request::create("/enrollments/$enrolmentId?jwt=$this->studentJwt", 'DELETE');
        $req->query->add(['retain_original' => false]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolRepo = $app[EnrolmentRepository::class];
        $enrolment = $enrolRepo->loadByLoAndUserId($this->courseId2, $this->studentUserId);
        $this->assertEmpty($enrolment);

        $planRepo = $app[PlanRepository::class];
        $plan = $planRepo->loadUserPlanByEntity($this->portalId, $this->studentUserId, $this->courseId2);
        $this->assertEmpty($plan);
    }

    public function testAssignmentOnly()
    {
        $app = $this->getApp();
        $enrolRepo = $app[EnrolmentRepository::class];
        $planRepo = $app[PlanRepository::class];

        // create enrolment
        $req = Request::create(
            "/enrollments?jwt={$this->managerJwt}",
            'POST',
            [
                'enrollment_type' => 'assigned',
                'user_account_id' => "$this->studentAccountId",
                'lo_id' => "$this->courseId2",
                'parent_enrollment_id' => 0,
                'status' => 'not-started'
            ]
        );
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $enrolmentId = json_decode($res->getContent())->id;

        $enrolment = $enrolRepo->loadByLoAndUserId($this->courseId2, $this->studentUserId);
        $this->assertEquals($enrolmentId, $enrolment->id);
        $plan = $planRepo->loadUserPlanByEntity($this->portalId, $this->studentUserId, $this->courseId2)[0];
        $this->assertEquals($this->courseId2, $plan->entity_id);
        $this->assertEquals(PlanStatuses::SCHEDULED, $plan->status);

        $req = Request::create("/enrollments/$enrolmentId?jwt=$this->studentJwt", 'DELETE');
        $req->query->add(['retain_original' => false]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolment = $enrolRepo->loadByLoAndUserId($this->courseId2, $this->studentUserId);
        $this->assertEmpty($enrolment);
        $plan = $planRepo->loadUserPlanByEntity($this->portalId, $this->studentUserId, $this->courseId2);
        $this->assertEmpty($plan);
    }

    public function testInvalidJwt()
    {
        $app = $this->getApp();

        $req = Request::create("/enrollments/1", 'DELETE');
        $req->query->add(['retain_original' => false]);
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Permission denied. Missing or invalid jwt.', json_decode($res->getContent())->message);
    }

    public function testInvalidPortal()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        // create enrolment
        $enrolmentId = $this->createEnrolment($go1, [
            'lo_id' => $this->courseInvalidId,
            'user_id' => $this->studentUserId,
            'taken_instance_id' => $this->portalId,
            'status' => EnrolmentStatuses::NOT_STARTED
        ]);

        $req = Request::create("/enrollments/$enrolmentId?jwt=$this->jwt", 'DELETE');
        $req->query->add(['retain_original' => false]);
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
        $this->assertStringContainsString('Portal not found.', json_decode($res->getContent())->message);
    }

    public function testInvalidUser()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        // create enrolment
        $enrolmentId = $this->createEnrolment($go1, [
            'lo_id' => $this->courseId2,
            'user_id' => 1000,
            'taken_instance_id' => $this->portalId,
            'status' => EnrolmentStatuses::NOT_STARTED
        ]);

        $req = Request::create("/enrollments/$enrolmentId?jwt=$this->jwt", 'DELETE');
        $req->query->add(['retain_original' => false]);
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
        $this->assertStringContainsString('Student not found.', json_decode($res->getContent())->message);
    }

    public function testLegacyEnrolment()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $enrolRepo = $app[EnrolmentRepository::class];

        // create enrolment
        $enrolmentId = $this->createEnrolment($go1, [
            'lo_id' => $this->courseId2,
            'user_id' => $this->studentUserId,
            'taken_instance_id' => $this->portalId,
            'status' => EnrolmentStatuses::NOT_STARTED
        ]);

        $req = Request::create("/enrollments/$enrolmentId?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $enrolment = $enrolRepo->loadByLoAndUserId($this->courseId2, $this->studentUserId);
        $this->assertEmpty($enrolment);
    }

    public function testLegacyEnrolmentWithPlan()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $enrolRepo = $app[EnrolmentRepository::class];
        $planRepo = $app[PlanRepository::class];

        // create enrolment
        $enrolmentId = $this->createEnrolment($go1, [
            'lo_id' => $this->courseId2,
            'user_id' => $this->studentUserId,
            'taken_instance_id' => $this->portalId,
            'status' => EnrolmentStatuses::NOT_STARTED
        ]);

        //create plan
        $planId = $this->createPlan($go1, [
            'user_id' => $this->studentUserId,
            'instance_id' => $this->portalId,
            'entity_type' => EntityTypes::LO,
            'entity_id' => $this->courseId2,
            'status' => PlanStatuses::ASSIGNED,
        ]);

        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $enrolmentId, 'plan_id' => $planId]);

        $req = Request::create("/enrollments/$enrolmentId?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $enrolment = $enrolRepo->loadByLoAndUserId($this->courseId2, $this->studentUserId);
        $this->assertEmpty($enrolment);
        $plan = $enrolRepo->loadByLoAndUserId($this->courseId2, $this->studentUserId);
        $this->assertEmpty($plan);
    }
}
