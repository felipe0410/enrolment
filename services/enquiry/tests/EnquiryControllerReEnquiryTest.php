<?php

namespace go1\core\learning_record\enquiry\tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\core\learning_record\enquiry\EnquiryServiceProvider;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\create\App;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\Roles;
use Symfony\Component\HttpFoundation\Request;

class EnquiryControllerReEnquiryTest extends EnrolmentTestCase
{
    use EnrolmentMockTrait;
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $portalName  = 'az.mygo1.com';
    private $portalId;
    private $loId;
    private $learnerMail = 'foo@bar.baz';
    private $learnerUserId;
    private $profileId   = 12345;
    private $learnerJwt;
    private $managerMail = 'manager@bar.baz';
    private $managerJwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->loId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'data' => '{"allow_enrolment":"enquiry"}']);

        $this->learnerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->learnerMail, 'profile_id' => $this->profileId]);
        $learnerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->learnerMail]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->learnerUserId, $learnerAccountId);
        $this->learnerJwt = $this->jwtForUser($go1, $this->learnerUserId, $this->portalName);

        $managerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->managerMail]);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $learnerAccountId, $managerUserId);
        $this->managerJwt = $this->getJwt($this->managerMail, $app['accounts_name'], $this->portalName, [Roles::MANAGER]);
    }

    private function createEnquiryEdge(Connection $db, array $options)
    {
        $db->insert('gc_ro', [
            'type'      => EdgeTypes::HAS_ENQUIRY,
            'source_id' => $options['lo_id'],
            'target_id' => $options['user_id'],
            'weight'    => $options['weight'] ?? 0,
            'data'      => json_encode([
                'course'     => $options['data']['course'] ?? 'Example course',
                'first'      => $options['data']['first'] ?? 'A',
                'last'       => $options['data']['last'] ?? 'T',
                'mail'       => $options['data']['mail'] ?? 'thehongtt@gmail.com',
                'phone'      => $options['data']['phone'] ?? '0123456789',
                'created'    => $options['data']['created'] ?? time(),
                'updated'    => $options['data']['updated'] ?? null,
                'updated_by' => $options['data']['updated_by'] ?? null,
                'body'       => $options['data']['body'] ?? 'I want to enroll to this course',
                're_enquiry' => $options['data']['re_enquiry'] ?? null,
                'status'     => $options['data']['status'] ?? EnquiryServiceProvider::ENQUIRY_PENDING,
            ]),
        ]);

        return $db->lastInsertId('gc_ro');
    }

    public function dataGet200()
    {
        return [
            [EnquiryServiceProvider::ENQUIRY_PENDING],
            [EnquiryServiceProvider::ENQUIRY_REJECTED],
        ];
    }

    /**
     * @dataProvider dataGet200
     */
    public function testGet200InCompleted($status)
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $enquiryId = $this->createEnquiryEdge($db, ['lo_id' => $this->loId, 'user_id' => $this->learnerUserId, 'data' => ['status' => $status]]);

        $req = Request::create("/enquiry/{$this->loId}/{$this->learnerMail}?re_enquiry=1&jwt={$this->learnerJwt}", 'GET');
        $res = $app->handle($req);
        $enquiryEdge = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals($enquiryId, $enquiryEdge->id);
        $this->assertEquals($this->loId, $enquiryEdge->source_id);
        $this->assertEquals($this->learnerUserId, $enquiryEdge->target_id);
        $this->assertEquals($status, $enquiryEdge->data->status);
    }

    public function testGet404()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $req = Request::create("/enquiry/{$this->loId}/{$this->learnerMail}?re_enquiry=1&jwt={$this->learnerJwt}", 'GET');
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testGet404Completed()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $this->createEnquiryEdge($db, ['lo_id' => $this->loId, 'user_id' => $this->learnerUserId, 'data' => ['status' => EnquiryServiceProvider::ENQUIRY_ACCEPTED]]);

        $req = Request::create("/enquiry/{$this->loId}/{$this->learnerMail}?re_enquiry=1&jwt={$this->learnerJwt}", 'GET');
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testPostCreate200WithoutParam()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $enquiryEdgeId = $this->createEnquiryEdge($db, ['lo_id' => $this->loId, 'user_id' => $this->learnerUserId, 'data' => ['status' => EnquiryServiceProvider::ENQUIRY_ACCEPTED]]);

        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->loId}/{$this->learnerMail}", 'POST');
        $req->request->add([
            'enquireFirstName' => 'Student',
            'enquireLastName'  => 'GO1',
            'enquirePhone'     => null,
            'enquireMessage'   => 'Let me learning!',
            'reEnquiry'        => 1,
        ]);
        $req->query->replace(['jwt' => $this->learnerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals($enquiryEdgeId, json_decode($res->getContent())->id);
    }

    public function dataPostCreate200WithPreviousInCompletedEnrolment()
    {
        return [
            [EnrolmentStatuses::NOT_STARTED],
            [EnrolmentStatuses::IN_PROGRESS],
            [EnrolmentStatuses::PENDING],
        ];
    }

    /**
     * @dataProvider dataPostCreate200WithPreviousInCompletedEnrolment
     */
    public function testPostCreate200WithPreviousInCompletedEnrolment($enrolmentStatus)
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $enquiryEdgeId = $this->createEnquiryEdge($db, ['lo_id' => $this->loId, 'user_id' => $this->learnerUserId, 'data' => ['status' => EnquiryServiceProvider::ENQUIRY_ACCEPTED]]);
        $this->createEnrolment($db, ['lo_id' => $this->loId, 'user_id' => $this->learnerUserId, 'taken_instance_id' => $this->portalId, 'status' => $enrolmentStatus]);

        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->loId}/{$this->learnerMail}", 'POST');
        $req->request->add([
            'enquireFirstName' => 'Student',
            'enquireLastName'  => 'GO1',
            'enquirePhone'     => null,
            'enquireMessage'   => 'Let me learning!',
            'reEnquiry'        => 1,
        ]);
        $req->query->replace(['jwt' => $this->learnerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals($enquiryEdgeId, json_decode($res->getContent())->id);
    }

    public function testPostCreate200()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $enquiryEdgeId = $this->createEnquiryEdge($db, ['lo_id' => $this->loId, 'user_id' => $this->learnerUserId, 'data' => ['status' => EnquiryServiceProvider::ENQUIRY_ACCEPTED]]);
        $this->createEnrolment($db, ['lo_id' => $this->loId, 'user_id' => $this->learnerUserId, 'taken_instance_id' => $this->portalId, 'status' => EnrolmentStatuses::COMPLETED]);

        $req = Request::create("/enquiry/{$this->loId}/{$this->learnerMail}", 'POST');
        $req->request->add([
            'enquireFirstName' => 'Student',
            'enquireLastName'  => 'GO1',
            'enquirePhone'     => null,
            'enquireMessage'   => 'Let me learning!',
            'reEnquiry'        => 1,
        ]);
        $req->query->replace(['jwt' => $this->learnerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertTrue(is_int(json_decode($res->getContent())->id));

        // There's a message published
        $this->assertEquals(1, count($this->queueMessages[Queue::RO_DELETE]));
        $this->assertEquals($enquiryEdgeId, $this->queueMessages[Queue::RO_DELETE][0]['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_ACCEPTED, $this->queueMessages[Queue::RO_DELETE][0]['data']['status']);

        $this->assertEquals(1, count($this->queueMessages[Queue::RO_CREATE]));
        $this->assertEquals($this->loId, $this->queueMessages[Queue::RO_CREATE][0]['source_id']);
        $this->assertEquals($this->learnerUserId, $this->queueMessages[Queue::RO_CREATE][0]['target_id']);
        $newEdgeData = is_scalar($this->queueMessages[Queue::RO_CREATE][0]['data']) ? json_decode($this->queueMessages[Queue::RO_CREATE][0]['data']) : $this->queueMessages[Queue::RO_CREATE][0]['data'];
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_PENDING, $newEdgeData->status);
        $this->assertEquals(true, $newEdgeData->re_enquiry);
    }

    public function testPostReviewing406Accepted()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $this->createEnquiryEdge($db, ['lo_id' => $this->loId, 'user_id' => $this->learnerUserId, 'data' => ['status' => EnquiryServiceProvider::ENQUIRY_ACCEPTED]]);

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->learnerMail}", 'POST');
        $req->request->add([
            'status'   => EnquiryServiceProvider::ENQUIRY_ACCEPTED,
            'instance' => $this->portalId,
        ]);
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(406, $res->getStatusCode());
        $this->assertEquals('Can not accept or reject if enquiry is not pending.', json_decode($res->getContent())->message);
    }

    public function testPostReviewing200Accepted()
    {
        $app = $this->getApp();
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);
        $db = $app['dbs']['go1'];
        $enquiryEdgeId = $this->createEnquiryEdge($db, ['lo_id' => $this->loId, 'user_id' => $this->learnerUserId, 'data' => ['status' => EnquiryServiceProvider::ENQUIRY_PENDING, 'mail' => $this->learnerMail, 're_enquiry' => true]]);

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->learnerMail}", 'POST');
        $req->request->add([
            'status'   => EnquiryServiceProvider::ENQUIRY_ACCEPTED,
            'instance' => $this->portalId,
        ]);
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolmentRepository = $app[EnrolmentRepository::class];
        $enrolment = $enrolmentRepository->loadByLoAndUserId($this->loId, $this->learnerUserId);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $enrolment->status);
        $this->assertEquals($this->portalId, $enrolment->taken_instance_id);
        $this->assertEquals($enrolment->start_date, $enrolment->changed);

        $newEdge = $db->fetchAssoc('SELECT * FROM gc_ro WHERE type = ? AND source_id = ? AND target_id = ? ', [EdgeTypes::HAS_ENQUIRY, $this->loId, $this->learnerUserId]);
        $edgeData = json_decode($newEdge['data']);
        $this->assertEquals($enquiryEdgeId, $newEdge['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_ACCEPTED, $edgeData->status);
        $this->assertEquals($this->learnerMail, $edgeData->mail);
        $this->assertTrue(time() >= $edgeData->updated);
        $this->assertEquals($this->managerMail, $edgeData->updated_by);

        // There's a message published
        $this->assertEquals($enquiryEdgeId, $this->queueMessages[Queue::RO_UPDATE][0]['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_ACCEPTED, $this->queueMessages[Queue::RO_UPDATE][0]['data']['status']);
        $this->assertEquals($enrolment->id, $this->queueMessages[Queue::ENROLMENT_CREATE][0]['id']);
    }

    public function testPostReviewing200Rejected()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $enquiryEdgeId = $this->createEnquiryEdge($db, ['lo_id' => $this->loId, 'user_id' => $this->learnerUserId, 'data' => ['status' => EnquiryServiceProvider::ENQUIRY_PENDING, 'mail' => $this->learnerMail, 're_enquiry' => true]]);

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->learnerMail}", 'POST');
        $req->request->add([
            'status'   => EnquiryServiceProvider::ENQUIRY_REJECTED,
            'instance' => $this->portalId,
        ]);
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolmentRepository = $app[EnrolmentRepository::class];
        $this->assertFalse($enrolmentRepository->loadByLoAndUserId($this->loId, $this->learnerUserId));

        $newEdge = $db->fetchAssoc('SELECT * FROM gc_ro WHERE type = ? AND source_id = ? AND target_id = ? ', [EdgeTypes::HAS_ENQUIRY, $this->loId, $this->learnerUserId]);
        $edgeData = json_decode($newEdge['data']);
        $this->assertEquals($enquiryEdgeId, $newEdge['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_REJECTED, $edgeData->status);
        $this->assertEquals($this->learnerMail, $edgeData->mail);
        $this->assertTrue(time() >= $edgeData->updated);
        $this->assertEquals($this->managerMail, $edgeData->updated_by);

        // There's a message published
        $this->assertEquals($enquiryEdgeId, $this->queueMessages[Queue::RO_UPDATE][0]['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_REJECTED, $this->queueMessages[Queue::RO_UPDATE][0]['data']['status']);
        $this->assertTrue(empty($this->queueMessages[Queue::ENROLMENT_CREATE]));
    }
}
