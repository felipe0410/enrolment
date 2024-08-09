<?php

namespace go1\enrolment\tests\create;

use DateTimeZone;
use go1\util\DB;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\content_learning\ErrorMessageCodes;
use go1\enrolment\controller\create\LegacyPaymentClient;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\exceptions\ResourceAlreadyExistsException;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime as DateTimeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\util\lo\LiTypes;
use go1\util\lo\LoHelper;
use go1\util\model\Enrolment;
use go1\util\plan\Plan;
use go1\util\plan\PlanStatuses;
use go1\util\queue\Queue;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\schema\mock\EnrolmentTrackingMockTrait;
use go1\util\user\UserHelper;
use GuzzleHttp\Client;
use ReflectionObject;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentCreateControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use PlanMockTrait;
    use EnrolmentTrackingMockTrait;

    private $portalName              = 'az.mygo1.com';
    private $legacyPortalName        = 'legacy.mygo1.com';
    private $clonePortalName         = 'clone.mygo1.com';
    private $virtualPortalName       = 'virtual1.mygo1.com';
    private $secondVirtualPortalName = 'virtual2.mygo1.com';
    private $anotherPortalName       = 'another.mygo1.com';
    private $invalidPortalName       = 'invalid.mygo1.com';
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
    private $mail                    = 'student@go1.com.au';
    private $anotherMail             = 'another@go1.com.au';
    private $portalLicenseMail       = 'portallicense@go1.com.au';
    private $adminMail               = 'admin@go1.com.au';
    private $nonExistentStudentMail  = 'user-does-not-exist@portal.mygo1.com';
    private $studentAccountId;
    private $anotherStudentAccountId;
    private $anotherStudentUserId;
    private $studentUserId;
    private $studentProfileId        = 11;
    private $anotherUserProfileId    = 22;
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
    private $invalidLoId             = 99999;
    private $subscriptionLoId;
    private $jwt;
    private $adminJwt;
    private $virtualJwt;
    private $anotherVirtualJwt;
    private $cloneJwt;
    private $anotherJwt;
    private $loPrice                 = 9.9;
    private $loCurrency              = 'USD';
    private $studentAccountGuid      = 'xxxx-yyyy-zzzz';

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $go1 = $app['dbs']['go1'];
        $accountsName = $app['accounts_name'];

        // Create instance
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
        $this->courseBId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->moduleAId = $this->createModule($go1, ['instance_id' => $this->portalId]);
        $this->moduleBId = $this->createModule($go1, ['instance_id' => $this->portalId]);
        $this->singleAId = $this->createLO($go1, ['type' => LiTypes::RESOURCE, 'instance_id' => $this->portalId, 'data' => ['single_li' => true]]);

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
        $adminUserId = $this->createUser($go1, ['mail' => $this->adminMail, 'instance' => $app['accounts_name'], 'profile_id' => 50]);
        $adminAccountId = $this->createUser($go1, ['instance' => $this->portalLicensePortalName, 'mail' => $this->adminMail, 'profile_id' => 4]);

        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $adminUserId, $adminAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $this->studentAccountId);
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $this->portalLicensePortalName]));

        $this->adminJwt = $this->jwtForUser($go1, $adminUserId, $this->portalLicensePortalName);
        $this->jwt = $this->jwtForUser($go1, $this->studentUserId, $this->portalName);
        $this->cloneJwt = $this->getJwt($this->mail, $app['accounts_name'], $this->clonePortalName, [], null, null, $this->studentProfileId, $this->studentUserId);
        $this->anotherJwt = $this->getJwt($this->anotherMail, $app['accounts_name'], $this->anotherPortalName, [], null, $this->anotherStudentAccountId, $this->anotherUserProfileId, $this->anotherStudentUserId);
        $this->anotherVirtualJwt = $this->getJwt('another.virtual.user@go1.com.au', $app['accounts_name'], $this->virtualPortalName, [], null, null, 44, 4);

        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseAId, $this->moduleAId);
        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseBId, $this->moduleBId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleAId, $this->singleAId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleBId, $this->singleAId);

        if ('testCantEnrolToCommercialCourse' === $this->getName()) {
            $this->createUser($go1, ['instance' => $accountsName, 'mail' => 'user.1@' . $accountsName]);
            $this->createUser($go1, ['instance' => $accountsName, 'mail' => 'user.0@' . $this->portalName, 'profile_id' => 111]);
            $this->createUser($go1, ['instance' => $accountsName, 'mail' => 'user.1@' . $this->portalName, 'profile_id' => 222]);

            $app->extend(LegacyPaymentClient::class, function (LegacyPaymentClient $service) use ($app) {
                $rService = new ReflectionObject($service);
                $rClient = $rService->getProperty('client');
                $rClient->setAccessible(true);
                $rClient->setValue($service, $client = $this->getMockBuilder(Client::class)->setMethods(['get', 'post', 'patch'])->getMock());

                return $service;
            });
        }
    }

    public function testCreateTwoEnrolmentSimultaneously()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $createService = $app[EnrolmentCreateService::class];

        $newEnrolment = Enrolment::create();
        $newEnrolment->takenPortalId = $this->portalId;
        $newEnrolment->loId = $this->loId;
        $newEnrolment->userId = $this->studentUserId;
        $newEnrolment->profileId = $this->studentProfileId;
        $newEnrolment->status = EnrolmentStatuses::IN_PROGRESS;
        $enrolmentId = $createService->create($newEnrolment, false, $reEnrol = false)->enrolment->id;

        //Create duplicate enrolment
        $newEnrolment1 = Enrolment::create();
        $newEnrolment1->takenPortalId = $this->portalId;
        $newEnrolment1->loId = $this->loId;
        $newEnrolment1->userId = $this->studentUserId;
        $newEnrolment1->profileId = $this->studentProfileId;
        $newEnrolment1->status = EnrolmentStatuses::IN_PROGRESS;
        $ret = $createService->create($newEnrolment1, $newEnrolment, $reEnrol = false);
        $enrolmentIdDuplicate = $ret->enrolment->id;
        $this->assertEquals(1, $enrolmentId);
        $this->assertEquals(1, $enrolmentIdDuplicate);
        $this->assertEquals(409, $ret->code);
    }

    public function testReEnrol()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        // free learning objects will now receive a realm of 2 - unless they're part of a group LO package
        // with a subscription
        $this->loAccessGrant($this->loId, $this->studentUserId, $this->portalId, 2);

        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($originEnrolmentId = json_decode($res->getContent())->id));
        $originEnrolment = $repository->load($originEnrolmentId);
        $this->assertEquals($this->studentUserId, $originEnrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $originEnrolment->status);

        // re-enroll on current LO
        // #enrolment should archive exist enrolment and creating new in-progress enrolment
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->jwt}&reEnrol=1", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($enrolmentId = json_decode($res->getContent())->id));

        $enrolment = $repository->load($enrolmentId);
        $this->assertNotEquals($originEnrolmentId, $enrolmentId);
        $this->assertEquals(true, $enrolment->start_date >= $originEnrolment->start_date);
        $this->assertEquals(true, $enrolment->timestamp >= $originEnrolment->timestamp);

        $this->assertEquals($originEnrolment->profile_id, $enrolment->profile_id);
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
    }

    public function testReEnrolWhenNotCompleted()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $this->loAccessGrant($this->loId, $this->studentUserId, $this->portalId, 2);
        $req = Request::create(
            "/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->jwt}",
            'POST'
        );
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $originEnrolmentId = json_decode($res->getContent())->id;
        $originEnrolment = $repository->load($originEnrolmentId);

        // re-enrol should create new enrolment regardless of current status
        $req = Request::create(
            "/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->jwt}&reEnrol=1",
            'POST',
            ['startDate' => (new \DateTime('-1 day'))->format(DATE_ISO8601)]
        );
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $enrolmentId = json_decode($res->getContent())->id;
        $enrolment = $repository->load($enrolmentId);
        $this->assertNotEquals($originEnrolmentId, $enrolmentId);
        $this->assertEquals($originEnrolment->lo_id, $enrolment->lo_id);
    }

    public function dataPost200()
    {
        $app = $this->getApp();

        $expectedEnrolmentId = 1;

        return [
            // user has jwt with portal A - LO of legacy portal A - enrolling from portal A
            [[
                 'app'                     => $app,
                 'jwt'                     => $this->jwt,
                 'instanceName'            => $this->portalName,
                 'loId'                    => $this->loId,
                 'expectedEnrolmentId'     => $expectedEnrolmentId++,
                 'expectedProfileId'       => $this->studentProfileId,
                 'expectedApiKey'          => 'USER_UUID',
                 'expectedTakenInstanceId' => $this->portalId,
                 'actorUserId'             => $this->studentUserId,
             ]],
            // user has jwt with portal A - marketPlace LO of portal A - enrolling from portal A
            [[
                 'app'                     => $app,
                 'jwt'                     => $this->jwt,
                 'instanceName'            => $this->portalName,
                 'loId'                    => $this->marketPlaceLoId,
                 'expectedEnrolmentId'     => $expectedEnrolmentId++,
                 'expectedProfileId'       => $this->studentProfileId,
                 'expectedApiKey'          => 'USER_UUID',
                 'expectedTakenInstanceId' => $this->portalId,
                 'actorUserId'             => $this->studentUserId,
             ]],
            // user has jwt with portal A - marketPlace LO of portal B - enrolling from portal A
            [[
                 'app'                     => $app,
                 'jwt'                     => $this->jwt,
                 'instanceName'            => $this->portalName,
                 'loId'                    => $this->anotherMarketPlaceLoId,
                 'expectedEnrolmentId'     => $expectedEnrolmentId++,
                 'expectedProfileId'       => $this->studentProfileId,
                 'expectedApiKey'          => 'USER_UUID',
                 'expectedTakenInstanceId' => $this->portalId,
                 'actorUserId'             => $this->studentUserId,
             ]],
            // user has jwt with portal A - marketPlace LO of virtual portal D (configuration.is_virtual = 1) - enrolling from portal A
            [[
                 'app'                     => $app,
                 'jwt'                     => $this->jwt,
                 'instanceName'            => $this->portalName,
                 'loId'                    => $this->secondVirtualMarketPlaceLoId,
                 'expectedEnrolmentId'     => $expectedEnrolmentId++,
                 'expectedProfileId'       => $this->studentProfileId,
                 'expectedApiKey'          => null,
                 'expectedTakenInstanceId' => $this->portalId,
                 'actorUserId'             => $this->studentUserId,
             ]],
            // user has jwt with portal B - marketPlace LO of portal B - enrolling from portal A
            [[
                 'app'                     => $app,
                 'jwt'                     => $this->anotherJwt,
                 'instanceName'            => $this->anotherPortalName,
                 'loId'                    => $this->anotherMarketPlaceLoId,
                 'expectedEnrolmentId'     => $expectedEnrolmentId++,
                 'expectedProfileId'       => $this->anotherUserProfileId,
                 'expectedApiKey'          => 'ANOTHER_USER_UUID',
                 'expectedTakenInstanceId' => $this->anotherPortalId,
                 'actorUserId'             => $this->anotherStudentUserId,
             ]],
        ];
    }

    public function testEnrolForNonExistentStudent()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalId}/0/{$this->loId}/enrolment/{$this->nonExistentStudentMail}/in-progress", 'POST');
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
        $json = json_decode($res->getContent(), true);
        $this->assertEquals($json['message'], 'Student not found.');
    }

    public function testDependencyModuleWithNonCompletedEnrolment()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $courseId = $this->createCourse($db, $base = ['instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module1Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module2Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE_DEPENDENCY, $module1Id, $module2Id);
        $this->createEnrolment($db, ['profile_id' => $this->studentProfileId, 'user_id' => $this->studentUserId, 'lo_id' => $courseId, 'taken_instance_id' => $this->portalId]);

        //Completes module2
        $req = Request::create("/{$this->portalName}/{$courseId}/{$module2Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $req = Request::create("/{$this->portalName}/{$courseId}/{$module1Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $data = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());

        $req = Request::create("/{$data->id}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $enrolment = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::PENDING, $enrolment->status, 'Status must be "pending" if user did not complete dependency modules.');
    }

    public function testMultipleDependencyModuleWithNonCompletedEnrolment()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $courseId = $this->createCourse($db, $base = ['instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module1Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module2Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module3Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE_DEPENDENCY, $module1Id, $module2Id);
        $this->link($db, EdgeTypes::HAS_MODULE_DEPENDENCY, $module1Id, $module3Id);
        $this->createEnrolment($db, ['profile_id' => $this->studentProfileId, 'user_id' => $this->studentUserId, 'lo_id' => $courseId, 'taken_instance_id' => $this->portalId]);

        // Enroll into module2
        $req = Request::create("/{$this->portalName}/{$courseId}/{$module2Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        // Enroll into module3
        $req = Request::create("/{$this->portalName}/{$courseId}/{$module3Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $req = Request::create("/{$this->portalName}/{$courseId}/{$module1Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $data = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());

        $req = Request::create("/{$data->id}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $enrolment = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::PENDING, $enrolment->status, 'Status must be "pending" if user did not complete dependency modules.');
    }

    public function testMultipleDependencyModuleWithNonCompletedAHalf()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $courseId = $this->createCourse($db, $base = ['instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module1Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module2Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module3Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE_DEPENDENCY, $module1Id, $module2Id);
        $this->link($db, EdgeTypes::HAS_MODULE_DEPENDENCY, $module1Id, $module3Id);
        $this->createEnrolment($db, ['profile_id' => $this->studentProfileId, 'user_id' => $this->studentUserId, 'lo_id' => $courseId, 'taken_instance_id' => $this->portalId]);

        // Enroll into module2 and complete it
        $req = Request::create("/{$this->portalName}/{$courseId}/{$module2Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $data = json_decode($res->getContent());
        $db->update('gc_enrolment', ['status' => 'completed'], ['id' => $data->id]);
        // Enroll into module3
        $req = Request::create("/{$this->portalName}/{$courseId}/{$module3Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $req = Request::create("/{$this->portalName}/{$courseId}/{$module1Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $data = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());

        $req = Request::create("/{$data->id}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $enrolment = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::PENDING, $enrolment->status, 'Status must be "pending" if user did not complete dependency modules.');
    }

    public function testDependencyModuleWithCompletedEnrolment()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $courseId = $this->createCourse($db, $base = ['instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module1Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module2Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE_DEPENDENCY, $module1Id, $module2Id);
        $this->createEnrolment($db, ['profile_id' => $this->studentProfileId, 'user_id' => $this->studentUserId, 'lo_id' => $courseId, 'taken_instance_id' => $this->portalId]);

        // Enroll into module2 and complete it
        $req = Request::create("/{$this->portalName}/{$courseId}/{$module2Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $data = json_decode($res->getContent());
        $db->update('gc_enrolment', ['status' => 'completed'], ['id' => $data->id]);

        $req = Request::create("/{$this->portalName}/{$courseId}/{$module1Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $data = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());

        $req = Request::create("/{$data->id}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $enrolment = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $enrolment->status, 'Status must be "in-progress" if user completed dependency modules.');
    }

    public function testMultipleDependencyModuleWithCompletedEnrolment()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $courseId = $this->createCourse($db, $base = ['instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module1Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module2Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $module3Id = $this->createModule($db, $base));
        $this->link($db, EdgeTypes::HAS_MODULE_DEPENDENCY, $module1Id, $module2Id);
        $this->link($db, EdgeTypes::HAS_MODULE_DEPENDENCY, $module1Id, $module3Id);
        $this->createEnrolment($db, ['profile_id' => $this->studentProfileId, 'user_id' => $this->studentUserId, 'lo_id' => $courseId, 'taken_instance_id' => $this->portalId]);

        // Enroll into module2 and complete it
        $req = Request::create("/{$this->portalName}/{$courseId}/{$module2Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $module2Data = json_decode($res->getContent());
        $db->update('gc_enrolment', ['status' => 'completed'], ['id' => $module2Data->id]);
        // Enroll into module3 and complete it
        $req = Request::create("/{$this->portalName}/{$courseId}/{$module3Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $module3Data = json_decode($res->getContent());
        $db->update('gc_enrolment', ['status' => 'completed'], ['id' => $module3Data->id]);

        $req = Request::create("/{$this->portalName}/{$courseId}/{$module1Id}/enrolment/in-progress?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $data = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());

        $req = Request::create("/{$data->id}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $enrolment = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $enrolment->status, 'Status must be "in-progress" if user completed dependency modules.');
    }

    public function testCreateEnrolmentTrackingSelfDir()
    {
        $app = $this->getApp();
        $this->loAccessGrant($this->loId, $this->studentUserId, $this->portalId, 2);
        $req = Request::create(
            "/{$this->portalId}/0/{$this->loId}/enrolment/in-progress?jwt={$this->jwt}",
            'POST'
        );
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolmentId = json_decode($res->getContent())->id;

        $db = $app['dbs']['enrolment'];
        $record = $db->executeQuery(
            'SELECT * FROM enrolment_tracking where enrolment_id=?',
            [$enrolmentId]
        )->fetch(DB::OBJ);

        $this->assertEquals(
            EnrolmentOriginalTypes::I_SELF_DIRECTED,
            $record->original_enrolment_type
        );
        $this->assertEquals($this->studentUserId, $record->actor_id);
    }

    public function testCreateEnrolmentTrackingAssigned()
    {
        $app = $this->getApp();
        $this->loAccessGrant($this->loId, $this->studentUserId, $this->portalId, 2);
        $req = Request::create(
            "/plan/$this->portalId/$this->loId/user/$this->studentUserId?jwt={$this->jwt}",
            'POST'
        );
        $req->query->set('due_date', strtotime('+3 days'));
        $req->query->set('status', -2);
        $req->query->set('version', 2);
        $res = $app->handle($req);

        $req = Request::create(
            "/{$this->portalId}/0/{$this->loId}/enrolment/in-progress?jwt={$this->jwt}",
            'POST'
        );
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolmentId = json_decode($res->getContent())->id;

        $db = $app['dbs']['enrolment'];
        $record = $db->executeQuery(
            'SELECT * FROM enrolment_tracking where enrolment_id=?',
            [$enrolmentId]
        )->fetch(DB::OBJ);

        $this->assertEquals(
            EnrolmentOriginalTypes::I_ASSIGNED,
            $record->original_enrolment_type
        );
        $this->assertEquals($this->studentUserId, $record->actor_id);
    }

    /**
     * @dataProvider subscription200DataProvider
     */
    public function testCanEnrolToCourseInPackageWithSubscription($status)
    {
        $app = $this->getApp();
        $this->mockContentSubscription($app, $status);
        $db = $app['dbs']['go1'];
        $db->insert('gc_lo_pricing', ['id' => $this->subscriptionLoId, 'price' => 0, 'currency' => $this->loCurrency, 'tax' => 0.0]);

        $req = Request::create("/{$this->portalLicensePortalName}/0/{$this->subscriptionLoId}/enrolment/{$this->portalLicenseMail}/not-started", 'POST');
        $req->query->replace(['jwt' => $this->adminJwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(true, is_numeric(json_decode($res->getContent())->id));
    }

    /**
     * @dataProvider subscription403DataProvider
     */
    public function testCanEnrolToCourseInPackageWithoutSubscription($status)
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $db->insert('gc_lo_pricing', ['id' => $this->subscriptionLoId, 'price' => 0, 'currency' => $this->loCurrency, 'tax' => 0.0]);
        $this->mockContentSubscription($app, $status);
        $req = Request::create("/{$this->portalLicensePortalName}/0/{$this->subscriptionLoId}/enrolment/{$this->portalLicenseMail}/not-started", 'POST');
        $req->query->replace(['jwt' => $this->adminJwt]);
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Failed to claim a license for the subscription.', json_decode($res->getContent())->message);
    }

    public function subscription403DataProvider()
    {
        return [[false], [0], [2]];
    }

    public function subscription200DataProvider()
    {
        return [[3], [4]];
    }
}
