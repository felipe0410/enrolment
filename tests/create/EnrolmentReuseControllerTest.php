<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\model\Enrolment;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EnrolmentReuseControllerTest extends EnrolmentTestCase
{
    use LoMockTrait;
    use PortalMockTrait;
    use UserMockTrait;
    use EnrolmentMockTrait;

    private $portalName              = 'go1.co';
    private $portalId                = 7;
    private $profileId               = 8;
    private $userId                  = 9;
    private $courseId                = 10;
    private $moduleId                = 20;
    private $video1                  = 31;
    private $singleVideo2            = 32;
    private $singleVideo3            = 33;
    private $singleVideo2EnrolmentId = 40;
    private $singleVideo3EnrolmentId = 41;
    private $courseEnrolmentId       = 42;
    private $moduleEnrolmentId       = 43;
    private $jwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $db = $app['dbs']['go1'];
        $this->createPortal($db, ['id' => $this->portalId, 'title' => $this->portalName]);
        $this->createCourse($db, ['id' => $this->courseId, 'instance_id' => $this->portalId, 'data' => ['allow_reuse_enrolment' => true]]);
        $this->createModule($db, ['id' => $this->moduleId, 'instance_id' => $this->portalId]);
        $this->createVideo($db, ['id' => $this->video1, 'title' => 'video1', 'instance_id' => $this->portalId]);
        $this->createVideo($db, ['id' => $this->singleVideo2, 'title' => 'video2', 'instance_id' => $this->portalId, 'data' => ['single_li' => true]]);
        $this->createVideo($db, ['id' => $this->singleVideo3, 'title' => 'video3', 'instance_id' => $this->portalId, 'data' => ['single_li' => true]]);
        $this->createUser($db, ['mail' => 'learner1@mail.co', 'id' => $this->userId, 'profile_id' => $this->profileId, 'instance' => $app['accounts_name']]);
        $accountId = $this->createUser($db, ['mail' => 'learner1@mail.co', 'instance' => $this->portalName]);

        $this->link($db, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->video1);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->singleVideo2);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->singleVideo3);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->userId, $accountId);
        $this->jwt = $this->jwtForUser($db, $this->userId, $this->portalName);

        $this->createEnrolment($db, [
            'id'                  => $this->singleVideo2EnrolmentId,
            'profile_id'          => $this->profileId,
            'user_id'             => $this->userId,
            'lo_id'               => $this->singleVideo2,
            'taken_instance_id'   => $this->portalId,
            'status'              => EnrolmentStatuses::COMPLETED,
            'parent_enrolment_id' => 0,
            'pass'                => 1,
            'start_date'          => (new \DateTimeImmutable())->format(DATE_ISO8601),
            'end_date'            => (new \DateTimeImmutable())->format(DATE_ISO8601),
        ]);
        $this->createEnrolment($db, [
            'id'                  => $this->singleVideo3EnrolmentId,
            'profile_id'          => $this->profileId,
            'user_id'             => $this->userId,
            'lo_id'               => $this->singleVideo3,
            'taken_instance_id'   => $this->portalId,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
            'parent_enrolment_id' => 0,

        ]);
        $courseEnrolmentId = $this->createEnrolment($db, [
            'id'                  => $this->courseEnrolmentId,
            'profile_id'          => $this->profileId,
            'user_id'             => $this->userId,
            'lo_id'               => $this->courseId,
            'taken_instance_id'   => $this->portalId,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
            'parent_enrolment_id' => 0,
        ]);
        $this->createEnrolment($db, [
            'id'                  => $this->moduleEnrolmentId,
            'profile_id'          => $this->profileId,
            'user_id'             => $this->userId,
            'lo_id'               => $this->moduleId,
            'taken_instance_id'   => $this->portalId,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
            'parent_enrolment_id' => $courseEnrolmentId,
        ]);
    }

    public function test404()
    {
        $app = $this->getApp();
        $req = Request::create("/404/reuse-enrolment?jwt=$this->jwt", 'POST');
        $req->request->replace([
            'parentEnrolmentId' => $this->moduleEnrolmentId,
            'reuseEnrolmentId'  => $this->singleVideo2EnrolmentId,
        ]);

        $res = $app->handle($req);
        $this->assertEquals(Response::HTTP_NOT_FOUND, $res->getStatusCode());
    }

    public function test403()
    {
        $app = $this->getApp();
        $req = Request::create("/$this->portalName/reuse-enrolment", 'POST');
        $req->request->replace([
            'parentEnrolmentId' => $this->moduleEnrolmentId,
            'reuseEnrolmentId'  => $this->singleVideo2EnrolmentId,
        ]);

        $res = $app->handle($req);
        $this->assertEquals(Response::HTTP_FORBIDDEN, $res->getStatusCode());
    }

    public function test()
    {
        $app = $this->getApp();
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $req = Request::create("/$this->portalName/reuse-enrolment?jwt=$this->jwt", 'POST');
        $req->request->replace([
            'parentEnrolmentId' => $this->moduleEnrolmentId,
            'reuseEnrolmentId'  => $this->singleVideo2EnrolmentId,
        ]);

        $res = $app->handle($req);
        $this->assertEquals(Response::HTTP_OK, $res->getStatusCode());
        $enrolment = Enrolment::create(json_decode($res->getContent()));
        $reuseEnrolment = EnrolmentHelper::loadSingle($app['dbs']['go1'], $this->singleVideo2EnrolmentId);

        $this->assertEquals($this->profileId, $enrolment->profileId);
        $this->assertEquals($this->singleVideo2, $enrolment->loId);
        $this->assertEquals($this->userId, $enrolment->userId);
        $this->assertEquals($this->userId, $reuseEnrolment->userId);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals($this->moduleEnrolmentId, $enrolment->parentEnrolmentId);
        $this->assertEqualsWithDelta(DateTime::create($reuseEnrolment->startDate)->getTimestamp(), DateTime::create($enrolment->startDate)->getTimestamp(), 5);
        $this->assertEqualsWithDelta(DateTime::create($reuseEnrolment->endDate)->getTimestamp(), DateTime::create($enrolment->endDate)->getTimestamp(), 5);
        $this->assertEquals(1, $enrolment->pass);
        $this->assertNotEmpty($enrolment->id);
    }

    public function testCourseDisableReuseEnrolment()
    {
        $app = $this->getApp();
        $app['dbs']['go1']->update('gc_lo', ['data' => json_encode(['allow_reuse_enrolment' => false])], ['id' => $this->courseId]);
        $req = Request::create("/$this->portalName/reuse-enrolment?jwt=$this->jwt", 'POST');
        $req->request->replace([
            'parentEnrolmentId' => $this->moduleEnrolmentId,
            'reuseEnrolmentId'  => $this->singleVideo2EnrolmentId,
        ]);
        $res = $app->handle($req);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
    }

    public function badData()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $this->createEnrolment($db, [
            'id'                  => $anotherUserVideoEnrolmentId = 101,
            'profile_id'          => 101,
            'lo_id'               => $this->singleVideo2,
            'taken_instance_id'   => $this->portalId,
            'status'              => EnrolmentStatuses::COMPLETED,
            'parent_enrolment_id' => 0,
            'pass'                => 1,
            'start_date'          => (new \DateTimeImmutable())->format(DATE_ISO8601),
            'end_date'            => (new \DateTimeImmutable())->format(DATE_ISO8601),
        ]);

        $this->createEnrolment($db, [
            'id'                  => $anotherUserModuleEnrolmentId = 102,
            'profile_id'          => 101,
            'lo_id'               => $this->moduleId,
            'taken_instance_id'   => $this->portalId,
            'status'              => EnrolmentStatuses::COMPLETED,
            'parent_enrolment_id' => $this->courseEnrolmentId,
        ]);

        return [
            ['Incorrect parent enrolment', $app, $this->jwt, $this->courseEnrolmentId, $this->singleVideo2EnrolmentId],
            ['Not exist parent enrolment', $app, $this->jwt, 404, $this->singleVideo2EnrolmentId],
            ['Not exist reuse enrolment', $app, $this->jwt, $this->moduleEnrolmentId, 404],
            ['Incomplete reuse enrolment', $app, $this->jwt, $this->moduleEnrolmentId, $this->singleVideo3EnrolmentId],
            ['Reuse enrolment of another user', $app, $this->jwt, $this->moduleEnrolmentId, $anotherUserVideoEnrolmentId],
            ['Parent enrolment of another user', $app, $this->jwt, $anotherUserModuleEnrolmentId, $this->singleVideo2EnrolmentId],
        ];
    }

    /**
     * @dataProvider badData
     */
    public function testBadRequest(string $name, DomainService $app, string $jwt, int $parentEnrolmentId, int $reuseEnrolmentId)
    {
        $req = Request::create("/$this->portalName/reuse-enrolment?jwt=$jwt", 'POST');
        $req->request->replace([
            'parentEnrolmentId' => $parentEnrolmentId,
            'reuseEnrolmentId'  => $reuseEnrolmentId,
        ]);
        $res = $app->handle($req);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $res->getStatusCode(), $name);
    }
}
