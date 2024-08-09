<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentRecalculateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $mail             = 'student@go1.com.au';
    private $studentUserId;
    private $studentProfileId = 123;
    private $studentAccountId;
    private $portalName       = 'az.mygo1.com';
    private $portalId;
    private $portalPublicKey;
    private $courseId;
    private $moduleId;
    private $jwt;
    private $resourceId;
    private $enrolmentId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $db = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName, 'version' => 'v2.10.0']);
        $this->portalPublicKey = $this->createPortalPublicKey($db, ['instance' => $this->portalName]);
        $this->courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $this->moduleId = $this->createModule($db, ['instance_id' => $this->portalId]);
        $this->resourceId = $this->createVideo($db, ['instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->resourceId);
        $this->studentUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->mail, 'profile_id' => $this->studentProfileId]);
        $this->studentAccountId = $this->createUser($db, ['instance' => $this->portalName, 'uuid' => 'USER_UUID', 'mail' => $this->mail]);
        $this->jwt = $this->jwtForUser($db, $this->studentUserId, $this->portalName);

        $courseEnrolmentId = $this->createEnrolment($db, [
            'profile_id'        => $this->studentProfileId,
            'user_id'           => $this->studentUserId,
            'lo_id'             => $this->courseId,
            'status'            => EnrolmentStatuses::COMPLETED,
            'taken_instance_id' => $this->portalId,
        ]);

        $moduleEnrolmentId = $this->createEnrolment($db, [
            'profile_id'          => $this->studentProfileId,
            'user_id'             => $this->studentUserId,
            'lo_id'               => $this->moduleId,
            'parent_lo_id'        => $this->courseId,
            'status'              => EnrolmentStatuses::COMPLETED,
            'taken_instance_id'   => $this->portalId,
            'parent_enrolment_id' => $courseEnrolmentId,
        ]);

        $this->enrolmentId = $this->createEnrolment($db, [
            'profile_id'          => $this->studentProfileId,
            'user_id'             => $this->studentUserId,
            'lo_id'               => $this->resourceId,
            'parent_lo_id'        => $this->moduleId,
            'status'              => EnrolmentStatuses::COMPLETED,
            'taken_instance_id'   => $this->portalId,
            'parent_enrolment_id' => $moduleEnrolmentId,
        ]);
    }

    public function testRecalculateByReEnrol()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/{$this->portalName}/$this->moduleId/{$this->resourceId}/enrolment?jwt={$this->jwt}&reEnrol=1&reCalculate=1", 'POST');
        $res = $app->handle($req);

        $courseEnrolment = EnrolmentHelper::loadByLoAndUserId($go1, $this->courseId, $this->studentUserId);
        $moduleEnrolment = EnrolmentHelper::loadByLoAndUserId($go1, $this->moduleId, $this->studentUserId);


        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $courseEnrolment[0]->status);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $moduleEnrolment[0]->status);
    }

    public function testWithoutRecalculateByReEnrol()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/{$this->portalName}/$this->moduleId/{$this->resourceId}/enrolment?jwt={$this->jwt}&reEnrol=1&reCalculate=0", 'POST');
        $res = $app->handle($req);

        $courseEnrolment = EnrolmentHelper::loadByLoAndUserId($go1, $this->courseId, $this->studentUserId);
        $moduleEnrolment = EnrolmentHelper::loadByLoAndUserId($go1, $this->courseId, $this->studentUserId);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $courseEnrolment[0]->status);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $moduleEnrolment[0]->status);
    }

    public function testRecalculateByUpdate()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/enrolment/$this->enrolmentId?jwt={$this->jwt}&reCalculate=1", 'PUT');
        $req->request->replace(['status' => EnrolmentStatuses::IN_PROGRESS]);
        $res = $app->handle($req);

        $courseEnrolment = EnrolmentHelper::loadByLoAndUserId($go1, $this->courseId, $this->studentUserId);
        $moduleEnrolment = EnrolmentHelper::loadByLoAndUserId($go1, $this->courseId, $this->studentUserId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $courseEnrolment[0]->status);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $moduleEnrolment[0]->status);
    }

    public function testWithoutRecalculateByUpdate()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/enrolment/$this->enrolmentId?jwt={$this->jwt}&reCalculate=0", 'PUT');
        $req->request->replace(['status' => EnrolmentStatuses::IN_PROGRESS]);
        $res = $app->handle($req);

        $courseEnrolment = EnrolmentHelper::loadByLoAndUserId($go1, $this->courseId, $this->studentUserId);
        $moduleEnrolment = EnrolmentHelper::loadByLoAndUserId($go1, $this->courseId, $this->studentUserId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $courseEnrolment[0]->status);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $moduleEnrolment[0]->status);
    }
}
