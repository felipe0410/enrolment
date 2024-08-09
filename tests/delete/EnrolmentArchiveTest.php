<?php

namespace go1\enrolment\tests\delete;

use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\Roles;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentArchiveTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $courseId;
    private $moduleId;
    private $li1Id;
    private $li2Id;
    private $jwt       = UserHelper::ROOT_JWT;
    private $managerJwt;
    private $noneManagerJwt;
    private $mail      = 'student@mygo1.com';
    private $studentUserId;
    private $studentAccountId;
    private $profileId = 999;
    private $courseEnrolmentId;
    private $moduleEnrolmentId;
    private $li1EnrolmentId;
    private $li2EnrolmentId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => $portalName = 'az.mygo1.com']);
        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->moduleId = $this->createModule($go1, ['instance_id' => $this->portalId]);
        $this->li1Id = $this->createVideo($go1, ['instance_id' => $this->portalId]);
        $this->li2Id = $this->createVideo($go1, ['instance_id' => $this->portalId]);

        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleId, $this->li1Id);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleId, $this->li2Id);

        $this->studentUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->mail, 'profile_id' => $this->profileId]);
        $this->studentAccountId = $this->createUser($go1, ['instance' => $portalName, 'mail' => $this->mail, 'profile_id' => $this->profileId]);
        $base = ['profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'user_id' => $this->studentUserId];
        $this->courseEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->courseId, 'status' => EnrolmentStatuses::IN_PROGRESS]);
        $this->moduleEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->li1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->li1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleEnrolmentId]);
        $this->li2EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->li2Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleEnrolmentId]);

        $managerId = $this->createUser($go1, ['mail' => $managerMail = 'manager@mail.com', 'instance' => $app['accounts_name']]);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $this->studentAccountId, $managerId);
        $this->managerJwt = JWT::encode((array) $this->getPayload(['id' => $managerId, 'mail' => $managerMail, 'roles' => [Roles::MANAGER, 'instance' => $portalName]]), 'PRIVATE_KEY', 'HS256');
        $this->noneManagerJwt = JWT::encode((array) $this->getPayload(['mail' => 'none-manager@mail.com', 'roles' => [Roles::MANAGER, 'instance' => $portalName]]), 'PRIVATE_KEY', 'HS256');

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $repository->spreadCompletionStatus($this->portalId, $this->li1Id, $this->studentUserId);
        $repository->spreadCompletionStatus($this->portalId, $this->li2Id, $this->studentUserId);

        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->loadByLoAndUserId($this->moduleId, $this->studentUserId)->status);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->loadByLoAndUserId($this->courseId, $this->studentUserId)->status);
    }

    public function test403()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/$this->courseEnrolmentId", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
    }

    public function test404()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/404?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testArchiveWithRecalculate()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/$this->li1EnrolmentId?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        /** @var $repository \go1\enrolment\EnrolmentRepository* */
        $repository = $app[EnrolmentRepository::class];
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->loadByLoAndUserId($this->moduleId, $this->studentUserId)->status);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->loadByLoAndUserId($this->courseId, $this->studentUserId)->status);
        $this->assertEquals(204, $res->getStatusCode());
    }

    public function testDontArchiveChild()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/$this->courseEnrolmentId?jwt=$this->jwt&archiveChild=0", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        /** @var $repository \go1\enrolment\EnrolmentRepository* */
        $repository = $app[EnrolmentRepository::class];
        $this->assertEquals($repository->load($this->moduleEnrolmentId)->id, $this->moduleEnrolmentId);
        $this->assertEquals($repository->load($this->li1EnrolmentId)->id, $this->li1EnrolmentId);
        $this->assertEquals($repository->load($this->li2EnrolmentId)->id, $this->li2EnrolmentId);
    }

    public function testDontCreateRevision()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $newCourseId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $newCourseEnrolmentId = $this->createEnrolment($go1, [
            'profile_id'        => $this->profileId,
            'taken_instance_id' => $this->portalId,
            'user_id'           => $this->studentUserId,
            'lo_id'             => $newCourseId,
            'status'            => EnrolmentStatuses::IN_PROGRESS
        ]);

        $req = Request::create("/enrolment/$newCourseEnrolmentId?jwt=$this->jwt&createRevision=0", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        /** @var $repository \go1\enrolment\EnrolmentRepository* */
        $repository = $app[EnrolmentRepository::class];
        $this->assertCount(0, $repository->revisions($newCourseId, $this->studentUserId));
        $this->assertEmpty($repository->load($newCourseEnrolmentId));
    }

    public function testArchiveChild()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/$this->courseEnrolmentId?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        /** @var $repository \go1\enrolment\EnrolmentRepository* */
        $repository = $app[EnrolmentRepository::class];
        $this->assertNull($repository->load($this->moduleEnrolmentId));
        $this->assertNull($repository->load($this->li1EnrolmentId));
        $this->assertNull($repository->load($this->li2EnrolmentId));
    }

    public function testHookEnrolmentDeleteOnArchive()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $this->link($db, EdgeTypes::HAS_ENQUIRY, $this->courseId, $this->studentUserId, 0, ['mail' => $this->mail, 'status' => 'accepted']);

        $req = Request::create("/enrolment/$this->courseEnrolmentId?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        /** @var $repository \go1\enrolment\EnrolmentRepository* */
        $repository = $app[EnrolmentRepository::class];
        $this->assertNull($repository->load($this->courseEnrolmentId));

        // There's a ENROLMENT_DELETE message published
        $this->assertEquals(4, count($this->queueMessages[Queue::ENROLMENT_DELETE]));
        $this->assertEquals($this->courseEnrolmentId, $this->queueMessages[Queue::ENROLMENT_DELETE][0]->id);
        $this->assertEquals(999, $this->queueMessages[Queue::ENROLMENT_DELETE][0]->profile_id);
        $this->assertEquals($this->courseId, $this->queueMessages[Queue::ENROLMENT_DELETE][0]->lo_id);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $this->queueMessages[Queue::ENROLMENT_DELETE][0]->status);
        $this->assertEquals($this->studentAccountId, $this->queueMessages[Queue::ENROLMENT_DELETE][0]->embedded['account']['id']);
    }

    public function testManagerCanArchive()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/$this->li1EnrolmentId?jwt=$this->noneManagerJwt", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Only portal admin or manager can archive enrolment', $res->getContent());

        $req = Request::create("/enrolment/$this->li1EnrolmentId?jwt=$this->managerJwt", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var $repository \go1\enrolment\EnrolmentRepository* */
        $repository = $app[EnrolmentRepository::class];
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->loadByLoAndUserId($this->moduleId, $this->studentUserId)->status);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->loadByLoAndUserId($this->courseId, $this->studentUserId)->status);
    }
}
