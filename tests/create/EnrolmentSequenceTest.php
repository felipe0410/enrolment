<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentSequenceTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalName = 'az.mygo1.com';
    private $portalId;
    private $courseId;
    private $courseEnrolmentId;
    private $moduleIdA;
    private $moduleIdB;
    private $moduleIdC;
    private $moduleIdD;
    private $electiveModuleXId;
    private $electiveModuleYId;
    private $electiveModuleZId;
    private $mail       = 'student@go1.com';
    private $userId;
    private $accountId;
    private $jwt;
    private $adminJwt;
    private $profileId  = 1111;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);
        $this->createPortalPrivateKey($go1, ['instance' => $this->portalName]);

        $this->userId = $this->createUser($go1, ['mail' => $this->mail, 'instance' => $app['accounts_name'], 'profile_id' => $this->profileId]);
        $this->accountId = $this->createUser($go1, ['mail' => $this->mail, 'instance' => $this->portalName, 'profile_id' => 123]);
        $this->jwt = $this->getJwt($this->mail, $app['accounts_name'], $this->portalName, [], 123, $this->accountId, $this->profileId, $this->userId);
        $this->adminJwt = JWT::encode((array) $this->getAdminPayload($this->portalName), 'private_key', 'HS256');
        ;

        $data = json_encode(['elective_number' => 2, 'requiredSequence' => true]);
        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'data' => $data]);

        $this->moduleIdA = $this->createModule($go1, ['instance_id' => $this->portalId, 'remote_id' => 501]); // completed
        $this->electiveModuleXId = $this->createModule($go1, ['instance_id' => $this->portalId, 'remote_id' => 502]); // in-progress
        $this->moduleIdB = $this->createModule($go1, ['instance_id' => $this->portalId, 'remote_id' => 503]);
        $this->electiveModuleYId = $this->createModule($go1, ['instance_id' => $this->portalId, 'remote_id' => 504]);
        $this->moduleIdC = $this->createModule($go1, ['instance_id' => $this->portalId, 'remote_id' => 505]);
        $this->electiveModuleZId = $this->createModule($go1, ['instance_id' => $this->portalId, 'remote_id' => 506]);
        $this->moduleIdD = $this->createModule($go1, ['instance_id' => $this->portalId, 'remote_id' => 507]);

        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleIdA, 0);
        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleIdB, 2);
        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleIdC, 4);
        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleIdD, 6);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LO, $this->courseId, $this->electiveModuleXId, 1);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LO, $this->courseId, $this->electiveModuleYId, 3);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LO, $this->courseId, $this->electiveModuleZId, 4);

        $this->courseEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $this->courseId, 'taken_instance_id' => $this->portalId]);
        $this->createEnrolment($go1, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $this->moduleIdA, 'taken_instance_id' => $this->portalId, 'status' => 'completed']);
        $this->createEnrolment($go1, ['user_id' => $this->userId, 'profile_id' => $this->profileId, 'lo_id' => $this->electiveModuleXId, 'taken_instance_id' => $this->portalId]);
    }

    public function dataTest400()
    {
        $this->getApp();

        return [
            [$this->moduleIdC],
            [$this->moduleIdD],
        ];
    }

    /**
     * @dataProvider dataTest400
     */
    public function test400($loId)
    {
        $app = $this->getApp();

        $req = Request::create("/{$this->portalName}/{$this->courseId}/{$loId}/enrolment/in-progress", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'parentEnrolmentId' => $this->courseEnrolmentId]);

        $this->assertEquals(400, ($app->handle($req))->getStatusCode());
        $this->assertStringContainsString('Invalid enrolment - sequence order', ($app->handle($req))->getContent());
    }

    public function dataTest200()
    {
        $this->getApp();

        return [
            [$this->moduleIdB],
            [$this->electiveModuleXId],
            [$this->electiveModuleYId],
            [$this->electiveModuleZId],
        ];
    }

    /**
     * @dataProvider dataTest200
     */
    public function test200($loId)
    {
        $app = $this->getApp();

        $req = Request::create("/{$this->portalName}/{$this->courseId}/{$loId}/enrolment/in-progress", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'parentEnrolmentId' => $this->courseEnrolmentId]);
        $this->assertEquals(200, ($app->handle($req))->getStatusCode());
    }

    /**
     * Can't enroll into module if the previous one still in-progress
     */
    public function test400BrotherInProgress()
    {
        $app = $this->getApp();

        $req = Request::create("/{$this->portalName}/{$this->courseId}/{$this->moduleIdB}/enrolment/in-progress", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'parentEnrolmentId' => $this->courseEnrolmentId]);

        $this->assertEquals(200, ($app->handle($req))->getStatusCode());

        $req = Request::create("/{$this->portalName}/{$this->courseId}/{$this->moduleIdC}/enrolment/in-progress", 'POST');
        $req->query->replace(['jwt' => $this->jwt, 'parentEnrolmentId' => $this->courseEnrolmentId]);

        $this->assertEquals(400, ($app->handle($req))->getStatusCode());
        $this->assertStringContainsString('Invalid enrolment - sequence order', ($app->handle($req))->getContent());
    }
}
