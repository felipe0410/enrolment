<?php

namespace go1\core\learning_record\manual_record\tests;

use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\queue\Queue;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\Text;
use go1\util\user\Roles;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class ManualRecordTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;

    private $portalName      = 'qa.mygo1.com';
    private $premiumInstance = 'premium.mygo1.com';
    private $portalId;
    private $premiumInstanceId;
    private $courseId;
    private $premiumCourseId;
    private $marketplaceCourseId;
    private $nonMarketplaceCourseId;
    private $userId;
    private $userJwt;
    private $portalManagerJwt;
    private $studentManagerJwt;
    private $noAccountUserId;
    private $noAccountJwt;
    private $adminJwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));

        $db = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->adminJwt = JWT::encode((array) $this->getAdminPayload($this->portalName), 'INTERNAL', 'HS256');
        $this->courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);

        $this->premiumInstanceId = $this->createPortal($db, ['title' => $this->premiumInstance]);
        $this->marketplaceCourseId = $this->createCourse($db, ['instance_id' => $this->premiumInstanceId, 'marketplace' => 1, 'remote_id' => 1]);
        $this->nonMarketplaceCourseId = $this->createCourse($db, ['instance_id' => $this->premiumInstanceId, 'marketplace' => 0, 'remote_id' => 2]);
        $this->premiumCourseId = $this->createCourse($db, ['instance_id' => $this->premiumInstanceId, 'marketplace' => 0, 'remote_id' => 3]);

        # Create user inside portal.
        $this->userId = $this->createUser($db, ['instance' => $app['accounts_name']]);
        $accountId = $this->createUser($db, ['instance' => $this->portalName]);
        $managerUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => 'manager@go1.com']);
        $managerPortalAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'manager@go1.com']);

        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->userId, $accountId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $managerUserId, $managerPortalAccountId);

        $this->userJwt = $this->jwtForUser($db, $this->userId, $this->portalName);
        $this->portalManagerJwt = JWT::encode((array) $this->getPayload(['instance_name' => $this->portalName, 'user_id' => $managerUserId, 'roles' => [Roles::MANAGER]]), 'INTERNAL', 'HS256');
        $this->studentManagerJwt = $this->jwtForUser($db, $managerUserId, $this->portalName);
        $this->link($db, EdgeTypes::HAS_MANAGER, $accountId, $managerUserId);

        # Create a user outside the portal
        $this->noAccountUserId = $this->createUser($db, ['title' => $app['accounts_name'], 'mail' => 'no@account.com']);
        $this->noAccountJwt = $this->jwtForUser($db, $this->noAccountUserId);
    }

    public function testCreate406Portal()
    {
        $app = $this->getApp();
        $req = Request::create('/manual-record/invalid.mygo1.com/lo/666?jwt=' . $this->userJwt, 'POST');
        $res = $app->handle($req);
        $this->assertEquals(406, $res->getStatusCode());
        $this->assertStringContainsString('Portal not found.', $res->getContent());
    }

    public function testCreate406Lo()
    {
        $app = $this->getApp();
        $req = Request::create('/manual-record/qa.mygo1.com/lo/666?jwt=' . $this->userJwt, 'POST');
        $res = $app->handle($req);
        $this->assertEquals(406, $res->getStatusCode());
        $this->assertStringContainsString('Entity not found.', $res->getContent());
    }

    public function testCreate406LoNonMarketplace()
    {
        $app = $this->getApp();
        $req = Request::create('/manual-record/qa.mygo1.com/lo/' . $this->nonMarketplaceCourseId . '?jwt=' . $this->userJwt, 'POST');
        $res = $app->handle($req);
        $this->assertEquals(406, $res->getStatusCode());
        $this->assertStringContainsString('Invalid portal.', $res->getContent());
    }

    public function testCreate403()
    {
        $app = $this->getApp();
        $req = Request::create('/manual-record/qa.mygo1.com/lo/' . $this->courseId . '?jwt=' . $this->noAccountJwt, 'POST');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Account not found.', $res->getContent());
    }

    public function dataCreate200()
    {
        $this->getApp();

        return [
            [$this->courseId],
            [$this->marketplaceCourseId],
        ];
    }

    /**
     * @dataProvider dataCreate200
     */
    public function testCreate200($courseId)
    {
        $app = $this->getApp();
        $req = Request::create("/manual-record/{$this->portalName}/lo/{$courseId}?jwt={$this->userJwt}", 'POST', [
            'data' => $data = ['description' => 'I studied this at home. Please verify, thanks!'],
        ]);
        $res = $app->handle($req);
        $msg = $this->queueMessages[Queue::MANUAL_RECORD_CREATE][0];

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals($this->portalId, $msg->instanceId);
        $this->assertEquals('lo', $msg->entityType);
        $this->assertEquals($courseId, $msg->entityId);
        $this->assertEquals($this->userId, $msg->userId);
        $this->assertEquals(false, $msg->verified);
        $this->assertEquals($data, $msg->data);

        # Create again to check duplication
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('waiting for verification', $res->getContent());
    }

    public function testCreateExternalRecord()
    {
        $app = $this->getApp();
        $req = Request::create('/manual-record/qa.mygo1.com/external/-?jwt=' . $this->userJwt, 'POST', [
            'data' => $data = [
                'description' => 'I studied this at home. Please verify, thanks!',
                'name'        => '',
                'size'        => '',
                'url'         => '',
            ],
        ]);
        $res = $app->handle($req);
        $msg = $this->queueMessages[Queue::MANUAL_RECORD_CREATE][0];

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals($this->portalId, $msg->instanceId);
        $this->assertEquals('external', $msg->entityType);
        $this->assertEquals('-', $msg->entityId);
        $this->assertEquals($this->userId, $msg->userId);
        $this->assertEquals(false, $msg->verified);
        $this->assertEquals($data, $msg->data);
    }

    public function testCreateExternalRecordWithFileInfo()
    {
        $app = $this->getApp();
        $req = Request::create('/manual-record/qa.mygo1.com/external/-?jwt=' . $this->userJwt, 'POST', [
            'data' => $data = [
                'description' => 'I studied this at home. Please verify, thanks!',
                'name'        => 'my-certificate.pdf',
                'size'        => 1024,
                'url'         => 'http://path-to-my.com/uri-of-my-certificate.pdf',
                'type'        => 'application/pdf',
            ],
        ]);
        $res = $app->handle($req);
        $msg = $this->queueMessages[Queue::MANUAL_RECORD_CREATE][0];

        $this->assertEquals(201, $res->getStatusCode());
        $this->assertEquals($this->portalId, $msg->instanceId);
        $this->assertEquals('external', $msg->entityType);
        $this->assertEquals('-', $msg->entityId);
        $this->assertEquals($this->userId, $msg->userId);
        $this->assertEquals(false, $msg->verified);
        $this->assertEquals($data, $msg->data);
    }

    public function testUpdate()
    {
        $app = $this->getApp();

        # Create the manual record
        $req = Request::create('/manual-record/qa.mygo1.com/lo/' . $this->courseId . '?jwt=' . $this->userJwt, 'POST', ['data' => $data = ['description' => 'I studied this at home. Please verify, thanks!']]);
        $manualRecordId = json_decode($app->handle($req)->getContent())->id;

        # Update it
        $req = Request::create('/manual-record/' . $manualRecordId . '?jwt=' . $this->userJwt, 'PUT', [
            'entity_type' => 'external',
            'entity_id'   => '-',
            'data'        => $data = [
                'description' => 'Hello <script>alert(666);</script>!',
            ],
        ]);
        $res = $app->handle($req);
        $msg = $this->queueMessages[Queue::MANUAL_RECORD_UPDATE][0];

        # Check publishing message
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals('Hello !', $msg->data['description']);
        $this->assertNotEquals($msg->entityType, $msg->original->entityType);
        $this->assertNotEquals($msg->entityId, $msg->original->entityId);
    }

    public function testVerify403()
    {
        $app = $this->getApp();

        # Create the manual record.
        $req = Request::create('/manual-record/qa.mygo1.com/lo/' . $this->courseId . '?jwt=' . $this->userJwt, 'POST');
        $manualRecordId = json_decode($app->handle($req)->getContent())->id;

        # Verify it with WRONG jwt.
        $req = '/manual-record/' . $manualRecordId . '/verify/1?jwt=' . $this->userJwt;
        $req = Request::create($req, 'PUT', ['verified' => true]);
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testVerify200()
    {
        $app = $this->getApp();

        # Create the manual record.
        $req = Request::create('/manual-record/qa.mygo1.com/lo/' . $this->courseId . '?jwt=' . $this->userJwt, 'POST');
        $manualRecordId = json_decode($app->handle($req)->getContent())->id;

        # Verify it.
        $req = '/manual-record/' . $manualRecordId . '/verify/1?jwt=' . $this->adminJwt;
        $req = Request::create($req, 'PUT', ['verified' => true]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        # Check for publishing message
        $msg = $this->queueMessages[Queue::MANUAL_RECORD_UPDATE][0];
        $this->assertEquals($manualRecordId, $msg->id);
        $this->assertEquals($manualRecordId, $msg->original->id);
        $this->assertEquals(true, $msg->verified);
        $this->assertEquals(false, $msg->original->verified);
        $this->assertEquals('approved', $msg->data['verify'][0]['action']);
        $this->assertEquals(Text::jwtContent($this->adminJwt)->object->content->id, $msg->data['verify'][0]['actor_id']);
    }

    public function testDelete403()
    {
        $app = $this->getApp();

        # Create the manual record.
        $req = Request::create('/manual-record/qa.mygo1.com/lo/' . $this->courseId . '?jwt=' . $this->userJwt, 'POST');
        $manualRecordId = json_decode($app->handle($req)->getContent())->id;

        # Delete it using other user JWT.
        $req = Request::create('/manual-record/' . $manualRecordId . '?jwt=' . $this->noAccountJwt, 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testDeleteByAuthor()
    {
        $app = $this->getApp();

        # Create the manual record.
        $req = Request::create('/manual-record/qa.mygo1.com/lo/' . $this->courseId . '?jwt=' . $this->userJwt, 'POST');
        $manualRecordId = json_decode($app->handle($req)->getContent())->id;

        # Delete it using user JWT.
        $req = Request::create('/manual-record/' . $manualRecordId . '?jwt=' . $this->userJwt, 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
    }

    public function testDelete200()
    {
        $app = $this->getApp();

        # Create the manual record.
        $req = Request::create('/manual-record/qa.mygo1.com/lo/' . $this->courseId . '?jwt=' . $this->userJwt, 'POST');
        $manualRecordId = json_decode($app->handle($req)->getContent())->id;

        # Delete it
        $req = Request::create('/manual-record/' . $manualRecordId . '?jwt=' . $this->adminJwt, 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        # Check for publishing message
        $msg = $this->queueMessages[Queue::MANUAL_RECORD_DELETE][0];
        $this->assertEquals($manualRecordId, $msg->id);
    }

    public function testLoad()
    {
        $app = $this->getApp();
        $app->handle(Request::create('/manual-record/qa.mygo1.com/lo/' . $this->courseId . '?jwt=' . $this->userJwt, 'POST'));

        $this->assertEquals(200, $app->handle(Request::create('/manual-record/qa.mygo1.com/lo/' . $this->courseId . '?jwt=' . $this->userJwt))->getStatusCode());
        $this->assertEquals(404, $app->handle(Request::create('/manual-record/qa.mygo1.com/lo/' . ($this->courseId + 6) . '?jwt=' . $this->userJwt))->getStatusCode());
    }

    public function testBrowsing()
    {
        $app = $this->getApp();

        # Create a manual record.
        $app->handle(Request::create('/manual-record/qa.mygo1.com/lo/' . $this->courseId . '?jwt=' . $this->userJwt, 'POST'));

        # Browse on invalid portal
        $req = Request::create('/manual-record/invalid.mygo1.com?jwt=' . $this->userJwt);
        $this->assertEquals(406, $app->handle($req)->getStatusCode());

        # Browse on invalid user id
        $req = Request::create('/manual-record/qa.mygo1.com/456?jwt=' . $this->userJwt);
        $this->assertEquals(406, $app->handle($req)->getStatusCode());

        # Browse other -> 403
        $req = Request::create('/manual-record/qa.mygo1.com/' . $this->userId . '?jwt=' . $this->getJwt());
        $this->assertEquals(403, $app->handle($req)->getStatusCode());

        # Simple browsing
        $req = Request::create('/manual-record/qa.mygo1.com?jwt=' . $this->userJwt);
        $res = $app->handle($req);
        $records = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $records);

        # Browse by portal manager
        $req = Request::create('/manual-record/qa.mygo1.com/' . $this->userId . '?jwt=' . $this->portalManagerJwt);
        $res = $app->handle($req);
        $records = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $records);

        # Browse by student manager
        $req = Request::create('/manual-record/qa.mygo1.com/' . $this->userId . '?jwt=' . $this->studentManagerJwt);
        $res = $app->handle($req);
        $records = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $records);
        //
        //        # Browse with userId = 'me'
        //        $req = Request::create('/manual-record/qa.mygo1.com/me?jwt=' . $this->userJwt);
        //        $res = $app->handle($req);
        //        $records = json_decode($res->getContent());
        //        $this->assertEquals(200, $res->getStatusCode());
        //        $this->assertCount(1, $records);
        //
        //        # With entity-type filter.
        //        $req = Request::create('/manual-record/qa.mygo1.com/me?entityType=lo&jwt=' . $this->userJwt);
        //        $res = $app->handle($req);
        //        $records = json_decode($res->getContent());
        //
        //        $this->assertEquals(200, $res->getStatusCode());
        //        $this->assertCount(1, $records);
        //
        //        $req = Request::create('/manual-record/qa.mygo1.com/me?entityType=manual&jwt=' . $this->userJwt);
        //        $res = $app->handle($req);
        //        $records = json_decode($res->getContent());
        //        $this->assertEquals(200, $res->getStatusCode());
        //        $this->assertCount(0, $records);
    }
}
