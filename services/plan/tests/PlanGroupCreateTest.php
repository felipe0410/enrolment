<?php

namespace go1\core\learning_record\plan\tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\core\util\client\federation_api\v1\Marshaller;
use go1\core\util\client\federation_api\v1\schema\object\PortalAccount;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\group\GroupAssignTypes;
use go1\util\group\GroupItemTypes;
use go1\util\lo\LoStatuses;
use go1\util\plan\PlanStatuses;
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

    protected function mockUserDomainHelper(DomainService $app)
    {
        /**
         * @var Connection $go1;
         */
        $go1 = $app['dbs']['go1'];
        $userDomainHelper = parent::mockUserDomainHelper($app);


        $fooPortalAccount = UserHelper::loadByEmail($go1, $this->portalName, 'foo@bar.com');
        if ($fooPortalAccount) {
            $barPortalAccount = UserHelper::loadByEmail($go1, $this->portalName, 'bar@bar.com');

            $fooUser = UserHelper::loadByEmail($go1, $app['accounts_name'], 'foo@bar.com');
            $barUser = UserHelper::loadByEmail($go1, $app['accounts_name'], 'bar@bar.com');

            $userDomainHelper
                ->loadMultiplePortalAccounts($this->portalName, [$fooPortalAccount->id, $barPortalAccount->id], true)
                ->willReturn([
                    (new Marshaller())->parse((object)['user' => (object)['legacyId' => $fooUser->id]], new PortalAccount()),
                    (new Marshaller())->parse((object)['user' => (object)['legacyId' => $barUser->id]], new PortalAccount()),
                ]);
        }

        return $userDomainHelper;
    }

    public function test200()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $social = $app['dbs']['social'];

        $fooUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $fooMail = 'foo@bar.com']);
        $fooAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $fooMail]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $fooUserId, $fooAccountId);
        $barAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $barMail = 'bar@bar.com']);
        $barUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $barMail]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $barUserId, $barAccountId);

        $_ = ['group_id' => $this->groupId, 'entity_type' => GroupItemTypes::USER];
        $this->createGroupItem($social, $_ + ['entity_id' => $fooAccountId]);
        $this->createGroupItem($social, $_ + ['entity_id' => $barAccountId]);

        $req = "/plan/{$this->portalId}/{$this->loId}/group/{$this->groupId}?jwt={$this->ownerUserJwt}";
        $req = Request::create($req, 'POST', [
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => $dueDate = time(),
            'notify'   => true,
            'data'     => ['note' => 'foo'],
        ]);
        $res = $app->handle($req);

        $this->queueMessages = json_decode(json_encode($this->queueMessages), true);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(3, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE]);

        foreach ($this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE] as $enrolmentPlanCreate) {
            $this->assertEquals(['group_id' => $this->groupId, 'notify' => true], $enrolmentPlanCreate['_context']);
        }

        $this->assertEquals($this->ownerUserId, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][0]['user_id']);
        $this->assertEquals($fooUserId, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][1]['user_id']);
        $this->assertEquals($barUserId, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][2]['user_id']);
        $this->assertCount(1, $this->queueMessages[Queue::GROUP_ASSIGN_CREATE]);

        $this->assertEquals($this->groupId, $this->queueMessages[Queue::GROUP_ASSIGN_CREATE][0]['group_id']);
        $this->assertEquals($this->portalId, $this->queueMessages[Queue::GROUP_ASSIGN_CREATE][0]['instance_id']);
        $this->assertEquals(GroupAssignTypes::LO, $this->queueMessages[Queue::GROUP_ASSIGN_CREATE][0]['entity_type']);
        $this->assertEquals($this->loId, $this->queueMessages[Queue::GROUP_ASSIGN_CREATE][0]['entity_id']);
        $this->assertEquals($dueDate, $this->queueMessages[Queue::GROUP_ASSIGN_CREATE][0]['due_date']);
        $this->assertEquals(null, $this->queueMessages[Queue::GROUP_ASSIGN_CREATE][0]['data']);
    }

    public function notifyData()
    {
        return [[true, true], [false, false], ['This-String', true]];
    }

    /** @dataProvider notifyData */
    public function test200WithNotifyParameter($notify, $notifyContext)
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $social = $app['dbs']['social'];

        $fooAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $fooMail = 'foo@bar.com']);
        $fooUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $fooMail]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $fooUserId, $fooAccountId);
        $barAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $barMail = 'bar@bar.com']);
        $barUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $barMail]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $barUserId, $barAccountId);

        $this->createGroupItem($social, [
            'group_id'    => $this->groupId,
            'entity_type' => GroupItemTypes::USER,
            'entity_id'   => $fooAccountId,
        ]);
        $this->createGroupItem($social, [
            'group_id'    => $this->groupId,
            'entity_type' => GroupItemTypes::USER,
            'entity_id'   => $barAccountId,
        ]);

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/group/{$this->groupId}?jwt={$this->ownerUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => $dueDate = time(),
            'notify'   => $notify,
            'data'     => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);

        $this->queueMessages = json_decode(json_encode($this->queueMessages), true);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(3, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE]);

        foreach ($this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE] as $enrolmentPlanCreate) {
            $this->assertEquals(['group_id' => $this->groupId, 'notify' => $notifyContext], $enrolmentPlanCreate['_context']);
        }
    }

    public function test200Exclude()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $dbSocial = $app['dbs']['social'];

        $fooAccountId = $this->createUser($go1, [
            'instance' => $this->portalName,
            'mail'     => $fooMail = 'foo@bar.com',
        ]);
        $fooUserId = $this->createUser($go1, [
            'instance' => $app['accounts_name'],
            'mail'     => $fooMail,
        ]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $fooUserId, $fooAccountId);
        $barAccountId = $this->createUser($go1, [
            'instance' => $this->portalName,
            'mail'     => $barMail = 'bar@bar.com',
        ]);
        $barUserId = $this->createUser($go1, [
            'instance' => $app['accounts_name'],
            'mail'     => $barMail,
        ]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $barUserId, $barAccountId);

        $this->createGroupItem($dbSocial, [
            'group_id'    => $this->groupId,
            'entity_type' => GroupItemTypes::USER,
            'entity_id'   => $fooAccountId,
        ]);
        $this->createGroupItem($dbSocial, [
            'group_id'    => $this->groupId,
            'entity_type' => GroupItemTypes::USER,
            'entity_id'   => $barAccountId,
        ]);

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/group/{$this->groupId}?jwt={$this->ownerUserJwt}", 'POST');
        $req->request->replace([
            'status'       => PlanStatuses::ASSIGNED,
            'due_date'     => null,
            'notify'       => true,
            'data'         => ['note' => 'foo'],
            'exclude_self' => true,
        ]);

        $res = $app->handle($req);

        $this->queueMessages = json_decode(json_encode($this->queueMessages), true);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(2, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE]);
        $this->assertEquals($this->groupId, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][0]['_context']['group_id']);
        $this->assertEquals($this->groupId, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][1]['_context']['group_id']);
    }

    public function test200NoGroupUser()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/group/{$this->groupId}?jwt={$this->ownerUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => null,
            'notify'   => true,
            'data'     => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE]);
        $this->assertEquals($this->ownerUserId, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][0]['user_id']);
        $this->assertEquals($this->groupId, $this->queueMessages[Queue::DO_ENROLMENT_PLAN_CREATE][0]['_context']['group_id']);
    }

    public function test403InvalidJwt()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/group/{$this->groupId}?jwt=xx", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Missing or invalid JWT.', $res->getContent());
    }

    public function test403InvalidUser()
    {
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $bazUserId = $this->createUser($db, ['instance' => $app['accounts_name']]);
        $bazJwt = $this->jwtForUser($db, $bazUserId);
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/group/{$this->groupId}?jwt={$bazJwt}", 'POST');

        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Only group owner or admin can assign learning to group.', $res->getContent());
    }

    public function data404()
    {
        $this->getApp();

        return [
            [99, $this->loId, $this->groupId, 'Portal not found.'],
            [$this->portalId, 99, $this->groupId, 'LO not found.'],
            [$this->portalId, $this->loId, 99, 'Group not found.'],
            [$this->portalId, $this->archivedLoId, $this->groupId, 'LO not found.'],
            [$this->portalId, $this->unpublishedLoId, $this->groupId, 'LO not found.'],
        ];
    }

    /** @dataProvider data404 */
    public function test404($portalId, $loId, $groupId, $expectedMsg)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$portalId}/{$loId}/group/{$groupId}?jwt={$this->ownerUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => (new \DateTime('+1 day'))->format(DATE_ISO8601),
            'notify'   => true,
            'data'     => ['note' => 'foo'],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
        $this->assertStringContainsString($expectedMsg, $res->getContent());
    }

    public function data400Assert()
    {
        return [
            [['status' => 99], 'Value "99" is not an element of the valid values:'],
            [['due_date' => 0], 'Invalid due date.'],
            [['data' => 0], 'Value "0" is not an array.'],
            [['data' => []], 'The element with key "note" was not found'],
            [['data' => ['note' => 99]], 'Value "99" expected to be string, type integer given.'],
        ];
    }

    /** @dataProvider data400Assert */
    public function test400Assert($options, $expectedMsg)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/group/{$this->groupId}?jwt={$this->ownerUserJwt}", 'POST');
        $req->request->replace([
            'status'   => $options['status'] ?? PlanStatuses::ASSIGNED,
            'due_date' => $options['due_date'] ?? (new \DateTime('+1 day'))->format(DATE_ISO8601),
            'notify'   => true,
            'data'     => $options['data'] ?? ['note' => 'foo'],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString($expectedMsg, json_decode($res->getContent())->message);
    }
}
