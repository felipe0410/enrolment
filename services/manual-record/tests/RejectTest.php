<?php

namespace go1\core\learning_record\manual_record\tests;

use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class RejectTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;
    use EnrolmentMockTrait;

    private $portalName    = 'qa.mygo1.com';
    private $enrolmentId;
    private $transactionId = 100;
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

        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'profile_id' => $this->profileId]),
            $this->createUser($go1, ['instance' => 'qa.mygo1.com'])
        );
        $this->jwt = JWT::encode((array) $this->getPayload(['mail' => 'author@course.com']), 'INTERNAL', 'HS256');

        $this->enrolmentId = $this->createEnrolment($go1, [
            'profile_id'        => $this->profileId,
            'user_id'           => $userId,
            'lo_id'             => $courseId,
            'taken_instance_id' => $portalId,
            'status'            => EnrolmentStatuses::PENDING,
            'data'              => json_encode(['transaction' => ['id' => $this->transactionId]]),
        ]);
    }

    public function test()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/manual-payment/reject/{$this->enrolmentId}?jwt={$this->jwt}", 'POST');
        $res = $app->handle($req);
        $app->terminate($req, $res);

        $this->assertEquals(204, $res->getStatusCode());

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $enrolment = $repository->load($this->enrolmentId);
        $this->assertNull($enrolment);
    }
}
