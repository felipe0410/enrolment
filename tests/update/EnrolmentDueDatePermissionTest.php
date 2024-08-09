<?php

namespace go1\enrolment\tests\update;

use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentDueDatePermissionTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use PlanMockTrait;

    private $adminJwt;
    private $assessorJwt;
    private $studentJwt;

    private $fooLiEnrolmentId; // enrolment with due date (assessor assigner)
    private $barLiEnrolmentId; // enrolment with due date (admin assigner)
    private $bazLiEnrolmentId; // enrolment with due date (no assign)
    private $quxLiEnrolmentId; // enrolment with no due date

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];
        $portalId = $this->createPortal($go1, ['title' => $portalName = 'foo.com']);
        $fooLoId = $this->createCourse($go1, ['instance_id' => $portalId]);
        $fooLiId = $this->createLO($go1, ['instance_id' => $portalId, 'type' => LiTypes::ACTIVITY]);
        $barLoId = $this->createCourse($go1, ['instance_id' => $portalId]);
        $barLiId = $this->createLO($go1, ['instance_id' => $portalId, 'type' => LiTypes::ACTIVITY]);
        $bazLoId = $this->createCourse($go1, ['instance_id' => $portalId]);
        $bazLiId = $this->createLO($go1, ['instance_id' => $portalId, 'type' => LiTypes::ACTIVITY]);
        $quxLoId = $this->createCourse($go1, ['instance_id' => $portalId]);
        $quxLiId = $this->createLO($go1, ['instance_id' => $portalId, 'type' => LiTypes::ACTIVITY]);
        $this->link($go1, EdgeTypes::HAS_LI, $fooLoId, $fooLiId);
        $this->link($go1, EdgeTypes::HAS_LI, $barLoId, $barLiId);
        $this->link($go1, EdgeTypes::HAS_LI, $bazLoId, $bazLiId);
        $this->link($go1, EdgeTypes::HAS_LI, $quxLoId, $quxLiId);

        $adminUserId = $this->createUser($go1, ['profile_id' => $adminProfileId = 33, 'instance' => $app['accounts_name'], 'mail' => $adminMail = 'admin@foo.com']);
        $adminAccountId = $this->createUser($go1, ['profile_id' => $adminProfileId, 'instance' => $portalName, 'mail' => $adminMail]);
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $portalName]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $adminUserId, $adminAccountId);
        $this->adminJwt = $this->jwtForUser($go1, $adminUserId, $portalName);

        $assessorUserId = $this->createUser($go1, ['profile_id' => $assessorProfileId = 34, 'instance' => $app['accounts_name'], 'mail' => $assessorMail = 'assessor@foo.com']);
        $assessorAccountId = $this->createUser($go1, ['profile_id' => $assessorProfileId, 'instance' => $portalName, 'mail' => $assessorMail]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $assessorUserId, $assessorAccountId);
        $this->link($go1, EdgeTypes::COURSE_ASSESSOR, $fooLoId, $assessorUserId);
        $this->link($go1, EdgeTypes::COURSE_ASSESSOR, $bazLoId, $assessorUserId);
        $this->link($go1, EdgeTypes::COURSE_ASSESSOR, $quxLoId, $assessorUserId);
        $this->assessorJwt = $this->jwtForUser($go1, $assessorUserId, $portalName);

        $studentUserId = $this->createUser($go1, ['profile_id' => $studentProfileId = 35, 'instance' => $app['accounts_name'], 'mail' => $studentMail = 'student@foo.com']);
        $studentAccountId = $this->createUser($go1, ['profile_id' => $studentProfileId, 'instance' => $portalName, 'mail' => $studentMail]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $studentUserId, $studentAccountId);
        $this->studentJwt = $this->jwtForUser($go1, $studentUserId, $portalName);

        $fooLoEnrolmentId = $this->createEnrolment($go1, ['user_id' => $studentUserId, 'profile_id' => $studentProfileId, 'lo_id' => $fooLoId, 'taken_instance_id' => $portalId]);
        $this->fooLiEnrolmentId = $this->createEnrolment($go1, ['user_id' => $studentUserId, 'profile_id' => $studentProfileId, 'lo_id' => $fooLiId, 'taken_instance_id' => $portalId]);
        $fooPlanId = $this->createPlan($go1, [
            'assigner_id' => $assessorUserId,
            'instance_id' => $portalId,
            'entity_id'   => $fooLoId,
            'due_date'    => time(),
        ]);

        $barLoEnrolmentId = $this->createEnrolment($go1, ['user_id' => $studentUserId, 'profile_id' => $studentProfileId, 'lo_id' => $barLoId, 'taken_instance_id' => $portalId]);
        $this->barLiEnrolmentId = $this->createEnrolment($go1, ['user_id' => $studentUserId, 'profile_id' => $studentProfileId, 'lo_id' => $barLiId, 'taken_instance_id' => $portalId]);
        $barPlanId = $this->createPlan($go1, [
            'assigner_id' => $adminUserId,
            'instance_id' => $portalId,
            'entity_id'   => $barLoId,
            'due_date'    => time(),
        ]);

        $bazLoEnrolmentId = $this->createEnrolment($go1, ['user_id' => $studentUserId, 'profile_id' => $studentProfileId, 'lo_id' => $bazLoId, 'taken_instance_id' => $portalId]);
        $this->bazLiEnrolmentId = $this->createEnrolment($go1, ['user_id' => $studentUserId, 'profile_id' => $studentProfileId, 'lo_id' => $bazLiId, 'taken_instance_id' => $portalId]);
        $bazPlanId = $this->createPlan($go1, [
            'assigner_id' => null,
            'instance_id' => $portalId,
            'entity_id'   => $bazLoId,
            'due_date'    => time(),
        ]);

        $quxLoEnrolmentId = $this->createEnrolment($go1, ['user_id' => $studentUserId, 'profile_id' => $studentProfileId, 'lo_id' => $quxLoId, 'taken_instance_id' => $portalId]);
        $this->quxLiEnrolmentId = $this->createEnrolment($go1, ['user_id' => $studentUserId, 'profile_id' => $studentProfileId, 'lo_id' => $quxLiId, 'taken_instance_id' => $portalId]);
        $quxPlanId = $this->createPlan($go1, [
            'assigner_id' => $adminUserId,
            'instance_id' => $portalId,
            'entity_id'   => $quxLoId,
            'due_date'    => null,
        ]);

        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $fooLoEnrolmentId, 'plan_id' => $fooPlanId]);
        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $barLoEnrolmentId, 'plan_id' => $barPlanId]);
        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $bazLoEnrolmentId, 'plan_id' => $bazPlanId]);
        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $quxLoEnrolmentId, 'plan_id' => $quxPlanId]);
    }

    public function testAdmin()
    {
        $app = $this->getApp();
        $fooReq = Request::create("/enrolment/{$this->fooLiEnrolmentId}?jwt={$this->adminJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);
        $barReq = Request::create("/enrolment/{$this->barLiEnrolmentId}?jwt={$this->adminJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);
        $bazReq = Request::create("/enrolment/{$this->bazLiEnrolmentId}?jwt={$this->adminJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);
        $quxReq = Request::create("/enrolment/{$this->quxLiEnrolmentId}?jwt={$this->adminJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);

        $fooRes = $app->handle($fooReq);
        $barRes = $app->handle($barReq);
        $bazRes = $app->handle($bazReq);
        $quxRes = $app->handle($quxReq);

        $this->assertEquals(204, $fooRes->getStatusCode());
        $this->assertEquals(204, $barRes->getStatusCode());
        $this->assertEquals(204, $bazRes->getStatusCode());
        $this->assertEquals(204, $quxRes->getStatusCode());
    }

    public function testAssessor()
    {
        $app = $this->getApp();
        $fooReq = Request::create("/enrolment/{$this->fooLiEnrolmentId}?jwt={$this->assessorJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);
        $barReq = Request::create("/enrolment/{$this->barLiEnrolmentId}?jwt={$this->assessorJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);
        $bazReq = Request::create("/enrolment/{$this->bazLiEnrolmentId}?jwt={$this->assessorJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);
        $quxReq = Request::create("/enrolment/{$this->quxLiEnrolmentId}?jwt={$this->assessorJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);

        $fooRes = $app->handle($fooReq);
        $barRes = $app->handle($barReq);
        $bazRes = $app->handle($bazReq);
        $quxRes = $app->handle($quxReq);

        $this->assertEquals(204, $fooRes->getStatusCode());
        $this->assertEquals(403, $barRes->getStatusCode());
        $this->assertEquals('Only portal admin can update enrollment.', json_decode($barRes->getContent())->message);
        $this->assertEquals(204, $bazRes->getStatusCode());
        $this->assertEquals(204, $quxRes->getStatusCode());
    }

    public function testStudent()
    {
        $app = $this->getApp();

        {
            $req = Request::create("/enrolment/{$this->fooLiEnrolmentId}?jwt={$this->studentJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
            $this->assertEquals('Only admin can change due date.', json_decode($res->getContent())->message);
        }


        {
            $req = Request::create("/enrolment/{$this->barLiEnrolmentId}?jwt={$this->studentJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
            $this->assertEquals('Only admin can change due date.', json_decode($res->getContent())->message);
        }

        {
            $req = Request::create("/enrolment/{$this->bazLiEnrolmentId}?jwt={$this->studentJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);
            $res = $app->handle($req);
            $this->assertEquals(204, $res->getStatusCode());
        }

        {
            $req = Request::create("/enrolment/{$this->quxLiEnrolmentId}?jwt={$this->studentJwt}", 'PUT', ['dueDate' => DateTime::create('+1 day')->format(DATE_ISO8601)]);
            $res = $app->handle($req);
            $this->assertEquals(403, $res->getStatusCode());
            $this->assertEquals('Only admin can change due date.', json_decode($res->getContent())->message);
        }

        {
            $req = Request::create("/enrolment/{$this->barLiEnrolmentId}?jwt={$this->studentJwt}", 'PUT', ['status' => EnrolmentStatuses::COMPLETED]);
            $res = $app->handle($req);
            $this->assertEquals(204, $res->getStatusCode());
        }
    }
}
