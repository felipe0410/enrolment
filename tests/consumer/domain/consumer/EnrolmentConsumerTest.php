<?php

namespace go1\enrolment\tests\consumer\domain\consumer;

use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LoHelper;
use go1\util\lo\LoSuggestedCompletionTypes;
use go1\util\plan\Plan;
use go1\util\plan\PlanTypes;
use go1\util\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentConsumerTest extends EnrolmentTestCase
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
    public static $portalId    = 1;
    private int   $profileId   = 20;
    private int   $userId      = 30;

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
            static::$courseId1 => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::formatDate(static::$course1TwentyDays . ' days')],
            static::$moduleId1 => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::formatDate(static::$module1NineteenDays . ' days')],
            static::$videoId1  => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::formatDate(static::$video1ThreeDays . ' days')],
            static::$videoId2  => [LoSuggestedCompletionTypes::E_DURATION => static::$video2ThreeDays . ' days'],
            static::$videoId3  => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$video3ThreeDays . ' days'],

            static::$moduleId2 => [LoSuggestedCompletionTypes::E_DURATION => static::$module2EighteenDays . ' days'],
            static::$videoId4  => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::formatDate(static::$video4FiveDays . ' days')],
            static::$videoId5  => [LoSuggestedCompletionTypes::E_DURATION => static::$video5ThreeDays . ' days'],
            static::$videoId6  => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$video6ThreeDays . ' days'],

            static::$moduleId3 => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$module3SeventeenDays . ' days'],
            static::$videoId7  => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::formatDate(static::$video7FiveDays . ' days')],
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

            static::$courseId6 => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::formatDate(static::$course6FourDays . ' days')],
            static::$moduleId9 => [LoSuggestedCompletionTypes::DUE_DATE => static::$module9FiveDays . ' days'],

            static::$singleliId => [
                static::$moduleId7 => [LoSuggestedCompletionTypes::DUE_DATE => DateTime::formatDate(static::$singleLi1FiveDays . ' days')],
                static::$moduleId8 => [LoSuggestedCompletionTypes::E_DURATION => static::$singleLi2ThreeDays . ' days'],
                static::$moduleId9 => [LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$singleLi2ThreeDays . ' days'],
            ],

            static::$singleLiId2 => [
                static::$moduleId9 => [
                    LoSuggestedCompletionTypes::E_PARENT_DURATION => static::$singleLi2ThreeDays . ' days',
                ],
            ],
        ];

        foreach ($rules as $sourceId => $value) {
            if ($lo = LoHelper::load($go1, $sourceId)) {
                if (LoHelper::isSingleLi($lo)) {
                    foreach ($value as $k => $v) {
                        $targetId = EdgeHelper::hasLink($go1, EdgeTypes::HAS_LI, $k, $lo->id);
                        $type = key($v);
                        $this->link($go1, EdgeTypes::HAS_SUGGESTED_COMPLETION, $sourceId, (int ) $targetId, 0, [
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

    public function testEnrolmentUpdate()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $id = $this->createEnrolment($go1, [
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => static::$portalId,
            'lo_id'             => static::$courseId1,
            'parent_lo_id'      => 0,
        ]);

        $enrolment = EnrolmentHelper::load($go1, $id);
        $enrolment->original = clone $enrolment;
        $enrolment->status = EnrolmentStatuses::IN_PROGRESS;
        $enrolment->original->status = EnrolmentStatuses::NOT_STARTED;

        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::ENROLMENT_UPDATE,
            'body'       => $enrolment,
        ]);

        $res = $app->handle($req);
        $this->assertEquals(JsonResponse::HTTP_NO_CONTENT, $res->getStatusCode());
        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_CREATE][0]);
        $this->assertEquals($this->userId, $plan->userId);
        $this->assertEquals(PlanTypes::ENTITY_LO, $plan->entityType);
        $this->assertEquals($enrolment->lo_id, $plan->entityId);
        $this->assertEquals(DateTime::formatDate(static::$course1TwentyDays . ' days', 'd/m/Y'), $plan->due->format('d/m/Y'));
    }
}
