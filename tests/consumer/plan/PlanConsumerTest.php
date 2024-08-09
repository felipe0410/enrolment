<?php

namespace go1\enrolment\tests\plan;

use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\plan\PlanTypes;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class PlanConsumerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use PlanMockTrait;

    protected $portalId       = 1;
    protected $portalName     = 'qa.go1.co';
    protected $portalLogo     = 'http://portal.png';
    protected $portalTimeZone = 'Australia/Brisbane';
    protected $enrolmentId    = 1;
    protected $courseId       = 100;
    protected $courseName     = 'GO1 VN';

    protected $userId        = 69;
    protected $userMail      = 'user@foo.com';
    protected $userProfileId = 96;
    protected $userFirstName = 'Foo';
    protected $userLastName  = 'Bar';

    private $assignerId        = 51;
    private $assignerMail      = 'assigner@foo.com';
    private $assignerFirstName = 'Assigner';
    private $assignerLastName  = 'Last';

    private $planId = 999;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $go1 = $app['dbs']['go1'];

        $this->createPortal($go1, ['id' => $this->portalId, 'title' => $this->portalName, 'data' => ['files' => ['logo' => $this->portalLogo]]]);
        $this->createUser($go1, ['mail' => $this->userMail, 'profile_id' => $this->userProfileId, 'instance' => $app['accounts_name'], 'first_name' => $this->userFirstName, 'last_name' => $this->userLastName]);
        $this->createUser($go1, ['id' => $this->userId, 'mail' => $this->userMail, 'profile_id' => $this->userProfileId, 'instance' => $this->portalName, 'first_name' => $this->userFirstName, 'last_name' => $this->userLastName]);
        $this->createEnrolment($go1, ['id' => $this->enrolmentId, 'profile_id' => $this->userProfileId, 'user_id' => $this->userId, 'taken_instance_id' => $this->portalId, 'lo_id' => $this->courseId, 'parent_lo_id' => 0]);
        $this->createUser($go1, ['id' => $this->assignerId, 'mail' => $this->assignerMail, 'first_name' => $this->assignerFirstName, 'last_name' => $this->assignerLastName,]);
    }

    public function testLinkPlanNotExists()
    {
        $app = $this->getApp();

        $body = [
            'id'           => $this->planId,
            'user_id'      => $this->userId,
            'assigner_id'  => $this->assignerId,
            'instance_id'  => $this->portalId,
            'entity_type'  => PlanTypes::ENTITY_LO,
            'entity_id'    => $this->courseId,
            'status'       => 2,
            'due_date'     => DateTime::formatDate('3 days'),
            'created_date' => $time = time(),
            'data'         => null,
        ];

        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::PLAN_CREATE,
            'body'       => $body,
        ]);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(JsonResponse::HTTP_NO_CONTENT, $res->getStatusCode());
    }
}
