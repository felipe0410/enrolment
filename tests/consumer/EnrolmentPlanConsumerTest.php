<?php

namespace go1\enrolment\tests\consumer;

use go1\app\DomainService;
use go1\enrolment\consumer\EnrolmentPlanConsumer;
use go1\enrolment\domain\etc\EnrolmentPlanRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentPlanConsumerTest extends EnrolmentTestCase
{
    use EnrolmentMockTrait;
    use PlanMockTrait;

    private EnrolmentPlanRepository $enrolmentPlanRepository;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $app->extend('consumers', fn () => [
            $app[EnrolmentPlanConsumer::class]
        ]);
        $this->enrolmentPlanRepository = $app[EnrolmentPlanRepository::class];
    }

    public function testPlanCreate()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $plan = [
            'instance_id' => 1,
            'user_id'     => 1,
            'entity_id'   => 1,
        ];
        $planId = $this->createPlan($go1, $plan);
        $plan = (object) $plan;
        $plan->id = $planId;

        $enrolmentId = $this->createEnrolment($go1, ['taken_instance_id' => 1, 'lo_id' => 1, 'user_id' => 1]);
        $this->assertEmpty($this->enrolmentPlanRepository->has($enrolmentId, $plan->id));

        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::PLAN_CREATE,
            'body'       => $plan,
        ]);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertNotEmpty($this->enrolmentPlanRepository->has($enrolmentId, $plan->id));
    }
}
