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

class BulkAcceptTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $adminUserId = 1;
    private $requesterUserId;
    private $courseId;
    private $roId;
    private $jwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $db = $app['dbs']['go1'];
        $portalId = $this->createPortal($db, ['title' => 'qa.mygo1.com']);
        $this->createUser($db, ['id' => $this->adminUserId, 'mail' => 'author@course.com']);
        $this->requesterUserId = $this->createUser($db, ['instance' => $app['accounts_name']]);

        $this->courseId = $this->createCourse($db, [
            'instance_id' => $portalId,
            'price'       => ['price' => 111.00, 'currency' => 'USD', 'tax' => 0.00],
            'data'        => json_encode(['manual_payment' => true, 'manual_payment_recipient' => 'author@course.com']),
        ]);

        $mqClient = $app['go1.client.mq'];
        $this->roId = EdgeHelper::link($db, $mqClient, EdgeTypes::HAS_MANUAL_PAYMENT, $this->courseId, 0, $this->requesterUserId, [
            'quantity'    => 10,
            'description' => 'I want to buy this course.',
            'credit_type' => 1,
        ]);

        $this->jwt = JWT::encode((array) $this->getPayload(['user_id' => $this->adminUserId, 'mail' => 'author@course.com']), 'INTERNAL', 'HS256');
    }

    private function mockServices(DomainService $app, $jwt, $productType, $productId, $creditType, $creditQuantity)
    {
        $app->extend('client', function () use ($app, $productType, $productId, $creditType, $creditQuantity) {
            $client = $this->getMockBuilder(Client::class)->setMethods(['post', 'put'])->getMock();

            $creditUrl = $app['credit_url'];
            $credits = [[
                            'transaction_id' => 100,
                        ]];
            $client
                ->expects($this->once())
                ->method('post')
                ->with(
                    "{$creditUrl}/purchase/qa.mygo1.com/{$productType}/{$productId}/{$creditQuantity}/{$creditType}",
                    $this->callback(function (array $options) {
                        $this->assertEquals('cod', $options['json']['paymentMethod']);

                        return true;
                    })
                )
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

    public function testInvalidLO()
    {
        $app = $this->getApp();
        $queue = $app['go1.client.mq'];
        $go1 = $app['dbs']['go1'];

        $edgeId = EdgeHelper::link($go1, $queue, EdgeTypes::HAS_MANUAL_PAYMENT, 1000, 0, 91, [
            'quantity'    => 10,
            'description' => 'I want to buy this course.',
            'credit_type' => 0,
        ]);

        $req = Request::create("/manual-payment/bulk/{$edgeId}/accept?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $message = json_decode($res->getContent(), true);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals("Invalid manual payment learning object.", $message['error'][0]['message']);
    }

    public function testInvalidUser()
    {
        $app = $this->getApp();
        $req = Request::create("/manual-payment/bulk/{$this->roId}/accept?jwt={$this->getJwt()}", 'POST');
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
        $req = Request::create("/manual-payment/bulk/{$roId}/accept?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $message = json_decode($res->getContent(), true);
        $this->assertEquals("Invalid roId.", $message['error'][0]['message']);
    }

    public function test204()
    {
        $app = $this->getApp();

        $this->mockServices($app, $this->jwt, 'lo', $this->courseId, 1, 10);

        $req = Request::create("/manual-payment/bulk/{$this->roId}/accept", 'POST');
        $req->request->replace(['ids' => [1, 2, 3]]);
        $req->headers->replace(['authorization' => "Bearer {$this->jwt}"]);
        $res = $app->handle($req);
        $go1 = $app['dbs']['go1'];
        $edge = EdgeHelper::load($go1, $this->roId);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EdgeTypes::HAS_MANUAL_PAYMENT_ACCEPT, $edge->type);
        $this->assertEquals(1, $edge->data->log[0]->accept_bulk_manual);
    }

    public function test204ByPassByAdmin()
    {
        $app = $this->getApp();

        $this->mockServices($app, $this->jwt, 'lo', $this->courseId, 1, 10);

        $req = Request::create("/manual-payment/bulk/{$this->roId}/accept", 'POST');
        $req->headers->replace(['authorization' => "Bearer {$this->jwt}"]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());

        $db = $app['dbs']['go1'];
        $edge = EdgeHelper::load($db, $this->roId);
        $this->assertEquals(EdgeTypes::HAS_MANUAL_PAYMENT_ACCEPT, $edge->type);
        $this->assertEquals(1, $edge->data->log[0]->accept_bulk_manual);
    }
}
