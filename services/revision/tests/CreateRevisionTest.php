<?php

namespace go1\core\learning_record\revision\tests;

use go1\app\DomainService;
use go1\core\util\DateTime;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CreateRevisionTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $userId = 33;
    private $profileId = 100;
    private $loId;
    private $enrolmentId = 100000;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $db = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($db, ['title' => $portalName = 'foo.com']);
        $this->createUser($db, ['id' => $this->userId, 'instance' => $app['accounts_name'], 'mail' => 'user@user.com',  'profile_id' => $this->profileId]);
        $this->loId = $this->createLO($db, ['title' => 'foo lo']);
        $this->enrolmentId = $this->createEnrolment($db, [
            'id'                => $this->enrolmentId,
            'profile_id'        => $this->profileId,
            'taken_instance_id' => $this->portalId,
            'user_id'           => $this->userId,
            'lo_id'             => $this->loId,
            'status'            => EnrolmentStatuses::IN_PROGRESS
        ]);
    }

    public function testInvalidRevision()
    {
        $app = $this->getApp();

        $req = Request::create("/revision?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_POST);
        $req->request->replace([
            'enrolment_id' => 10000,
            'start_date'   => 1546300800,
            'end_date'     => 1546300800
        ]);

        $res = $app->handle($req);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $res->getStatusCode());
        $msg = json_decode($res->getContent());
        $this->assertEquals("Revision not found.", $msg->message);
    }

    public function testWithActiveEnrolment()
    {
        $app = $this->getApp();

        $req = Request::create("/revision?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_POST);
        $req->request->replace([
            'enrolment_id' => $this->enrolmentId,
            'start_date'   => 1546300800,
            'end_date'     => 1546300800
        ]);

        $res = $app->handle($req);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
        $msg = json_decode($res->getContent());
        $this->assertEquals('Could not create revision since there is an active enrolment.', $msg->message);
    }

    public function testCreateRevision()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $db = $app['dbs']['go1'];
        $enrolment = EnrolmentHelper::loadSingle($db, $this->enrolmentId);
        $repository->deleteEnrolment($enrolment, 0);

        $req = Request::create("/revision?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_POST);
        $req->request->replace([
            'enrolment_id' => $this->enrolmentId,
            'start_date'   => 1546300800,
            'end_date'     => 1546300800,
            'note'         => 'php test case',
            'pass'         => 1,
            'status'       => EnrolmentStatuses::COMPLETED
        ]);

        $res = $app->handle($req);

        $this->assertEquals(Response::HTTP_CREATED, $res->getStatusCode());
        $revision = json_decode($res->getContent());

        $this->assertEquals($this->enrolmentId, $revision->id);
        $this->assertEquals(DateTime::atom(1546300800), $revision->start_date);
        $this->assertEquals(DateTime::atom(1546300800), $revision->start_date);
        $this->assertEquals(1, $revision->pass);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $revision->status);
        $this->assertNotEmpty($revision->data->history);
    }
}
