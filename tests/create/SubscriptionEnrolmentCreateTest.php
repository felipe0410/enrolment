<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class SubscriptionEnrolmentCreateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $portalName = 'qa.mygo1.com';
    private $courseId;
    private $userId;
    private $accountId;
    private $jwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $db = $app['dbs']['go1'];
        $portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->createPortalPublicKey($db, ['instance' => $this->portalName]);

        $this->courseId = $this->createCourse($db, [
            'instance_id' => $portalId,
            'price'       => ['price' => 111.00, 'currency' => 'USD', 'tax' => 0.00],
            'data'        => json_encode([
                'manual_payment' => true,
                'manual_payment_recipient' => 'author@course.com'
            ]),
        ]);

        $this->userId = $this->createUser($db, ['mail' => 'user@mail.com', 'instance' => $app['accounts_name'], 'profile_id' => 1111]);
        $this->accountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'user@mail.com', 'profile_id' => 1122]);

        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);

        $this->jwt = $this->jwtForUser($db, $this->userId, $this->portalName);
    }

    public function testHavingASubscriptionIgnoresPayment()
    {
        $app = $this->getApp([
            'status' => 'OK'
        ]);
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/pending?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $app->terminate($req, $res);
        $this->assertEquals($res->getStatusCode(), 200);
    }

    public function testHavingExpiredSubscriptionDoesntWork()
    {
        $app = $this->getApp([
            'hasLicense' => false
        ]);
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/pending?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $app->terminate($req, $res);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals(
            '{"message":"The following 1 assertions failed:\n1) paymentMethod: Invalid payment method\n","error":[{"path":"paymentMethod","message":"Invalid payment method"}]}',
            $res->getContent()
        );
    }

    public function testHavingNoSubscriptionDoesntWork()
    {
        $app = $this->getApp([]);
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/pending?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $app->terminate($req, $res);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals(
            '{"message":"The following 1 assertions failed:\n1) paymentMethod: Invalid payment method\n","error":[{"path":"paymentMethod","message":"Invalid payment method"}]}',
            $res->getContent()
        );
    }
}
