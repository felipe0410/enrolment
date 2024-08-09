<?php

namespace go1\core\learning_record\enquiry\tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\clients\portal\config\MailTemplate;
use go1\core\learning_record\enquiry\EnquiryServiceProvider;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\create\App;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\queue\Queue;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\Text;
use go1\util\user\Roles;
use Symfony\Component\HttpFoundation\Request;

class EnquiryAdminEnrolControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $portalName         = 'az.mygo1.com';
    private $portalId;
    private $takenPortalName    = 'za.mygo1.com';
    private $takenPortalId;
    private $loId;
    private $studentMail        = 'learner@qa.com';
    private $managerMail        = 'manager@bar.baz';
    private $studentManagerMail = 'student.manager@qa.com';
    private $authorMail         = 'author@bar.baz';
    private $studentUserId;
    private $studentManagerUserId;
    private $studentManagerAccountId;
    private $authorUserId;
    private $authorAccountId;
    private $studentProfileId   = 123;
    private $normalLoId;
    private $invalidLoId;
    private $managerJwt;
    private $studentManagerJWT;
    private $loAuthorJwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->takenPortalId = $this->createPortal($db, ['title' => $this->takenPortalName]);
        $this->loId = $this->createCourse($db, ['instance_id' => $this->portalId, 'data' => '{"allow_enrolment":"enquiry"}']);
        $this->studentUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->studentMail, 'profile_id' => $this->studentProfileId]);
        $studentAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => $this->studentMail]);
        $takenPortalStudentAccountId = $this->createUser($db, ['instance' => $this->takenPortalName, 'mail' => $this->studentMail]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $studentAccountId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $takenPortalStudentAccountId);
        $this->normalLoId = $this->createCourse($db, ['instance_id' => $this->portalName]);
        $this->invalidLoId = $this->createCourse($db, ['instance_id' => $invalidInstanceId = 123, 'data' => '{"allow_enrolment":"enquiry"}']);
        $this->managerJwt = $this->getJwt($this->managerMail, $app['accounts_name'], $this->portalName, [Roles::MANAGER]);

        $this->studentManagerUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->studentManagerMail, 'profile_id' => 456]);
        $this->studentManagerAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => $this->studentManagerMail]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->studentManagerUserId, $this->studentManagerAccountId);
        $this->link($db, EdgeTypes::HAS_MANAGER, $studentAccountId, $this->studentManagerUserId);
        $this->studentManagerJWT = $this->jwtForUser($db, $this->studentManagerUserId, $this->portalName);

        $this->authorUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->authorMail]);
        $this->authorAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => $this->authorMail]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->authorUserId, $this->authorAccountId);
        $this->loAuthorJwt = $this->jwtForUser($db, $this->authorUserId, $this->portalName);
        $_ = Text::jwtContent($this->loAuthorJwt);
        $_->object->content->accounts[0]->roles[] = Roles::MANAGER;
        $this->loAuthorJwt = JWT::encode((array) $_, 'INTERNAL', 'HS256');
    }

    private function createEnquiry(Connection $db, $data = null)
    {
        $data = !$data ? [] : $data + [
                'course'     => 'Example course',
                'first'      => 'Student',
                'last'       => 'GO1',
                'mail'       => $this->studentMail,
                'phone'      => '0123456789',
                'created'    => time(),
                'updated'    => null,
                'updated_by' => null,
                'body'       => 'I want to enroll to this course',
                'status'     => EnquiryServiceProvider::ENQUIRY_PENDING,
            ];

        $db->insert('gc_ro', [
            'type'      => EdgeTypes::HAS_ENQUIRY,
            'source_id' => $this->loId,
            'target_id' => $this->studentUserId,
            'weight'    => 0,
            'data'      => json_encode($data),
        ]);

        return $db->lastInsertId('gc_ro');
    }

    public function testPost403InvalidJwt()
    {
        $app = $this->getApp();
        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->studentMail}", 'POST');

        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Missing or invalid JWT.', json_decode($res->getContent())->message);
    }

    public function dataPost400()
    {
        $this->getApp();

        return [
            [123, $this->studentMail],
            [$this->invalidLoId, $this->studentMail],
            [$this->invalidLoId, 'invalid@go1.com'],
        ];
    }

    /**
     * @dataProvider dataPost400
     */
    public function testPost400($loId, $mail)
    {
        $app = $this->getApp();
        $req = Request::create("/admin/enquiry/{$loId}/{$mail}", 'POST');
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }

    public function testPost406EnquiringOnNormalCourse()
    {
        $app = $this->getApp();

        $req = Request::create("/admin/enquiry/{$this->normalLoId}/{$this->studentMail}", 'POST');
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(406, $res->getStatusCode());
        $this->assertEquals('This learning object is not available for enquiring action.', json_decode($res->getContent())->message);
    }

    public function testPost403Role()
    {
        $app = $this->getApp();
        $jwt = $this->getJwt('user@go1.com', $app['accounts_name'], 'pubic.mygo1.com', [Roles::AUTHENTICATED]);
        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->studentMail}", 'POST');
        $req->query->replace(['jwt' => $jwt]);

        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Only portal\'s admin, student\'s manager or learning object\'s author can review enquiry request.', json_decode($res->getContent())->message);
    }

    public function testPost400InvalidStatus()
    {
        $app = $this->getApp();

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->studentMail}", 'POST');
        $req->request->add([
            'status' => 'invalidStatus',
        ]);
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Status must be accepted or rejected.', json_decode($res->getContent())->message);
    }

    public function testPost404InvalidTakenInstance()
    {
        $app = $this->getApp();

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->studentMail}", 'POST');
        $req->request->add([
            'status'   => EnquiryServiceProvider::ENQUIRY_ACCEPTED,
            'instance' => 'nowhere.mygo1.com',
        ]);
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Invalid taken portal.', json_decode($res->getContent())->message);
    }

    public function testPost406StudentNotEnquiry()
    {
        $app = $this->getApp();

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->studentMail}", 'POST');
        $req->request->add([
            'status'   => EnquiryServiceProvider::ENQUIRY_ACCEPTED,
            'instance' => $this->takenPortalName,
        ]);
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(406, $res->getStatusCode());
        $this->assertEquals('Student did not enquire to this course.', json_decode($res->getContent())->message);
    }

    public function testPost406MissingEnquiryData()
    {
        $app = $this->getApp();
        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Create Enquiry ro with empty data value
        $this->createEnquiry($db);

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->studentMail}", 'POST');
        $req->request->add([
            'status'   => EnquiryServiceProvider::ENQUIRY_ACCEPTED,
            'instance' => $this->takenPortalName,
        ]);
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(406, $res->getStatusCode());
        $this->assertEquals('Missing data for enquiry.', json_decode($res->getContent())->message);
    }

    public function dataPostStatus()
    {
        return [
            [EnquiryServiceProvider::ENQUIRY_REJECTED],
            [EnquiryServiceProvider::ENQUIRY_ACCEPTED],
        ];
    }

    /**
     * @dataProvider dataPostStatus
     */
    public function testPost406InvalidEnquiryStatus($status)
    {
        $app = $this->getApp();

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Create Enquiry ro with empty data value
        $this->createEnquiry($db, ['status' => $status]);

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->studentMail}", 'POST');
        $req->request->add([
            'status'   => EnquiryServiceProvider::ENQUIRY_ACCEPTED,
            'instance' => $this->takenPortalName,
        ]);
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(406, $res->getStatusCode());
        $this->assertEquals('Can not accept or reject if enquiry is not pending.', json_decode($res->getContent())->message);
    }

    public function dataPost200()
    {
        $this->getApp();

        return [
            [$this->managerJwt, DEFAULT_USER_ID, $this->managerMail],
            [$this->studentManagerJWT, $this->studentManagerUserId, $this->studentManagerMail],
            [$this->loAuthorJwt, $this->authorUserId, $this->authorMail],
        ];
    }

    /**
     * @dataProvider dataPost200
     */
    public function testPost200Accept(string $jwt, int $reviewerUserId, string $reviewerMail)
    {
        $app = $this->getApp();

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $enquiryId = $this->createEnquiry($db, ['status' => EnquiryServiceProvider::ENQUIRY_PENDING]);

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->studentMail}", 'POST');
        $req->request->add([
            'status'   => EnquiryServiceProvider::ENQUIRY_ACCEPTED,
            'instance' => $this->takenPortalName,
        ]);
        $req->query->replace(['jwt' => $jwt]);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolmentRepository = $app[EnrolmentRepository::class];
        $enrolment = $enrolmentRepository->loadByLoAndUserId($this->loId, $this->studentUserId);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $enrolment->status);
        $this->assertEquals($this->takenPortalId, $enrolment->taken_instance_id);
        $this->assertEquals($enrolment->start_date, $enrolment->changed);

        $enrolmentData = json_decode($enrolment->data);
        $this->assertEquals('enquiry_accepted', $enrolmentData->history[0]->action);
        $this->assertEquals($reviewerUserId, $enrolmentData->history[0]->actorId);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $enrolmentData->history[0]->status);
        $this->assertEquals($reviewerUserId, $enrolmentData->actor_user_id);

        $newEdge = $db->fetchAssoc('SELECT * FROM gc_ro WHERE type = ? AND source_id = ? AND target_id = ? ', [EdgeTypes::HAS_ENQUIRY, $this->loId, $this->studentUserId]);
        $edgeData = json_decode($newEdge['data']);
        $this->assertEquals($enquiryId, $newEdge['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_ACCEPTED, $edgeData->status);
        $this->assertEquals($this->studentMail, $edgeData->mail);
        $this->assertTrue(time() >= $edgeData->updated);
        $this->assertEquals($reviewerMail, $edgeData->updated_by);

        // There's a message published
        $this->assertEquals($enquiryId, $this->queueMessages[Queue::RO_UPDATE][0]['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_ACCEPTED, $this->queueMessages[Queue::RO_UPDATE][0]['data']['status']);
        $this->assertEquals($enrolment->id, $this->queueMessages[Queue::ENROLMENT_CREATE][0]['id']);
    }

    /**
     * @dataProvider dataPost200
     */
    public function testPost200AcceptWithEvent($jwt, $reviewerId)
    {
        $app = $this->getApp();

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $liEventId = $this->createLO($db, [
            'type'        => LiTypes::EVENT,
            'title'       => 'Example li event',
            'instance_id' => $this->portalId,
        ]);
        $this->createEvent($db, $liEventId, ['start' => DateTime::atom('now', DATE_ISO8601)]);
        $this->link($db, EdgeTypes::HAS_LI, $this->loId, $liEventId);

        $this->createEnquiry($db, ['status' => EnquiryServiceProvider::ENQUIRY_PENDING, 'event' => $liEventId]);

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->studentMail}", 'POST');
        $req->request->add(['status' => EnquiryServiceProvider::ENQUIRY_ACCEPTED, 'instance' => $this->takenPortalName]);
        $req->query->replace(['jwt' => $jwt]);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolmentRepository = $app[EnrolmentRepository::class];
        $loEnrolment = $enrolmentRepository->loadByLoAndUserId($this->loId, $this->studentUserId);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $loEnrolment->status);
        $this->assertEquals($this->takenPortalId, $loEnrolment->taken_instance_id);
        $this->assertGreaterThanOrEqual($loEnrolment->start_date, $loEnrolment->changed);

        $eventEnrolment = $enrolmentRepository->loadByLoAndUserId($liEventId, $this->studentUserId);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $eventEnrolment->status);
        $this->assertEquals($this->takenPortalId, $eventEnrolment->taken_instance_id);
        $this->assertGreaterThanOrEqual($loEnrolment->start_date, $eventEnrolment->changed);

        $eventEnrolmentData = json_decode($loEnrolment->data);
        $this->assertEquals('enquiry_accepted', $eventEnrolmentData->history[0]->action);
        $this->assertEquals($reviewerId, $eventEnrolmentData->history[0]->actorId);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $eventEnrolmentData->history[0]->status);
        $this->assertEquals($reviewerId, $eventEnrolmentData->actor_user_id);

        // There's a message published
        $this->assertEquals($loEnrolment->id, $this->queueMessages[Queue::ENROLMENT_CREATE][0]['id']);
        $this->assertEquals($eventEnrolment->id, $this->queueMessages[Queue::ENROLMENT_CREATE][1]['id']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testPost200Reject()
    {
        $app = $this->getApp();
        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $enquiryId = $this->createEnquiry($db, ['status' => EnquiryServiceProvider::ENQUIRY_PENDING]);

        $req = Request::create("/admin/enquiry/{$this->loId}/{$this->studentMail}", 'POST');
        $req->request->add([
            'status'   => EnquiryServiceProvider::ENQUIRY_REJECTED,
            'instance' => $this->takenPortalName,
        ]);
        $req->query->replace(['jwt' => $this->managerJwt]);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolmentRepository = $app[EnrolmentRepository::class];
        $this->assertFalse($enrolmentRepository->loadByLoAndUserId($this->loId, $this->studentUserId));

        $newEdge = $db->fetchAssoc('SELECT * FROM gc_ro WHERE type = ? AND source_id = ? AND target_id = ? ', [EdgeTypes::HAS_ENQUIRY, $this->loId, $this->studentUserId]);
        $edgeData = json_decode($newEdge['data']);
        $this->assertEquals($enquiryId, $newEdge['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_REJECTED, $edgeData->status);
        $this->assertEquals($this->studentMail, $edgeData->mail);
        $this->assertTrue(time() >= $edgeData->updated);
        $this->assertEquals($this->managerMail, $edgeData->updated_by);

        // There's a message published
        $this->assertEquals($enquiryId, $this->queueMessages[Queue::RO_UPDATE][0]['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_REJECTED, $this->queueMessages[Queue::RO_UPDATE][0]['data']['status']);
        $this->assertTrue(empty($this->queueMessages[Queue::ENROLMENT_CREATE][0]['id']));
    }
}
