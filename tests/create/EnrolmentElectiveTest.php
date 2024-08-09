<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentElectiveTest extends EnrolmentTestCase
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
    private $electiveModuleAId;
    private $electiveModuleBId;
    private $electiveModuleCId;
    private $liVideoId;
    private $mail       = 'student@go1.com';
    private $userId;
    private $accountId;
    private $enrolments;
    private $jwt;
    private $adminJwt;
    private $profileId  = 1111;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        /** @var Connection $db */
        $db = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->portalPublicKey = $this->createPortalPublicKey($db, ['instance' => $this->portalName]);
        $this->portalPrivateKey = $this->createPortalPrivateKey($db, ['instance' => $this->portalName]);
        $this->userId = $this->createUser($db, ['mail' => $this->mail, 'instance' => $app['accounts_name'], 'profile_id' => $this->profileId]);
        $this->accountId = $this->createUser($db, ['mail' => $this->mail, 'instance' => $this->portalName, 'profile_id' => 123]);
        $this->jwt = $this->getJwt($this->mail, $app['accounts_name'], $this->portalName, [], 123, $this->accountId, $this->profileId, $this->userId);
        $this->adminJwt = JWT::encode((array) $this->getAdminPayload($this->portalName), 'private_key', 'HS256');

        $data = json_encode(['elective_number' => 2]);
        $this->courseId = $this->createCourse($db, ['instance_id' => $this->portalId, 'data' => $data]);
        $this->moduleId = $this->createModule($db, ['instance_id' => $this->portalId]);
        $this->electiveModuleAId = $this->createModule($db, ['instance_id' => $this->portalId, 'remote_id' => 567]);
        $this->electiveModuleBId = $this->createModule($db, ['instance_id' => $this->portalId, 'remote_id' => 568]);
        $this->electiveModuleCId = $this->createModule($db, ['instance_id' => $this->portalId, 'remote_id' => 569]);
        $this->liVideoId = $this->createVideo($db, ['instance_id' => $this->portalId, 'remote_id' => 570]);

        $this->link($db, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId, 0);
        $this->link($db, EdgeTypes::HAS_ELECTIVE_LO, $this->courseId, $this->electiveModuleAId, 0);
        $this->link($db, EdgeTypes::HAS_ELECTIVE_LO, $this->courseId, $this->electiveModuleBId, 0);
        $this->link($db, EdgeTypes::HAS_ELECTIVE_LO, $this->courseId, $this->electiveModuleCId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->liVideoId, 0);

        $this->enrolments = [
            'course'  => $courseEnrolmentId = $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $this->courseId, 'taken_instance_id' => $this->portalId]),
            'module'  => $moduleEnrolmentId = $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $this->moduleId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $courseEnrolmentId]),
            'moduleA' => $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $this->electiveModuleAId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $courseEnrolmentId]),
            'moduleB' => $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $this->electiveModuleBId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $courseEnrolmentId]),
            'moduleC' => $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $this->electiveModuleCId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $courseEnrolmentId]),
            'video'   => $this->createEnrolment($db, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $this->liVideoId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $moduleEnrolmentId]),
        ];
    }

    public function testInProgress()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        // Complete non-elective module, course is in-progress
        $req = Request::create("/$this->portalName/$this->moduleId/$this->liVideoId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->status(json_decode($app->handle($req)->getContent())->id));
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->status($this->enrolments['module']));
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->status($this->enrolments['course']));
    }

    public function testElectiveInProgress()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        // Complete non-elective module, course is in-progress
        $req = Request::create("/$this->portalName/$this->moduleId/$this->liVideoId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->status(json_decode($app->handle($req)->getContent())->id));
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->status($this->enrolments['module']));

        // Complete 1 non-elective module, course is in-progress
        $req = Request::create("/enrolment/{$this->enrolments['moduleA']}", 'PUT', [
            'status' => EnrolmentStatuses::COMPLETED,
            'pass'   => 1,
            'end'    => (new \DateTime('now'))->format(DATE_ISO8601),
            'note'   => 'Manual completed by admin'
        ]);
        $req->query->set('jwt', $this->adminJwt);
        $this->assertEquals(204, ($app->handle($req))->getStatusCode());

        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->status($this->enrolments['moduleA']));
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->status($this->enrolments['course']));
    }

    public function testElectiveComplete()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        // Complete non-elective module, course is in-progress
        $req = Request::create("/$this->portalName/$this->moduleId/$this->liVideoId/enrolment/completed", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'reEnrol' => 1, 'parentEnrolmentId' => $this->enrolments['module']]);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->status(json_decode($app->handle($req)->getContent())->id));
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->status($this->enrolments['module']));

        // Complete 1 non-elective module, course is in-progress
        $req = Request::create("/enrolment/{$this->enrolments['moduleA']}", 'PUT', [
            'status' => EnrolmentStatuses::COMPLETED,
            'pass'   => 1,
            'end'    => (new \DateTime('now'))->format(DATE_ISO8601),
            'note'   => 'Manual completed by admin'
        ]);
        $req->query->set('jwt', $this->adminJwt);
        $this->assertEquals(204, ($app->handle($req))->getStatusCode());

        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->status($this->enrolments['moduleA']));
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $repository->status($this->enrolments['course']));

        // Complete 2 non-elective modules, course is completed
        $req = Request::create("/enrolment/{$this->enrolments['moduleB']}", 'PUT', [
            'status' => EnrolmentStatuses::COMPLETED,
            'pass'   => 1,
            'end'    => (new \DateTime('now'))->format(DATE_ISO8601),
            'note'   => 'Manual completed by admin'
        ]);
        $req->query->set('jwt', $this->adminJwt);
        $this->assertEquals(204, ($app->handle($req))->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->status($this->enrolments['moduleB']));
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $repository->status($this->enrolments['course']));
    }
}
