<?php

namespace go1\core\learning_record\enquiry\tests;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\clients\portal\config\MailTemplate;
use go1\core\learning_record\enquiry\EnquiryServiceProvider;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\edge\EdgeTypes;
use go1\util\lo\LiTypes;
use go1\util\queue\Queue;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnquiryControllerCreateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $portalName = 'az.mygo1.com';
    private $portalId;
    private $loId;
    private $mail       = 'foo@bar.baz';
    private $studentUserId;
    private $invalidLoId;
    private $studentJwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->loId = $this->createCourse($db, ['instance_id' => $this->portalId, 'data' => '{"allow_enrolment":"enquiry"}']);
        $this->studentUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->mail]);
        $this->invalidLoId = $this->createCourse($db, ['instance_id' => $invalidInstanceId = 123, 'data' => '{"allow_enrolment":"enquiry"}']);

        $this->studentJwt = $this->jwtForUser($db, $this->studentUserId, $this->portalName);
    }

    public function dataPost400()
    {
        $this->getApp();

        return [
            [null, 123, 'invalidMail'],
            [null, 123, 'abc@go1.site'],
            [null, 123, 'abc@go1.site', 'firstName'],
            [null, 123, 'abc@go1.site', 'firstName', 'lastName'],
            [$this->studentJwt, 123, 'abc@go1.site', 'firstName', 'lastName', 'phoneNumber', 'messageString'],
            [$this->studentJwt, 123, $this->mail, 'firstName', 'lastName', 'phoneNumber', 'messageString'],
            [$this->studentJwt, $this->invalidLoId, $this->mail, 'firstName', 'lastName', 'phoneNumber', 'messageString'],
        ];
    }

    /**
     * @dataProvider dataPost400
     */
    public function testPost400($jwt, $loId, $mail = null, $firstName = null, $lastName = null, $phone = null, $message = null)
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/{$loId}/{$mail}", 'POST');
        $req->request->replace([
            'enquireFirstName' => $firstName,
            'enquireLastName'  => $lastName,
            'enquirePhone'     => $phone,
            'enquireMessage'   => $message,
        ]);
        $jwt && $req->query->replace(['jwt' => $jwt]);

        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }

    public function testPost403InvalidJwt()
    {
        $app = $this->getApp();
        $req = Request::create("/enquiry/{$this->loId}/{$this->mail}", 'POST');
        $req->request->add([
            'enquireFirstName' => 'Student',
            'enquireLastName'  => 'GO1',
            'enquirePhone'     => null,
            'enquireMessage'   => 'Let me learning!',
        ]);

        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Missing or invalid JWT.', json_decode($res->getContent())->message);
    }

    public function testPost406EnquiringOnNormalCourse()
    {
        $app = $this->getApp();
        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        $loId = $this->createCourse($db, ['instance_id' => $this->portalId]);

        $req = Request::create("/enquiry/{$loId}/{$this->mail}", 'POST');
        $req->request->add([
            'enquireFirstName' => 'Student',
            'enquireLastName'  => 'GO1',
            'enquirePhone'     => null,
            'enquireMessage'   => 'Let me learning!',
        ]);
        $req->query->replace(['jwt' => $this->studentJwt]);

        $res = $app->handle($req);

        $this->assertEquals(406, $res->getStatusCode());
        $this->assertEquals('This learning object is not available for enquiring action.', json_decode($res->getContent())->message);
    }

    public function testPost200()
    {
        $app = $this->getApp();
        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $req = Request::create("/enquiry/{$this->loId}/{$this->mail}", 'POST');
        $req->request->add([
            'enquireFirstName' => $firstName = 'Student',
            'enquireLastName'  => $lastName = 'GO1',
            'enquirePhone'     => $phone = '123456789',
            'enquireMessage'   => $message = 'Let me learning!',
        ]);
        $req->query->replace(['jwt' => $this->studentJwt]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enquiryId = json_decode($res->getContent())->id;
        $this->assertTrue(is_numeric($enquiryId));

        $newEdge = $db->fetchAssoc('SELECT * FROM gc_ro WHERE type = ? AND source_id = ?', [EdgeTypes::HAS_ENQUIRY, $this->loId]);
        $edgeData = json_decode($newEdge['data']);
        $this->assertEquals($enquiryId, $newEdge['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_PENDING, $edgeData->status);
        $this->assertEquals($firstName, $edgeData->first);
        $this->assertEquals($lastName, $edgeData->last);
        $this->assertEquals($this->mail, $edgeData->mail);
        $this->assertEquals($phone, $edgeData->phone);
        $this->assertEquals($message, $edgeData->body);
        $this->assertTrue(time() >= $edgeData->created);
        $this->assertNull($edgeData->updated);
        $this->assertNull($edgeData->updated_by);

        // There's a message published
        $this->assertEquals($enquiryId, $this->queueMessages[Queue::RO_CREATE][0]['id']);
    }

    public function testPost200ExistEnquiry()
    {
        $app = $this->getApp();
        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        $enquiryId = $db->insert('gc_ro', [
            'type'      => EdgeTypes::HAS_ENQUIRY,
            'source_id' => $this->loId,
            'target_id' => $this->studentUserId,
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
                'status'     => EnquiryServiceProvider::ENQUIRY_REJECTED,
            ]),
        ]);

        $req = Request::create("/enquiry/{$this->loId}/{$this->mail}", 'POST');
        $req->request->add([
            'enquireFirstName' => $firstName = 'Student',
            'enquireLastName'  => $lastName = 'GO1',
            'enquirePhone'     => $phone = '123456789',
            'enquireMessage'   => $message = 'Let me learning!',
        ]);
        $req->query->replace(['jwt' => $this->studentJwt]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $newEnquiryId = json_decode($res->getContent())->id;
        $this->assertEquals($enquiryId, $newEnquiryId);

        $newEdge = $db->fetchAssoc('SELECT * FROM gc_ro WHERE id = ?', [$enquiryId]);
        $edgeData = json_decode($newEdge['data']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_REJECTED, $edgeData->status);
        $this->assertEquals('A', $edgeData->first);
        $this->assertEquals('T', $edgeData->last);
        $this->assertEquals('thehongtt@gmail.com', $edgeData->mail);

        $this->assertTrue(empty($this->queueMessages[Queue::RO_CREATE]));
    }

    public function dataPost400InvalidLiEvent()
    {
        $app = $this->getApp();
        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $liVideoId = $this->createVideo($db, ['instance_id' => $this->portalId]);
        $liEventId = $this->createLO($db, [
            'type'        => LiTypes::EVENT,
            'title'       => 'Example li event',
            'instance_id' => $this->portalId,
        ]);
        $this->createEvent($db, $liEventId, ['start' => DateTime::atom('now', DATE_ISO8601)]);
        $this->link($db, EdgeTypes::HAS_LI, $this->loId, $liEventId);

        return [
            [123456],
            [$liVideoId],
            [$liEventId],
        ];
    }

    /**
     * @dataProvider dataPost400InvalidLiEvent
     */
    public function testPost400InvalidLiEvent($liEventId)
    {
        $app = $this->getApp();
        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $req = Request::create("/enquiry/{$this->loId}/{$this->mail}", 'POST');
        $req->request->add([
            'enquireFirstName' => 'Student',
            'enquireLastName'  => 'GO1',
            'enquirePhone'     => null,
            'enquireMessage'   => 'Let me learning!',
            'enquireEvent'     => $liEventId,
        ]);
        $req->query->replace(['jwt' => $this->studentJwt]);

        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Invalid attached event.', json_decode($res->getContent())->message);
    }

    public function testPost200AttachLiEvent()
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

        $req = Request::create("/enquiry/{$this->loId}/{$this->mail}", 'POST');
        $req->request->add([
            'enquireFirstName' => 'Student',
            'enquireLastName'  => 'GO1',
            'enquirePhone'     => null,
            'enquireMessage'   => 'Let me learning!',
            'enquireEvent'     => $liEventId,
        ]);
        $req->query->replace(['jwt' => $this->studentJwt]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $enquiryId = json_decode($res->getContent())->id;
        $this->assertTrue(is_numeric($enquiryId));

        $newEdge = $db->fetchAssoc('SELECT * FROM gc_ro WHERE type = ? AND source_id = ?', [EdgeTypes::HAS_ENQUIRY, $this->loId]);
        $edgeData = json_decode($newEdge['data']);
        $this->assertEquals($enquiryId, $newEdge['id']);
        $this->assertEquals(EnquiryServiceProvider::ENQUIRY_PENDING, $edgeData->status);
        $this->assertEquals($liEventId, $edgeData->event);
    }
}
