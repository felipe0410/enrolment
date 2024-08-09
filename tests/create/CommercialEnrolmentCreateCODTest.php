<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\controller\create\PaymentMiddleware;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use ReflectionObject;
use Symfony\Component\HttpFoundation\Request;

class CommercialEnrolmentCreateCODTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $portalName = 'qa.mygo1.com';
    private $courseId;
    private $userId;
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
            'data'        => json_encode(['manual_payment' => true, 'manual_payment_recipient' => 'author@course.com']),
        ]);

        $this->userId = $this->createUser($db, ['instance' => $app['accounts_name'], 'profile_id' => 1111]);
        $this->link(
            $db,
            EdgeTypes::HAS_ACCOUNT,
            $this->userId,
            $this->createUser($db, ['instance' => $this->portalName, 'profile_id' => 1112])
        );

        $this->jwt = $this->jwtForUser($db, $this->userId, $this->portalName);
        $this->mockServices($app);
    }

    private function mockServices(DomainService $app)
    {
        $app->extend(PaymentMiddleware::class, function (PaymentMiddleware $payment) use ($app) {
            $rPayment = new ReflectionObject($payment);
            $rHttp = $rPayment->getProperty('client');
            $rHttp->setAccessible(true);
            $rHttp->setValue($payment, $client = $this->getMockBuilder(Client::class)->setMethods(['post'])->getMock());

            $client
                ->expects($this->once())
                ->method('post')
                ->with(
                    "{$app['payment_url']}/cart/process",
                    $this->callback(function (array $options) use ($app) {
                        $this->assertNotEmpty($options['headers']['Authorization']);
                        $this->assertEquals('application/json', $options['headers']['Content-Type']);
                        $this->assertEquals('cod', $options['json']['paymentMethod']);
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
                    'status'         => EnrolmentStatuses::PENDING,
                    'payment_method' => 'cod',
                ])));

            return $payment;
        });
    }

    public function test()
    {
        /** @var Connection $db */
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/pending?jwt={$this->jwt}", 'POST');
        $req->request->replace(['paymentMethod' => 'cod', 'paymentOptions' => [], 'data' => ['description' => 'user description']]);
        $res = $app->handle($req);
        $app->terminate($req, $res);
        $content = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($content->id));

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $enrolment = $repository->load($content->id);

        $this->assertEquals(EnrolmentStatuses::PENDING, $enrolment->status);
        $this->assertCount(1, $this->queueMessages);
        $this->assertArrayHasKey(Queue::ENROLMENT_CREATE, $this->queueMessages);
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $this->assertEquals($enrolment->id, $this->queueMessages[Queue::ENROLMENT_CREATE][0]['id']);
        $this->assertEquals(EnrolmentStatuses::PENDING, $enrolment->data->transaction->status);
        $this->assertEquals(666666, $enrolment->data->transaction->id);
        $this->assertEquals('user description', $enrolment->data->description);

        # Check enrolment-transaction mapping.
        # ---------------------
        $map = 'SELECT payment_method FROM gc_enrolment_transaction WHERE enrolment_id = ? AND transaction_id = ?';
        $map = $db->fetchColumn($map, [$enrolment->id, $enrolment->data->transaction->id]);
        $this->assertEquals('cod', $map);
    }
}
