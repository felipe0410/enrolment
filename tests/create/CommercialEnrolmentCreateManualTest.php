<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Schema\Schema;
use Exception;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\controller\create\PaymentMiddleware;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\payment\PaymentMethods;
use go1\util\payment\TransactionStatus;
use go1\util\queue\Queue;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\RequestInterface;
use ReflectionObject;
use Symfony\Component\HttpFoundation\Request;

class CommercialEnrolmentCreateManualTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $portalName = 'qa.mygo1.com';
    private $courseId;
    private $userId;
    private $jwt;
    private $adminJwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $go1 = $app['dbs']['go1'];
        $portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);

        $this->courseId = $this->createCourse($go1, [
            'instance_id' => $portalId,
            'price'       => ['price' => 111.00, 'currency' => 'USD', 'tax' => 0.00],
        ]);

        $this->userId = $this->createUser($go1, ['mail' => 'student@dev.com', 'instance' => $app['accounts_name']]);
        $accountId = $this->createUser($go1, ['mail' => 'student@dev.com', 'instance' => $this->portalName]);

        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $accountId);
        $this->jwt = $this->jwtForUser($go1, $this->userId, $this->portalName);
        $this->adminJwt = JWT::encode((array) $this->getAdminPayload($this->portalName), 'INTERNAL', 'HS256');
    }

    private function mockServices(DomainService $app, bool $success = true, $expectedException = null)
    {
        $app->extend(PaymentMiddleware::class, function (PaymentMiddleware $payment) use ($app, $success, $expectedException) {
            $rPayment = new ReflectionObject($payment);
            $rHttp = $rPayment->getProperty('client');
            $rHttp->setAccessible(true);
            $rHttp->setValue($payment, $client = $this->getMockBuilder(Client::class)->setMethods(['post', 'put'])->getMock());

            if ($success) {
                $client
                    ->expects($this->once())
                    ->method('post')
                    ->with(
                        "{$app['payment_url']}/cart/process",
                        $this->callback(function (array $options) use ($app) {
                            $this->assertNotEmpty($options['headers']['Authorization']);
                            $this->assertEquals('application/json', $options['headers']['Content-Type']);
                            $this->assertEquals(PaymentMethods::MANUAL, $options['json']['paymentMethod']);
                            $this->assertEquals($this->courseId, $options['json']['cartOptions']['items'][0]['productId']);
                            $this->assertEquals('lo', $options['json']['cartOptions']['items'][0]['type']);
                            $this->assertEquals(111.0, $options['json']['cartOptions']['items'][0]['price']);
                            $this->assertEquals('USD', $options['json']['cartOptions']['items'][0]['currency']);
                            $this->assertEquals(1, $options['json']['cartOptions']['items'][0]['qty'], 'Important, this must be 1.');
                            return true;
                        })
                    )
                    ->willReturn(new Response(200, [], json_encode([
                        'id'             => 666666,
                        'status'         => TransactionStatus::COMPLETED,
                        'payment_method' => PaymentMethods::MANUAL
                    ])));

                $client
                    ->expects($this->once())
                    ->method('put')
                    ->with("{$app['payment_url']}/transaction/666666/complete")
                    ->willReturn(new Response(200, [], 'payment result response'));
            } else {
                $client
                    ->expects($this->once())
                    ->method('post')
                    ->with(
                        "{$app['payment_url']}/cart/process",
                        $this->callback(function (array $options) use ($app) {
                            return true;
                        })
                    )
                    ->willThrowException($expectedException ?? new Exception("oh no, exception on paymentmiddleware process"));
            }

            return $payment;
        });
    }

    public function test200()
    {
        $app = $this->getApp();
        $this->mockServices($app);

        $req = "/{$this->portalName}/0/{$this->courseId}/enrolment/student@dev.com/not-started?jwt={$this->adminJwt}";
        $req = Request::create($req, 'POST', ['paymentMethod' => PaymentMethods::MANUAL, 'paymentOptions' => []]);
        $res = $app->handle($req);
        $app->terminate($req, $res);
        $content = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($content->id));

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $enrolment = $repository->load($content->id);

        $this->assertEquals($this->userId, $enrolment->user_id);
        $this->assertEquals(EnrolmentStatuses::NOT_STARTED, $enrolment->status);
        $this->assertCount(1, $this->queueMessages);
        $this->assertArrayHasKey(Queue::ENROLMENT_CREATE, $this->queueMessages);
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $this->assertEquals($enrolment->id, $this->queueMessages[Queue::ENROLMENT_CREATE][0]['id']);
        $this->assertEquals(TransactionStatus::COMPLETED, $enrolment->data->transaction->status);
        $this->assertEquals(666666, $enrolment->data->transaction->id);
    }

    public function test200GO1Staff()
    {
        $app = $this->getApp();
        $this->mockServices($app);

        $jwt = JWT::encode((array) $this->getRootPayload(), 'INTERNAL', 'HS256');
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/student@dev.com/not-started?jwt={$jwt}", 'POST');
        $req->request->replace(['paymentMethod' => PaymentMethods::MANUAL, 'paymentOptions' => []]);
        $res = $app->handle($req);
        $app->terminate($req, $res);
        $content = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($content->id));
    }

    public function test400()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/not-started?jwt={$this->jwt}", 'POST');
        $req->request->replace(['paymentMethod' => PaymentMethods::MANUAL, 'paymentOptions' => []]);
        $res = $app->handle($req);
        $app->terminate($req, $res);

        $this->assertEquals(400, $res->getStatusCode());
        $error = json_decode($res->getContent());
        $this->assertEquals('Only admin of portal that LO resides on can enrol user using manual payment.', $error->error[0]->message);
    }

    public function test400WithStripeCardDeclined()
    {
        $app = $this->getApp();

        $cardDeclinedEx = new BadResponseException(
            '',
            $this->prophesize(RequestInterface::class)->reveal(),
            new Response(400, [], 'card_declined by stripe')
        );
        $this->mockServices($app, false, $cardDeclinedEx);

        $req = "/{$this->portalName}/0/{$this->courseId}/enrolment/student@dev.com/not-started?jwt={$this->adminJwt}";
        $req = Request::create($req, 'POST', ['paymentMethod' => PaymentMethods::MANUAL, 'paymentOptions' => []]);
        $res = $app->handle($req);
        $app->terminate($req, $res);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('"card_declined by stripe"', $res->getContent());
    }

    public function test500()
    {
        $app = $this->getApp();
        $this->mockServices($app, false);

        $req = "/{$this->portalName}/0/{$this->courseId}/enrolment/student@dev.com/not-started?jwt={$this->adminJwt}";
        $req = Request::create($req, 'POST', ['paymentMethod' => PaymentMethods::MANUAL, 'paymentOptions' => []]);
        $res = $app->handle($req);
        $app->terminate($req, $res);

        $this->assertEquals(500, $res->getStatusCode());
        $this->assertEquals('{"message":"Failed to process payment. Please try again later"}', $res->getContent());
    }
}
