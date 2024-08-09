<?php

namespace go1\enrolment\tests;

use Doctrine\DBAL\Connection;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\services\EnrolmentCreateService;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\model\Enrolment;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

use function strtotime;
use function time;

class EnrolmentRepositoryTest extends EnrolmentTestCase
{
    use EnrolmentMockTrait;
    use LoMockTrait;
    use PortalMockTrait;
    use UserMockTrait;

    private string $portalName = 'qa.mygo1.com';
    private int    $portalId;
    private int    $courseId;
    private int    $moduleId;
    private int    $liId;
    private int    $userId;
    private int    $accountId;
    private string $email      = 'john@doe.qa';

    public function testChangeStatus()
    {
        /** @var EnrolmentRepository $re */
        $start = time();
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $re = $app[EnrolmentRepository::class];
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $this->userId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->email]);
        $this->accountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => $this->email, 'uuid' => 'USER_UUID']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);

        // setup data
        $id = $this->createEnrolment($db, [
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'lo_id'             => 123,
            'status'            => EnrolmentStatuses::COMPLETED,
            'end_date' => '2017-03-15 00:17:55',
        ]);

        $raw = EnrolmentHelper::load($db, $id);
        $enrolment = Enrolment::create($raw);

        // call the method
        $re->changeStatus($enrolment, EnrolmentStatuses::COMPLETED);

        // check result
        $reload = EnrolmentHelper::load($db, $id);
        $this->assertTrue(strtotime($reload->end_date) >= $start, 'gc_enrolment.end_time must be updated.');
    }


    public function testChangeStatusUpdatesChangedDate()
    {
        /** @var EnrolmentRepository $re */

        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $re = $app[EnrolmentRepository::class];
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $this->userId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->email]);
        $this->accountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => $this->email, 'uuid' => 'USER_UUID']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);

        // setup data
        $id = $this->createEnrolment($db, [
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'lo_id'             => 124,
            'status'            => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $raw = EnrolmentHelper::load($db, $id);
        $enrolment = Enrolment::create($raw);

        // call the method
        $re->changeStatus($enrolment, EnrolmentStatuses::COMPLETED);

        // check result
        $reload = EnrolmentHelper::load($db, $id);
        $this->assertEquals($reload->status, EnrolmentStatuses::COMPLETED);
        $this->assertEquals(strtotime($reload->changed), strtotime($reload->end_date));
    }

    public function testCourseStartDate()
    {
        /** @var EnrolmentRepository $re */
        /** @var Connection $db */
        /** @var EnrolmentCreateService $service */
        $timpstamp = time();
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $service = $app[EnrolmentCreateService::class];

        // setup data: course was created without start-date
        {
            $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
            $this->userId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->email]);
            $this->courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
            $this->moduleId = $this->createModule($db, ['instance_id' => $this->portalId]);
            $this->liId = $this->createVideo($db, ['instance_id' => $this->portalId]);
            $this->link($db, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
            $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->liId);

            $courseEnrolmentId = $this->createEnrolment($db, [
                'taken_instance_id' => $this->portalId,
                'lo_id'             => $this->courseId,
                'user_id'           => $this->userId,
                'status'            => EnrolmentStatuses::NOT_STARTED,
            ]);

            $moduleEnrolmentId = $this->createEnrolment($db, [
                'taken_instance_id'   => $this->portalId,
                'lo_id'               => $this->moduleId,
                'user_id'             => $this->userId,
                'status'              => EnrolmentStatuses::NOT_STARTED,
                'parent_enrolment_id' => $courseEnrolmentId,
            ]);

            $db->update('gc_enrolment', ['start_date' => null], ['id' => $courseEnrolmentId]);
            $db->update('gc_enrolment', ['start_date' => null], ['id' => $moduleEnrolmentId]);
        }

        // enrol to LI -> check course * module enrolments
        {
            $liEnrolment = Enrolment::create((object) [
                'taken_instance_id'   => $this->portalId,
                'lo_id'               => $this->liId,
                'user_id'             => $this->userId,
                'profile_id'          => $this->userId,
                'status'              => EnrolmentStatuses::IN_PROGRESS,
                'parent_enrolment_id' => $moduleEnrolmentId,
            ]);

            $createResult = $service->create($liEnrolment);
            $this->assertEquals(200, $createResult->code);

            // Check the change.
            $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, EnrolmentHelper::load($db, $createResult->enrolment->id)->status);
            $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, EnrolmentHelper::load($db, $moduleEnrolmentId)->status);

            $courseEnrolment  = EnrolmentHelper::load($db, $courseEnrolmentId);
            $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $courseEnrolment->status);
            $this->assertTrue(strtotime($courseEnrolment->start_date) >= $timpstamp);
        }
    }

    public function testDeletePlanReferencesByEnrolmentId(): void
    {
        $app = $this->getApp();
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));
        $enrolmentId1 = 123;
        $enrolmentId2 = 234;
        $db = $app['dbs']['go1'];
        $db->insert('gc_enrolment_plans', ['enrolment_id' => $enrolmentId1, 'plan_id' => 2]);
        $db->insert('gc_enrolment_plans', ['enrolment_id' => $enrolmentId1, 'plan_id' => 4]);
        $db->insert('gc_enrolment_plans', ['enrolment_id' => $enrolmentId1, 'plan_id' => 6]);
        $db->insert('gc_enrolment_plans', ['enrolment_id' => $enrolmentId2, 'plan_id' => 8]);
        $db->insert('gc_enrolment_plans', ['enrolment_id' => $enrolmentId2, 'plan_id' => 10]);
        $db->insert('gc_plan_reference', ['plan_id' => 2, 'source_type' => 'group', 'source_id' => 123, 'status' => 1]);


        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $repository->deletePlanReferencesByEnrolmentId($enrolmentId1);
        $results = $db
            ->executeQuery('SELECT plan_id FROM gc_enrolment_plans WHERE enrolment_id IN (?)', [[$enrolmentId1, $enrolmentId2]], [DB::INTEGERS])
            ->fetchAll(DB::COL);
        $this->assertEquals([8, 10], $results);
        $this->assertEquals([0], $db->executeQuery('SELECT status FROM gc_plan_reference WHERE plan_id = ?', [2])->fetchAll(DB::COL));
        $this->assertEquals([2, 4, 6], array_column($this->queueMessages['ro.delete'], 'target_id'));
        $this->assertEquals([
            [
                'id' => 1,
                'plan_id' => 2,
                'source_type' => 'group',
                'source_id' => 123,
                'status' => 0,
                'original' => [
                    'id' => 1,
                    'plan_id' => 2,
                    'source_type' => 'group',
                    'source_id' => 123,
                    'status' => 1
                ]
            ]
        ], $this->queueMessages['plan-reference.update']);
    }

    public function testFindParentEnrolment()
    {
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $db = $app['dbs']['go1'];
        $profileId = 1;
        $userId = $this->createUser($db, ['instance' => $this->portalName]);
        $portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $data = json_encode(['elective_number' => 1]);
        $lpId = $this->createCourse($db, ['type' => 'learning_pathway', 'instance_id' => $portalId]);
        $courseId = $this->createCourse($db, ['type' => 'course', 'instance_id' => $portalId]);
        $moduleId = $this->createCourse($db, ['type' => 'module', 'instance_id' => $portalId, 'data' => $data]);
        $liVideoId = $this->createCourse($db, ['type' => 'video', 'instance_id' => $portalId]);
        $liResourceId = $this->createCourse($db, ['type' => 'iframe', 'instance_id' => $portalId]);
        $electiveQuestionId = $this->createCourse($db, ['type' => 'question', 'instance_id' => $portalId]);
        $electiveTextId = $this->createCourse($db, ['type' => 'text', 'instance_id' => $portalId]);

        $basicLiData = ['profile_id' => $profileId, 'user_id' => $userId, 'taken_instance_id' => $portalId];
        $enrolments = [
            'lp' => $this->createEnrolment($db, $basicLiData + ['lo_id' => $lpId]),
            'course' => $this->createEnrolment($db, $basicLiData + ['lo_id' => $courseId, 'parent_lo_id' => $lpId]),
            'module' => $this->createEnrolment($db, $basicLiData + ['lo_id' => $moduleId, 'parent_lo_id' => $courseId]),
            'video' => $this->createEnrolment($db, $basicLiData + ['lo_id' => $liVideoId, 'parent_lo_id' => $moduleId]),
            'resource' => $this->createEnrolment($db, $basicLiData + ['lo_id' => $liResourceId, 'parent_lo_id' => $moduleId]),
            'question' => $this->createEnrolment($db, $basicLiData + ['lo_id' => $electiveQuestionId, 'parent_lo_id' => $moduleId]),
            'text' => $this->createEnrolment($db, $basicLiData + ['lo_id' => $electiveTextId, 'parent_lo_id' => $moduleId]),
        ];

        $course = $repository->findParentEnrolment(EnrolmentHelper::load($db, $enrolments['lp']));
        $this->assertNull($course);

        $course = $repository->findParentEnrolment(EnrolmentHelper::load($db, $enrolments['module']));
        $this->assertEquals($courseId, $course->id);

        $course = $repository->findParentEnrolment(EnrolmentHelper::load($db, $enrolments['video']));
        $this->assertEquals($courseId, $course->id);

        $course = $repository->findParentEnrolment(EnrolmentHelper::load($db, $enrolments['resource']));
        $this->assertEquals($courseId, $course->id);

        $course = $repository->findParentEnrolment(EnrolmentHelper::load($db, $enrolments['question']));
        $this->assertEquals($courseId, $course->id);

        $course = $repository->findParentEnrolment(EnrolmentHelper::load($db, $enrolments['text']));
        $this->assertEquals($courseId, $course->id);
    }
}
