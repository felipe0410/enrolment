<?php

namespace go1\enrolment\tests\consumer\plan;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\DB;
use go1\util\group\GroupAssignStatuses;
use go1\util\group\GroupAssignTypes;
use go1\util\group\GroupItemTypes;
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

class GroupConsumerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use GroupMockTrait;

    private $portalId;
    private $portalName = 'foo.com';
    private $userId;
    private $accountId;
    private $fooLoId;
    private $barLoId;
    private $fooGroupId;
    private $barGroupId;
    private $dueDate;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['social'], [function (Schema $schema) {
            SocialSchema::install($schema);
        }]);

        $go1 = $app['dbs']['go1'];
        $social = $app['dbs']['social'];

        $this->portalId = $this->createPortal($go1, []);
        $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name']]);
        $this->accountId = $this->createUser($go1, ['instance' => $this->portalName]);
        $this->fooLoId = $this->createCourse($go1);
        $this->barLoId = $this->createCourse($go1);
        $this->fooGroupId = $this->createGroup($social, ['instance_id' => $this->portalId]);
        $this->barGroupId = $this->createGroup($social, ['instance_id' => $this->portalId]);
        $this->createGroupAssign($social, [
            'group_id'    => $this->fooGroupId,
            'instance_id' => $this->portalId,
            'user_id'     => $this->userId,
            'entity_type' => GroupAssignTypes::LO,
            'entity_id'   => $this->fooLoId,
        ]);
        $this->createGroupAssign($social, [
            'group_id'    => $this->fooGroupId,
            'instance_id' => $this->portalId,
            'user_id'     => $this->userId,
            'entity_type' => GroupAssignTypes::LO,
            'entity_id'   => $this->barLoId,
            'due_date'    => $this->dueDate = time(),
        ]);
        $this->createGroupAssign($social, [
            'group_id'    => $this->barGroupId,
            'instance_id' => $this->portalId,
            'user_id'     => $this->userId,
            'entity_type' => GroupAssignTypes::LO,
            'entity_id'   => $this->fooLoId,
            'status'      => GroupAssignStatuses::ARCHIVED,
        ]);
    }

    public function test204()
    {
        $app = $this->getApp();
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::GROUP_ITEM_CREATE,
            'body'       => [
                'id'          => 99,
                'group_id'    => $this->fooGroupId,
                'entity_type' => GroupItemTypes::USER,
                'entity_id'   => $this->accountId,
            ],
        ]);

        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(2, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE]);

        $msgBody = $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][0];
        $this->assertEquals($msgBody['type'], PlanTypes::ASSIGN);
        $this->assertEquals($msgBody['user_id'], $this->userId);
        $this->assertEquals($msgBody['assigner_id'], null);
        $this->assertEquals($msgBody['instance_id'], $this->portalId);
        $this->assertEquals($msgBody['entity_type'], PlanTypes::ENTITY_LO);
        $this->assertEquals($msgBody['entity_id'], $this->fooLoId);
        $this->assertEquals($msgBody['status'], PlanStatuses::ASSIGNED, );
        $this->assertEquals($msgBody['due_date'], null);

        $this->assertEquals(['group_id' => $this->fooGroupId, 'notify' => true], $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][0]['_context']);
        $this->assertEquals($this->barLoId, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][1]['entity_id']);
        $this->assertEquals(DateTime::create($this->dueDate)->format(DATE_ISO8601), $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][1]['due_date']);
        $this->assertEquals(['group_id' => $this->fooGroupId, 'notify' => true], $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][1]['_context']);
    }

    public function notifyData()
    {
        return [[true], [false]];
    }

    /** @dataProvider notifyData */
    public function test204WithNotifyContext($notify)
    {
        $app = $this->getApp();
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::GROUP_ITEM_CREATE,
            'body'       => [
                'id'          => 99,
                'group_id'    => $this->fooGroupId,
                'entity_type' => GroupItemTypes::USER,
                'entity_id'   => $this->accountId,
            ],
            'context'    => ['notify' => $notify],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(2, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE]);
        $msgBody = $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][0];
        $this->assertEquals($msgBody['type'], PlanTypes::ASSIGN);
        $this->assertEquals($msgBody['user_id'], $this->userId);
        $this->assertEquals($msgBody['assigner_id'], null);
        $this->assertEquals($msgBody['instance_id'], $this->portalId);
        $this->assertEquals($msgBody['entity_type'], PlanTypes::ENTITY_LO);
        $this->assertEquals($msgBody['entity_id'], $this->fooLoId);
        $this->assertEquals($msgBody['status'], PlanStatuses::ASSIGNED, );
        $this->assertEquals($msgBody['due_date'], null);
        $this->assertEquals(['group_id' => $this->fooGroupId, 'notify' => $notify], $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][0]['_context']);
        $this->assertEquals($this->barLoId, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][1]['entity_id']);
        $this->assertEquals(DateTime::create($this->dueDate)->format(DATE_ISO8601), $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][1]['due_date']);
        $this->assertEquals(['group_id' => $this->fooGroupId, 'notify' => $notify], $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][1]['_context']);
    }

    public function test204Empty()
    {
        $app = $this->getApp();
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::GROUP_ITEM_CREATE,
            'body'       => [
                'id'          => 99,
                'group_id'    => $this->barGroupId,
                'entity_type' => GroupItemTypes::USER,
                'entity_id'   => $this->accountId,
            ],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEmpty($this->queueMessages);
    }
}
