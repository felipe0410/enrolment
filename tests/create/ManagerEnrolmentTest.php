<?php

namespace go1\enrolment\tests\manager;

use Doctrine\DBAL\Schema\Schema;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\lo\LoHelper;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\GroupMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class ManagerEnrolmentTest extends EnrolmentTestCase
{
    use LoMockTrait;
    use PortalMockTrait;
    use UserMockTrait;
    use GroupMockTrait;

    protected $users      = [];
    protected $accounts   = [];
    protected $mails      = [
        'learner1' => 'learner1@go1.co',
        'learner2' => 'learner2@go1.co',
        'manager1' => 'manager1@go1.co',
        'manager2' => 'manager2@go1.co',
    ];
    protected $portalName = 'foo.mygo1.co';
    protected $courseId;
    protected $portalId;
    protected $manager1Jwt;
    protected $manager2Jwt;
    protected $enrolmentId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $go1 = $app['dbs']['go1'];
        $accountsName = $app['accounts_name'];
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);

        $this->users = [
            'manager' => [
                $this->createUser($go1, ['mail' => $this->mails['manager1'], 'instance' => $accountsName]),
                $this->createUser($go1, ['mail' => $this->mails['manager2'], 'instance' => $accountsName]),
            ],
            'learner' => [
                $this->createUser($go1, ['mail' => $this->mails['learner1'], 'instance' => $accountsName]),
                $this->createUser($go1, ['mail' => $this->mails['learner2'], 'instance' => $accountsName]),
            ],
        ];

        $this->accounts = [
            'manager' => [
                $this->createUser($go1, ['mail' => $this->mails['manager1'], 'instance' => $this->portalName]),
                $this->createUser($go1, ['mail' => $this->mails['manager2'], 'instance' => $this->portalName]),
            ],
            'learner' => [
                $this->createUser($go1, ['mail' => $this->mails['learner1'], 'instance' => $this->portalName]),
                $this->createUser($go1, ['mail' => $this->mails['learner2'], 'instance' => $this->portalName]),
            ],
        ];

        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->users['manager'][0], $this->accounts['manager'][0]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->users['manager'][1], $this->accounts['manager'][1]);

        $this->manager1Jwt = $this->jwtForUser($go1, $this->users['manager'][0], $this->portalName);
        $this->manager2Jwt = $this->jwtForUser($go1, $this->users['manager'][1], $this->portalName);
    }

    public function testCreate()
    {
        $app = $this->getApp();

        $go1 = $app['dbs']['go1'];
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->users['learner'][0], $this->accounts['learner'][0]);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $this->accounts['learner'][0], $this->users['manager'][0]);

        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/{$this->mails['learner1']}/in-progress?jwt={$this->manager1Jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->enrolmentId = json_decode($res->getContent())->id;

        return $app;
    }

    public function testCreateOnRestrictedCourses()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $go1 = $app['dbs']['go1'];
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->users['learner'][0], $this->accounts['learner'][0]);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $this->accounts['learner'][0], $this->users['manager'][0]);

        $enquiryCourseId = $this->createCourse($db, ['instance_id' => $this->portalId, 'data' => json_encode(['allow_enrolment' => LoHelper::ENROLMENT_ALLOW_DISABLE])]);

        $req = Request::create("/{$this->portalName}/0/{$enquiryCourseId}/enrolment/{$this->mails['learner1']}/in-progress?jwt={$this->manager1Jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->enrolmentId = json_decode($res->getContent())->id;

        $disabledCourseId = $this->createCourse($db, ['instance_id' => $this->portalId, 'data' => json_encode(['allow_enrolment' => LoHelper::ENROLMENT_ALLOW_ENQUIRY])]);
        $req = Request::create("/{$this->portalName}/0/{$disabledCourseId}/enrolment/{$this->mails['learner1']}/in-progress?jwt={$this->manager1Jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->enrolmentId = json_decode($res->getContent())->id;
    }

    public function testUserCantEnrolOtherOne()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->users['learner'][0], $this->accounts['learner'][0]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->users['learner'][1], $this->accounts['learner'][1]);

        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/{$this->mails['learner1']}/in-progress?jwt={$this->manager2Jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testCanView()
    {
        $app = $this->testCreate();

        $req = Request::create("/{$this->enrolmentId}?jwt={$this->manager1Jwt}");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testCantView()
    {
        $app = $this->testCreate();
        $req = Request::create("/{$this->enrolmentId}?jwt={$this->manager2Jwt}");
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testManagerCanEdit()
    {
        $app = $this->testCreate();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$this->manager1Jwt}", 'PUT', ['pass' => 1]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
    }

    public function testManagerCantEdit()
    {
        $app = $this->testCreate();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$this->manager2Jwt}", 'PUT', ['pass' => 1]);
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testManagerCanDelete()
    {
        $app = $this->testCreate();
        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$this->manager1Jwt}", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
    }

    public function testManagerCantDelete()
    {
        $app = $this->testCreate();

        $req = Request::create("/enrolment/{$this->enrolmentId}?jwt={$this->manager2Jwt}", 'DELETE');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }
}
