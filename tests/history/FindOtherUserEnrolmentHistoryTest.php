<?php

namespace go1\enrolment\tests\history;

use Doctrine\DBAL\Connection;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime as DateTimeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\model\Enrolment;
use go1\util\portal\PortalHelper;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class FindOtherUserEnrolmentHistoryTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalName = 'az.mygo1.com';
    private $loId;
    private $userId;
    private $profileId  = 909;
    private $accountId;
    private $jwt;
    private $assessorJwt;
    private $adminJwt;
    private $managerJwt;
    private $otherUserJwt;
    private $startDate1;
    private $startDate2;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $db */
        /** @var EnrolmentRepository $repository */
        /** @var EnrolmentCreateService $createService */
        parent::appInstall($app);

        $db = $app['dbs']['go1'];

        $portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->loId = $this->createCourse($db, ['title' => 'Foo', 'instance_id' => $portalId]);

        $this->userId = $this->createUser($db, ['profile_id' => $this->profileId, 'instance' => $app['accounts_name']]);
        $this->accountId = $this->createUser($db, ['instance' => $this->portalName]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);
        $this->jwt = JWT::encode((array) $this->getPayload(['user_profile_id' => $this->profileId]), 'INTERNAL', 'HS256');
        $adminId = $this->createUser($db, ['mail' => 'admin@go1.com', 'instance' => $app['accounts_name']]);
        $assessorId = $this->createUser($db, ['mail' => 'assessor@go1.com', 'instance' => $app['accounts_name']]);
        $managerId = $this->createUser($db, ['mail' => 'manager@go1.com', 'instance' => $app['accounts_name']]);
        $otherUserId = $this->createUser($db, ['mail' => 'other@go1.com', 'instance' => $app['accounts_name']]);

        $repository = $app[EnrolmentRepository::class];
        $createService = $app[EnrolmentCreateService::class];

        $newEnrolment = Enrolment::create();
        $newEnrolment->takenPortalId = $portalId;
        $newEnrolment->loId = $this->loId;
        $newEnrolment->userId = $this->userId;
        $newEnrolment->profileId = $this->profileId;
        $newEnrolment->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment, false, EnrolmentOriginalTypes::I_SELF_DIRECTED, $reEnrol = false)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS, 'start_date' => $this->startDate1 = DateTimeHelper::atom('+1 day', DATE_ISO8601)]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 1]);

        $lastEnrolment = $repository->load($enrolmentId);
        $newEnrolment1 = Enrolment::create();
        $newEnrolment1->takenPortalId = $portalId;
        $newEnrolment1->loId = $this->loId;
        $newEnrolment1->userId = $this->userId;
        $newEnrolment1->profileId = $this->profileId;
        $newEnrolment1->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment1, $lastEnrolment, EnrolmentOriginalTypes::I_SELF_DIRECTED, $reEnrol = true)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS, 'start_date' => $this->startDate2 = DateTimeHelper::atom('now', DATE_ISO8601)]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 2]);

        $lastEnrolment = $repository->load($enrolmentId);
        $newEnrolment2 = Enrolment::create();
        $newEnrolment2->takenPortalId = $portalId;
        $newEnrolment2->loId = $this->loId;
        $newEnrolment2->userId = $this->userId;
        $newEnrolment2->profileId = $this->profileId;
        $newEnrolment2->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment2, $lastEnrolment, EnrolmentOriginalTypes::I_SELF_DIRECTED, $reEnrol = true)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 3]);

        $this->adminJwt = JWT::encode((array) $this->getAdminPayload($this->portalName, ['id' => $adminId]), 'INTERNAL', 'HS256');
        $this->assessorJwt = JWT::encode((array) $this->getPayload(['id' => $assessorId]), 'INTERNAL', 'HS256');
        $this->managerJwt = JWT::encode((array) $this->getPayload(['id' => $managerId]), 'INTERNAL', 'HS256');
        $this->otherUserJwt = JWT::encode((array) $this->getPayload(['id' => $otherUserId]), 'INTERNAL', 'HS256');
        $this->link($db, EdgeTypes::COURSE_ASSESSOR, $this->loId, $assessorId);
        $this->link($db, EdgeTypes::HAS_MANAGER, $this->accountId, $managerId);
    }

    public function testNoJWT()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/1/history/$this->userId?jwt=null", 'GET');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testNoLo()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/404/history/$this->userId?jwt=$this->jwt", 'GET');
        $res = $app->handle($req);
        $content = json_decode($res->getContent(), true);

        $this->assertEquals(404, $res->getStatusCode());
        $this->assertEquals('Learning object not found.', $content['message']);
    }

    public function test200()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/{$this->loId}/history/$this->userId?jwt=$this->jwt", 'GET');
        $res = $app->handle($req);
        $enrolments = json_decode($res->getContent(), true);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(2, count($enrolments));
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[0]['status']);
        $this->assertEquals(DateTimeHelper::atom($this->startDate1, DATE_ISO8601), $enrolments[0]['start_date']);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[1]['status']);
        $this->assertEquals(DateTimeHelper::atom($this->startDate2, DATE_ISO8601), $enrolments[1]['start_date']);
        $this->assertEquals($this->userId, $enrolments[0]['user_id']);
        $this->assertEquals($this->userId, $enrolments[1]['user_id']);

        $this->assertEquals('1.0', $enrolments[0]['result']);
        $this->assertEquals('2.0', $enrolments[1]['result']);
    }

    public function testAssessorCanSee()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/{$this->loId}/history/$this->userId?jwt=$this->assessorJwt", 'GET');
        $res = $app->handle($req);
        $enrolments = json_decode($res->getContent(), true);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(2, count($enrolments));
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[0]['status']);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[1]['status']);

        $this->assertEquals('1.0', $enrolments[0]['result']);
        $this->assertEquals('2.0', $enrolments[1]['result']);
    }

    public function testManagerCanSee()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/{$this->loId}/history/$this->userId?jwt=$this->managerJwt", 'GET');
        $res = $app->handle($req);
        $enrolments = json_decode($res->getContent(), true);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(2, count($enrolments));
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[0]['status']);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[1]['status']);

        $this->assertEquals('1.0', $enrolments[0]['result']);
        $this->assertEquals('2.0', $enrolments[1]['result']);
    }

    public function testOtherCantSee()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/{$this->loId}/history/$this->userId?jwt=$this->otherUserJwt", 'GET');
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testAdminAndManagerCanSeeEnrolmentFromSharedCourse()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $portal = PortalHelper::load($go1, $this->portalName);
        $sharedPortal = $this->createPortal($go1, ['title' => 'shared.mygo1.com']);
        $loId = $this->createCourse($go1, ['instance_id' => $sharedPortal]);

        $repository = $app[EnrolmentRepository::class];
        $createService = $app[EnrolmentCreateService::class];

        $newEnrolment = Enrolment::create();
        $newEnrolment->takenPortalId = $portal->id;
        $newEnrolment->loId = $loId;
        $newEnrolment->userId = $this->userId;
        $newEnrolment->profileId = $this->profileId;
        $newEnrolment->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS, 'start_date' => $this->startDate1 = DateTimeHelper::atom('+1 day', DATE_ISO8601)]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 1]);

        $lastEnrolment = $repository->load($enrolmentId);
        $newEnrolment1 = Enrolment::create();
        $newEnrolment1->takenPortalId = $portal->id;
        $newEnrolment1->loId = $loId;
        $newEnrolment1->userId = $this->userId;
        $newEnrolment1->profileId = $this->profileId;
        $newEnrolment1->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment1, $lastEnrolment, EnrolmentOriginalTypes::I_SELF_DIRECTED, $reEnrol = true)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS, 'start_date' => $this->startDate2 = DateTimeHelper::atom('now', DATE_ISO8601)]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 2]);

        $lastEnrolment = $repository->load($enrolmentId);
        $newEnrolment2 = Enrolment::create();
        $newEnrolment2->takenPortalId = $portal->id;
        $newEnrolment2->loId = $loId;
        $newEnrolment2->userId = $this->userId;
        $newEnrolment2->profileId = $this->profileId;
        $newEnrolment2->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment2, $lastEnrolment, EnrolmentOriginalTypes::I_SELF_DIRECTED, $reEnrol = true)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 3]);

        $req = Request::create("/lo/{$loId}/history/$this->userId?jwt=$this->adminJwt", 'GET');
        $res = $app->handle($req);
        $enrolments = json_decode($res->getContent(), true);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(2, count($enrolments));

        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[0]['status']);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[1]['status']);

        $this->assertEquals('1.0', $enrolments[0]['result']);
        $this->assertEquals('2.0', $enrolments[1]['result']);

        $req = Request::create("/lo/{$loId}/history/$this->userId?jwt=$this->managerJwt", 'GET');
        $res = $app->handle($req);
        $enrolments = json_decode($res->getContent(), true);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(2, count($enrolments));

        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[0]['status']);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[1]['status']);

        $this->assertEquals('1.0', $enrolments[0]['result']);
        $this->assertEquals('2.0', $enrolments[1]['result']);
    }

    public function testLearnerCanNotSeeEnrolmentFromShareCourse()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $portal = PortalHelper::load($go1, $this->portalName);
        $sharedPortal = $this->createPortal($go1, ['title' => 'shared.mygo1.com']);
        $loId = $this->createCourse($go1, ['instance_id' => $sharedPortal]);
        $repository = $app[EnrolmentRepository::class];
        $createService = $app[EnrolmentCreateService::class];

        $newEnrolment = Enrolment::create();
        $newEnrolment->takenPortalId = $portal->id;
        $newEnrolment->loId = $loId;
        $newEnrolment->userId = $this->userId;
        $newEnrolment->profileId = $this->profileId;
        $newEnrolment->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS, 'start_date' => $this->startDate1 = DateTimeHelper::atom('+1 day', DATE_ISO8601)]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 1]);

        $lastEnrolment = $repository->load($enrolmentId);
        $newEnrolment1 = Enrolment::create();
        $newEnrolment1->takenPortalId = $portal->id;
        $newEnrolment1->loId = $loId;
        $newEnrolment1->userId = $this->userId;
        $newEnrolment1->profileId = $this->profileId;
        $newEnrolment1->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment1, $lastEnrolment, EnrolmentOriginalTypes::I_SELF_DIRECTED, $reEnrol = true)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS, 'start_date' => $this->startDate2 = DateTimeHelper::atom('now', DATE_ISO8601)]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 2]);

        $lastEnrolment = $repository->load($enrolmentId);
        $newEnrolment2 = Enrolment::create();
        $newEnrolment2->takenPortalId = $portal->id;
        $newEnrolment2->loId = $loId;
        $newEnrolment2->userId = $this->userId;
        $newEnrolment2->profileId = $this->profileId;
        $newEnrolment2->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment2, $lastEnrolment, EnrolmentOriginalTypes::I_SELF_DIRECTED, $reEnrol = true)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 3]);

        $req = Request::create("/lo/{$loId}/history/$this->userId?jwt=$this->otherUserJwt", 'GET');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }
}
