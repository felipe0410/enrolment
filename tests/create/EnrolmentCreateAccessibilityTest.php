<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\controller\create\LoAccessClient;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\policy\Realm;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentCreateAccessibilityTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;

    private $portalName       = 'qa.mygo1.com';
    private $portalId;
    private $courseId;
    private $learnerJwt;
    private $learnerEmail     = 'learner@go1.com';
    private $learnerUserId;
    private $learnerAccountId;
    private $learnerProfileId = 11;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);
        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'price' => ['price' => 10]]);
        $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);
        $this->createPortalPrivateKey($go1, ['instance' => $this->portalName]);

        $this->learnerUserId = $this->createUser($go1, ['mail' => $this->learnerEmail, 'instance' => $app['accounts_name'], 'profile_id' => $this->learnerProfileId]);
        $this->learnerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'uuid' => 'USER_UUID', 'mail' => $this->learnerEmail]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->learnerUserId, $this->learnerAccountId);
        $this->learnerJwt = $this->jwtForUser($go1, $this->learnerUserId, $this->portalName);
    }

    protected function mockLoAccessClient(DomainService $app)
    {
        $app->extend(LoAccessClient::class, function () {
            $loAccessClient = $this
                ->getMockBuilder(LoAccessClient::class)
                ->disableOriginalConstructor()
                ->setMethods(['realm', 'setAuthorization'])
                ->getMock();

            $loAccessClient
                ->expects($this->any())
                ->method('realm')
                ->willReturnCallback(function (int $loId, int $userId = 0, int $portalId = 0): ?int {
                    $cacheId = "{$loId}:{$userId}:{$portalId}";
                    return $this->loAccessList[$cacheId] ?? $this->loAccessDefaultValue;
                });

            $loAccessClient
                ->expects($this->once())
                ->method('setAuthorization');

            return $loAccessClient;
        });
    }

    public function testNonePermission()
    {
        $app = $this->getApp();
        $this->loAccessGrant($this->courseId, $this->learnerUserId, $this->portalId, 0);
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/in-progress", 'POST');
        $req->query->replace(['jwt' => $this->learnerJwt]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('Learning Object is private', $res->getContent());
    }

    public function testCanView()
    {
        $app = $this->getApp();
        $this->loAccessGrant($this->courseId, $this->learnerUserId, $this->portalId, Realm::VIEW);
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/in-progress", 'POST');
        $req->query->replace(['jwt' => $this->learnerJwt]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('paymentMethod: Invalid payment method', $res->getContent());
    }

    public function testCanAccess()
    {
        $app = $this->getApp();
        $this->loAccessGrant($this->courseId, $this->learnerUserId, $this->portalId, Realm::ACCESS);
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/in-progress", 'POST');
        $req->query->replace(['jwt' => $this->learnerJwt]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $json = json_decode($res->getContent());
        $this->assertNotEmpty($json->id);
    }
}
