<?php

namespace go1\enrolment\tests\load;

use Doctrine\DBAL\Connection;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\EnrolmentRevisionRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\lo\LoTypes;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentRevisionTreeLoadTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $userId;
    private $accountId;
    private $profileId = 999;
    private $courseId;
    private $moduleAId;
    private $moduleBId;
    private $liA1Id;
    private $liA2Id;
    private $liB1Id;
    private $liB2Id;
    private $courseEnrolmentId;
    private $moduleAEnrolmentId;
    private $moduleBEnrolmentId;
    private $liA1EnrolmentId;
    private $liA2EnrolmentId;
    private $liB1EnrolmentId;
    private $liB2EnrolmentId;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => 'qa.mygo1.com']);
        $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'qa@student.com', 'profile_id' => $this->profileId]);
        $this->accountId = $this->createUser($go1, ['instance' => 'qa.mygo1.com', 'mail' => 'qa@student.com']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);

        # Setup course structure
        # ---------------------
        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->moduleAId = $this->createModule($go1, ['instance_id' => $this->portalId]);
        $this->moduleBId = $this->createModule($go1, ['instance_id' => $this->portalId]);
        $this->liA1Id = $this->createVideo($go1, ['instance_id' => $this->portalId]);
        $this->liA2Id = $this->createVideo($go1, ['instance_id' => $this->portalId]);
        $this->liB1Id = $this->createVideo($go1, ['instance_id' => $this->portalId]);
        $this->liB2Id = $this->createVideo($go1, ['instance_id' => $this->portalId]);
        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleAId);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LO, $this->courseId, $this->moduleBId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleAId, $this->liA1Id);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LI, $this->moduleAId, $this->liA2Id);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleBId, $this->liB1Id);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LI, $this->moduleBId, $this->liB2Id);

        $this->courseEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->courseId]);
        $this->moduleAEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->moduleAId, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->moduleBEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->moduleBId, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->liA1EnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liA1Id, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->liA2EnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liA2Id, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->liB1EnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liB1Id, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $this->liB2EnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liB2Id, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
    }

    public function testLo()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $repository = $app[EnrolmentRepository::class];

        $repository->deleteEnrolment(EnrolmentHelper::findEnrolment($go1, $this->portalId, $this->userId, $this->courseId), 0);
        $base = ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'user_id' => $this->userId];
        $this->courseEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->courseId, 'status' => EnrolmentStatuses::COMPLETED]);
        $this->moduleAEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleAId, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->moduleBEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleBId, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->liA1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liA1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->liA2EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liA2Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->liB1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liB1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $this->liB1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liB2Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);

        $jwt = JWT::encode((array) $this->getPayload(['user_profile_id' => $this->profileId, 'user_id' => $this->userId]), 'INTERNAL', 'HS256');
        $req = Request::create("/lo/{$this->courseId}?tree=1&jwt=$jwt&portalId=$this->portalId");

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $result = json_decode($res->getContent());
        $this->assertEquals(1, $result->items[0]->lo_published);
    }

    public function test()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $repository = $app[EnrolmentRepository::class];

        $repository->deleteEnrolment(EnrolmentHelper::findEnrolment($go1, $this->portalId, $this->userId, $this->courseId), 0);
        $base = ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'user_id' => $this->userId];
        $this->courseEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->courseId, 'status' => EnrolmentStatuses::COMPLETED]);
        $this->moduleAEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleAId, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->moduleBEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleBId, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->liA1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liA1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->liA2EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liA2Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->liB1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liB1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $this->liB1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liB2Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $repository->deleteEnrolment(EnrolmentHelper::findEnrolment($go1, $this->portalId, $this->userId, $this->courseId), 0);

        $this->courseEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->courseId, 'status' => EnrolmentStatuses::COMPLETED]);
        $this->moduleAEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleAId, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->moduleBEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleBId, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->liA1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liA1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->liA2EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liA2Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->liB1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liB1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $this->liB1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->liB2Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $repository->deleteEnrolment(EnrolmentHelper::findEnrolment($go1, $this->portalId, $this->userId, $this->courseId), 0);

        $jwt = JWT::encode((array) $this->getPayload(['user_profile_id' => $this->profileId, 'user_id' => $this->userId]), 'INTERNAL', 'HS256');
        $req = Request::create("/lo/{$this->courseId}/history/{$this->userId}?jwt=$jwt");

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(2, $history = json_decode($res->getContent()));

        /** @var EnrolmentRevisionRepository $repository */
        $repository = $app[EnrolmentRevisionRepository::class];
        $tree = $repository->loadEnrolmentRevisionTree($history[0]->id);
        $this->assertEquals(LoTypes::COURSE, $tree->lo_type);
        $this->assertEquals($this->moduleAId, $tree->items[0]->lo_id);
        $this->assertEquals(LoTypes::MODULE, $tree->items[0]->lo_type);
        $this->assertCount(2, $tree->items[0]->items);
        $this->assertEquals(LiTypes::VIDEO, $tree->items[0]->items[0]->lo_type);
        $this->assertEquals($this->moduleBId, $tree->items[1]->lo_id);
        $this->assertEquals(LoTypes::MODULE, $tree->items[1]->lo_type);
        $this->assertCount(2, $tree->items[1]->items);
        $this->assertEquals(LiTypes::VIDEO, $tree->items[1]->items[0]->lo_type);

        $tree = $repository->loadEnrolmentRevisionTree($history[1]->id);
        $this->assertEquals(LoTypes::COURSE, $tree->lo_type);
        $this->assertEquals($this->moduleAId, $tree->items[0]->lo_id);
        $this->assertEquals(LoTypes::MODULE, $tree->items[0]->lo_type);
        $this->assertCount(2, $tree->items[0]->items);
        $this->assertEquals(LiTypes::VIDEO, $tree->items[1]->items[0]->lo_type);
        $this->assertEquals($this->moduleBId, $tree->items[1]->lo_id);
        $this->assertEquals(LoTypes::MODULE, $tree->items[1]->lo_type);
        $this->assertCount(2, $tree->items[1]->items);
        $this->assertEquals(LiTypes::VIDEO, $tree->items[1]->items[0]->lo_type);
    }

    public function testCompleteWithCourseOnly()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $repository = $app[EnrolmentRepository::class];

        $repository->deleteEnrolment(EnrolmentHelper::findEnrolment($go1, $this->portalId, $this->userId, $this->courseId), 0);
        $this->createEnrolment($go1, ['user_id' => $this->userId, 'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->courseId, 'status' => EnrolmentStatuses::COMPLETED]);
        $repository->deleteEnrolment(EnrolmentHelper::findEnrolment($go1, $this->portalId, $this->userId, $this->courseId), 0);

        $jwt = JWT::encode((array) $this->getPayload(['user_profile_id' => $this->profileId]), 'INTERNAL', 'HS256');
        $req = Request::create("/lo/{$this->courseId}/history/{$this->userId}?jwt=$jwt");
        $this->assertCount(1, $history = json_decode($app->handle($req)->getContent()));

        /** @var EnrolmentRevisionRepository $repository */
        $repository = $app[EnrolmentRevisionRepository::class];
        $tree = $repository->loadEnrolmentRevisionTree($history[0]->id);
        $this->assertEquals(LoTypes::COURSE, $tree->lo_type);
        $this->assertArrayNotHasKey('items', json_decode(json_encode($tree), true));
    }

    public function testCompleteWithModuleOnly()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $repository = $app[EnrolmentRepository::class];

        $repository->deleteEnrolment(EnrolmentHelper::findEnrolment($go1, $this->portalId, $this->userId, $this->courseId), 0);
        $base = ['user_id' => $this->userId, 'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'status' => EnrolmentStatuses::COMPLETED];
        $this->courseEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->courseId]);
        $this->moduleAEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleAId, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->moduleBEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleBId, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $repository->deleteEnrolment(EnrolmentHelper::findEnrolment($go1, $this->portalId, $this->userId, $this->courseId), 0);

        $jwt = JWT::encode((array) $this->getPayload(['user_profile_id' => $this->profileId]), 'INTERNAL', 'HS256');
        $req = Request::create("/lo/{$this->courseId}/history/{$this->userId}?jwt=$jwt");
        $this->assertCount(1, $history = json_decode($app->handle($req)->getContent()));

        /** @var EnrolmentRevisionRepository $repository */
        $repository = $app[EnrolmentRevisionRepository::class];
        $tree = $repository->loadEnrolmentRevisionTree($history[0]->id);
        $this->assertEquals($this->moduleAId, $tree->items[0]->lo_id);
        $this->assertArrayNotHasKey('items', json_decode(json_encode($tree->items[0]), true));
        $this->assertEquals($this->moduleBId, $tree->items[1]->lo_id);
        $this->assertArrayNotHasKey('items', json_decode(json_encode($tree->items[1]), true));
    }
}
