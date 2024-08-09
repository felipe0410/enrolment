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
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\HttpFoundation\Request;

class BulkCreateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $courseId;
    private $jwt;
    private $requesterId;

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

        $this->requesterId = $this->createUser($db, ['instance' => $app['accounts_name']]);
        $this->jwt = $this->getJwt(null, null, null, null, null, null, null, $this->requesterId);
    }

    public function testInvalidLO()
    {
        $app = $this->getApp();
        $req = Request::create("/manual-payment/bulk/1000/10/0?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'description' => 'I want to buy this course.',
        ]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $message = json_decode($res->getContent(), true);
        $this->assertEquals("Invalid learning object.", $message['error'][0]['message']);
        $this->assertEquals("Invalid manual payment learning object.", $message['error'][1]['message']);
    }

    public function testInvalidQuantity()
    {
        $app = $this->getApp();
        $req = Request::create("/manual-payment/bulk/{$this->courseId}/0/0?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'description' => 'I want to buy this course.',
        ]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $message = json_decode($res->getContent(), true);
        $this->assertEquals("Invalid quantity.", $message['error'][0]['message']);
    }

    public function testInvalidCreditType()
    {
        $app = $this->getApp();
        $req = Request::create("/manual-payment/bulk/{$this->courseId}/10/2?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'description' => 'I want to buy this course.',
        ]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $message = json_decode($res->getContent(), true);
        $this->assertEquals("Invalid credit type.", $message['error'][0]['message']);
    }

    public function test200()
    {
        $app = $this->getApp();
        $req = Request::create("/manual-payment/bulk/{$this->courseId}/10/0?jwt={$this->jwt}", 'POST');
        $req->request->replace(['description' => 'I want to buy this course.']);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $id = json_decode($res->getContent())->id;

        $go1 = $app['dbs']['go1'];
        $edge = EdgeHelper::load($go1, $id);
        $this->assertEquals(EdgeTypes::HAS_MANUAL_PAYMENT, $edge->type);
        $this->assertEquals($this->courseId, $edge->sourceId);
        $this->assertEquals(1, $edge->targetId);
        $this->assertEquals($this->requesterId, $edge->weight);
        $this->assertEquals(10, $edge->data->quantity);
        $this->assertEquals(0, $edge->data->credit_type);
        $this->assertEquals('I want to buy this course.', $edge->data->description);
    }

    public function testSubmitMore1Time()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $this->mockServices($app);

        // Create 5 times
        $req = Request::create("/manual-payment/bulk/{$this->courseId}/10/0?jwt={$this->jwt}", 'POST');
        $req->request->replace(['description' => 'I want to buy this course.']);

        $i = 0;
        while ($i < 5) {
            $res = $app->handle($req);

            $this->assertEquals(200, $res->getStatusCode());
            $id = json_decode($res->getContent())->id;
            $edge = EdgeHelper::load($go1, $id);
            $this->assertEquals(++$i, $edge->targetId);
        }

        // Accept the last manual payment
        $jwt = JWT::encode((array) $this->getPayload(['mail' => 'author@course.com']), 'INTERNAL', 'HS256');
        $req = Request::create("/manual-payment/bulk/{$id}/accept?jwt={$jwt}", 'POST');
        $req->request->replace(['ids' => [1, 2, 3]]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        // Create 5 times
        $jwt = $this->getJwt(null, null, null, null, null, null, null, 1000);
        $req = Request::create("/manual-payment/bulk/{$this->courseId}/5/0?jwt={$jwt}", 'POST');
        $req->request->replace([
            'description' => 'I want to buy this course.',
        ]);

        $j = 0;
        while ($j < 5) {
            $res = $app->handle($req);
            $this->assertEquals(200, $res->getStatusCode());
            $id = json_decode($res->getContent())->id;
            $edge = EdgeHelper::load($go1, $id);
            $this->assertEquals(++$i, $edge->targetId);
            $j++;
        }

        // Reject the last manual payment
        $jwt = JWT::encode((array) $this->getPayload(['mail' => 'author@course.com']), 'INTERNAL', 'HS256');
        $req = Request::create("/manual-payment/bulk/{$id}/reject?jwt={$jwt}", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
    }

    private function mockServices(DomainService $app)
    {
        $app->extend('client', function () use ($app) {
            $client = $this->getMockBuilder(Client::class)->setMethods(['post', 'put'])->getMock();

            $creditUrl = $app['credit_url'];
            $credits = [[
                            'transaction_id' => 100,
                        ]];
            $client
                ->expects($this->once())
                ->method('post')
                ->with("{$creditUrl}/purchase/qa.mygo1.com/lo/{$this->courseId}/10/0")
                ->willReturn(new Response(200, [], json_encode($credits)));

            $paymentUrl = $app['payment_url'];
            $client
                ->expects($this->once())
                ->method('put')
                ->with("{$paymentUrl}/transaction/100/complete")
                ->willReturn(new Response());

            return $client;
        });
    }
}
