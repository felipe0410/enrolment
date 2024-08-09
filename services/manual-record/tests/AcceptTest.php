<?php

namespace go1\core\learning_record\manual_record\tests;

use go1\app\DomainService;
use go1\enrolment\controller\create\PaymentMiddleware;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use GuzzleHttp\Client;
use ReflectionObject;
use Symfony\Component\HttpFoundation\Request;

class AcceptTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;
    use EnrolmentMockTrait;

    private $portalName    = 'qa.mygo1.com';
    private $enrolmentId;
    private $transactionId = 100;
    private $userId;
    private $profileId     = 111;
    private $jwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];
        $portalId = $this->createPortal($go1, ['title' => 'qa.mygo1.com']);
        $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);

        $courseId = $this->createCourse($go1, [
            'instance_id' => $portalId,
            'price'       => ['price' => 111.00, 'currency' => 'USD', 'tax' => 0.00],
            'data'        => json_encode(['manual_payment' => true, 'manual_payment_recipient' => 'author@course.com']),
        ]);

        $this->userId = $this->createUser($go1, ['mail' => 'author@course.com', 'instance' => $app['accounts_name'], 'profile_id' => $this->profileId]);
        $accountId = $this->createUser($go1, ['mail' => 'author@course.com', 'instance' => $this->portalName]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $accountId);
        $this->jwt = $this->jwt = $this->jwtForUser($go1, $this->userId, $this->portalName);

        $this->enrolmentId = $this->createEnrolment($go1, [
            'taken_instance_id' => $portalId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'lo_id'             => $courseId,
            'status'            => EnrolmentStatuses::PENDING,
            'data'              => json_encode(['transaction' => ['id' => $this->transactionId]]),
        ]);

        $this->mockServices($app);
    }

    private function mockServices(DomainService $app)
    {
        $app->extend(PaymentMiddleware::class, function (PaymentMiddleware $payment) use ($app) {
            $rPayment = new ReflectionObject($payment);
            $rHttp = $rPayment->getProperty('client');
            $rHttp->setAccessible(true);
            $rHttp->setValue($payment, $client = $this->getMockBuilder(Client::class)->setMethods(['put'])->getMock());

            $client
                ->expects($this->once())
                ->method('put')
                ->with("{$app['payment_url']}/transaction/{$this->transactionId}/complete");

            return $payment;
        });
    }

    public function test()
    {
        $app = $this->getApp();
        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $req = Request::create("/enrolment/manual-payment/accept/{$this->enrolmentId}?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $app->terminate($req, $res);
        $enrolment = $repository->load($this->enrolmentId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::NOT_STARTED, $enrolment->status);
        $this->assertEquals('manual_payment_accepted', $enrolment->data->history[0]->action);
        $this->assertEquals($this->userId, $enrolment->data->history[0]->actorId);
        $this->assertEquals(EnrolmentStatuses::NOT_STARTED, $enrolment->data->history[0]->status);
    }
}
