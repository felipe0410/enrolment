<?php

namespace go1\enrolment\tests\update;

use DateTime;
use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\create\EnrolmentCreateSpreadingTest;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentEventSpreadingTest extends EnrolmentCreateSpreadingTest
{
    private $events;
    private $eventCourseId;

    public function testModuleWithEvents()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);
        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);
        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);
        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);

        foreach ($this->events as $eventId) {
            $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $eventId);
            $this->enrolments['events'][] = $this->createEnrolment(
                $db,
                [
                    'profile_id'          => $this->profileId,
                    'user_id'             => $this->userId,
                    'lo_id'               => $eventId,
                    'taken_instance_id'   => $this->portalId,
                    'parent_enrolment_id' => $this->enrolments['module'],
                ]
            );
        }

        // Complete a non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ]
        );

        // Complete a non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ]
        );

        // Complete an elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ]
        );

        // Complete an elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ]
        );

        // Complete an event
        $req = Request::create("/enrolment/{$this->enrolments['events'][0]}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['events'][0], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['events'][1], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['events'][2], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['events'][3], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ]
        );

        // Complete all remaining events
        for ($i = 1; $i < 4; $i++) {
            $req = Request::create("/enrolment/{$this->enrolments['events'][$i]}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
            $req->request->replace(
                ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
            );
            $app->handle($req);
        }
        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['events'][0], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['events'][1], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['events'][2], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['events'][3], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ]
        );

        // not attended an event
        $req = Request::create("/enrolment/{$this->enrolments['events'][0]}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);
        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['events'][0], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
                ['id' => $this->enrolments['events'][1], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['events'][2], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['events'][3], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ]
        );
    }

    public function testCourseWithEvents()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);
        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);
        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);
        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);

        foreach ($this->events as $eventId) {
            $this->link($db, EdgeTypes::HAS_LI, $this->courseId, $eventId);
            $this->enrolments['events'][] = $this->createEnrolment($db, [
                'profile_id'          => $this->profileId,
                'user_id'             => $this->userId,
                'lo_id'               => $eventId,
                'parent_enrolment_id' => $this->enrolments['course'],
                'taken_instance_id'   => $this->portalId,
            ]);
        }

        // Complete passed non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ]
        );

        // Complete passed non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ]
        );

        // Complete passed elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ]
        );

        // Complete passed elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
                ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ]
        );

        // Complete passed event LI.event
        $req = Request::create("/enrolment/{$this->enrolments['events'][0]}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
                ['id' => $this->enrolments['events'][0], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ]
        );
    }

    public function testCourseWithEventsOnly()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);
        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);
        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);
        $this->events[] = $this->createLO($db, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);

        $enrolmentId = $this->createEnrolment($db, [
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'lo_id'             => $this->eventCourseId,
            'taken_instance_id' => $this->portalId,
        ]);

        foreach ($this->events as $eventId) {
            $this->link($db, EdgeTypes::HAS_LI, $this->eventCourseId, $eventId);
            $this->enrolments['events'][] = $this->createEnrolment($db, [
                'profile_id'          => $this->profileId,
                'user_id'             => $this->userId,
                'lo_id'               => $eventId,
                'taken_instance_id'   => $this->portalId,
                'parent_enrolment_id' => $enrolmentId,
            ]);
        }

        // Complete passed non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['events'][0]}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(
            ['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]
        );
        $app->handle($req);

        $this->assertEnrolments(
            $repository,
            $db,
            [
                ['id' => $enrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ]
        );
    }

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $basicLiData = [
            'profile_id'          => $this->profileId,
            'user_id'             => $this->userId,
            'taken_instance_id'   => $this->portalId,
            'parent_enrolment_id' => $this->enrolments['module'],
        ];

        $this->enrolments += [
            'video'    => $this->createEnrolment($db, $basicLiData + ['lo_id' => $this->liVideoId]),
            'resource' => $this->createEnrolment($db, $basicLiData + ['lo_id' => $this->liResourceId]),
            'question' => $this->createEnrolment($db, $basicLiData + ['lo_id' => $this->electiveQuestionId]),
            'text'     => $this->createEnrolment($db, $basicLiData + ['lo_id' => $this->electiveTextId]),
        ];

        $this->eventCourseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
    }
}
