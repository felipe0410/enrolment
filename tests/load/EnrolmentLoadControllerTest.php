<?php

namespace go1\enrolment\tests\load;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\lo\LoTypes;
use go1\util\plan\PlanTypes;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\Roles;
use go1\util\user\UserHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentLoadControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use PlanMockTrait;

    private $portalName = 'az.mygo1.com';
    private $portalPublicKey;
    private $portalId;
    private $mail       = 'student@go1.com';
    private $mail2      = 'student2@go1.com';
    private $userId;
    private $userId2;
    private $accountId;
    private $accountId2;
    private $profileId  = 555;
    private $profileId2  = 554;
    private $courseId;
    private int $courseId2;
    private $remoteId   = 999;
    private $remoteId2   = 998;
    private $moduleId;
    private $moduleId2;
    private $videoId;
    private $ltiId;
    private $courseEnrolmentId;
    private $courseEnrolmentId2;
    private $moduleEnrolmentId2;
    private $moduleEnrolmentId;
    private $videoEnrolmentId;
    private $userUUID   = 'USER_UUID';
    private $userUUID2   = 'USER_UUID2';
    private $jwt;
    private $ltiRegistrations;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->portalPublicKey = $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);
        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId]);
        $this->courseId2 = (int) $this->createCourse($go1, ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId2]);
        $this->moduleId2 = $this->createModule($go1, ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId2]);
        $this->moduleId = $this->createModule($go1, ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId]);
        $this->videoId = $this->createVideo($go1, ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId]);
        $this->ltiId = $this->createLO($go1, ['type' => LiTypes::LTI, 'title' => 'LTI 1.3 course', 'instance_id' => $this->portalId, 'remote_id' => $this->remoteId]);
        $this->userId = $this->createUser($go1, ['mail' => $this->mail, 'instance' => $app['accounts_name'], 'profile_id' => $this->profileId, 'uuid' => $this->userUUID]);
        $this->userId2 = $this->createUser($go1, ['mail' => $this->mail2, 'instance' => $app['accounts_name'], 'profile_id' => $this->profileId2, 'uuid' => $this->userUUID2]);
        $this->accountId = $this->createUser($go1, ['mail' => $this->mail, 'user_id' => $this->userId, 'instance' => $this->portalName, 'profile_id' => 123]);
        $this->accountId2 = $this->createUser($go1, ['mail' => $this->mail2, 'user_id' => $this->userId2, 'instance' => $this->portalName, 'profile_id' => 124]);
        $this->courseEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'lo_id' => $this->courseId, 'profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId]);
        $this->courseEnrolmentId2 = $this->createEnrolment($go1, ['user_id' => $this->userId2, 'lo_id' => $this->courseId2, 'profile_id' => $this->profileId2, 'taken_instance_id' => $this->portalId]);
        $this->moduleEnrolmentId2 = $this->createEnrolment($go1, ['user_id' => $this->userId2, 'lo_id' => $this->moduleId2, 'profile_id' => $this->profileId2, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $this->courseEnrolmentId2]);
        $this->moduleEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'lo_id' => $this->moduleId, 'profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->videoEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'lo_id' => $this->videoId, 'profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $this->moduleEnrolmentId]);
        $this->ltiEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'lo_id' => $this->ltiId, 'profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $this->moduleEnrolmentId]);

        $this->jwt = $this->getJwt($this->mail, $app['accounts_name'], $this->portalName, [], null, $this->accountId, $this->profileId, $this->userId);
        $this->jwt2 = $this->getJwt($this->mail, $app['accounts_name'], $this->portalName, [], null, $this->accountId2, $this->profileId2, $this->userId2);
        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleId, $this->videoId);
    }

    public function testLoad()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->courseEnrolmentId}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($this->courseId, $enrolment->lo_id);
        $this->assertEquals($this->profileId, $enrolment->profile_id);
        $this->assertEquals($this->userId, $enrolment->user_id);
        $this->assertNull($enrolment->due_date);
    }

    public function testLoadFail()
    {
        $req = Request::create("/sss?jwt={$this->jwt}");
        $res = $this->getApp()->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testLoadHasDueDate()
    {
        $app = $this->getApp();
        $this->addDueDateForEnrolment($app['dbs']['go1'], $this->courseEnrolmentId, $due = time());

        $req = Request::create("/{$this->courseEnrolmentId}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($this->courseId, $enrolment->lo_id);
        $this->assertEquals($this->profileId, $enrolment->profile_id);
        $this->assertEquals($this->userId, $enrolment->user_id);
        $this->assertEquals(DateTime::create($due)->format(DATE_ISO8601), $enrolment->due_date);
    }

    public function testLoadEnrolmentForChildEnrolment()
    {
        $app = $this->getApp();

        $req = Request::create("/enrollments/{$this->moduleEnrolmentId2}");
        $req->query->replace(['jwt' => $this->jwt2]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertNotNull($req->attributes->get('BeamMiddleware'));
        $enrolment = json_decode($res->getContent());

        $this->assertObjectNotHasAttribute('due_date', $enrolment);
        $this->assertEquals($this->courseEnrolmentId2, $enrolment->parent_enrollment_id);
        $this->assertEquals('', $enrolment->enrollment_type);
    }

    public function testLoadSlimEnrollmentWithoutPlan()
    {
        $app = $this->getApp();

        $req = Request::create("/enrollments/{$this->courseEnrolmentId2}");
        $req->query->replace(['jwt' => $this->jwt2]);
        $date = DateTime::create(time())->format(DATE_ATOM);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertObjectNotHasAttribute('due_date', $enrolment);
        $this->assertEquals($this->courseId2, $enrolment->lo_id);
        $this->assertEquals($this->accountId2, $enrolment->user_account_id);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $enrolment->status);
        $this->assertSame($this->courseId2, (int) $enrolment->id);
        $this->assertEquals(EnrolmentOriginalTypes::SELF_DIRECTED, $enrolment->enrollment_type);
        $this->assertEquals($date, $enrolment->created_time);
        $this->assertEquals($date, $enrolment->updated_time);
        $this->assertSame(0, $enrolment->result);
        $this->assertSame(false, $enrolment->pass);
        $this->assertEquals($date, $enrolment->start_date);
    }

    public function testLoadSlimEnrollmentWithPlanDetails()
    {
        $app = $this->getApp();
        $this->addDueDateForEnrolment($app['dbs']['go1'], $this->courseEnrolmentId2, $dueDate = strtotime('+1 day'), PlanTypes::ASSIGN, $assignDate = time(), $this->userId2);
        $assignDate = DateTime::create($assignDate)->format(DATE_ATOM);
        $dueDate = DateTime::create($dueDate)->format(DATE_ATOM);

        $req = Request::create("/enrollments/{$this->courseEnrolmentId2}");
        $req->query->replace(['jwt' => $this->jwt2]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());
        $this->assertEquals($this->accountId2, $enrolment->user_account_id);
        $this->assertEquals($assignDate, $enrolment->assign_date);
        $this->assertEquals(EnrolmentOriginalTypes::ASSIGNED, $enrolment->enrollment_type);
        $this->assertEquals($dueDate, $enrolment->due_date);
        $this->assertEquals($this->accountId2, $enrolment->assigner_account_id);
    }

    public function testLoadDueDateAfterAssignAndEdit()
    {
        $app = $this->getApp();
        $this->addDueDateForEnrolment($app['dbs']['go1'], $this->courseEnrolmentId, $due = time(), PlanTypes::ASSIGN);
        $this->addDueDateForEnrolment($app['dbs']['go1'], $this->courseEnrolmentId, $due1day = strtotime('+3 days'), PlanTypes::SUGGESTED);

        $req = Request::create("/{$this->courseEnrolmentId}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($this->courseId, $enrolment->lo_id);
        $this->assertEquals($this->profileId, $enrolment->profile_id);
        $this->assertEquals($this->userId, $enrolment->user_id);
        $this->assertEqualsWithDelta(DateTime::create($due1day)->getTimestamp(), DateTime::create($enrolment->due_date)->getTimestamp(), 5);
    }

    public function testLoadByLearningObject()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/{$this->courseId}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($this->courseId, $enrolment->lo_id);
        $this->assertEquals($this->profileId, $enrolment->profile_id);
        $this->assertEquals($this->userId, $enrolment->user_id);
        $this->assertNull($enrolment->due_date);
    }

    public function testLoadByLearningObjectUndefined()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/undefined");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testLoadByLearningObjectHasDueDate()
    {
        $app = $this->getApp();
        $this->addDueDateForEnrolment($app['dbs']['go1'], $this->courseEnrolmentId, $due = time());

        $req = Request::create("/lo/{$this->courseId}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($this->courseId, $enrolment->lo_id);
        $this->assertEquals($this->profileId, $enrolment->profile_id);
        $this->assertEquals(DateTime::create($due)->format(DATE_ISO8601), $enrolment->due_date);
        $this->assertEquals($this->userId, $enrolment->user_id);
    }

    public function testLoadByRemoteLearningObject()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/{$this->portalId}/course/{$this->remoteId}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($this->courseId, $enrolment->lo_id);
        $this->assertEquals($this->profileId, $enrolment->profile_id);
        $this->assertNull($enrolment->due_date);
        $this->assertEquals($this->userId, $enrolment->user_id);
    }

    public function testLoadByRemoteLearningObjectHasDueDate()
    {
        $app = $this->getApp();
        $this->addDueDateForEnrolment($app['dbs']['go1'], $this->courseEnrolmentId, $due = time());

        $req = Request::create("/lo/{$this->portalId}/course/{$this->remoteId}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($this->courseId, $enrolment->lo_id);
        $this->assertEquals($this->profileId, $enrolment->profile_id);
        $this->assertEquals(DateTime::create($due)->format(DATE_ISO8601), $enrolment->due_date);
        $this->assertEquals($this->userId, $enrolment->user_id);
    }

    private function addDueDateForEnrolment(Connection $db, int $enrolmentId, $dueDate = null, $type = PlanTypes::ASSIGN, $createdDate = null, int $assignerId = 999)
    {
        $planId = $this->createPlan($db, [
            'type'        => $type,
            'user_id'     => $this->userId,
            'instance_id' => $this->portalId,
            'due_date'    => $dueDate ?? time(),
            'created_date' => $createdDate ?? time(),
            'assigner_id' => $assignerId
        ]);
        $this->linkPlan($db, $enrolmentId, $planId);
    }

    public function testAssessorCanSeeLearnerEnrolment()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $moduleId = $this->createModule($db, ['instance_id' => $this->portalId]);
        $resource1Id = $this->createLO($db, ['type' => LiTypes::RESOURCE, 'instance_id' => $this->portalId]);
        $resource2Id = $this->createLO($db, ['type' => LiTypes::RESOURCE, 'instance_id' => $this->portalId]);

        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $moduleId);
        $this->link($db, EdgeTypes::HAS_LI, $moduleId, $resource1Id);

        $enrolments['course'] = $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $courseId, 'taken_instance_id' => $this->portalId]);
        $enrolments['module'] = $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $moduleId, 'taken_instance_id' => $this->portalId]);
        $enrolments['resource1'] = $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $resource1Id, 'taken_instance_id' => $this->portalId]);
        $enrolments['resource2'] = $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $resource2Id, 'taken_instance_id' => $this->portalId]);

        $assessor1Id = $this->createUser($db, ['mail' => 'assessor1@mail.com']);
        $jwt = $this->getJwt('assessor1@mail.com', $app['accounts_name'], null, [], null, null, null, $assessor1Id);
        $this->link($db, EdgeTypes::HAS_TUTOR_ENROLMENT_EDGE, $assessor1Id, $enrolments['course']);

        $req = Request::create("/{$enrolments['resource1']}?jwt={$jwt}");
        $this->assertEquals(200, ($json = $app->handle($req))->getStatusCode());
        $this->assertEquals($resource1Id, json_decode($json->getContent())->lo_id);

        $req = Request::create("/{$enrolments['resource2']}?jwt={$jwt}");
        $this->assertEquals(403, $app->handle($req)->getStatusCode());

        $req = Request::create("/{$enrolments['module']}?jwt={$jwt}");
        $this->assertEquals(200, ($json = $app->handle($req))->getStatusCode());
        $this->assertEquals($moduleId, json_decode($json->getContent())->lo_id);
    }

    public function testTakenPortalAdminCanSeeLearnerEnrolment()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];


        $takenInstanceId = $this->createPortal($db, ['title' => $portalName = 'somewhere.mygo1.com']);
        $this->createUser($db, ['mail' => $this->mail, 'instance' => $portalName]);
        $courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $enrolmentId = $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $courseId, 'taken_instance_id' => $takenInstanceId]);

        $adminUserId = $this->createUser($db, ['mail' => 'somewhere.admin@mail.com', 'instance' => $app['accounts_name']]);
        $adminPortalAccountId = $this->createUser($db, ['mail' => 'somewhere.admin@mail.com', 'instance' => $portalName]);
        $jwt = $this->getJwt('somewhere.admin@mail.com', $app['accounts_name'], $portalName, [Roles::ADMIN], null, $adminPortalAccountId, null, $adminUserId);

        $req = Request::create("/{$enrolmentId}?jwt={$jwt}");
        $this->assertEquals(200, ($json = $app->handle($req))->getStatusCode());
        $this->assertEquals($courseId, json_decode($json->getContent())->lo_id);
    }

    public function testLoadRevision()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $enrolmentRevisionId = $this->createRevisionEnrolment($db, [
            'lo_id'             => $this->courseId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'status'            => $status = EnrolmentStatuses::NOT_STARTED,
            'start_date'        => $startDate = DateTime::atom('now', DATE_ISO8601),
            'enrolment_id'      => $this->courseEnrolmentId,
            'taken_instance_id' => $this->portalId
        ]);

        $req = Request::create("/revision/$enrolmentRevisionId");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $revisionEnrolment = json_decode($res->getContent());
        $this->assertEquals($this->courseId, $revisionEnrolment->lo_id);
        $this->assertEquals($status, $revisionEnrolment->status);
        $this->assertEquals($startDate, $revisionEnrolment->start_date);
    }

    public function testLoadLiEnrolmentWithCourseContext()
    {
        $app = $this->getApp();

        $req = Request::create("/lo/$this->videoId");
        $req->query->replace(['jwt' => $this->jwt, 'courseId' => $this->courseId, 'moduleId' => $this->moduleId, 'portalId' => $this->portalId]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $videoEnrolment = json_decode($res->getContent());
        $this->assertEquals($this->videoEnrolmentId, $videoEnrolment->id);
    }

    public function testLoadLiEnrolmentWithBadContext()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/$this->videoId");

        # Bad course id
        $req->query->replace(['jwt' => $this->jwt, 'courseId' => 404, 'moduleId' => $this->moduleId, 'portalId' => $this->portalId]);
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());

        # Bad module id
        $req->query->replace(['jwt' => $this->jwt, 'courseId' => $this->courseId, 'moduleId' => 404, 'portalId' => $this->portalId]);
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());

        # Bad portal id
        $req->query->replace(['jwt' => $this->jwt, 'courseId' => $this->courseId, 'moduleId' => $this->moduleId, 'portalId' => 400]);
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }

    public function testLoadAchievement()
    {
        $app = $this->getApp();

        $go1 = $app['dbs']['go1'];

        $achievementLoId = $this->createLO($go1, [
            'type'        => LoTypes::ACHIEVEMENT,
            'title'       => 'CPD example',
            'instance_id' => $this->portalId,
            'remote_id'   => $this->remoteId
        ]);
        $id = $this->createEnrolment($go1, [
            'lo_id'             => $achievementLoId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId
        ]);

        $app->extend('client', function () {
            $client = $this
                ->getMockBuilder(Client::class)
                ->disableOriginalConstructor()
                ->setMethods(['get'])
                ->getMock();

            $client
                ->expects($this->any())
                ->method('get')
                ->willReturnCallback(function () {
                    $str = '{"id":4715295,"lo_id":4714945,"user_id":106498,"status":"in-progress","pass":0,"start_date":"2019-11-18 06:36:41","end_date":null,"award":{"required":[{"goal_id":2597,"value":18,"requirements":[{"goal_id":2598,"value":10},{"goal_id":2599,"value":5}]}],"achieved":null}}';
                    return new Response(200, ['Content-Type' => 'application/json'], $str);
                });

            return $client;
        });

        $req = Request::create("/{$id}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $content = json_decode($res->getContent(), true);
        $this->assertEquals($id, $content['id']);
        $this->assertNotEmpty($content['award']);
        $this->assertNotEmpty($content['award']['required']);
    }

    public function testEnrolmentLoadSingle()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/{$this->courseId}/{$this->portalId}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $content = json_decode($res->getContent(), true);
        $this->assertEquals($this->courseEnrolmentId, $content['id']);
    }

    public function testEnrolmentLoadSingleWithImpersonateUserId()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/{$this->courseId}/{$this->portalId}");
        $req->query->replace([
            'userId' => $this->userId,
            'jwt'    => UserHelper::ROOT_JWT,
        ]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $content = json_decode($res->getContent(), true);
        $this->assertEquals($this->courseEnrolmentId, $content['id']);
        $this->assertEquals($this->userId, $content['user_id']);
    }

    public function testEnrolmentLoadSingleWithNotFound()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/404/{$this->portalId}");
        $req->query->replace([
            'userId' => $this->userId,
            'jwt'    => UserHelper::ROOT_JWT,
        ]);
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testManagerCanSeeLearnerEnrolment()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $portalIdA = $this->createPortal($go1, ['title' => 'portalA.mygo1.com']);
        $portalIdB = $this->createPortal($go1, ['title' => 'portalB.mygo1.com']);
        $courseId = $this->createCourse($go1, ['instance_id' => $portalIdA]);

        $studentUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'thuatle@go1.com', 'profile_id' => 6969]);
        $studentAccountId = $this->createUser($go1, ['instance' => 'portalB.mygo1.com', 'mail' => 'thuatle@go1.com', 'uuid' => Uuid::uuid4(), 'profile_id' => 6969]);

        $managerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'nguyen.n@go1.com', 'profile_id' => 9669]);
        $managerAccountId = $this->createUser($go1, ['instance' => 'portalB.mygo1.com', 'mail' => 'nguyen.n@go1.com', 'profile_id' => 9669]);

        $this->link($go1, EdgeTypes::HAS_MANAGER, $studentAccountId, $managerUserId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $studentUserId, $studentAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $managerUserId, $managerAccountId);

        $courseEnrolmentId = $this->createEnrolment($go1, ['user_id' => $studentUserId, 'lo_id' => $courseId, 'profile_id' => 6969, 'taken_instance_id' => $portalIdB]);

        $managerJwt = $this->jwtForUser($go1, $managerUserId, 'portalB.mygo1.com');

        $req = Request::create("/{$courseEnrolmentId}?tree=1");
        $req->query->replace(['jwt' => $managerJwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($courseId, $enrolment->lo_id);
        $this->assertEquals(6969, $enrolment->profile_id);
        $this->assertEquals($studentUserId, $enrolment->user_id);
        $this->assertNull($enrolment->due_date);
    }

    public function testEnrolmentLoadWithincludeLTIRegistrationsWithoutTree()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->courseId}");
        $req->query->replace([
            'userId'                    => $this->userId,
            'jwt'                       => UserHelper::ROOT_JWT,
            'tree'                      => 0,
            'includeLTIRegistrations'   => 1
        ]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($this->courseId, $enrolment->lo_id);
        $this->assertEquals(false, isset($enrolment->registrations));
        $this->assertEquals(false, isset($enrolment->items));
    }

    public function testEnrolmentLoadWithincludeLTIRegistrationsAndTree()
    {
        $app = $this->getApp();
        $this->ltiRegistrations = ['registrationCompletion' => 'UNKNOWN', 'registrationCompletionAmount' => 1];
        $app->extend('client', function () use ($app) {
            $httpClient = $this
                ->getMockBuilder(Client::class)
                ->disableOriginalConstructor()
                ->setMethods(['get'])
                ->getMock();

            $httpClient
                ->expects($this->once())
                ->method('get')
                ->willReturnCallback(function (string $url, array $options) use ($app) {
                    if (strpos($url, "progress/$this->ltiEnrolmentId") !== false) {
                        return new Response(200, [], json_encode($this->ltiRegistrations));
                    }
                    return  new Response(404, [], null);
                });

            return $httpClient;
        });

        $req = Request::create("/{$this->courseId}");
        $req->query->replace([
            'userId'                    => $this->userId,
            'jwt'                       => UserHelper::ROOT_JWT,
            'tree'                      => 1,
            'includeLTIRegistrations'   => 1
        ]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($this->courseId, $enrolment->lo_id);
        $this->assertEquals(false, isset($enrolment->registrations));

        $this->assertCount(1, $enrolment->items);
        $this->assertEquals($this->moduleId, $enrolment->items[0]->lo_id);
        $this->assertEquals(false, isset($enrolment->items[0]->registrations));

        $this->assertCount(2, $enrolment->items[0]->items);
        $this->assertEquals($this->videoId, $enrolment->items[0]->items[0]->lo_id);
        $this->assertEquals(LiTypes::VIDEO, $enrolment->items[0]->items[0]->lo_type);
        $this->assertEquals(false, isset($enrolment->items[0]->items[0]->registrations));

        $this->assertEquals($this->ltiId, $enrolment->items[0]->items[1]->lo_id);
        $this->assertEquals(LiTypes::LTI, $enrolment->items[0]->items[1]->lo_type);
        $this->assertEquals(true, isset($enrolment->items[0]->items[1]->registrations));
        $this->assertEquals((object) $this->ltiRegistrations, $enrolment->items[0]->items[1]->registrations);
    }

    public function testLoadSingleEnrolmentByAchievementRevision()
    {
        $app = $this->getApp();

        $go1 = $app['dbs']['go1'];
        $loId = $this->createLO($go1, [
            'type'        => LoTypes::ACHIEVEMENT,
            'instance_id' => $this->portalId
        ]);
        $this->createRevisionEnrolment($go1, [
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::COMPLETED,
            'lo_id'             => $loId,
            'user_id'           => $this->userId,
            'enrolment_id'      => 1000000
        ]);

        $req = Request::create("/lo/{$loId}/{$this->portalId}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $content = json_decode($res->getContent(), true);
        $this->assertEquals(1000000, $content['id']);
    }
}
