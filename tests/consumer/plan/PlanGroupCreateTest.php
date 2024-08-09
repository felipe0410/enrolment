<?php

namespace go1\enrolment\tests\consumer\plan;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\lo\LoStatuses;
use go1\util\plan\Plan;
use go1\util\plan\PlanRepository;
use go1\util\plan\PlanStatuses;
use go1\util\plan\PlanTypes;
use go1\util\queue\Queue;
use go1\util\schema\mock\GroupMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\schema\SocialSchema;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class PlanGroupCreateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use GroupMockTrait;

    private $portalId;
    private $portalName = 'qa.mygo1.com';
    private $groupId;
    private $loId;
    private $archivedLoId;
    private $unpublishedLoId;
    private $ownerUserId;
    private $ownerUserJwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['social'], [function (Schema $schema) {
            SocialSchema::install($schema);
        }]);

        $db = $app['dbs']['go1'];
        $social = $app['dbs']['social'];

        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->loId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $this->archivedLoId = $this->createCourse($db, ['instance_id' => $this->portalId, 'published' => LoStatuses::ARCHIVED]);
        $this->unpublishedLoId = $this->createCourse($db, ['instance_id' => $this->portalId, 'published' => LoStatuses::UNPUBLISHED]);
        $this->ownerUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => 'foo@qa.mygo1.com']);
        $fooAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'foo@qa.mygo1.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->ownerUserId, $fooAccountId);
        $this->ownerUserJwt = $this->jwtForUser($db, $this->ownerUserId, $this->portalName);
        $this->groupId = $this->createGroup($social, ['user_id' => $this->ownerUserId]);
    }

    public function testConsumePlanCreateMessage()
    {
        $app = $this->getApp();

        /** @var PlanRepository $rPlan */
        $rPlan = $app[PlanRepository::class];

        $plan = Plan::create((object) [
            'type'        => PlanTypes::ASSIGN,
            'user_id'     => $this->ownerUserId,
            'assigner_id' => $this->ownerUserId,
            'instance_id' => $this->portalId,
            'entity_type' => PlanTypes::ENTITY_LO,
            'entity_id'   => $this->loId,
            'status'      => PlanStatuses::ASSIGNED,
            'due_date'    => $dueDate = time(),
        ]);

        // test queue message
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST', [
            'routingKey' => Queue::DO_ENROLMENT_PLAN_CREATE,
            'body'       => json_encode($plan),
        ]);
        $app->handle($req);

        $plan = $rPlan->load(1);
        $this->assertInstanceOf(Plan::class, $plan);
        $this->assertEquals($this->ownerUserId, $plan->userId);
        $this->assertEquals($this->ownerUserId, $plan->assignerId);
        $this->assertEquals($this->portalId, $plan->instanceId);
        $this->assertEquals(PlanTypes::ENTITY_LO, $plan->entityType);
        $this->assertEquals(PlanTypes::ASSIGN, $plan->type);
        $this->assertEquals($this->loId, $plan->entityId);
        $this->assertEquals(PlanStatuses::ASSIGNED, $plan->status);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
    }
}
