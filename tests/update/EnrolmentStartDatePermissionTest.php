<?php

namespace go1\enrolment\tests\update;

use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentStartDatePermissionTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    protected $portalName  = 'az.mygo1.com';
    protected $portalPublicKey;
    protected $portalPrivateKey;
    protected $portalId;
    protected $userId;
    protected $studentJwt;
    protected $jwt;
    protected $profileId   = 22;
    protected $studentMail = 'student@go1.com';

    protected $loId;
    protected $enrolmentId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $studentUserId = $this->createUser($go1, ['profile_id' => $this->profileId, 'instance' => $app['accounts_name'], 'mail' => $this->studentMail]);
        $studentAccountId = $this->createUser($go1, ['profile_id' => 123, 'instance' => $this->portalName, 'mail' => $this->studentMail]);
        $this->studentJwt = $this->getJwt($this->studentMail, $app['accounts_name'], $this->portalName, ['authenticated'], 123, $studentAccountId, $this->profileId, $studentUserId);
        $this->jwt = JWT::encode((array) $this->getAdminPayload($this->portalName), 'private_key', 'HS256');

        $this->loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->enrolmentId = $this->createEnrolment($go1, ['user_id' => $studentUserId, 'profile_id' => $this->profileId, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId]);
    }

    public function testLeanerCantUpdateStartDate()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$this->studentJwt}", 'PUT', ['startDate' => '2012-12-29T02:01:33+0700']);
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testAdminPortalCanUpdateStartDate()
    {
        $app = $this->getApp();
        $jwt = JWT::encode((array) $this->getAdminPayload($this->portalName), 'private_key', 'HS256');

        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$jwt}", 'PUT', ['startDate' => '2012-12-29T02:01:33+0700']);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
    }

    public function testAssessorCanUpdateStartDate()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $assessorId = $this->createUser($go1, ['mail' => 'assessor1@mail.com']);
        $jwt = $this->getJwt('assessor1@mail.com', $app['accounts_name'], $this->portalName, ['authenticated'], null, null, null, $assessorId);

        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$jwt}", 'PUT', ['startDate' => '2012-12-29T02:01:33+0700']);
        $this->assertEquals(403, ($res = $app->handle($req))->getStatusCode());

        $this->link($go1, EdgeTypes::HAS_TUTOR_ENROLMENT_EDGE, $assessorId, $this->enrolmentId);
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$jwt}", 'PUT', ['startDate' => '2012-12-29T02:01:33+0700']);
        $this->assertEquals(204, $app->handle($req)->getStatusCode());
    }
}
