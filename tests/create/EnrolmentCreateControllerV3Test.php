<?php

namespace go1\enrolment\tests\create;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\core\learning_record\plan\util\PlanReference;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\lo\LoTypes;
use go1\util\user\Roles;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\lo\LoHelper;
use go1\util\plan\PlanRepository;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\schema\mock\EnrolmentTrackingMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;
use stdClass;

class EnrolmentCreateControllerV3Test extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use PlanMockTrait;
    use EnrolmentTrackingMockTrait;

    private $portalName = 'az.mygo1.com';
    private $legacyPortalName = 'legacy.mygo1.com';
    private $clonePortalName = 'clone.mygo1.com';
    private $virtualPortalName = 'virtual1.mygo1.com';
    private $secondVirtualPortalName = 'virtual2.mygo1.com';
    private $anotherPortalName = 'another.mygo1.com';
    private $invalidPortalName = 'invalid.mygo1.com';
    private $portalLicensePortalName = 'portal-licensing.mygo1.com';
    private $portalPublicKey;
    private $portalPrivateKey;
    private $portalId;
    private $legacyPortalId;
    private $clonePortalId;
    private $virtualPortalId;
    private $secondVirtualPortalId;
    private $anotherPortalId;
    private $portalLicensePortalId;
    private $mail = 'student@go1.com.au';
    private $anotherMail = 'another@go1.com.au';
    private $portalLicenseMail = 'portallicense@go1.com.au';
    private $adminMail = 'admin@go1.com.au';
    private $nonExistentStudentMail = 'user-does-not-exist@portal.mygo1.com';
    private $studentAccountId;
    private $anotherStudentAccountId;
    private $anotherStudentUserId;
    private $studentUserId;
    private $studentProfileId = 11;
    private $anotherUserProfileId = 22;
    private $adminUserId;
    private $adminAccountId;
    private $altAdminUserId;
    private $altAdminAccountId;
    private $loId;
    private $anotherLoId;
    private $legacyLoId;
    private $marketPlaceLoId;
    private $virtualMarketPlaceLoId;
    private $secondVirtualMarketPlaceLoId;
    private $anotherMarketPlaceLoId;
    private $cloneLoId;
    private $virtualLoId;
    private $secondVirtualLoId;
    private $loIdUnPublished;
    private $loIdDisabled;
    private $suggestedCompletionLoId;
    private $courseAId;
    private $courseBId;
    private $moduleAId;
    private $moduleBId;
    private $singleAId;
    private $invalidLoId = 99999;
    private $subscriptionLoId;
    private $jwt;
    private $adminJwt;
    private $virtualJwt;
    private $anotherVirtualJwt;
    private $cloneJwt;
    private $anotherJwt;
    private $accountAdminJwt;
    private $loPrice = 9.9;
    private $loCurrency = 'USD';
    private $studentAccountGuid = 'xxxx-yyyy-zzzz';
    private string $groupLoId;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));

        $go1 = $app['dbs']['go1'];
        $accountsName = $app['accounts_name'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName, 'version' => 'v2.10.0']);
        $this->portalPublicKey = $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);
        $this->portalPrivateKey = $this->createPortalPrivateKey($go1, ['instance' => $this->portalName]);

        $this->clonePortalId = $this->createPortal($go1, ['title' => $this->clonePortalName]);
        $this->legacyPortalId = $this->createPortal($go1, ['title' => $this->legacyPortalName, 'version' => 'v2.10.0', 'data' => json_encode(['version' => 'v2.10.0'])]);
        $this->virtualPortalId = $this->createPortal($go1, ['title' => $this->virtualPortalName, 'version' => 'v3.0.0-alpha16']);
        $this->secondVirtualPortalId = $this->createPortal($go1, ['title' => $this->secondVirtualPortalName, 'data' => json_encode(['configuration' => ['is_virtual' => 1]])]);
        $this->anotherPortalId = $this->createPortal($go1, ['title' => $this->anotherPortalName]);
        $this->portalLicensePortalId = $this->createPortal($go1, ['title' => $this->portalLicensePortalName]);

        $this->loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->anotherLoId = $this->createCourse($go1, ['instance_id' => $this->anotherPortalId]);
        $this->cloneLoId = $this->createCourse($go1, ['instance_id' => $this->clonePortalId, 'origin_id' => $this->loId, 'remote_id' => -1 * $this->loId, 'marketplace' => 0]);
        $this->legacyLoId = $this->createCourse($go1, ['instance_id' => $this->legacyPortalId]);
        $this->virtualLoId = $this->createCourse($go1, ['instance_id' => $this->virtualPortalId]);
        $this->secondVirtualLoId = $this->createCourse($go1, ['instance_id' => $this->secondVirtualPortalId]);
        $this->loIdUnPublished = $this->createCourse($go1, ['instance_id' => $this->portalId, 'published' => 0]);
        $this->loIdDisabled = $this->createCourse($go1, ['instance_id' => $this->portalId, 'data' => [LoHelper::ENROLMENT_ALLOW => LoHelper::ENROLMENT_ALLOW_DISABLE]]);
        $this->suggestedCompletionLoId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'data' => [LoHelper::SUGGESTED_COMPLETION_TIME => '1', LoHelper::SUGGESTED_COMPLETION_UNIT => 'day']]);

        $this->courseAId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->courseBId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'data' => '{"re_enrol":false}']);
        $this->moduleAId = $this->createModule($go1, ['instance_id' => $this->portalId]);
        $this->moduleBId = $this->createModule($go1, ['instance_id' => $this->portalId]);

        $this->singleAId = $this->createLO($go1, ['type' => LiTypes::RESOURCE, 'instance_id' => $this->portalId, 'data' => ['single_li' => true]]);
        $this->groupLoId = $this->createLO($go1, ['type' => LoTypes::GROUP, 'instance_id' => $this->portalId, 'data' => ['re_enrol' => true]]);

        $this->marketPlaceLoId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'remote_id' => 345, 'marketplace' => 1]);
        $this->virtualMarketPlaceLoId = $this->createCourse($go1, ['instance_id' => $this->virtualPortalId, 'remote_id' => 346, 'marketplace' => 1]);
        $this->secondVirtualMarketPlaceLoId = $this->createCourse($go1, ['instance_id' => $this->secondVirtualPortalId, 'remote_id' => 346, 'marketplace' => 1]);
        $this->anotherMarketPlaceLoId = $this->createCourse($go1, ['instance_id' => $this->anotherPortalId, 'remote_id' => 347, 'marketplace' => 1]);

        $this->subscriptionLoId = $this->createLO($go1, ['type' => LiTypes::TEXT, 'instance_id' => $this->portalId, 'data' => ['single_li' => true]]);

        $this->anotherStudentAccountId = $this->createUser($go1, ['instance' => $this->anotherPortalName, 'uuid' => 'ANOTHER_USER_UUID', 'mail' => $this->anotherMail]);
        $this->createUser($go1, ['instance' => $this->anotherPortalName, 'uuid' => 'ANOTHER_USER_0_UUID', 'mail' => "user.0@{$this->anotherPortalName}"]);
        $this->createUser($go1, ['instance' => $this->virtualPortalName, 'uuid' => 'VIRTUAL_USER_0_UUID', 'mail' => "user.0@{$this->virtualPortalName}"]);
        $this->createUser($go1, ['instance' => $this->secondVirtualPortalName, 'uuid' => 'SECOND_VIRTUAL_USER_0_UUID', 'mail' => "user.0@{$this->secondVirtualPortalName}"]);

        $this->studentUserId = $this->createUser($go1, ['mail' => $this->mail, 'instance' => $app['accounts_name'], 'profile_id' => $this->studentProfileId]);
        $this->studentAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'user_id' => $this->studentUserId, 'uuid' => 'USER_UUID', 'mail' => $this->mail]);
        $this->anotherStudentUserId = $this->createUser($go1, ['mail' => $this->anotherMail, 'instance' => $app['accounts_name'], 'profile_id' => $this->anotherUserProfileId]);
        $this->createUser($go1, ['mail' => $this->portalLicenseMail, 'instance' => $app['accounts_name'], 'profile_id' => 45]);
        $this->createUser($go1, ['mail' => $this->portalLicenseMail, 'instance' => $this->portalLicensePortalName]);
        $this->createUser($go1, ['mail' => 'virtual.user@go1.com.au', 'instance' => $app['accounts_name'], 'profile_id' => 33]);
        $this->createUser($go1, ['mail' => 'another.virtual.user@go1.com.au', 'instance' => $app['accounts_name'], 'profile_id' => 44]);
        $this->adminUserId = $this->createUser($go1, ['mail' => $this->adminMail, 'instance' => $app['accounts_name'], 'profile_id' => 50]);
        $this->adminAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->adminMail, 'profile_id' => 4, 'user_id' => $this->adminUserId, 'data' => ['roles' => [Roles::ADMIN]]]);
        $this->altAdminUserId = $this->createUser($go1, ['mail' => 'altadmin@go1.com.au', 'instance' => $app['accounts_name'], 'profile_id' => 51]);
        $this->altAdminAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'altadmin@go1.com.au', 'profile_id' => 5, 'user_id' => $this->altAdminUserId, 'data' => ['roles' => [Roles::ADMIN]]]);

        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->adminUserId, $this->adminAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $this->studentAccountId);
        $this->link($go1, EdgeTypes::HAS_ROLE, $this->adminAccountId, $this->createAccountsAdminRole($go1, ['instance' => $this->portalLicensePortalName]));

        $this->adminJwt = $this->jwtForUser($go1, $this->adminUserId, $this->portalLicensePortalName);
        $this->jwt = $this->jwtForUser($go1, $this->studentUserId, $this->portalName);
        $this->cloneJwt = $this->getJwt($this->mail, $app['accounts_name'], $this->clonePortalName, [], null, null, $this->studentProfileId, $this->studentUserId);
        $this->anotherJwt = $this->getJwt($this->anotherMail, $app['accounts_name'], $this->anotherPortalName, [], null, $this->anotherStudentAccountId, $this->anotherUserProfileId, $this->anotherStudentUserId);
        $this->anotherVirtualJwt = $this->getJwt('another.virtual.user@go1.com.au', $app['accounts_name'], $this->virtualPortalName, [], null, null, 44, 4);
        $this->accountAdminJwt = $this->getJwt($this->adminMail, $app['accounts_name'], $this->portalName, [Roles::ROOT], null, $this->adminAccountId, null, $this->adminUserId);

        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseAId, $this->moduleAId);
        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseBId, $this->moduleBId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleAId, $this->singleAId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleBId, $this->singleAId);
    }

    public function testSelfDirNotStarted()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'not-started',
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);
        $this->assertEquals($this->studentUserId, $originEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::NOT_STARTED, $originEnrolment->status);

        $req = Request::create("/enrollments/{$originEnrolmentId}?jwt={$this->jwt}", 'GET');
        $res = $app->handle($req);
        $content = json_decode($res->getContent());
        $this->assertTrue(!isset($content->start_date));
    }

    public function testCantEnrolInGroupLO()
    {
        // Status OK needed for license check
        $app = $this->getApp(['status' => 'OK']);

        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->groupLoId",
            'parent_enrollment_id' => 0,
            'status' => 'not-started',
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals("Award and Group enrolment is not supported.", json_decode($res->getContent())->message);
    }

    public function testCantEnrolWithoutPayment()
    {
        $app = $this->getApp();

        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        $db->insert('gc_lo_pricing', ['id' => $this->subscriptionLoId, 'price' => 1, 'currency' => $this->loCurrency, 'tax' => 0.0]);

        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->subscriptionLoId",
            'parent_enrollment_id' => 0,
            'status' => 'not-started',
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(406, $res->getStatusCode());
    }
    public function testSelfDirInProgress()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'in-progress',
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);
        $this->assertEquals($this->studentUserId, $originEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $originEnrolment->status);
    }

    public function testSelfAssign()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $assignDate = (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM);
        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->adminAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'not-started',
            'assign_date' => $assignDate
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);
        $this->assertEquals($this->adminUserId, $originEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::NOT_STARTED, $originEnrolment->status);

        $planRepo = $app[PlanRepository::class];
        $plan = $planRepo->loadUserPlanByEntity($originEnrolment->taken_instance_id, $originEnrolment->user_id, $originEnrolment->lo_id)[0];
        $this->assertEquals($this->adminUserId, $plan->user_id);
        $this->assertEquals($this->adminUserId, $plan->assigner_id);
        $this->assertEquals($this->courseAId, $plan->entity_id);
        $this->assertEquals($assignDate, (new DateTime($plan->created_date))->format(DATE_ATOM));
    }

    public function testAssignNoAssignerId()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $sourceType = 'group';
        $sourceId = 123;
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'completed',
            'result' => 100,
            'pass' => true,
            'assign_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'end_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'source_type' => $sourceType,
            'source_id' => $sourceId
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);
        $this->assertEquals($this->studentUserId, $originEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $originEnrolment->status);
        $planRepo = $app[PlanRepository::class];
        $plan = $planRepo->loadUserPlanByEntity($originEnrolment->taken_instance_id, $originEnrolment->user_id, $originEnrolment->lo_id)[0];
        $this->assertEquals($this->studentUserId, $plan->user_id);
        $this->assertEquals($this->adminUserId, $plan->assigner_id);
        $this->assertEquals($this->courseAId, $plan->entity_id);
        $object = new stdClass();
        $object->source_type = $sourceType;
        $object->source_id = $sourceId;
        $object->plan_id = $plan->id;
        $planRef = $repository->loadPlanReference(PlanReference::createFromRecord($object));
        $this->assertNotNull($planRef);
    }

    public function testAssignWithAssignerId()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'assigner_account_id' => "$this->adminAccountId",
            'status' => 'completed',
            'result' => 100,
            'pass' => true,
            'assign_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'end_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);
        $this->assertEquals($this->studentUserId, $originEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $originEnrolment->status);
        $planRepo = $app[PlanRepository::class];
        $plan = $planRepo->loadUserPlanByEntity($originEnrolment->taken_instance_id, $originEnrolment->user_id, $originEnrolment->lo_id)[0];
        $this->assertEquals($this->studentUserId, $plan->user_id);
        $this->assertEquals($this->adminUserId, $plan->assigner_id);
        $this->assertEquals($this->courseAId, $plan->entity_id);
    }

    public function testAssignWithAssignerIdForDifferentAdmin()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'assigner_account_id' => "$this->altAdminAccountId",
            'status' => 'completed',
            'result' => 100,
            'pass' => true,
            'assign_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'end_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);
        $this->assertEquals($this->studentUserId, $originEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $originEnrolment->status);
        $planRepo = $app[PlanRepository::class];
        $plan = $planRepo->loadUserPlanByEntity($originEnrolment->taken_instance_id, $originEnrolment->user_id, $originEnrolment->lo_id)[0];
        $this->assertEquals($this->studentUserId, $plan->user_id);
        $this->assertEquals($this->altAdminUserId, $plan->assigner_id);
        $this->assertEquals($this->courseAId, $plan->entity_id);
    }

    public function testReEnrolSelfDir()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'not-started',
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);

        // re-enroll on current LO
        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => true,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'in-progress',
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($enrolmentId = json_decode($res->getContent())->id));
        $enrolment = $repository->load($enrolmentId);
        $this->assertNotEquals($originEnrolmentId, $enrolmentId);
        $this->assertEquals(true, $enrolment->timestamp >= $originEnrolment->timestamp);
        $this->assertEquals($originEnrolment->user_id, $enrolment->user_id);
        $this->assertEquals($originEnrolment->parent_lo_id, $enrolment->parent_lo_id);
        $this->assertEquals($originEnrolment->instance_id, $enrolment->instance_id);
        $this->assertEquals($originEnrolment->lo_id, $enrolment->lo_id);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $enrolment->status);
        $this->assertEquals(0.0, $enrolment->result);
        $this->assertEquals(0, $enrolment->pass);
        $this->assertEmpty($enrolment->end_date);

        // Previous enrolment should be archive and added to revision table
        $this->assertEmpty($repository->load($originEnrolmentId));
        $revisions = $repository->loadRevisions($originEnrolmentId, 0, 10);
        $this->assertEquals(1, count($revisions));
        $this->assertEquals($originEnrolmentId, $revisions[0]->enrolment_id);
    }

    public function testAssignOnSelfDir()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'not-started'
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);

        // assign
        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'assigner_account_id' => "$this->adminAccountId",
            'parent_enrollment_id' => 0,
            'status' => 'in-progress',
            'assign_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(409, $res->getStatusCode());
        $this->assertStringContainsString('Enrollment already exists. To create a new enrollment and archive the current enrollment, include the re_enroll=true parameter.', $res->getContent());
    }

    public function testSelfDirOnSelfDir()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'in-progress',
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);
        $this->assertEquals($this->studentUserId, $originEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $originEnrolment->status);

        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'in-progress',
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(409, $res->getStatusCode());
        $this->assertEquals($originEnrolmentId, json_decode($res->getContent())->ref);
    }

    public function testSelfDirWithParentEnrolment()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'in-progress',
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);
        $this->assertEquals($this->studentUserId, $originEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $originEnrolment->status);

        // child enrolment
        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->moduleAId",
            'parent_enrollment_id' => $originEnrolmentId,
            'status' => 'in-progress',
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($subEnrolmentId = json_decode($res->getContent())->id));
        $subEnrolment = $repository->load($subEnrolmentId);
        $this->assertEquals($this->studentUserId, $subEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $subEnrolment->status);

        // child enrolment again
        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'self-directed',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->moduleAId",
            'parent_enrollment_id' => $originEnrolmentId,
            'status' => 'in-progress',
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(409, $res->getStatusCode());
        $this->assertStringContainsString('Enrollment already exists. To create a new enrollment and archive the current enrollment, include the re_enroll=true parameter.', $res->getContent());
    }

    public function testNoReAssign()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'in-progress',
            'assign_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);
        $this->assertEquals($this->studentUserId, $originEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $originEnrolment->status);

        // assign again
        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'in-progress',
            'assign_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(409, $res->getStatusCode());
        $this->assertEquals($originEnrolmentId, json_decode($res->getContent())->ref);
    }

    public function testReAssign()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $planRepo = $app[PlanRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'status' => 'not-started'
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $oldEnrolment = $repository->load($originEnrolmentId);
        $oldPlan = $planRepo->loadUserPlanByEntity($oldEnrolment->taken_instance_id, $oldEnrolment->user_id, $oldEnrolment->lo_id)[0];
        $this->assertEquals($this->adminUserId, $oldPlan->assigner_id);
        $this->assertEquals($this->courseAId, $oldPlan->entity_id);

        // re-assign
        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->query->add([
            're-enroll' => true,
        ]);
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'assigner_account_id' => "$this->adminAccountId",
            'parent_enrollment_id' => 0,
            'status' => 'in-progress',
            'assign_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(201, $res->getStatusCode());
        $newEnrolment = $repository->load(json_decode($res->getContent())->id);
        $this->assertTrue($newEnrolment->id != $oldEnrolment->id);
        $this->assertEquals($newEnrolment->user_id, $oldEnrolment->user_id);
        $this->assertEquals($newEnrolment->lo_id, $oldEnrolment->lo_id);

        $revisions = $repository->loadRevisions($originEnrolmentId);
        $this->assertEquals(1, count($revisions));
        $this->assertEquals($this->studentUserId, $revisions[0]->user_id);
        $this->assertEquals($this->courseAId, $revisions[0]->lo_id);
        $this->assertEquals(EnrolmentStatuses::NOT_STARTED, $revisions[0]->status);

        $newPlan = $planRepo->loadUserPlanByEntity($newEnrolment->taken_instance_id, $newEnrolment->user_id, $newEnrolment->lo_id)[0];
        $this->assertEquals($this->studentUserId, $newPlan->user_id);
        $this->assertEquals($this->adminUserId, $newPlan->assigner_id);
        $this->assertEquals($this->courseAId, $newPlan->entity_id);

        $planRevisions = $planRepo->loadRevisions($oldPlan->id);
        $this->assertEquals(1, count($planRevisions));
    }

    public function testResourceNotFound()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->query->add([
            're-enroll' => false,
        ]);
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => 222,
            'parent_enrollment_id' => 0,
            'assigner_account_id' => "$this->adminAccountId",
            'status' => 'completed',
            'result' => 100,
            'pass' => true,
            'assign_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'end_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
        $this->assertStringContainsString('Learning object not found.', $res->getContent());
    }

    public function testOperationNotAcceptable()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->query->add([
            're-enroll' => true,
        ]);
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseBId",
            'parent_enrollment_id' => 0,
            'assigner_account_id' => "$this->adminAccountId",
            'status' => 'completed',
            'result' => 100,
            'pass' => true,
            'assign_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'end_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(406, $res->getStatusCode());
        $this->assertStringContainsString('.', $res->getContent());
    }

    public function testOperationNotPermit()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->jwt}", 'POST');
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'assigner_account_id' => "$this->studentAccountId",
            'status' => 'not-started'
        ]);
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Permission denied. Only manager or admin could assign enrollments.', $res->getContent());
    }

    public function testInvalidDate1()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'assigner_account_id' => "$this->adminAccountId",
            'status' => 'completed',
            'result' => 100,
            'pass' => true,
            'assign_date' => (new DateTime('2023-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'end_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('Assign date\/time should not be later than due date\/time', $res->getContent());
    }

    public function testInvalidDate2()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'assigner_account_id' => "$this->adminAccountId",
            'status' => 'completed',
            'result' => 100,
            'pass' => true,
            'assign_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2023-03-29T01:29:36Z'))->format(DATE_ATOM),
            'end_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2022-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('Start date\/time should not be later than end date\/time', $res->getContent());
    }

    public function testInvalidDate3()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'assigner_account_id' => "$this->adminAccountId",
            'status' => 'completed',
            'result' => 100,
            'pass' => true,
            'assign_date' => (new DateTime('+1 day'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2023-03-29T01:29:36Z'))->format(DATE_ATOM),
            'end_date' => (new DateTime('2023-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2023-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('Assign date\/time should not be later than current date\/time.', $res->getContent());
    }

    public function testInvalidDate4()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create("/enrollments?jwt={$this->accountAdminJwt}", 'POST');
        $req->request->add([
            'enrollment_type' => 'assigned',
            'user_account_id' => "$this->studentAccountId",
            'lo_id' => "$this->courseAId",
            'parent_enrollment_id' => 0,
            'assigner_account_id' => "$this->adminAccountId",
            'status' => 'completed',
            'result' => 100,
            'pass' => true,
            'assign_date' => (new DateTime('2023-03-29T01:29:36Z'))->format(DATE_ATOM),
            'start_date' => (new DateTime('2023-02-29T01:29:36Z'))->format(DATE_ATOM),
            'end_date' => (new DateTime('2023-03-29T01:29:36Z'))->format(DATE_ATOM),
            'due_date' => (new DateTime('2023-03-29T01:29:36Z'))->format(DATE_ATOM)
        ]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('Assign date\/time should not be later than start date\/time.', $res->getContent());
    }
}
