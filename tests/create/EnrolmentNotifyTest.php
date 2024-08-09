<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\queue\Queue;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentNotifyTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use PlanMockTrait;

    private $db;
    private $portalId;
    private $portalName = 'foo.com';
    private $portalPublicKey;
    private $userId;
    private $userMail   = 'foo@foo.com';
    private $userJwt;
    private $loId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $this->db = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($this->db, ['title' => $this->portalName]);
        $this->portalPublicKey = $this->createPortalPublicKey($this->db, ['instance' => $this->portalName]);
        $this->loId = $this->createCourse($this->db, ['instance_id' => $this->portalId]);
        $this->userId = $this->createUser($this->db, ['instance' => $app['accounts_name'], 'mail' => $this->userMail, 'profile_id' => $userProfileId = 99]);
        $accountId = $this->createUser($this->db, ['instance' => $this->portalName, 'mail' => $this->userMail, 'profile_id' => $userProfileId]);
        $this->link($this->db, EdgeTypes::HAS_ACCOUNT, $this->userId, $accountId);
        $this->userJwt = $this->jwtForUser($this->db, $this->userId, $this->portalName);

        $this->loAccessGrant($this->loId, $this->userId, $this->portalId, 2);
    }

    public function test()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->userJwt}", 'POST');

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $this->assertTrue($this->queueMessages[Queue::ENROLMENT_CREATE][0]['_context']['notify_email']);
        $this->assertNotNull($this->queueMessages[Queue::ENROLMENT_CREATE][0]['timestamp']);
    }

    public function dataNotify()
    {
        $notifyOn = $isNotify = $willNotify = true;
        $notifyOff = $isNotNotify = $willNotNotify = false;

        return [
            [$notifyOn, $isNotify, $willNotify],
            [$notifyOn, $isNotNotify, $willNotNotify],
            [$notifyOn, null, $willNotNotify],
            [$notifyOn, '', $willNotNotify],
            [$notifyOff, $isNotify, $willNotify],
            [$notifyOff, $isNotNotify, $willNotify],
            [$notifyOff, null, $willNotify],
            [$notifyOff, '', $willNotify],

        ];
    }

    /** @dataProvider dataNotify */
    public function testCreate($notifyOn, $isNotify, $willNotify)
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/in-progress?jwt={$this->userJwt}", 'POST');
        if ($notifyOn) {
            $req->request->replace([
                'notify' => $isNotify,
            ]);
        }

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $this->assertEquals($willNotify, $this->queueMessages[Queue::ENROLMENT_CREATE][0]['_context']['notify_email']);
        $this->assertNotNull($this->queueMessages[Queue::ENROLMENT_CREATE][0]['timestamp']);
    }

    /** @dataProvider dataNotify */
    public function testCreateForStudent($notifyOn, $isNotify, $willNotify)
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/{$this->userMail}/not-started?jwt=" . UserHelper::ROOT_JWT, 'POST');

        if ($notifyOn) {
            $req->request->replace([
                'notify' => $isNotify,
            ]);
        }

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $this->assertEquals($willNotify, $this->queueMessages[Queue::ENROLMENT_CREATE][0]['_context']['notify_email']);
        $this->assertNotNull($this->queueMessages[Queue::ENROLMENT_CREATE][0]['timestamp']);
    }
}
