<?php

namespace go1\core\learning_record\enquiry\tests;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\core\learning_record\enquiry\EnquiryServiceProvider;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\queue\Queue;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\Roles;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnquiryControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $portalName = 'az.mygo1.com';
    private $portalId;
    private $loId;
    private $mail       = 'foo@bar.baz';
    private $enquiryId;
    private $portalAccountId;
    private $status;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->enquiryId = $go1->insert('gc_ro', [
            'type'      => EdgeTypes::HAS_ENQUIRY,
            'source_id' => $this->loId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'data' => '{"allow_enrolment":"enquiry"}']),
            'target_id' => $userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->mail]),
            'weight'    => 0,
            'data'      => json_encode([
                'course'     => 'Example course',
                'first'      => 'A',
                'last'       => 'T',
                'mail'       => 'thehongtt@gmail.com',
                'phone'      => '0123456789',
                'created'    => time(),
                'updated'    => null,
                'updated_by' => null,
                'body'       => 'I want to enroll to this course',
                'status'     => EnquiryServiceProvider::ENQUIRY_PENDING,
            ]),
        ]);

        $this->portalAccountId = $this->createUser($go1, ['mail' => $this->mail, 'instance' => $this->portalName]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $userId, $this->portalAccountId);
    }

    public function testGet403()
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->loId}/{$this->mail}", 'GET');
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Missing or invalid JWT.', json_decode($res->getContent())->message);
    }

    public function testGet200()
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->loId}/{$this->mail}", 'GET');
        $req->attributes->set('jwt.payload', $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [Roles::AUTHENTICATED],
        ]));
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testDeleteById403()
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->enquiryId}", 'DELETE');

        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Missing or invalid JWT.', json_decode($res->getContent())->message);
    }

    public function testDeleteById403Role()
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->enquiryId}", 'DELETE');
        $req->attributes->set('jwt.payload', $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [Roles::AUTHENTICATED],
        ]));
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Only portal\'s admin or student\'s manager can delete enquiry request', json_decode($res->getContent())->message);
    }

    public function testDeleteById400InvalidInstance()
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->enquiryId}", 'DELETE');
        $req->request->set('instance', 'nowhere.mygo1.com');
        $req->attributes->set('jwt.payload', $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [Roles::MANAGER],
        ]));
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Invalid portal.', json_decode($res->getContent())->message);
    }

    public function testDeleteById400InvalidEnquiry()
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/999", 'DELETE');
        $req->attributes->set('jwt.payload', $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [Roles::ROOT],
        ]));
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Invalid enquiry.', json_decode($res->getContent())->message);
    }

    public function testDeleteById404InvalidIdType()
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/invalid_type", 'DELETE');
        $req->attributes->set('jwt.payload', $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [Roles::ROOT],
        ]));
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
    }

    public function dataDeleteById204()
    {
        $this->getApp();

        return [
            [Roles::MANAGER, $this->portalId],
            [Roles::MANAGER, $this->portalName],
            [Roles::ADMIN, $this->portalId],
            [Roles::ROOT],
        ];
    }

    /**
     * @dataProvider dataDeleteById204
     */
    public function testDeleteById204($role, $portalName = null)
    {
        $app = $this->getApp();
        $this->status = EnquiryServiceProvider::ENQUIRY_REJECTED;

        $req = Request::create("/enquiry/{$this->enquiryId}", 'DELETE');
        $req->attributes->set('jwt.payload', $payload = $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [$role],
        ]));
        $req->request->set('instance', $portalName);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        $this->assertFalse($db->fetchColumn('SELECT id FROM gc_ro WHERE id = ?', [$this->enquiryId]));

        // There's a message published
        $this->assertEquals($this->enquiryId, $this->queueMessages[Queue::RO_DELETE][0]['id']);
    }

    public function testDelete403()
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->loId}/student/{$this->mail}", 'DELETE');

        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Missing or invalid JWT.', json_decode($res->getContent())->message);
    }

    public function testDelete400InvalidLo()
    {
        $app = $this->getApp();
        $invalidLoId = 123;

        $req = Request::create("/enquiry/{$invalidLoId}/student/{$this->mail}", 'DELETE');
        $req->attributes->set('jwt.payload', $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [Roles::MANAGER],
        ]));
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Invalid learning object.', json_decode($res->getContent())->message);
    }

    public function testDelete406NormalLo()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $normalLoId = $this->createCourse($db, ['title' => 'New course', 'instance_id' => $this->portalId, 'data' => '{"foo":"bar"}']);

        $req = Request::create("/enquiry/{$normalLoId}/student/{$this->mail}", 'DELETE');
        $req->attributes->set('jwt.payload', $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [Roles::MANAGER],
        ]));
        $res = $app->handle($req);

        $this->assertEquals(406, $res->getStatusCode());
        $this->assertEquals('This learning object is not available for enquiring action.', json_decode($res->getContent())->message);
    }

    public function testDelete400InvalidPortal()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $loId = $this->createCourse($db, ['title' => 'New course', 'instance_id' => $invalidInstanceId = 345, 'data' => '{"allow_enrolment":"enquiry"}']);

        $req = Request::create("/enquiry/{$loId}/student/{$this->mail}", 'DELETE');
        $req->attributes->set('jwt.payload', $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [Roles::MANAGER],
        ]));
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Invalid portal.', json_decode($res->getContent())->message);
    }

    public function testDelete400InvalidMail()
    {
        $app = $this->getApp();
        $invalidMail = 'unknown@go1.site';

        $req = Request::create("/enquiry/{$this->loId}/student/{$invalidMail}", 'DELETE');
        $req->attributes->set('jwt.payload', $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [Roles::MANAGER],
        ]));
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Invalid enquiry mail.', json_decode($res->getContent())->message);
    }

    public function testDelete403Role()
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->loId}/student/{$this->mail}", 'DELETE');
        $req->attributes->set('jwt.payload', $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [Roles::AUTHENTICATED],
        ]));
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Only portal\'s admin or student\'s manager can delete enquiry request', json_decode($res->getContent())->message);
    }

    public function testDelete204StudentManager()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        // Create student & manager
        $managerUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => 'manager@go1.co']);
        $managerPortalAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'manager@go1.co']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $managerUserId, $managerPortalAccountId);
        $this->link($db, EdgeTypes::HAS_MANAGER, $this->portalAccountId, $managerUserId, 0);

        $jwt = $this->jwtForUser($db, $managerUserId, $this->portalName);
        $req = Request::create("/enquiry/{$this->loId}/student/{$this->mail}?jwt={$jwt}", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());

        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        $this->assertFalse($db->fetchColumn('SELECT id FROM gc_ro WHERE id = ?', [$this->enquiryId]));

        // There's a message published
        $this->assertEquals($this->enquiryId, $this->queueMessages[Queue::RO_DELETE][0]['id']);
    }

    /**
     * @dataProvider dataDeleteById204
     */
    public function testDeleteByLearningObjectAndEmail204($role, $portalName = null)
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->loId}/student/{$this->mail}", 'DELETE');
        $req->attributes->set('jwt.payload', $payload = $this->getPayload([
            'accounts_name' => $app['accounts_name'],
            'instance_name' => $this->portalName,
            'roles'         => [$role],
        ]));

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        $this->assertFalse($db->fetchColumn('SELECT id FROM gc_ro WHERE id = ?', [$this->enquiryId]));
    }
}
