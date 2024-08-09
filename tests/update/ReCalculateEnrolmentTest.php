<?php

namespace go1\enrolment\tests\update;

use DateTime;
use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class ReCalculateEnrolmentTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalName = 'az.mygo1.com';
    private $portalPublicKey;
    private $portalPrivateKey;
    private $portalId;
    private $courseId;
    private $moduleId;
    private $liVideoId;
    private $liResourceId;
    private $electiveQuestionId;
    private $electiveTextId;
    private $enrolments;

    private $learnerProfileId        = 999;
    private $learner                 = 'learner@go1.sites';
    private $admin                   = 'portal.admin@go1.sites';
    private $manager                 = 'manager@go1.sites';
    private $courseAuthor            = 'author.course@go1.sites';
    private $courseAssessor          = 'assessor.course@go1.sites';
    private $courseEnrolmentAssessor = 'assessor.enrolment.course@go1.sites';
    private $liAuthor                = 'author.li@go1.sites';
    private $liAssessor              = 'assessor.li@go1.sites';
    private $liEnrolmentAssessor     = 'assessor.enrolment.li@go1.sites';
    private $learnerJWT;
    private $adminJWT;
    private $managerJWT;
    private $courseAuthorJWT;
    private $courseAssessorJWT;
    private $courseEnrolmentAssessorJWT;
    private $liAuthorJWT;
    private $liAssessorJWT;
    private $liEnrolmentAssessorJWT;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        $go1 = $app['dbs']['go1'];

        // Create instance
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->portalPublicKey = $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);
        $this->portalPrivateKey = $this->createPortalPrivateKey($go1, ['instance' => $this->portalName]);

        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->moduleId = $this->createModule($go1, ['instance_id' => $this->portalId, 'data' => json_encode(['elective_number' => 1])]);
        $this->liVideoId = $this->createVideo($go1, ['instance_id' => $this->portalId]);
        $this->liResourceId = $this->createLO($go1, ['type' => 'iframe', 'instance_id' => $this->portalId]);
        $this->electiveQuestionId = $this->createLO($go1, ['type' => 'question', 'instance_id' => $this->portalId]);
        $this->electiveTextId = $this->createLO($go1, ['type' => 'text', 'instance_id' => $this->portalId]);

        // Linking
        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId, 0);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleId, $this->liVideoId, 0);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleId, $this->liResourceId, 1);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LI, $this->moduleId, $this->electiveQuestionId, 0);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LI, $this->moduleId, $this->electiveTextId, 1);

        // Create portal admin & manager & author & assessor & enrolment assessor & learner
        $adminUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->admin, 'profile_id' => 10]);
        $adminAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->admin, 'profile_id' => 1]);
        $managerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->manager, 'profile_id' => 20]);
        $managerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->manager, 'profile_id' => 2]);
        $courseAuthorUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->courseAuthor, 'profile_id' => 30]);
        $courseAuthorAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->courseAuthor, 'profile_id' => 3]);
        $courseAssessorUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->courseAssessor, 'profile_id' => 40]);
        $courseAssessorAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->courseAssessor, 'profile_id' => 4]);
        $courseEnrolmentAssessorUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->courseEnrolmentAssessor, 'profile_id' => 50]);
        $courseEnrolmentAssessorAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->courseEnrolmentAssessor, 'profile_id' => 5]);
        $liAuthorUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->liAuthor, 'profile_id' => 60]);
        $liAuthorAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->liAuthor, 'profile_id' => 6]);
        $liAssessorUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->liAssessor, 'profile_id' => 70]);
        $liAssessorAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->liAssessor, 'profile_id' => 7]);
        $liEnrolmentAssessorUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->liEnrolmentAssessor, 'profile_id' => 80]);
        $liEnrolmentAssessorAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->liEnrolmentAssessor, 'profile_id' => 8]);
        $learnerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->learner, 'profile_id' => $this->learnerProfileId]);
        $learnerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->learner, 'profile_id' => 99, 'uuid' => 'USER_UUID']);

        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $adminUserId, $adminAccountId);
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $this->portalName]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $managerUserId, $managerAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $courseAuthorUserId, $courseAuthorAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $courseAssessorUserId, $courseAssessorAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $courseEnrolmentAssessorUserId, $courseEnrolmentAssessorAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $liAuthorUserId, $liAuthorAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $liAssessorUserId, $liAssessorAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $liEnrolmentAssessorUserId, $liEnrolmentAssessorAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $learnerUserId, $learnerAccountId);

        $this->link($go1, EdgeTypes::HAS_MANAGER, $learnerAccountId, $managerUserId);
        $this->link($go1, EdgeTypes::HAS_AUTHOR_EDGE, $this->courseId, $courseAuthorUserId);
        $this->link($go1, EdgeTypes::COURSE_ASSESSOR, $this->courseId, $courseAssessorUserId);
        $this->link($go1, EdgeTypes::HAS_TUTOR_ENROLMENT_EDGE, $courseEnrolmentAssessorUserId, $this->courseId);
        $this->link($go1, EdgeTypes::HAS_AUTHOR_EDGE, $this->electiveQuestionId, $liAuthorUserId);
        $this->link($go1, EdgeTypes::COURSE_ASSESSOR, $this->electiveQuestionId, $liAssessorUserId);
        $this->link($go1, EdgeTypes::HAS_TUTOR_ENROLMENT_EDGE, $liEnrolmentAssessorUserId, $this->electiveQuestionId);

        $baseData = [
            'profile_id'        => $this->learnerProfileId,
            'user_id'           => $learnerUserId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::COMPLETED,
            'pass'              => 1,
            'start_date'        => (new DateTime('-1 week'))->format(DATE_ISO8601),
            'end_date'          => (new DateTime('-1 day'))->format(DATE_ISO8601),
        ];
        $this->enrolments['course'] = $this->createEnrolment($go1, $baseData + ['lo_id' => $this->courseId]);
        $this->enrolments['module'] = $this->createEnrolment($go1, $baseData + ['lo_id' => $this->moduleId, 'parent_enrolment_id' => $this->enrolments['course']]);
        $this->enrolments += [
            'liVideo'            => $this->createEnrolment($go1, $baseData + ['lo_id' => $this->liVideoId, 'parent_enrolment_id' => $this->enrolments['module']]),
            'liResource'         => $this->createEnrolment($go1, $baseData + ['lo_id' => $this->liResourceId, 'parent_enrolment_id' => $this->enrolments['module']]),
            'liElectiveQuestion' => $this->createEnrolment($go1, $baseData + ['lo_id' => $this->electiveQuestionId, 'parent_enrolment_id' => $this->enrolments['module']]),
        ];


        $this->adminJWT = $this->jwtForUser($go1, $adminUserId, $this->portalName);
        $this->managerJWT = $this->jwtForUser($go1, $managerUserId, $this->portalName);
        $this->courseAuthorJWT = $this->jwtForUser($go1, $courseAuthorUserId, $this->portalName);
        $this->courseAssessorJWT = $this->jwtForUser($go1, $courseAssessorUserId, $this->portalName);
        $this->courseEnrolmentAssessorJWT = $this->jwtForUser($go1, $courseEnrolmentAssessorUserId, $this->portalName);
        $this->liAuthorJWT = $this->jwtForUser($go1, $liAuthorUserId, $this->portalName);
        $this->liAssessorJWT = $this->jwtForUser($go1, $liAssessorUserId, $this->portalName);
        $this->liEnrolmentAssessorJWT = $this->jwtForUser($go1, $liEnrolmentAssessorUserId, $this->portalName);
        $this->learnerJWT = $this->jwtForUser($go1, $learnerUserId, $this->portalName);
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
                // @TODO need confirming this logic with mr.DP
                // $this->assertEmpty($endDate);
            }
        }
    }

    public function data204()
    {
        $this->getApp();

        return [
            [UserHelper::ROOT_JWT],
            [$this->adminJWT],
            [$this->managerJWT],
            [$this->courseAuthorJWT],
            [$this->courseAssessorJWT],
            [$this->courseEnrolmentAssessorJWT],
            [$this->liAuthorJWT],
            [$this->liAssessorJWT],
            [$this->liEnrolmentAssessorJWT],
        ];
    }

    /** @dataProvider data204 */
    public function testWorkflow($reCalculateJWT)
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Change li.elective.question enrolment status from COMPLETED to IN-PROGRESS
        $req = Request::create("/enrolment/{$this->enrolments['liElectiveQuestion']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['liElectiveQuestion'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['liResource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['liVideo'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
        ]);

        // Re-calculate li enrolment progress
        $req = Request::create("/enrolment/re-calculate/{$this->enrolments['liElectiveQuestion']}?jwt={$reCalculateJWT}", 'POST');
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['liElectiveQuestion'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['liResource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['liVideo'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Re-complete an elective LI.question => automatically complete a parent module and course
        $req = Request::create("/enrolment/{$this->enrolments['liElectiveQuestion']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['liElectiveQuestion'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['liResource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['liVideo'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
        ]);
    }

    public function dataAnotherLoEnrolment()
    {
        $this->getApp();

        return [
            [$this->enrolments['liVideo']],
            // @TODO need upgrading EnrolmentRepository::childrenCompleted logic, calculating on children LOs
            // [$this->enrolments['module']],
            // [$this->enrolments['course']],
        ];
    }

    /** @dataProvider dataAnotherLoEnrolment */
    public function testWorkflowReCalculateOnParentLo($loEnrolmentId)
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        // Change li.elective.question enrolment status from COMPLETED to IN-PROGRESS
        $req = Request::create("/enrolment/{$this->enrolments['liElectiveQuestion']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['liElectiveQuestion'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['liResource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['liVideo'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
        ]);

        // Re-calculate li enrolment progress
        $req = Request::create("/enrolment/re-calculate/{$loEnrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['liElectiveQuestion'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['liResource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['liVideo'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::IN_PROGRESS, 'pass' => 0],
        ]);

        // Re-complete an elective LI.question => automatically complete a parent module and course
        $req = Request::create("/enrolment/{$this->enrolments['liElectiveQuestion']}?jwt=" . UserHelper::ROOT_JWT, 'PUT');
        $req->request->replace(['status' => 'completed', 'pass' => 1, 'endDate' => (new DateTime())->format(DATE_ISO8601)]);
        $app->handle($req);

        $this->assertEnrolments($repository, $db, [
            ['id' => $this->enrolments['liElectiveQuestion'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['liResource'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['liVideo'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['module'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
            ['id' => $this->enrolments['course'], 'status' => EnrolmentStatuses::COMPLETED, 'pass' => 1],
        ]);
    }

    public function testRecalculateBadRequest()
    {
        $app = $this->getApp();
        // Re-calculate li enrolment progress
        $req = Request::create("/enrolment/re-calculate/{$this->enrolments['liElectiveQuestion']}?jwt=" . UserHelper::ROOT_JWT, 'POST', [
            'membership' => '9999999999',
        ]);
        $app->handle($req);
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }

    public function testRecalculateEnrolmentNotFound()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/re-calculate/{$this->enrolments['liElectiveQuestion']}999?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $app->handle($req);
        $res = $app->handle($req);
        $this->assertEquals(404, $res->getStatusCode());
    }
}
