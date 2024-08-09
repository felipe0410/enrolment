<?php

namespace go1\enrolment\tests\load;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class StaffEnrolmentLoadControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private string $portalName = 'az.mygo1.com';
    private $portalId;
    private $mail = 'student@go1.com';
    private $userId;
    private $accountId;
    private $profileId = 555;
    private $courseId;
    private $remoteId = 999;
    private $courseEnrolmentId;
    private $userUUID = 'USER_UUID';
    private $jwt;
    private string $staffJwt;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId]);
        $this->courseEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'lo_id' => $this->courseId, 'profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId]);
        $this->staffJwt = UserHelper::ROOT_JWT;
    }

    public function testLoadByLearningObjectForStaff403()
    {
        $app = $this->getApp();
        $req = Request::create("/staff/lo/{$this->courseId}");
        $req->query->replace(['jwt' => $this->jwt]);
        $res = $app->handle($req);
        $result = json_decode($res->getContent());
        $this->assertEquals('Invalid or missing JWT.', $result->message);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testLoadByLearningObjectForStaff404LoNotFound()
    {
        $app = $this->getApp();
        $randomLoId = 4274892374829473;
        $req = Request::create("/staff/lo/{$randomLoId}");
        $req->query->replace(['jwt' => $this->staffJwt]);

        $res = $app->handle($req);
        $result = json_decode($res->getContent());
        $this->assertEquals("Learning object not found $randomLoId", $result->message);
        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testLoadByLearningObjectForStaff400InvalidStatus()
    {
        $app = $this->getApp();
        $req = Request::create("/staff/lo/{$this->courseId}");
        $req->query->replace([
            'jwt' => $this->staffJwt,
            'status' => 'invalid-status'
        ]);

        $res = $app->handle($req);
        $result = json_decode($res->getContent());
        $this->assertStringContainsString("is not an element of the valid values: in-progress, completed, not-started", $result->message);
        $this->assertEquals(400, $res->getStatusCode());
    }

    public function testLoadByLearningObjectForStaffNotFound404()
    {
        $app = $this->getApp();
        $req = Request::create("/staff/lo/{$this->courseId}");
        $req->query->replace([
            'jwt' => $this->staffJwt,
            'status' => EnrolmentStatuses::COMPLETED
        ]);

        $res = $app->handle($req);
        $result = json_decode($res->getContent());
        $this->assertEquals('Enrolment not found.', $result->message);
        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testLoadByLearningObjectForStaffFoundWithStatus200()
    {
        $app = $this->getApp();
        $req = Request::create("/staff/lo/{$this->courseId}");
        $req->query->replace([
            'jwt' => $this->staffJwt,
            'status' => EnrolmentStatuses::IN_PROGRESS
        ]);

        $res = $app->handle($req);
        $enrolment = json_decode($res->getContent());

        $this->assertEquals($this->courseId, $enrolment->lo_id);
        $this->assertEquals($this->profileId, $enrolment->profile_id);
        $this->assertEquals($this->userId, $enrolment->user_id);
        $this->assertNull($enrolment->due_date);
        $this->assertEquals(200, $res->getStatusCode());
    }
}
