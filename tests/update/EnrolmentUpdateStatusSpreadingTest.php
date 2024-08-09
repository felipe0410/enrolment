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
use go1\util\lo\LoTypes;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentUpdateStatusSpreadingTest extends EnrolmentCreateSpreadingTest
{
    public $basicLiData;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $this->basicLiData = [
            'profile_id'          => $this->profileId,
            'user_id'             => $this->userId,
            'taken_instance_id'   => $this->portalId,
            'parent_enrolment_id' => $this->enrolments['module'],
            'parent_lo_id'        => $this->moduleId,
        ];

        $this->enrolments += [
            'video'    => $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $this->liVideoId]),
            'resource' => $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $this->liResourceId]),
            'question' => $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $this->electiveQuestionId]),
            'text'     => $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $this->electiveTextId]),
        ];
    }

    public function testWorkflowPassed()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Complete passed non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete passed non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete passed elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
        ]);

        // Complete passed elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
        ]);
    }

    public function testWorkflowNonElectiveFailed()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Complete failed non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete passed non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete passed elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);

        // Complete passed elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);
    }

    public function testWorkflowElectiveFailed()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Complete passed non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete passed non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete failed elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);

        // Complete failed elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);
    }

    public function testWorkflowFailedThenPassed()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Complete failed non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete failed non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete failed elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete failed elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);

        // Complete passed non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete passed non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete passed elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete passed elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
        ]);
    }

    public function testWorkflowPassedThenFailed()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Complete passed non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete passed non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete passed elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete passed elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
        ]);

        // Complete failed non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete failed non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete failed elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete failed elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);
    }

    public function testWorkflowInProgressThenCompleted()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // In progress non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'in-progress', 'pass' => 0]);
        $app->handle($req);

        // In progress non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'in-progress', 'pass' => 0]);
        $app->handle($req);

        // In progress elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'in-progress', 'pass' => 0]);
        $app->handle($req);

        // In progress elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'in-progress', 'pass' => 0]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete failed non-elective LI.resource
        $req = Request::create("/enrolment/{$this->enrolments['resource']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete failed non-elective LI.video
        $req = Request::create("/enrolment/{$this->enrolments['video']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete failed elective LI.question
        $req = Request::create("/enrolment/{$this->enrolments['question']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 0, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        // Complete failed elective LI.text
        $req = Request::create("/enrolment/{$this->enrolments['text']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['resource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['video'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['question'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['text'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);
    }

    public function testCourseEnrolmentWithMultipleAssessments()
    {
        $app = $this->getApp();

        $db = $app['dbs']['go1'];
        $interactiveId = $this->createLO($db, ['type' => LiTypes::INTERACTIVE, 'instance_id' => $this->portalId]);
        $quizId = $this->createLO($db, ['type' => LiTypes::QUIZ, 'instance_id' => $this->portalId]);
        $assignmentId = $this->createLO($db, ['type' => LiTypes::ASSIGNMENT, 'instance_id' => $this->portalId]);

        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $interactiveId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $quizId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $assignmentId, 0);

        $interactiveEnrolmentId = $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $interactiveId]);
        $quizEnrolmentId = $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $quizId]);
        $assignmentEnrolmentId = $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $assignmentId]);

        $expected = [
            [
                'id'     => $interactiveEnrolmentId,
                'lo_id'  => $interactiveId,
                'type'   => LiTypes::INTERACTIVE,
                'result' => 0,
                'pass'   => 0
            ],
            [
                'id'     => $quizEnrolmentId,
                'lo_id'  => $quizId,
                'type'   => LiTypes::QUIZ,
                'result' => 0,
                'pass'   => 0
            ],
            [
                'id'     => $assignmentEnrolmentId,
                'lo_id'  => $assignmentId,
                'type'   => LiTypes::ASSIGNMENT,
                'result' => 0,
                'pass'   => 0
            ],

        ];

        // Complete interactive
        $req = Request::create("/enrolment/{$interactiveEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_PUT);
        $req->request->replace([
            'status'  => EnrolmentStatuses::COMPLETED,
            'result'  => 99,
            'pass'    => 1,
            'endDate' => (new DateTime())->format(DATE_ISO8601)
        ]);
        $app->handle($req);

        $message = $this->queueMessages['enrolment.update'][0];
        $expected[0]['result'] = 99.0;
        $expected[0]['pass'] = 1;
        $this->assertEquals($expected, $message['assessments']);
        $this->assertEquals(0, $message['result']);

        // Complete quiz
        $this->queueMessages['enrolment.update'] = [];
        $req = Request::create("/enrolment/{$quizEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_PUT);
        $req->request->replace([
            'status'  => EnrolmentStatuses::COMPLETED,
            'result'  => 90,
            'pass'    => 1,
            'endDate' => (new DateTime())->format(DATE_ISO8601)
        ]);
        $app->handle($req);

        $message = $this->queueMessages['enrolment.update'][0];
        $expected[1]['result'] = 90.0;
        $expected[1]['pass'] = 1;
        $this->assertEquals($expected, $message['assessments']);
        $this->assertEquals(0, $message['result']);

        // Complete assignment
        $this->queueMessages['enrolment.update'] = [];
        $req = Request::create("/enrolment/{$assignmentEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_PUT);
        $req->request->replace([
            'status'  => EnrolmentStatuses::COMPLETED,
            'result'  => 95,
            'pass'    => 1,
            'endDate' => (new DateTime())->format(DATE_ISO8601)
        ]);
        $app->handle($req);

        $message = $this->queueMessages['enrolment.update'][0];
        $expected[2]['result'] = 95.0;
        $expected[2]['pass'] = 1;
        $this->assertEquals($expected, $message['assessments']);
        $this->assertEquals(0, $message['result']);
    }

    public function testCourseEnrolmentWithSingleAssessment()
    {
        $app = $this->getApp();

        $db = $app['dbs']['go1'];
        $interactiveId = $this->createLO($db, ['type' => LiTypes::INTERACTIVE, 'instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $interactiveId, 0);
        $interactiveEnrolmentId = $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $interactiveId]);

        // Complete interactive
        $req = Request::create("/enrolment/{$interactiveEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_PUT);
        $req->request->replace([
            'status'  => EnrolmentStatuses::COMPLETED,
            'result'  => 99,
            'pass'    => 1,
            'endDate' => (new DateTime())->format(DATE_ISO8601)
        ]);
        $app->handle($req);

        $message = $this->queueMessages['enrolment.update'][0];
        $this->assertEquals(99.0, $message['result']);
        $this->assertEquals(LoTypes::COURSE, $message['lo_type']);
        $this->assertTrue(empty($message['assessments']));
    }

    public function testCourseEnrolmentWithComplete()
    {
        $app = $this->getApp();

        $db = $app['dbs']['go1'];
        $interactiveId = $this->createLO($db, ['type' => LiTypes::INTERACTIVE, 'instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $interactiveId, 0);
        $interactiveEnrolmentId = $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $interactiveId]);

        $db->update('gc_enrolment', ['status' => EnrolmentStatuses::COMPLETED], ['id' => $this->enrolments['video']]);
        $db->update('gc_enrolment', ['status' => EnrolmentStatuses::COMPLETED], ['id' => $this->enrolments['resource']]);
        $db->update('gc_enrolment', ['status' => EnrolmentStatuses::COMPLETED], ['id' => $this->enrolments['question']]);

        $textId = $this->createLO($db, ['type' => LiTypes::TEXT, 'instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $textId, 0);
        $textEnrolmentId = $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $textId]);

        // Complete interactive
        $req = Request::create("/enrolment/{$interactiveEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_PUT);
        $req->request->replace([
            'status'  => EnrolmentStatuses::COMPLETED,
            'result'  => 99,
            'pass'    => 1,
            'endDate' => (new DateTime())->format(DATE_ISO8601)
        ]);
        $app->handle($req);

        $message = $this->queueMessages['enrolment.update'][0];
        $this->assertEquals(99.0, $message['result']);
        $this->assertEquals(LoTypes::COURSE, $message['lo_type']);
        $this->assertTrue(empty($message['assessments']));

        // Complete text
        $this->queueMessages = [];
        $req = Request::create("/enrolment/{$textEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_PUT);
        $req->request->replace([
            'status'  => EnrolmentStatuses::COMPLETED,
            'result'  => 99,
            'pass'    => 1,
            'endDate' => (new DateTime())->format(DATE_ISO8601)
        ]);
        $app->handle($req);

        $message = $this->queueMessages['enrolment.update'][1];
        $this->assertEquals(99.0, $message['result']);
        $this->assertEquals(LoTypes::COURSE, $message['lo_type']);
        $this->assertTrue(empty($message['assessments']));
    }

    public function testCourseEnrolmentWithMultipleAssessmentsAnd1Enrolment()
    {
        $app = $this->getApp();

        $db = $app['dbs']['go1'];
        $interactiveId = $this->createLO($db, ['type' => LiTypes::INTERACTIVE, 'instance_id' => $this->portalId]);
        $quizId = $this->createLO($db, ['type' => LiTypes::QUIZ, 'instance_id' => $this->portalId]);
        $assignmentId = $this->createLO($db, ['type' => LiTypes::ASSIGNMENT, 'instance_id' => $this->portalId]);

        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $interactiveId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $quizId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $assignmentId, 0);

        $interactiveEnrolmentId = $this->createEnrolment($db, $this->basicLiData + ['lo_id' => $interactiveId]);

        $expected = [
            [
                'id'     => $interactiveEnrolmentId,
                'lo_id'  => $interactiveId,
                'type'   => LiTypes::INTERACTIVE,
                'result' => 0,
                'pass'   => 0
            ],
            [
                'id'     => null,
                'lo_id'  => $quizId,
                'type'   => LiTypes::QUIZ,
                'result' => 0,
                'pass'   => 0
            ],
            [
                'id'     => null,
                'lo_id'  => $assignmentId,
                'type'   => LiTypes::ASSIGNMENT,
                'result' => 0,
                'pass'   => 0
            ],
        ];

        // Complete interactive
        $req = Request::create("/enrolment/{$interactiveEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_PUT);
        $req->request->replace([
            'status'  => EnrolmentStatuses::COMPLETED,
            'result'  => 99,
            'pass'    => 1,
            'endDate' => (new DateTime())->format(DATE_ISO8601)
        ]);
        $app->handle($req);

        $message = $this->queueMessages['enrolment.update'][0];
        $expected[0]['result'] = 99.0;
        $expected[0]['pass'] = 1;
        $this->assertEquals($expected, $message['assessments']);
        $this->assertEquals(0, $message['result']);
    }
}
