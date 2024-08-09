<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LoHelper;
use go1\util\lo\LoSuggestedCompletionTypes;
use go1\util\model\Enrolment;
use go1\util\plan\PlanHelper;
use go1\util\plan\PlanTypes;
use go1\util\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;

class CompletionRuleTest extends EnrolmentTestCase
{
    use LoMockTrait;
    use EnrolmentMockTrait;
    use UserMockTrait;
    use PortalMockTrait;

    public static $courseId1   = 10;
    public static $courseId2   = 60;
    public static $courseId3   = 80;
    public static $courseId4   = 90;
    public static $courseId5   = 100;
    public static $courseId6   = 110;
    public static $moduleId1   = 20;
    public static $moduleId2   = 30;
    public static $moduleId3   = 40;
    public static $moduleId4   = 50;
    public static $moduleId5   = 51;
    public static $moduleId6   = 52;
    public static $moduleId7   = 53;
    public static $moduleId8   = 54;
    public static $moduleId9   = 55;
    public static $videoId1    = 21;
    public static $videoId2    = 22;
    public static $videoId3    = 23;
    public static $videoId4    = 24;
    public static $videoId5    = 25;
    public static $videoId6    = 26;
    public static $videoId7    = 27;
    public static $videoId8    = 28;
    public static $videoId9    = 29;
    public static $videoId10   = 92;
    public static $videoId11   = 93;
    public static $videoId12   = 94;
    public static $videoId13   = 95;
    public static $singleliId  = 70;
    public static $singleLiId2 = 71;
    public static $singleLiId3 = 72;
    public static $portalId    = 1;
    private int   $profileId   = 20;
    private int   $userId      = 30;

    // this single LI will not have any parent enrolment
    private static $standAloneSingleLI = [ 72 ];

    public static $course1TwentyDays    = 20;
    public static $course2ThreeDays     = 3;
    public static $course6FourDays      = 4;
    public static $module1NineteenDays  = 19;
    public static $module2EighteenDays  = 18;
    public static $module3SeventeenDays = 17;
    public static $module4ThreeDays     = 3;
    public static $module6ThreeDays     = 3;
    public static $module7NoDays        = 19;
    public static $module8ThreeDays     = 3;
    public static $module9FiveDays      = 5;
    public static $video1ThreeDays      = 5;
    public static $video2ThreeDays      = 3;
    public static $video3ThreeDays      = 3;
    public static $video4FiveDays       = 5;
    public static $video5ThreeDays      = 3;
    public static $video6ThreeDays      = 3;
    public static $video7FiveDays       = 5;
    public static $video8NoDays         = 3;
    public static $video9ThreeDays      = 3;
    public static $video10ThreeDays     = 3;
    public static $video11ThreeDays     = 3;
    public static $video12ThreeDays     = 3;
    public static $video13ThreeDays     = 3;
    public static $singleLi1FiveDays    = 5;
    public static $singleLi2ThreeDays   = 3;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $go1 = $app['dbs']['go1'];

