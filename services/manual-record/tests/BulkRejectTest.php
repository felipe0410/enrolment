<?php

namespace go1\core\learning_record\manual_record\tests;

use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class BulkRejectTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $courseId;
    private $roId;
    private $jwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $db = $app['dbs']['go1'];
        $portalId = $this->createPortal($db, ['title' => 'qa.mygo1.com']);

        $this->courseId = $this->createCourse($db, [
            'instance_id' => $portalId,
            'price'       => ['price' => 111.00, 'currency' => 'USD', 'tax' => 0.00],
            'data'        => json_encode(['manual_payment' => true, 'manual_payment_recipient' => 'author@course.com']),
        ]);

        $mqClient = $app['go1.client.mq'];
        $this->roId = EdgeHelper::link($db, $mqClient, EdgeTypes::HAS_MANUAL_PAYMENT, $this->courseId, 0, 91, [
            'quantity' => 10, 'description' => 'I want to buy this course.',
        ]);

        $this->jwt = JWT::encode((array) $this->getPayload(['mail' => 'author@course.com']), 'INTERNAL', 'HS256');
    }

    public function testInvalidLO()
    {
        $app = $this->getApp();
        $mqClient = $app['go1.client.mq'];
        $db = $app['dbs']['go1'];
        $roId = EdgeHelper::link($db, $mqClient, EdgeTypes::HAS_MANUAL_PAYMENT, 1000, 0, 91, [
            'quantity' => 10, 'description' => 'I want to buy this course.',
        ]);

        $req = Request::create("/manual-payment/bulk/$roId/reject?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $message = json_decode($res->getContent(), true);
        $this->assertEquals("Invalid manual payment learning object.", $message['error'][0]['message']);
    }

    public function testInvalidUser()
    {
        $app = $this->getApp();
        $req = Request::create("/manual-payment/bulk/{$this->roId}/reject?jwt={$this->getJwt()}", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $message = json_decode($res->getContent(), true);
        $this->assertEquals("Invalid user.", $message['error'][0]['message']);
    }

    public function testInvalidRO()
    {
        $app = $this->getApp();

        $db = $app['dbs']['go1'];
        $mqClient = $app['go1.client.mq'];
        $roId = EdgeHelper::link($db, $mqClient, EdgeTypes::HAS_ACCOUNT, $this->courseId, 0, 91, [
            'quantity' => 10, 'description' => 'I want to buy this course.',
        ]);
        $req = Request::create("/manual-payment/bulk/{$roId}/reject?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $message = json_decode($res->getContent(), true);
        $this->assertEquals("Invalid roId.", $message['error'][0]['message']);
    }

    public function test204()
    {
        $app = $this->getApp();
        $req = Request::create("/manual-payment/bulk/{$this->roId}/reject?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());

        $go1 = $app['dbs']['go1'];
        $edge = EdgeHelper::load($go1, $this->roId);
        $this->assertEquals(EdgeTypes::HAS_MANUAL_PAYMENT_REJECT, $edge->type);
        $this->assertEquals(1, $edge->data->log[0]->reject_bulk_manual);
    }
}
