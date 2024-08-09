<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\policy\Realm;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentCreateControllerAccessTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;

    protected $loAccessDefaultValue = Realm::ACCESS;
    private $portalName           = 'az.mygo1.com';
    private $portalPublicKey;
    private $portalId;
    private $studentUserId;
    private $studentMail          = 'foo@bar.com';
    private $managerMail          = 'manager@foo.com';
    private $assessorMail         = 'assessor@foo.com';
    private $authorMail           = 'author@foo.com';
    private $courseId;
    private $moduleId;
    private $liId;
    private $studentJwt;
    private $managerJwt;
    private $assessorJwt;
    private $authorJwt;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $db */
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $db = $app['dbs']['go1'];

        // Create instance
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->portalPublicKey = $this->createPortalPublicKey($db, ['instance' => $this->portalName]);
        $this->courseId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $this->moduleId = $this->createModule($db, ['instance_id' => $this->portalId]);
        $this->liId = $this->createVideo($db, ['instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
        $this->link($db, EdgeTypes::HAS_LI, $this->moduleId, $this->liId);

        // Create student & manager & assessor
        $this->studentUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->studentMail, 'profile_id' => 42]);
        $studentAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => $this->studentMail, 'uuid' => 'USER_UUID', 'profile_id' => 42]);
        $managerUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->managerMail, 'profile_id' => 43]);
        $managerAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => $this->managerMail, 'profile_id' => 43]);
        $assessorUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->assessorMail, 'profile_id' => 44]);
        $assessorAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => $this->assessorMail, 'profile_id' => 44]);
        $authorUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->authorMail, 'profile_id' => 45]);
        $authorAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => $this->authorMail, 'profile_id' => 45]);

        $this->link($db, EdgeTypes::HAS_MANAGER, $studentAccountId, $managerUserId, 0);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $studentAccountId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $managerUserId, $managerAccountId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $assessorUserId, $assessorAccountId);
        $this->link($db, EdgeTypes::COURSE_ASSESSOR, $this->courseId, $assessorUserId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $authorUserId, $authorAccountId);
        $this->link($db, EdgeTypes::HAS_AUTHOR_EDGE, $this->courseId, $authorUserId);
        $this->studentJwt = $this->jwtForUser($db, $this->studentUserId, $this->portalName);
        $this->managerJwt = $this->jwtForUser($db, $managerUserId, $this->portalName);
        $this->assessorJwt = $this->jwtForUser($db, $assessorUserId, $this->portalName);
        $this->authorJwt = $this->jwtForUser($db, $authorUserId, $this->portalName);
    }

    public function data()
    {
        $this->getApp();

        return [
            [$this->studentJwt, 403],
            [$this->managerJwt, 200],
            [$this->assessorJwt, 200],
            [$this->authorJwt, 200],
            [UserHelper::ROOT_JWT, 200],
        ];
    }

    /** @dataProvider data */
    public function test($jwt, $expecting)
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/{$this->studentMail}/in-progress?jwt={$jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals($expecting, $res->getStatusCode());

        if (200 == $res->getStatusCode()) {
            $id = json_decode($res->getContent())->id;
            $req = Request::create("/{$id}?jwt={$this->studentJwt}");
            $res = $app->handle($req);
            $enrolment = json_decode($res->getContent());

            $this->assertEquals(200, $res->getStatusCode());
            $this->assertEquals('in-progress', $enrolment->status);
            $this->assertEquals(42, $enrolment->profile_id);
            $this->assertEquals($this->studentUserId, $enrolment->user_id);
            $this->assertEquals($this->courseId, $enrolment->lo_id);
            $this->assertEquals($this->portalId, $enrolment->taken_instance_id);

            $req = Request::create("/{$this->portalName}/{$this->courseId}/{$this->moduleId}/enrolment/{$this->studentMail}/in-progress?jwt={$jwt}&parentEnrolmentId=$enrolment->id", 'POST');
            $res = $app->handle($req);
            $this->assertEquals($expecting, $res->getStatusCode());
            $moduleEnrolment = json_decode($res->getContent());

            $req = Request::create("/{$this->portalName}/{$this->moduleId}/{$this->liId}/enrolment/{$this->studentMail}/in-progress?jwt={$jwt}&parentEnrolmentId=$moduleEnrolment->id", 'POST');
            $res = $app->handle($req);
            $this->assertEquals($expecting, $res->getStatusCode());
        }
    }

    public function testEventInstructorCanEnrolment()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $this->link(
            $db,
            EdgeTypes::HAS_ACCOUNT,
            $instructorUser = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $mail = 'instructor@go1.com']),
            $instructor = $this->createUser($db, ['mail' => $mail, 'instance' => $this->portalName])
        );

        $leanerProfile = 1007;
        $this->link(
            $db,
            EdgeTypes::HAS_ACCOUNT,
            $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $mail = 'learnx@go1.com', 'profile_id' => $leanerProfile]),
            $leaner = $this->createUser($db, ['mail' => $mail, 'instance' => $this->portalName])
        );

        $instructorJwt = $this->jwtForUser($db, $instructorUser, $this->portalName);
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/{$this->studentMail}/in-progress?jwt={$instructorJwt}", 'POST');
        $key = 'secret';
        $internalData = JWT::encode(['is_instructor' => true], $key, 'HS256');
        $req->headers->set('JWT-Private-Key', $key);
        $req->request->replace(['internal_data' => $internalData]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $courseEnrolment = json_decode($res->getContent());

        $req = Request::create("/{$this->portalName}/{$this->courseId}/{$this->moduleId}/enrolment/{$this->studentMail}/in-progress?jwt={$instructorJwt}&parentEnrolmentId=$courseEnrolment->id", 'POST');
        $req->headers->set('JWT-Private-Key', $key);
        $req->request->replace(['internal_data' => $internalData]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $moduleEnrolment = json_decode($res->getContent());

        $req = Request::create("/{$this->portalName}/{$this->moduleId}/{$this->liId}/enrolment/{$this->studentMail}/in-progress?jwt={$instructorJwt}&parentEnrolmentId=$moduleEnrolment->id", 'POST');
        $req->headers->set('JWT-Private-Key', $key);
        $req->request->replace(['internal_data' => $internalData]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }
}