        $this->createInstance($go1, ['id' => static::$portalId]);
        $this->createCourse($go1, ['id' => static::$courseId1]);
        $this->createCourse($go1, ['id' => static::$courseId2]);
        $this->createCourse($go1, ['id' => static::$courseId3]);
        $this->createCourse($go1, ['id' => static::$courseId4]);
        $this->createCourse($go1, ['id' => static::$courseId5]);
        $this->createCourse($go1, ['id' => static::$courseId6]);
        $this->createModule($go1, ['id' => static::$moduleId1]);
        $this->createModule($go1, ['id' => static::$moduleId2]);
        $this->createModule($go1, ['id' => static::$moduleId3]);
        $this->createModule($go1, ['id' => static::$moduleId4]);
        $this->createModule($go1, ['id' => static::$moduleId5]);
        $this->createModule($go1, ['id' => static::$moduleId6]);
        $this->createModule($go1, ['id' => static::$moduleId7]);
        $this->createModule($go1, ['id' => static::$moduleId8]);
        $this->createModule($go1, ['id' => static::$moduleId9]);
        $this->createVideo($go1, ['id' => static::$videoId1]);
        $this->createVideo($go1, ['id' => static::$videoId2]);
        $this->createVideo($go1, ['id' => static::$videoId3]);
        $this->createVideo($go1, ['id' => static::$videoId4]);
        $this->createVideo($go1, ['id' => static::$videoId5]);
        $this->createVideo($go1, ['id' => static::$videoId6]);
        $this->createVideo($go1, ['id' => static::$videoId7]);
        $this->createVideo($go1, ['id' => static::$videoId8]);
        $this->createVideo($go1, ['id' => static::$videoId9]);
        $this->createVideo($go1, ['id' => static::$videoId10]);
        $this->createVideo($go1, ['id' => static::$videoId11]);
        $this->createVideo($go1, ['id' => static::$videoId12]);
        $this->createVideo($go1, ['id' => static::$videoId13]);
        $this->createVideo($go1, ['id' => static::$singleliId, 'data' => ['single_li' => true]]);
        $this->createVideo($go1, ['id' => static::$singleLiId2, 'data' => ['single_li' => true]]);
        $this->createVideo($go1, ['id' => static::$singleLiId3, 'data' => ['single_li' => true]]);

