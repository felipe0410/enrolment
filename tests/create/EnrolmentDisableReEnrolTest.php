<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentDisableReEnrolTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;

    private $mail       = 'student@go1.com.au';
    private $profileId  = 11;
    private $portalName = 'az.mygo1.com';
    private $jwt;
    private $courseId;
    private $portalId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $db = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->courseId = $this->createCourse($db, ['instance_id' => $this->portalId, 'data' => json_encode(['re_enrol' => 0])]);
        $userId = $this->createUser($db, ['instance' => $app['accounts_name'], 'uuid' => 'USER_UUID_ROOT', 'mail' => $this->mail, 'profile_id' => $this->profileId]);
        $accountId = $this->createUser($db, ['instance' => $this->portalName, 'uuid' => 'USER_UUID', 'mail' => $this->mail, 'profile_id' => $this->profileId]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $userId, $accountId);
        $this->createPortalPublicKey($db, ['instance' => $this->portalName]);
        $this->jwt = $this->jwtForUser($db, $userId, $this->portalName);
    }

    public function testDisableReEnrolment()
    {
        $app = $this->getApp();
        $app->handle(Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/in-progress?jwt={$this->jwt}", 'POST'));
        $res = $app->handle(Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/in-progress?jwt={$this->jwt}&reEnrol=1", 'POST'));
        $this->assertEquals(406, $res->getStatusCode());
    }

    public function testDisableReEnrolmentForStudent()
    {
        $app = $this->getApp();
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/$this->mail/in-progress?jwt=". UserHelper::ROOT_JWT, 'POST');
        $req->attributes->replace(['jwt.payload' => $this->getAdminPayload($this->portalName)]);
        $this->assertEquals(200, $app->handle($req)->getStatusCode());

        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/$this->mail/in-progress?reEnrol=1&jwt=". UserHelper::ROOT_JWT, 'POST');
        $req->attributes->replace(['jwt.payload' => $this->getAdminPayload($this->portalName)]);
        $this->assertEquals(406, $app->handle($req)->getStatusCode());
    }
}
