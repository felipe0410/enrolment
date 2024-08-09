<?php

namespace go1\enrolment\tests\create;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LoStatuses;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentCreateSpreadingTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    protected $portalName = 'az.mygo1.com';
    protected $portalPublicKey;
    protected $portalPrivateKey;
    protected $portalId;
    protected $lpId;
    protected $courseId;
    protected $moduleId;
    protected $liVideoId;
    protected $liResourceId;
    protected $unpublishedLiId;
    protected $archivedLiId;
    protected $orphanLiId = 9999;
    protected $electiveQuestionId;
    protected $electiveTextId;
    protected $userId;
    protected $accountId;
    protected $enrolments;
    protected $jwt;
    protected $profileId  = 911;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Create instance
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->portalPublicKey = $this->createPortalPublicKey($db, ['instance' => $this->portalName]);
        $this->portalPrivateKey = $this->createPortalPrivateKey($db, ['instance' => $this->portalName]);
        $this->userId = $this->createUser($db, ['instance' => $app['accounts_name'], 'profile_id' => $this->profileId]);
        $this->accountId = $this->createUser($db, ['instance' => $this->portalName]);
        $this->jwt = $this->jwtForUser($db, $this->userId);

        $data = json_encode(['elective_number' => 1]);
        $this->lpId = $this->createCourse($db, ['type' => 'learning_pathway', 'instance_id' => $this->portalId]);
        $this->courseId = $this->createCourse($db, ['type' => 'course', 'instance_id' => $this->portalId]);
        $this->moduleId = $this->createModule($db, ['type' => 'module', 'instance_id' => $this->portalId, 'data' => $data]);
        $this->liVideoId = $this->createVideo($db, ['instance_id' => $this->portalId]);
        $this->liResourceId = $this->createLO($db, ['type' => 'iframe', 'instance_id' => $this->portalId]);
        $this->electiveQuestionId = $this->createLO($db, ['type' => 'question', 'instance_id' => $this->portalId]);
        $this->electiveTextId = $this->createLO($db, ['type' => 'text', 'instance_id' => $this->portalId]);

        // Linking
        $this->link($db, EdgeTypes::HAS_LP_ITEM, $this->lpId, $this->courseId, 0);
        $this->link($db, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->liVideoId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->liResourceId, 0);
        $this->link($db, EdgeTypes::HAS_ELECTIVE_LI, $this->moduleId, $this->electiveQuestionId, 0);
        $this->link($db, EdgeTypes::HAS_ELECTIVE_LI, $this->moduleId, $this->electiveTextId, 0);

        $baseData = [
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
        ];
        $this->enrolments['lp'] = $this->createEnrolment($db, $baseData + ['lo_id' => $this->lpId]);
        $this->enrolments['course'] = $this->createEnrolment($db, $baseData + ['lo_id' => $this->courseId, 'parent_enrolment_id' => $this->enrolments['lp']]);
        $this->enrolments['module'] = $this->createEnrolment($db, $baseData + ['lo_id' => $this->moduleId, 'parent_enrolment_id' => $this->enrolments['course']]);
    }

    public function testWorkflow()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Complete LI.video enrolment, the module enrolment should still be in-progress.
        $req = Request::create("/$this->portalName/$this->moduleId/$this->liVideoId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $res = $app->handle($req);
        $body = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $videoEnrolmentId = $body->id;
        $this->assertEnrolments($repository, $db, [
            ['id' => $videoEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete LI.resource enrolment, the module enrolment should still be in-progress.
        $req = Request::create("/$this->portalName/$this->moduleId/$this->liResourceId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $resourceEnrolmentId = json_decode($app->handle($req)->getContent())->id;
        $this->assertEnrolments($repository, $db, [
            ['id' => $videoEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $resourceEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete LI.question enrolment, enrolment status of module, course, learning pathway should be completed too.
        $req = Request::create("/$this->portalName/$this->moduleId/$this->electiveQuestionId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $res = $app->handle($req);
        $body = json_decode($res->getContent());
        $questionEnrolmentId = $body->id;
        $this->assertEnrolments($repository, $db, [
            ['id' => $videoEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $resourceEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $questionEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);

        // Complete LI.text enrolment.
        $req = Request::create("/$this->portalName/$this->moduleId/$this->electiveTextId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $textEnrolmentId = json_decode($app->handle($req)->getContent())->id;
        $this->assertEnrolments($repository, $db, [
            ['id' => $videoEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $resourceEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $questionEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $textEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);
    }

    public function testCompletionWithInActiveLis()
    {
        $app = $this->getApp();
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $this->unpublishedLiId = $this->createCourse($db, ['type' => 'quiz', 'instance_id' => $this->portalId, 'published' => LoStatuses::UNPUBLISHED]);
        $this->archivedLiId = $this->createCourse($db, ['type' => 'scorm', 'instance_id' => $this->portalId, 'published' => LoStatuses::ARCHIVED]);

        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->unpublishedLiId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->archivedLiId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->orphanLiId, 0);

        // Complete LI.video enrolment, the module enrolment should still be in-progress.
        $req = Request::create("/$this->portalName/$this->moduleId/$this->liVideoId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $videoEnrolmentId = json_decode($app->handle($req)->getContent())->id;
        $this->assertEnrolments($repository, $db, [
            ['id' => $videoEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete LI.resource enrolment, the module enrolment should still be in-progress.
        $req = Request::create("/$this->portalName/$this->moduleId/$this->liResourceId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $resourceEnrolmentId = json_decode($app->handle($req)->getContent())->id;
        $this->assertEnrolments($repository, $db, [
            ['id' => $videoEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $resourceEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Complete LI.question enrolment, enrolment status of module, course, learning pathway should be completed too.
        $req = Request::create("/$this->portalName/$this->moduleId/$this->electiveQuestionId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $questionEnrolmentId = json_decode($app->handle($req)->getContent())->id;
        $this->assertEnrolments($repository, $db, [
            ['id' => $videoEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $resourceEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $questionEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);

        // Complete LI.text enrolment.
        $req = Request::create("/$this->portalName/$this->moduleId/$this->electiveTextId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $textEnrolmentId = json_decode($app->handle($req)->getContent())->id;
        $this->assertEnrolments($repository, $db, [
            ['id' => $videoEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $resourceEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $questionEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $textEnrolmentId, 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
            ['id' => $this->enrolments['lp'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 0],
        ]);
    }

    protected function assertEnrolments($repository, $db, $enrolments)
    {
        foreach ($enrolments as $enrolment) {
            $this->assertEquals($enrolment['status'], $repository->status($enrolment['id']));
            $this->assertEquals($enrolment['pass'], $repository->pass($enrolment['id']));
            $endDate = $db->fetchColumn('SELECT end_date FROM gc_enrolment WHERE id = ?', [$enrolment['id']]);
            if (EnrolmentStatuses::COMPLETED === $enrolment['status']) {
                $this->assertNotEmpty($endDate);
                $this->assertLessThanOrEqual((new DateTime())->format(DATE_ISO8601), $endDate);
            } else {
                $this->assertEmpty($endDate);
            }
        }
    }
}