        $this->link($go1, EdgeTypes::HAS_MODULE, static::$courseId1, static::$moduleId1);
        $this->link($go1, EdgeTypes::HAS_MODULE, static::$courseId1, static::$moduleId2);
        $this->link($go1, EdgeTypes::HAS_MODULE, static::$courseId1, static::$moduleId3);
        $this->link($go1, EdgeTypes::HAS_MODULE, static::$courseId1, static::$moduleId7);
        $this->link($go1, EdgeTypes::HAS_MODULE, static::$courseId4, static::$moduleId8);
        $this->link($go1, EdgeTypes::HAS_MODULE, static::$courseId4, static::$moduleId4);
        $this->link($go1, EdgeTypes::HAS_MODULE, static::$courseId1, static::$moduleId5);
        $this->link($go1, EdgeTypes::HAS_MODULE, static::$courseId5, static::$moduleId6);
        $this->link($go1, EdgeTypes::HAS_MODULE, static::$courseId6, static::$moduleId9);

        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId1, static::$videoId1);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId1, static::$videoId2);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId1, static::$videoId3);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId2, static::$videoId4);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId2, static::$videoId5);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId2, static::$videoId6);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId3, static::$videoId7);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId3, static::$videoId8);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId3, static::$videoId9);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId4, static::$videoId10);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId5, static::$videoId11);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId5, static::$videoId12);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId5, static::$videoId13);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId7, static::$singleliId);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId8, static::$singleliId);
        $this->link($go1, EdgeTypes::HAS_LI, static::$moduleId9, static::$singleLiId2);

        $rules = [
            static::$courseId1 => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::atom(static::$course1TwentyDays . ' days', DATE_ISO8601)],
            static::$moduleId1 => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::atom(static::$module1NineteenDays . ' days', DATE_ISO8601)],
            static::$videoId1  => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::atom(static::$video1ThreeDays . ' days', DATE_ISO8601)],
            static::$videoId2  => [LoSuggestedCompletionTypes::E_DURATION => static::$video2ThreeDays . ' days'],
            static::$videoId3  => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$video3ThreeDays . ' days'],

            static::$moduleId2 => [LoSuggestedCompletionTypes::E_DURATION => static::$module2EighteenDays . ' days'],
            static::$videoId4  => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::atom(static::$video4FiveDays . ' days', DATE_ISO8601)],
            static::$videoId5  => [LoSuggestedCompletionTypes::E_DURATION => static::$video5ThreeDays . ' days'],
            static::$videoId6  => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$video6ThreeDays . ' days'],

            static::$moduleId3 => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$module3SeventeenDays . ' days'],
            static::$videoId7  => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::atom(static::$video7FiveDays . ' days', DATE_ISO8601)],
            static::$videoId8  => [LoSuggestedCompletionTypes::E_DURATION => static::$video8NoDays . ' days'],
            static::$videoId9  => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$video9ThreeDays . ' days'],

            static::$courseId2 => [LoSuggestedCompletionTypes::E_DURATION => static::$course2ThreeDays . ' days'],

            static::$moduleId4 => [LoSuggestedCompletionTypes::E_DURATION => static::$module4ThreeDays . ' days'],
            static::$videoId10 => [LoSuggestedCompletionTypes::E_DURATION => static::$video10ThreeDays . ' days'],
            static::$videoId11 => [LoSuggestedCompletionTypes::E_DURATION => static::$video11ThreeDays . ' days'],
            static::$videoId12 => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$video12ThreeDays . ' days'],
            static::$videoId13 => [LoSuggestedCompletionTypes::COURSE_ENROLMENT => static::$video13ThreeDays . ' days'],

            static::$moduleId6 => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$module6ThreeDays . ' days'],
            static::$moduleId7 => [LoSuggestedCompletionTypes::DUE_DATE => static::$module7NoDays . ' days'],
            static::$moduleId8 => [LoSuggestedCompletionTypes::E_DURATION => static::$module8ThreeDays . ' days'],

            static::$courseId6 => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::atom(static::$course6FourDays . ' days', DATE_ISO8601)],
            static::$moduleId9 => [LoSuggestedCompletionTypes::DUE_DATE => static::$module9FiveDays . ' days'],

            static::$singleliId => [
                static::$moduleId7 => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::atom(static::$singleLi1FiveDays . ' days', DATE_ISO8601)],
                static::$moduleId8 => [LoSuggestedCompletionTypes::E_DURATION => static::$singleLi2ThreeDays . ' days'],
                static::$moduleId9 => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$singleLi2ThreeDays . ' days'],
            ],

            static::$singleLiId2 => [
                static::$moduleId9 => [
                    LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$singleLi2ThreeDays . ' days',
                ],
            ],
            static::$singleLiId3 => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::atom(static::$course1TwentyDays . ' days', DATE_ISO8601)],
        ];

        foreach ($rules as $sourceId => $value) {
            if ($lo = LoHelper::load($go1, $sourceId)) {
                if (LoHelper::isSingleLi($lo) && (!in_array($lo->id, static::$standAloneSingleLI))) {
                    foreach ($value as $k => $v) {
                        $targetId = EdgeHelper::hasLink($go1, EdgeTypes::HAS_LI, $k, $lo->id);
                        $type = key($v);
                        $_ = $this->link($go1, EdgeTypes::HAS_SUGGESTED_COMPLETION, $sourceId, (int) $targetId, 0, [
                            'type'  => $type,
                            'value' => $v[$type],
                        ]);
                    }
                } else {
                    $type = key($value);
                    $this->link($go1, EdgeTypes::HAS_SUGGESTED_COMPLETION, $sourceId, 0, 0, [
                        'type'  => $type,
                        'value' => $value[$type],
                    ]);
                }
            }
        }

        $this->createUser($go1, ['id' => $this->userId, 'profile_id' => $this->profileId, 'instance' => $app['accounts_name']]);
    }

    public function data()
    {
        return [
            [
                [static::$courseId1 => 'now'], static::$course1TwentyDays,
            ],
            [
                [
                    static::$courseId1 => '10 days',
                    static::$moduleId1 => 'now',
                ], static::$module1NineteenDays,
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId1 => 'now',
                    static::$videoId1  => 'now',
                ], static::$video1ThreeDays,
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId1 => 'now',
                    static::$videoId2  => 'now',
                ], static::$video2ThreeDays,
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId1 => 'now',
                    static::$videoId3  => 'now',
                ], static::$module1NineteenDays + static::$video3ThreeDays,
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId2 => 'now',
                    static::$videoId4  => 'now',
                ], static::$video4FiveDays,
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId2 => 'now',
                    static::$videoId5  => 'now',
                ], static::$video5ThreeDays,
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId2 => 'now',
                    static::$videoId6  => 'now',
                ], static::$video6ThreeDays # FROM parent.module
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId3 => 'now',
                    static::$videoId7  => 'now',
                ], static::$video7FiveDays,
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId3 => 'now',
                    static::$videoId8  => 'now',
                ], static::$video8NoDays,
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId3 => 'now',
                    static::$videoId9  => 'now',
                ], static::$course1TwentyDays + static::$video9ThreeDays # FROM parent.course
            ],
            [
                [
                    static::$courseId3 => 'now',
                ], null,
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId5 => 'now',
                    static::$videoId11 => 'now',
                ], static::$video11ThreeDays # Parent.module not specify but course has
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId5 => 'now',
                    static::$videoId12 => 'now',
                ], static::$video12ThreeDays # Module not specify
            ],
            [
                [
                    static::$courseId1 => 'now',
                    static::$moduleId5 => 'now',
                    static::$videoId13 => 'now',
                ], static::$video13ThreeDays,
            ],
            [
                [
                    static::$courseId4 => 'now',
                    static::$moduleId4 => 'now',
                    static::$videoId10 => 'now',
                ], static::$module4ThreeDays # Parent not specify
            ],
            [
                [
                    static::$videoId9 => 'now',
                ], null # Missing parent enrolment
            ],
            [
                [static::$courseId1 => 'now'], static::$course1TwentyDays,
            ],
            [
                [
                    static::$courseId2 => '10 days',
                    static::$moduleId1 => 'now',
                ], static::$module1NineteenDays,
            ],
            [
                [
                    static::$courseId2 => 'now',
                    static::$moduleId1 => 'now',
                    static::$videoId1  => 'now',
                ], static::$video1ThreeDays,
            ],
            [
                [
                    static::$courseId2 => 'now',
                    static::$moduleId1 => 'now',
                    static::$videoId2  => 'now',
                ], static::$video2ThreeDays,
            ],
            [
                [
                    static::$courseId2 => 'now',
                    static::$moduleId1 => 'now',
                    static::$videoId3  => 'now',
                ], static::$module1NineteenDays + static::$video3ThreeDays,
            ],
            [
                [
                    static::$courseId2 => 'now',
                    static::$moduleId2 => 'now',
                    static::$videoId4  => 'now',
                ], static::$video4FiveDays,
            ],
            [
                [
                    static::$courseId2 => 'now',
                    static::$moduleId2 => 'now',
                    static::$videoId5  => 'now',
                ], static::$video5ThreeDays,
            ],
            [
                [
                    static::$courseId2 => 'now',
                    static::$moduleId2 => 'now',
                    static::$videoId6  => 'now',
                ], static::$video6ThreeDays # FROM parent.module
            ],
            [
                [
                    static::$courseId2 => 'now',
                    static::$moduleId3 => 'now',
                    static::$videoId7  => 'now',
                ], static::$video7FiveDays,
            ],
            [
                [
                    static::$courseId2 => 'now',
                    static::$moduleId3 => 'now',
                    static::$videoId8  => 'now',
                ], static::$video8NoDays,
            ],
            [
                [
                    static::$courseId2 => 'now',
                    static::$moduleId3 => 'now',
                    static::$videoId9  => 'now',
                ], static::$video9ThreeDays # FROM parent.course
            ],
            [
                [
                    static::$courseId5 => 'now',
                    static::$moduleId6 => 'now',
                ], static::$module6ThreeDays,
            ],
            [
                [
                    static::$courseId1  => 'now',
                    static::$moduleId7  => 'now',
                    static::$singleliId => 'now',
                ], static::$singleLi1FiveDays,
            ],
            [
                [
                    static::$courseId4  => 'now',
                    static::$moduleId8  => 'now',
                    static::$singleliId => 'now',
                ], static::$singleLi2ThreeDays,
            ],
        ];
    }

    /**
     * @dataProvider data()
     */
    public function test($data, $dueDate)
    {
        /** @var EnrolmentCreateService $service */
        /** @var Connection $go1 */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $service = $app[EnrolmentCreateService::class];

        $parentLoId = 0;
        $parentEnrolmentId = 0;
        foreach ($data as $loId => $_) {
            $enrolment = Enrolment::create((object) [
                'profile_id' => $this->profileId,
                'taken_instance_id' => static::$portalId,
                'user_id' => $this->userId,
                'lo_id' => $loId,
                'parent_lo_id' => $parentLoId,
                'parent_enrolment_id' => $parentEnrolmentId,
                'status' => EnrolmentStatuses::IN_PROGRESS,
                'start_date' => DateTime::atom('Now', 'd-m-Y')
            ]);

            $parentEnrolmentId = $id = $service->create($enrolment)->enrolment->id;
            $parentLoId = $loId;
            $enrolment = EnrolmentHelper::loadSingle($go1, $id);
            $lo = LoHelper::load($go1, $enrolment->loId);
        }

        if (!$dueDate) {
            $this->assertFalse(isset($this->queueMessages[Queue::PLAN_CREATE][0]));

            return null;
        }

        $entityId = $loId;
        $entityType = PlanTypes::ENTITY_LO;
        $plan = $this->loadPlanByEnrolmentId($go1, $enrolment->id);

        if (LoHelper::isSingleLi($lo) && $parentEnrolmentId === 0) {
            $entityType = PlanTypes::ENTITY_RO;
            $hasLiEdgeId = $go1->fetchColumn(
                'SELECT id FROM gc_ro WHERE type = ? AND source_id = ? AND target_id = ?',
                [EdgeTypes::HAS_LI, $enrolment->parentLoId, $enrolment->loId]
            );

            $entityId = $go1->fetchColumn(
                'SELECT * FROM gc_ro WHERE type = ? AND source_id = ? AND target_id = ?',
                [EdgeTypes::HAS_SUGGESTED_COMPLETION, $lo->id, $hasLiEdgeId]
            );

            $ro = array_filter(
                $this->queueMessages[Queue::RO_CREATE],
                fn ($ro) => ($ro['type'] == EdgeTypes::HAS_PLAN)
                    && ($ro['target_id'] == $plan->id)
                    && ($ro['source_id'] == $enrolment->id)
            );

            $this->assertNotEmpty($ro);
        }

        $userID = $plan ? $plan->userId : $enrolment->userId;
        $loId = $plan ? $plan->entityId : $enrolment->loId;

        $this->assertEquals($this->userId, $userID);
        $this->assertEquals($entityId, $loId);
        if ($plan) {
            $this->assertEquals($entityType, $plan->entityType);
            $this->assertEquals(PlanTypes::ASSIGN, $plan->type);
            $this->assertEquals(DateTime::atom($dueDate . ' days', 'd/m/Y'), $plan->due->format('d/m/Y'));
        }
    }

    public function testLearnerNotEnrolLI()
    {
        /** @var EnrolmentCreateService $service */
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $service = $app[EnrolmentCreateService::class];

        $enrolment = Enrolment::create((object) [
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => static::$portalId,
            'lo_id'             => static::$courseId6,
            'parent_lo_id'      => 0,
            'status'            => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $service->create($enrolment);

        $plan = $this->loadPlanByEnrolmentId($db, $enrolment->id);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(PlanTypes::ENTITY_LO, $plan->entityType);
        $this->assertEquals($enrolment->loId, $plan->entityId);
        $this->assertEquals(DateTime::atom(static::$course6FourDays . ' days', 'd/m/Y'), $plan->due->format('d/m/Y'));

        $edge = EdgeHelper::edgesFromSource($db, static::$singleLiId2, [EdgeTypes::HAS_SUGGESTED_COMPLETION])[0];
        $this->assertEmpty(
            $db->fetchColumn(
                'SELECT 1 FROM gc_plan WHERE entity_type = ? AND entity_id = ? AND user_id = ?',
                [PlanTypes::ENTITY_RO, $edge->id, $this->userId]
            ),
            'No suggested plan'
        );
    }

    public function testAssignmentWithCompletionRule()
    {
        /** @var EnrolmentCreateService $service */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $service = $app[EnrolmentCreateService::class];

        $courseId1 = $this->createCourse($go1);
        $module1 = $this->createModule($go1);
        $this->link($go1, EdgeTypes::HAS_MODULE, $courseId1, $module1);
        $videoId = $this->createVideo($go1);
        $this->link($go1, EdgeTypes::HAS_LI, $module1, $videoId);

        $courseScheduleTime = '18 months';
        $this->link($go1, EdgeTypes::HAS_SUGGESTED_COMPLETION, $courseId1, 0, 0, [
            'type'  => LoSuggestedCompletionTypes::E_DURATION,
            'value' => $courseScheduleTime,
        ]);

        $liScheduleTime = '4 days';
        $this->link($go1, EdgeTypes::HAS_SUGGESTED_COMPLETION, $videoId, 0, 0, [
            'type'  => LoSuggestedCompletionTypes::E_PARENT_DURATION,
            'value' => $liScheduleTime,
        ]);

        $courseEnrolmentId = $service->create(Enrolment::create((object) [
            'profile_id'          => $this->profileId,
            'taken_instance_id'   => static::$portalId,
            'user_id'             => $this->userId,
            'lo_id'               => $courseId1,
            'parent_lo_id'        => 0,
            'parent_enrolment_id' => 0,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
        ]))->enrolment->id;

        $moduleEnrolmentId = $service->create(Enrolment::create((object) [
            'profile_id'          => $this->profileId,
            'taken_instance_id'   => static::$portalId,
            'user_id'             => $this->userId,
            'lo_id'               => $module1,
            'parent_lo_id'        => $courseId1,
            'parent_enrolment_id' => $courseEnrolmentId,
            'start_date'          => $moduleEnrolmentDate = DateTime::atom('+10 days', 'd-m-Y'),
            'status'              => EnrolmentStatuses::IN_PROGRESS,
        ]))->enrolment->id;

        $service->create(Enrolment::create((object ) [
            'profile_id'          => $this->profileId,
            'taken_instance_id'   => static::$portalId,
            'user_id'             => $this->userId,
            'lo_id'               => $videoId,
            'parent_lo_id'        => $module1,
            'parent_enrolment_id' => $moduleEnrolmentId,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
        ]));

        // Consume course enrolment
        $plan = PlanHelper::loadByEntityAndUser($go1, 'lo', $courseId1, $this->userId);
        $date = DateTime::create(strtotime($courseScheduleTime));
        $date2 = DateTime::create($plan->due_date);
        $interval = $date->diff($date2);
        $this->assertEquals(0, $interval->days);

        // Consume module enrolment
        $plan = PlanHelper::loadByEntityAndUser($go1, 'lo', $module1, $this->userId);
        $this->assertFalse($plan);

        // Consume video enrolment
        $plan = PlanHelper::loadByEntityAndUser($go1, 'lo', $videoId, $this->userId);
        $this->assertFalse($plan);
    }

    public function testStandaloneLIWithPlan()
    {
        /** @var EnrolmentCreateService $service */
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $service = $app[EnrolmentCreateService::class];

        $enrolment = Enrolment::create((object) [
            'profile_id'          => $this->profileId,
            'user_id'             => $this->userId,
            'taken_instance_id'   => static::$portalId,
            'lo_id'               => static::$singleLiId3,
            'parent_lo_id'        => 0,
            'parent_enrolment_id' => 0,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $service->create($enrolment);

        $plan = $this->loadPlanByEnrolmentId($db, $enrolment->id);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(PlanTypes::ENTITY_LO, $plan->entityType);
        $this->assertEquals($enrolment->loId, $plan->entityId);
        $this->assertEquals(DateTime::atom(static::$course1TwentyDays . ' days', 'd/m/Y'), $plan->due->format('d/m/Y'));

        $edge = EdgeHelper::edgesFromSource($db, static::$singleLiId3, [EdgeTypes::HAS_SUGGESTED_COMPLETION])[0];
        $this->assertEmpty(
            $db->fetchColumn(
                'SELECT 1 FROM gc_plan WHERE entity_type = ? AND entity_id = ? AND user_id = ?',
                [PlanTypes::ENTITY_RO, $edge->id, $this->userId]
            ),
            'No suggested plan'
        );
    }
}
