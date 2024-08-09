<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class InstructorTest extends EnrolmentTestCase
{
    use EnrolmentMockTrait;
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;

    private $portalName = 'az.mygo1.com';
    private $portalId;
    private $mail       = 'student@go1.com.au';
    private $profileId  = 12345;
    private $accountId;
    private $userId;
    private $tutorId;
    private $courseId;
    private $courseEnrolmentId;
    private $moduleId;
    private $jwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $this->accountId = $this->createUser($db, ['instance' => $this->portalName, 'uuid' => 'USER_UUID', 'mail' => $this->mail]);
        $this->userId = $this->createUser($db, ['instance' => $app['accounts_name'], 'profile_id' => $this->profileId, 'mail' => $this->mail]);
        $this->jwt = $this->getJwt($this->mail, $app['accounts_name'], $this->portalName, [], null, $this->accountId, $this->profileId, $this->userId);

        $this->tutorId = $this->createUser($db, ['instance' => $this->portalName, 'uuid' => 'TUTOR_UUID', 'mail' => 'tutor@go1.com.au']);
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->createPortalPublicKey($db, ['instance' => $this->portalName]);

        $this->courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $this->moduleId = $this->createModule($db, ['instance_id' => $this->portalId]);

        $linkId = $this->link($db, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
        $this->link($db, EdgeTypes::HAS_TUTOR_EDGE, $linkId, $this->tutorId);
        $this->courseEnrolmentId = $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $this->courseId, 'taken_instance_id' => $this->portalId]);
    }

    public function testWorkflow()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/{$this->courseId}/{$this->moduleId}/enrolment/in-progress", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'parentEnrolmentId' => $this->courseEnrolmentId]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(2, $id = json_decode($res->getContent())->id);

        $req = Request::create("/{$id}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = json_decode($res->getContent());

        $this->assertEquals('in-progress', $enrolment->status);
        $this->assertEquals($this->profileId, $enrolment->profile_id);
        $this->assertEquals($this->userId, $enrolment->user_id);
        $this->assertEquals($this->moduleId, $enrolment->lo_id);
        $this->assertEquals($this->portalId, $enrolment->taken_instance_id);

        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        $foundTutor = $db->fetchColumn(
            'SELECT 1 FROM gc_ro WHERE type = ? AND source_id = ? AND target_id = ?',
            [EdgeTypes::HAS_TUTOR_ENROLMENT_EDGE, $id, $this->tutorId]
        );
        $this->assertEquals(1, $foundTutor);
    }
}
