<?php

namespace go1\core\learning_record\manual_record\tests;

use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\Roles;
use Symfony\Component\HttpFoundation\Request;

class InvalidStatusTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;
    use EnrolmentMockTrait;

    private $portalName    = 'qa.mygo1.com';
    private $courseId;
    private $transactionId = 100;
    private $mail          = 'user@go1.com';
    private $profileId     = 111;
    private $userJwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $db = $app['dbs']['go1'];
        $portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->createPortalPublicKey($db, ['instance' => $this->portalName]);

        $this->courseId = $this->createCourse($db, [
            'instance_id' => $portalId,
            'price'       => ['price' => 111.00, 'currency' => 'USD', 'tax' => 0.00],
            'data'        => json_encode(['manual_payment' => true, 'manual_payment_recipient' => 'author@course.com']),
        ]);

        $this->createEnrolment($db, [
            'profile_id'  => $this->profileId,
            'lo_id'       => $this->courseId,
            'status'      => EnrolmentStatuses::PENDING,
            'instance_id' => $portalId,
            'data'        => json_encode(['transaction' => ['id' => $this->transactionId]]),
        ]);

        $userId = $this->createUser($db, ['mail' => $this->mail, 'instance' => $app['accounts_name'], 'profile_id' => $this->profileId]);
        $this->userJwt = $this->getJwt($this->mail, $app['accounts_name'], $this->portalName, [Roles::AUTHENTICATED], $this->profileId, $userId);
    }

    public function test()
    {
        $app = $this->getApp();

        // Continue pending enrolment
        $req = Request::create("/{$this->portalName}/0/{$this->courseId}/enrolment/in-progress?jwt={$this->userJwt}", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertEquals("The following 1 assertions failed:\n1) paymentMethod: Invalid payment method\n", $body['message']);
    }
}
