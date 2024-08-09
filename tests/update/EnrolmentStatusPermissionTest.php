<?php

namespace go1\enrolment\tests\update;

use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentStatusPermissionTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    protected $portalName        = 'az.mygo1.com';
    protected $takenInstanceName = 'xy.mygo1.com';
    protected $portalPublicKey;
    protected $portalPrivateKey;
    protected $portalId;
    protected $takenInstanceId;
    protected $userId;
    protected $managerJwt;
    protected $studentJwt;
    protected $jwt;
    protected $takenPortalAdminJwt;
    protected $studentProfileId  = 22;
    protected $studentUserId;
    protected $managerMail       = 'manager@go1.com';
    protected $studentMail       = 'student@go1.com';

    protected $learningObjects      = ['learning_pathway', 'course', 'module',];
    protected $simpleLearningItems  = [];
    protected $complexLearningItems = LiTypes::COMPLEX;

    protected $loEnrolments        = [];
    protected $liSimpleEnrolments  = [];
    protected $liComplexEnrolments = [];

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];
        $this->simpleLearningItems = array_diff(LiTypes::all(), LiTypes::COMPLEX);
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->takenInstanceId = $this->createPortal($go1, ['title' => $this->takenInstanceName]);

        $managerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->managerMail]);
        $managerAccountId1 = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->managerMail]);
        $managerAccountId2 = $this->createUser($go1, ['instance' => $this->takenInstanceName, 'mail' => $this->managerMail]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $managerUserId, $managerAccountId1);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $managerUserId, $managerAccountId2);
        $this->managerJwt = $this->jwtForUser($go1, $managerUserId, $this->takenInstanceName);

        $this->studentUserId = $this->createUser($go1, ['mail' => $this->studentMail, 'profile_id' => $this->studentProfileId, 'instance' => $app['accounts_name']]);
        $studentAccountId = $this->createUser($go1, ['mail' => $this->studentMail, 'instance' => $this->portalName]);
        $takenPortalStudentAccountId = $this->createUser($go1, ['mail' => $this->studentMail, 'instance' => $this->takenInstanceName]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $studentAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $takenPortalStudentAccountId);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $takenPortalStudentAccountId, $managerUserId);
        $this->studentJwt = $this->jwtForUser($go1, $this->studentUserId, $this->portalName);

        $this->jwt = JWT::encode((array) $this->getAdminPayload($this->portalName), 'private_key', 'HS256');
        $this->takenPortalAdminJwt = JWT::encode((array) $this->getAdminPayload($this->takenInstanceName), 'private_key', 'HS256');

        foreach ($this->learningObjects as $type) {
            $loId = $this->createLO($go1, ['instance_id' => $this->portalId, 'type' => $type]);
            $this->loEnrolments[] = $this->createEnrolment($go1, [
                'user_id'           => $this->studentUserId,
                'profile_id'        => $this->studentProfileId,
                'lo_id'             => $loId,
                'taken_instance_id' => $this->takenInstanceId
            ]);
        }

        foreach ($this->simpleLearningItems as $type) {
            $liId = $this->createLO($go1, ['instance_id' => $this->portalId, 'type' => $type]);
            $this->liSimpleEnrolments[] = $this->createEnrolment($go1, [
                'user_id'           => $this->studentUserId,
                'profile_id'        => $this->studentProfileId,
                'lo_id'             => $liId,
                'taken_instance_id' => $this->takenInstanceId
            ]);
        }

        foreach ($this->complexLearningItems as $type) {
            $liId = $this->createLO($go1, ['instance_id' => $this->portalId, 'type' => $type]);
            $this->liComplexEnrolments[] = $this->createEnrolment($go1, [
                'user_id'           => $this->studentUserId,
                'profile_id'        => $this->studentProfileId,
                'lo_id'             => $liId,
                'taken_instance_id' => $this->takenInstanceId
            ]);
        }
    }

    public function dataEnrolmentStatuses()
    {
        return [
            [EnrolmentStatuses::COMPLETED],
            [EnrolmentStatuses::IN_PROGRESS],
            [EnrolmentStatuses::NOT_STARTED],
            [EnrolmentStatuses::PENDING],
        ];
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testManagerCanManuallyChangeEnrolmentStatusOfSimpleLearningItems($status)
    {
        $app = $this->getApp();
        foreach ($this->liSimpleEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->managerJwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(204, $res->getStatusCode());
        }
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testManagerCanManuallyChangeEnrolmentStatusOfComplexLearningItems($status)
    {
        $app = $this->getApp();
        foreach ($this->liComplexEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->managerJwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(204, $res->getStatusCode());
        }
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testStudentCanManuallyChangeEnrolmentStatusOfSimpleLearningItems($status)
    {
        $app = $this->getApp();
        foreach ($this->liSimpleEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->studentJwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(204, $res->getStatusCode());
        }
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testStudentCannotManuallyChangeEnrolmentStatusOfComplexLearningItems($status)
    {
        $app = $this->getApp();
        foreach ($this->liComplexEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->studentJwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
        }
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testStudentCannotManuallyChangeEnrolmentStatusOfLearningObjects($status)
    {
        $app = $this->getApp();
        foreach ($this->loEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->studentJwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
        }
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testPortalAdministratorCanNOTUpdateAnyEnrolmentStatus($status)
    {
        $app = $this->getApp();

        foreach ($this->loEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->jwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
        }

        foreach ($this->liSimpleEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->jwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
        }

        foreach ($this->liComplexEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->jwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
        }
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testTakenInPortalAdministratorCanUpdateAnyEnrolmentStatus($status)
    {
        $app = $this->getApp();

        foreach ($this->loEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->takenPortalAdminJwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(204, $res->getStatusCode());
        }

        foreach ($this->liSimpleEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->takenPortalAdminJwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(204, $res->getStatusCode());
        }

        foreach ($this->liComplexEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->takenPortalAdminJwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(204, $res->getStatusCode());
        }
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testStudentCannotManuallyChangeEnrolmentStatusOfOtherUser($status)
    {
        $app = $this->getApp();
        $this->jwt = $this->getJwt();

        foreach ($this->loEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->jwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
        }

        foreach ($this->liSimpleEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->jwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
        }

        foreach ($this->liComplexEnrolments as $enrolmentId) {
            $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->jwt}", 'PUT', ['status' => $status]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
        }
    }

    public function testAdminPortalCannotSetEnrolmentExpired()
    {
        $this->loAccessDefaultValue = 0;
        $app = $this->getApp();
        $jwt = JWT::encode((array) $this->getAdminPayload($this->portalName), 'private_key', 'HS256');

        $enrolmentId = $this->loEnrolments[0];
        $req = Request::create("/enrolment/{$enrolmentId}?jwt={$jwt}", 'PUT', ['status' => EnrolmentStatuses::EXPIRED]);
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }

    public function testAdminAccountCanSetEnrolmentExpired()
    {
        $app = $this->getApp();
        $jwt = JWT::encode((array) $this->getRootPayload(), 'private_key', 'HS256');

        $enrolmentId = $this->loEnrolments[0];
        $req = Request::create("/enrolment/{$enrolmentId}?jwt={$jwt}", 'PUT', ['status' => EnrolmentStatuses::EXPIRED]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testDefaultAssessorCanUpdateStatus($status)
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $moduleId = $this->createModule($db, ['instance_id' => $this->portalId]);
        $resource1Id = $this->createLO($db, ['type' => LiTypes::RESOURCE, 'instance_id' => $this->portalId]);
        $resource2Id = $this->createLO($db, ['type' => LiTypes::RESOURCE, 'instance_id' => $this->portalId]);

        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $moduleId);
        $this->link($db, EdgeTypes::HAS_LI, $moduleId, $resource1Id);

        $enrolments['course'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $courseId, 'taken_instance_id' => $this->portalId]);
        $enrolments['module'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $moduleId, 'taken_instance_id' => $this->portalId]);
        $enrolments['resource1'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $resource1Id, 'taken_instance_id' => $this->portalId]);
        $enrolments['resource2'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $resource2Id, 'taken_instance_id' => $this->portalId]);

        $assessor1Id = $this->createUser($db, ['mail' => 'assessor1@mail.com']);
        $jwt = $this->getJwt('assessor1@mail.com', $app['accounts_name'], $this->portalName, ['authenticated'], null, null, null, $assessor1Id);
        $this->link($db, EdgeTypes::COURSE_ASSESSOR, $courseId, $assessor1Id);

        $req = Request::create("/enrolment/{$enrolments['resource1']}?jwt={$jwt}", 'PUT', ['status' => $status]);
        $this->assertEquals(204, $app->handle($req)->getStatusCode());

        $req = Request::create("/enrolment/{$enrolments['resource2']}?jwt={$jwt}", 'PUT', ['status' => $status]);
        $this->assertEquals(403, $app->handle($req)->getStatusCode());

        $req = Request::create("/enrolment/{$enrolments['module']}?jwt={$jwt}", 'PUT', ['status' => $status]);
        $this->assertEquals(204, $app->handle($req)->getStatusCode());

        $req = Request::create("/enrolment/{$enrolments['course']}?jwt={$jwt}", 'PUT', ['status' => $status]);
        $this->assertEquals(204, $app->handle($req)->getStatusCode());
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testAssessorCanUpdateStatusWithNoParentLoId($status)
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $moduleId = $this->createModule($db, ['instance_id' => $this->portalId]);
        $resource1Id = $this->createLO($db, ['type' => LiTypes::RESOURCE, 'instance_id' => $this->portalId]);
        $resource2Id = $this->createLO($db, ['type' => LiTypes::RESOURCE, 'instance_id' => $this->portalId]);

        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $moduleId);
        $this->link($db, EdgeTypes::HAS_LI, $moduleId, $resource1Id);

        $enrolments['course'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $courseId, 'taken_instance_id' => $this->portalId]);
        $enrolments['module'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $moduleId, 'taken_instance_id' => $this->portalId]);
        $enrolments['resource1'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $resource1Id, 'taken_instance_id' => $this->portalId]);
        $enrolments['resource2'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $resource2Id, 'taken_instance_id' => $this->portalId]);

        $assessor1Id = $this->createUser($db, ['mail' => 'assessor1@mail.com']);
        $jwt = $this->getJwt('assessor1@mail.com', $app['accounts_name'], $this->portalName, ['authenticated'], null, null, null, $assessor1Id);
        $this->link($db, EdgeTypes::HAS_TUTOR_ENROLMENT_EDGE, $assessor1Id, $enrolments['course']);

        $req = Request::create("/enrolment/{$enrolments['resource1']}?jwt={$jwt}", 'PUT', ['status' => $status]);
        $this->assertEquals(204, $app->handle($req)->getStatusCode());

        $req = Request::create("/enrolment/{$enrolments['resource2']}?jwt={$jwt}", 'PUT', ['status' => $status]);
        $this->assertEquals(403, $app->handle($req)->getStatusCode());

        $req = Request::create("/enrolment/{$enrolments['module']}?jwt={$jwt}", 'PUT', ['status' => $status]);
        $this->assertEquals(204, $app->handle($req)->getStatusCode());
    }

    /** @dataProvider dataEnrolmentStatuses */
    public function testAssessorCanUpdateStatusWithParentLoId($status)
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $moduleId = $this->createModule($db, ['instance_id' => $this->portalId]);
        $resource1Id = $this->createLO($db, ['type' => LiTypes::RESOURCE, 'instance_id' => $this->portalId]);
        $resource2Id = $this->createLO($db, ['type' => LiTypes::RESOURCE, 'instance_id' => $this->portalId]);

        $this->link($db, EdgeTypes::HAS_MODULE, $courseId, $moduleId);
        $this->link($db, EdgeTypes::HAS_LI, $moduleId, $resource1Id);

        $enrolments['course'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $courseId, 'taken_instance_id' => $this->portalId]);
        $enrolments['module'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $moduleId, 'parent_lo_id' => $courseId, 'taken_instance_id' => $this->portalId]);
        $enrolments['resource1'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $resource1Id, 'parent_lo_id' => $moduleId, 'taken_instance_id' => $this->portalId]);
        $enrolments['resource2'] = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->studentProfileId, 'lo_id' => $resource2Id, 'parent_lo_id' => 0, 'taken_instance_id' => $this->portalId]);

        $assessor1Id = $this->createUser($db, ['mail' => 'assessor1@mail.com']);
        $jwt = $this->getJwt('assessor1@mail.com', $app['accounts_name'], $this->portalName, ['authenticated'], null, null, null, $assessor1Id);
        $this->link($db, EdgeTypes::HAS_TUTOR_ENROLMENT_EDGE, $assessor1Id, $enrolments['course']);

        $req = Request::create("/enrolment/{$enrolments['resource1']}?jwt={$jwt}", 'PUT', ['status' => $status]);
        $this->assertEquals(204, $app->handle($req)->getStatusCode());

        $req = Request::create("/enrolment/{$enrolments['resource2']}?jwt={$jwt}", 'PUT', ['status' => $status]);
        $this->assertEquals(403, $app->handle($req)->getStatusCode());

        $req = Request::create("/enrolment/{$enrolments['module']}?jwt={$jwt}", 'PUT', ['status' => $status]);
        $this->assertEquals(204, $app->handle($req)->getStatusCode());
    }

    public function testLearnerCanStartNotStartedEnrolment()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $courseId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $enrolmentId = $this->createEnrolment($go1, [
            'profile_id'        => $this->studentProfileId,
            'user_id'           => $this->studentUserId,
            'lo_id'             => $courseId,
            'status'            => EnrolmentStatuses::NOT_STARTED,
            'taken_instance_id' => $this->takenInstanceId,
        ]);

        $req = Request::create("/enrolment/{$enrolmentId}?jwt={$this->studentJwt}", 'PUT', ['status' => EnrolmentStatuses::IN_PROGRESS]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
    }
}
