<?php

namespace go1\enrolment\tests\restore;

use DateTime;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\Constants;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\EnrolmentRevisionRepository;
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
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentRevisionRestoreControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;
    use EnrolmentMockTrait;

    private int $portalId;
    private int $courseId;
    private int $moduleId;
    private int $li1Id;
    private int $li2Id;
    private string $jwt = UserHelper::ROOT_JWT;
    private string $mail = 'student@mygo1.com';
    private int $studentUserId;
    private int $profileId = 999;
    private int $studentAccountId;
    private int $courseEnrolmentId;
    private int $moduleEnrolmentId;
    private int $li1EnrolmentId;
    private int $li2EnrolmentId;
    private EnrolmentRepository $repository;
    private EnrolmentRevisionRepository $revisionRepository;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

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
        $this->studentAccountId = $this->createUser($go1, ['user_id' => $this->studentUserId, 'instance' => $portalName, 'mail' => $this->mail, 'profile_id' => $this->profileId]);

        $base = ['profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'user_id' => $this->studentUserId];
        $this->courseEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->courseId, 'status' => EnrolmentStatuses::IN_PROGRESS]);
        $this->moduleEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->li1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->li1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleEnrolmentId]);
        $this->li2EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->li2Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleEnrolmentId]);

        $this->repository = $app[EnrolmentRepository::class];
        $this->revisionRepository = $app[EnrolmentRevisionRepository::class];
        $this->repository->spreadCompletionStatus($this->portalId, $this->li1Id, $this->studentUserId);
        $this->repository->spreadCompletionStatus($this->portalId, $this->li2Id, $this->studentUserId);

        $this->assertEquals(EnrolmentStatuses::COMPLETED, $this->repository->loadByLoAndUserId($this->moduleId, $this->studentUserId)->status);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $this->repository->loadByLoAndUserId($this->courseId, $this->studentUserId)->status);
    }

    public function test403()
    {
        $app = $this->getApp();
        $req = Request::create("/staff/enrolment-revisions/$this->courseEnrolmentId/restore", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
    }

    public function test404()
    {
        $app = $this->getApp();
        $req = Request::create("/staff/enrolment-revisions/404/restore?jwt=$this->jwt", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
    }

    public function test200()
    {
        $app = $this->getApp();

        $courseEnrolment = $this->repository->load($this->courseEnrolmentId);
        $originalTree = $this->repository->loadEnrolmentTree($courseEnrolment);
        $req = Request::create("/enrollments/$this->courseEnrolmentId?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $req = Request::create("/enrollments/$this->courseEnrolmentId?jwt=$this->jwt");
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());

        // Reset queue messages
        $this->queueMessages = [];
        $revisions = $this->revisionRepository->loadByEnrolmentId($this->courseEnrolmentId);
        $revisionId = max(array_column($revisions, 'id'));
        $req = Request::create("/staff/enrolment-revisions/$revisionId/restore?jwt=$this->jwt", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $req = Request::create("/enrollments/$this->courseEnrolmentId?jwt=$this->jwt&tree=1");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $restoredCourseEnrolment = $this->repository->load($courseEnrolment->id);
        $tree = $this->repository->loadEnrolmentTree($restoredCourseEnrolment);
        $this->assertEquals(0, $tree->parent_enrolment_id);

        // compare trees
        {
            unset($originalTree->parent_enrolment_id);
            unset($tree->parent_enrolment_id);

            $this->assertEquals($originalTree, $tree);
        }

        $this->assertCount(4, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $this->assertEquals($tree->id, $this->queueMessages[Queue::ENROLMENT_CREATE][0]['id']);
        $this->assertEquals($tree->items[0]->id, $this->queueMessages[Queue::ENROLMENT_CREATE][1]['id']);
        $this->assertEquals($tree->items[0]->items[0]->id, $this->queueMessages[Queue::ENROLMENT_CREATE][2]['id']);
        $this->assertEquals($tree->items[0]->items[1]->id, $this->queueMessages[Queue::ENROLMENT_CREATE][3]['id']);
        $this->assertNotEmpty($this->queueMessages[Queue::ENROLMENT_CREATE][0]['embedded']['lo']);
        $this->assertNotEmpty($this->queueMessages[Queue::ENROLMENT_CREATE][0]['embedded']['portal']);
        $this->assertNotEmpty($this->queueMessages[Queue::ENROLMENT_CREATE][0]['embedded']['account']);

        /**
         * @var \Doctrine\DBAL\Connection $go1
         */
        $go1 = $app['dbs']['go1'];
        $enrolment = $go1->fetchAssociative("SELECT * FROM gc_enrolment WHERE id = $restoredCourseEnrolment->id");
        $this->assertNotFalse(DateTime::createFromFormat(Constants::DATE_MYSQL, $enrolment['changed']));
        $this->assertNotFalse(DateTime::createFromFormat(Constants::DATE_MYSQL, $enrolment['start_date']));
        $this->assertNotFalse(DateTime::createFromFormat(Constants::DATE_MYSQL, $enrolment['end_date']));
    }
}
