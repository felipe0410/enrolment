<?php

namespace go1\enrolment\tests\consumer\update;

use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\model\Enrolment;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentArchiveTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $portalName;
    private $courseId;
    private $questionLiId;
    private $studentUserId;
    private $mail      = 'student@mygo1.com';
    private $jwt       = UserHelper::ROOT_JWT;
    private $profileId = 999;
    private $courseEnrolmentId;
    private $questionLiEnrolmentId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $go1 = $app['dbs']['go1'];

        $this->studentUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->mail, 'profile_id' => $this->profileId]);
        $studentAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->mail, 'profile_id' => $this->profileId]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $studentAccountId);

        $this->portalId = $this->createPortal($go1, ['title' => 'qa.mygo1.com']);
        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->courseEnrolmentId = $this->createEnrolment($go1, [
            'profile_id'        => $this->profileId,
            'user_id'           => $this->studentUserId,
            'taken_instance_id' => $this->portalId,
            'lo_id'             => $this->courseId,
            'status'            => EnrolmentStatuses::IN_PROGRESS,
        ]);
        $this->questionLiId = $this->createLO($go1, [
            'id' => 111,
            'instance_id' => $this->portalId,
            'type' => LiTypes::QUESTION
        ]);
        $this->questionLiEnrolmentId = $this->createEnrolment($go1, [
            'profile_id'        => $this->profileId,
            'user_id'           => $this->studentUserId,
            'taken_instance_id' => $this->portalId,
            'lo_id'             => $this->questionLiId,
            'status'            => EnrolmentStatuses::IN_PROGRESS,
        ]);
    }

    public function testHookEnrolmentDeleteOnArchive()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        /** @var EnrolmentRepository $repository  */
        $repository = $app[EnrolmentRepository::class];

        $this->link($go1, EdgeTypes::HAS_ENQUIRY, $this->courseId, $this->studentUserId, 0, ['mail' => $this->mail, 'status' => 'accepted']);
        $raw = EnrolmentHelper::load($go1, $this->courseEnrolmentId);
        $enrolment = Enrolment::create($raw);
        $repository->deleteEnrolment($enrolment, 0, true);


        $this->assertNull($repository->load($this->courseEnrolmentId));
        $this->assertEquals(1, count($this->queueMessages[Queue::ENROLMENT_DELETE]));

        $routingKey = Queue::ENROLMENT_DELETE;
        $body = $this->queueMessages[Queue::ENROLMENT_DELETE][0];
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace(['routingKey' => $routingKey, 'body' => json_encode($body)]);
        $res = $app->handle($req);

        $msgEnrolmentDelete = &$this->queueMessages[Queue::ENROLMENT_DELETE][0];
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($this->courseEnrolmentId, $msgEnrolmentDelete->id, "There's a message published");

        $edgeHelper = new EdgeHelper();
        $oldEnquiryEdge = $edgeHelper->get($go1, [$this->courseId], [$this->studentUserId], [EdgeTypes::HAS_ENQUIRY]);
        $this->assertEquals(0, count($oldEnquiryEdge));

        $archivedEnquiryEdge = $edgeHelper->get($go1, [$this->courseId], [$this->courseEnrolmentId], [EdgeTypes::HAS_ARCHIVED_ENQUIRY]);
        $this->assertEquals(1, count($archivedEnquiryEdge));
        $data = json_decode($archivedEnquiryEdge[0]->data);
        $this->assertEquals($this->studentUserId, $data->student_id);
        $this->assertEquals($this->courseEnrolmentId, $data->enrolment_id);

        $msgEnquiryArchive = &$this->queueMessages[Queue::RO_UPDATE][0];
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($archivedEnquiryEdge[0]->id, $msgEnquiryArchive['id'], "There's a message published");
    }

    public function testPublishMessageOnDelete()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $raw = EnrolmentHelper::load($go1, $this->questionLiEnrolmentId);
        $enrolment = Enrolment::create($raw);
        $repository->deleteEnrolment($enrolment, 0, true);

        $routingKey = Queue::ENROLMENT_DELETE;
        $body = $this->queueMessages[Queue::ENROLMENT_DELETE][0];
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace(['routingKey' => $routingKey, 'body' => json_encode($body)]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());

        $testBody = [
            "loId" => $enrolment->loId,
            "userId" => $enrolment->userId
        ];
        $msgBody = $this->queueMessages[Queue::QUIZ_QUESTION_RESULT_DELETE][0];
        $this->assertEquals(1, count($this->queueMessages[Queue::QUIZ_QUESTION_RESULT_DELETE]));
        $this->assertEquals($testBody, $msgBody);
    }
}
