<?php

namespace go1\enrolment\tests\update;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LoHelper;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentResultTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalName = 'az.mygo1.com';
    private $portalId;
    private $adminJwt;
    private $profileId  = 555;
    private $loId;
    private $enrolmentId;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->loId = $this->createCourse($go1, [
            'instance_id' => $this->portalId,
            'data'        => [LoHelper::PASS_RATE => 70],
        ]);
        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'profile_id' => $this->profileId]),
            $this->createUser($go1, ['instance' => $this->portalName])
        );
        $this->enrolmentId = $this->createEnrolment($go1, ['user_id' => $userId, 'lo_id' => $this->loId, 'profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'status' => EnrolmentStatuses::IN_PROGRESS]);

        $adminUserId = $this->createUser($go1, ['profile_id' => $adminProfileId = 33, 'instance' => $app['accounts_name'], 'mail' => $adminMail = 'admin@foo.com']);
        $adminAccountId = $this->createUser($go1, ['profile_id' => $adminProfileId, 'instance' => $this->portalName, 'mail' => $adminMail]);
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $this->portalName]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $adminUserId, $adminAccountId);
        $this->adminJwt = $this->jwtForUser($go1, $adminUserId, $this->portalName);
    }

    public function testPass()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$this->adminJwt}", 'PUT', ['result' => 75]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());

        $db = $app['dbs']['go1'];
        $enrolment = EnrolmentHelper::load($db, $this->enrolmentId);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals(EnrolmentStatuses::PASSED, $enrolment->pass);
        $this->assertEquals(75, $enrolment->result);
    }

    public function testFail()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$this->adminJwt}", 'PUT', ['result' => 65]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());

        $db = $app['dbs']['go1'];
        $enrolment = EnrolmentHelper::load($db, $this->enrolmentId);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals(EnrolmentStatuses::FAILED, $enrolment->pass);
        $this->assertEquals(65, $enrolment->result);
    }

    public function testUpdateResult()
    {
        /** @var Connection $go1 */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$this->adminJwt}", 'PUT', ['result' => 75]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolment = EnrolmentHelper::load($go1, $this->enrolmentId);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals(EnrolmentStatuses::PASSED, $enrolment->pass);
        $this->assertEquals(75, $enrolment->result);

        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$this->adminJwt}", 'PUT', ['result' => 65]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());

        $enrolment = EnrolmentHelper::load($go1, $this->enrolmentId);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals(EnrolmentStatuses::FAILED, $enrolment->pass);
        $this->assertEquals(65, $enrolment->result);
    }
}
