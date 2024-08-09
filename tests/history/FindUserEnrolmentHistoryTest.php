<?php

namespace go1\enrolment\tests\history;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime as DateTimeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\model\Enrolment;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class FindUserEnrolmentHistoryTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalName = 'az.mygo1.com';
    private $userId;
    private $userId2;
    private $accountId;
    private $profileId  = 909;
    private $profileId2  = 908;
    private $loId;
    private $loId2;
    private $jwt;
    private $startDate1;
    private $startDate2;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        /** @var EnrolmentRepository $repository */
        /** @var EnrolmentCreateService $createService */
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];

        $portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->loId = $this->createCourse($go1, ['title' => 'Foo', 'instance_id' => $portalId]);
        $this->loId2 = $this->createCourse($go1, ['title' => 'Boo', 'instance_id' => $portalId]);
        $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'learner@' . $this->portalName, 'profile_id' => $this->profileId]);
        $this->userId2 = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'learner2@' . $this->portalName, 'profile_id' => $this->profileId2]);
        $this->accountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'learner@' . $this->portalName]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);
        $this->jwt = $this->jwtForUser($go1, $this->userId, $this->portalName);

        $repository = $app[EnrolmentRepository::class];
        $createService = $app[EnrolmentCreateService::class];
        $newEnrolment = Enrolment::create();
        $newEnrolment->takenPortalId = $portalId;
        $newEnrolment->loId = $this->loId;
        $newEnrolment->profileId = $this->profileId;
        $newEnrolment->userId = $this->userId;
        $newEnrolment->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS, 'start_date' => $this->startDate1 = DateTimeHelper::atom('+1 day', DATE_ISO8601)]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 1]);

        $lastEnrolment = $repository->load($newEnrolment->id);
        $newEnrolment1 = Enrolment::create();
        $newEnrolment1->id = $enrolmentId;
        $newEnrolment1->takenPortalId = $portalId;
        $newEnrolment1->loId = $this->loId;
        $newEnrolment1->profileId = $this->profileId;
        $newEnrolment1->userId = $this->userId;
        $newEnrolment1->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment1, $lastEnrolment, EnrolmentOriginalTypes::I_SELF_DIRECTED, $reEnrol = true)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS, 'start_date' => $this->startDate2 = DateTimeHelper::atom('now', DATE_ISO8601)]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 2]);

        $lastEnrolment = $repository->load($newEnrolment1->id);
        $newEnrolment2 = Enrolment::create();
        $newEnrolment2->id = $enrolmentId;
        $newEnrolment2->takenPortalId = $portalId;
        $newEnrolment2->loId = $this->loId;
        $newEnrolment2->profileId = $this->profileId;
        $newEnrolment2->userId = $this->userId;
        $newEnrolment2->status = EnrolmentStatuses::PENDING;
        $enrolmentId = $createService->create($newEnrolment2, $lastEnrolment, EnrolmentOriginalTypes::I_SELF_DIRECTED, $reEnrol = true)->enrolment->id;
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::IN_PROGRESS]);
        $repository->update($enrolmentId, ['status' => EnrolmentStatuses::COMPLETED, 'result' => 3]);
        $adminUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'admin@go1.com']);
        $adminAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'admin@go1.com']);
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $this->portalName]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $adminUserId, $adminAccountId);
        $this->adminJwt = $this->jwtForUser($go1, $adminUserId, $this->portalName);

        $this->createRevisionEnrolment($go1, ['id' => 996, 'enrolment_id' => 997, 'lo_id' => $this->loId2, 'status' => EnrolmentStatuses::COMPLETED, 'user_id' => $this->userId2, 'taken_instance_id' => $portalId, 'profile_id' => $this->profileId2]);
    }

    public function testNoJWT()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/1/history?jwt=null", 'GET');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testWithDefinedUserId()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/1/history/undefined?jwt=null", 'GET');
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testNoLo()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/404/history?jwt=$this->jwt", 'GET');
        $res = $app->handle($req);
        $content = json_decode($res->getContent(), true);

        $this->assertEquals(404, $res->getStatusCode());
        $this->assertEquals('Learning object not found.', $content['message']);
    }

    public function test200()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/{$this->loId}/history?jwt=$this->jwt", 'GET');
        $res = $app->handle($req);
        $enrolments = json_decode($res->getContent(), true);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(2, count($enrolments));
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[0]['status']);
        $this->assertEquals(DateTimeHelper::atom($this->startDate1, DATE_ISO8601), $enrolments[0]['start_date']);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[1]['status']);
        $this->assertEquals(DateTimeHelper::atom($this->startDate2, DATE_ISO8601), $enrolments[1]['start_date']);

        $this->assertEquals('1.0', $enrolments[0]['result']);
        $this->assertEquals('2.0', $enrolments[1]['result']);
    }

    public function test200ForDeletedEnrolment()
    {
        $app = $this->getApp();
        $req = Request::create("/lo/{$this->loId2}/history/{$this->userId2}?jwt=$this->adminJwt", 'GET');
        $res = $app->handle($req);
        $enrolments = json_decode($res->getContent(), true);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(1, count($enrolments));
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolments[0]['status']);
    }
}
