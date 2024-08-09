<?php

namespace go1\enrolment\tests\consumer\create;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LoHelper;
use go1\util\plan\Plan;
use go1\util\plan\PlanStatuses;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentCreateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use PlanMockTrait;

    private $portalId;
    private $portalName       = 'qa.mygo1.com';
    private $suggestedCompletionLoId;
    private $studentUserId;
    private $studentProfileId = 555;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];
        $accountsName = $app['accounts_name'];
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->studentUserId = $this->createUser($go1, ['profile_id' => 555, 'instance' => $accountsName]);

        $this->suggestedCompletionLoId = $this->createCourse($go1, [
            'instance_id' => $this->portalId,
            'data'        => [
                LoHelper::SUGGESTED_COMPLETION_TIME => '1',
                LoHelper::SUGGESTED_COMPLETION_UNIT => 'day',
            ],
        ]);
    }

    public function testEnrolToSuggestedCompletionCourse()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $id = $this->createEnrolment($go1, [
            'taken_instance_id' => $this->portalId,
            'lo_id'             => $this->suggestedCompletionLoId,
            'profile_id'        => $this->studentProfileId,
            'user_id'           => $this->studentUserId,
            'status'            => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $enrolment = EnrolmentHelper::load($go1, $id);
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST', [
            'routingKey' => Queue::ENROLMENT_CREATE,
            'body'       => $enrolment,
        ]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_CREATE][0]);
        $this->assertEquals($this->studentUserId, $plan->userId);
        $this->assertEqualsWithDelta(DateTime::create('1 day'), $plan->due, 1);
        $this->assertEquals(PlanStatuses::SCHEDULED, $plan->status);
    }

    public function testLinkPlanOnEnrolmentCreate()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        /** @var EnrolmentRepository $rEnrolment */
        $rEnrolment = $app[EnrolmentRepository::class];
        $planId = $this->createPlan($app['dbs']['go1'], [
            'user_id'     => $this->studentUserId,
            'instance_id' => $this->portalId,
            'entity_type' => Plan::TYPE_LO,
            'entity_id'   => $this->suggestedCompletionLoId,
            'status'      => PlanStatuses::SCHEDULED,
        ]);

        $enrolmentId = $this->createEnrolment($go1, [
            'profile_id'        => $this->studentProfileId,
            'user_id'           => $this->studentUserId,
            'lo_id'             => $this->suggestedCompletionLoId,
            'taken_instance_id' => $this->portalId,
        ]);
        $enrolment = EnrolmentHelper::load($go1, $enrolmentId);
        $plan = $rEnrolment->loadPlanByEnrolmentLegacy($enrolment->id);
        $this->assertNull($plan);

        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::ENROLMENT_CREATE,
            'body'       => $enrolment,
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
    }
}
