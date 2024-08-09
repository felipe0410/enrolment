<?php

namespace go1\core\learning_record\plan\tests;

use Doctrine\DBAL\Schema\Schema;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\group\GroupAssignTypes;
use go1\util\plan\PlanTypes;
use go1\util\schema\mock\GroupMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\schema\SocialSchema;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class PlanBrowsingTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use GroupMockTrait;
    use PlanMockTrait;

    private $fooPortalId;
    private $barPortalId;
    private $fooUserId = 33;
    private $fooUserJwt;
    private $barUserId = 44;
    private $barUserJwt;
    private $managerUserId = 55;
    private $managerJwt;
    private $adminJwt;
    private $fooAwardId = 1;
    private $barLoId = 2;
    private $bazLoId = 3;
    private $quxLoId = 4;
    private $fooPlanId;
    private $barPlanId;
    private $bazPlanId;
    private $groupId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['social'], [function (Schema $schema) {
            SocialSchema::install($schema);
        }]);

        $db       = $app['dbs']['go1'];
        $dbSocial = $app['dbs']['social'];

        $this->fooPortalId = $this->createPortal($db, ['title' => $fooPortalName = 'foo.com']);
        $this->barPortalId = $this->createPortal($db, ['title' => $barPortalName = 'bar.com']);

        $this->createUser($db, ['id' => $this->fooUserId, 'mail' => 'foo@foo.com', 'instance' => $app['accounts_name']]);
        $fooAccountId = $this->createUser($db, ['mail' => 'foo@foo.com', 'instance' => $fooPortalName]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->fooUserId, $fooAccountId);
        $this->fooUserJwt = $this->jwtForUser($db, $this->fooUserId, $fooPortalName);

        $this->createUser($db, ['id' => $this->barUserId, 'mail' => 'bar@foo.com', 'instance' => $app['accounts_name']]);
        $barAccountId = $this->createUser($db, ['mail' => 'bar@foo.com', 'instance' => $fooPortalName]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->barUserId, $barAccountId);
        $this->barUserJwt = $this->jwtForUser($db, $this->barUserId, $fooPortalName);

        $this->createUser($db, ['id' => $this->managerUserId, 'mail' => 'manager@foo.com', 'instance' => $app['accounts_name']]);
        $managerAccountId = $this->createUser($db, ['mail' => 'manager@foo.com', 'instance' => $fooPortalName]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->managerUserId, $managerAccountId);
        $this->link($db, EdgeTypes::HAS_MANAGER, $fooAccountId, $this->managerUserId);
        $this->managerJwt = $this->jwtForUser($db, $this->managerUserId, $fooPortalName);
        $this->adminJwt = JWT::encode((array) $this->getAdminPayload($fooPortalName), 'INTERNAL', 'HS256');

        $this->fooPlanId = $this->createPlan($db, [
            'user_id'      => $this->fooUserId,
            'instance_id'  => $this->fooPortalId,
            'entity_type'  => PlanTypes::ENTITY_AWARD,
            'entity_id'    => $this->fooAwardId,
            'created_date' => time(),
        ]);
        $this->barPlanId = $this->createPlan($db, [
            'user_id'      => $this->fooUserId,
            'instance_id'  => $this->fooPortalId,
            'entity_type'  => PlanTypes::ENTITY_LO,
            'entity_id'    => $this->barLoId,
            'due_date'     => time() + 100,
            'created_date' => time() - 5,
        ]);
        $this->bazPlanId = $this->createPlan($db, [
            'user_id'      => $this->fooUserId,
            'instance_id'  => $this->fooPortalId,
            'entity_type'  => PlanTypes::ENTITY_LO,
            'entity_id'    => $this->bazLoId,
            'due_date'     => time() + 50,
            'created_date' => time() + 5,
        ]);
        $this->createPlan($db, [
            'user_id'     => $this->fooUserId,
            'instance_id' => $this->barPortalId,
            'entity_type'  => PlanTypes::ENTITY_LO,
            'entity_id'   => $this->quxLoId,
        ]);
        $this->createPlan($db, [
            'user_id'     => $this->barUserId,
            'instance_id' => $this->fooPortalId,
            'entity_type'  => PlanTypes::ENTITY_LO,
            'entity_id'   => $this->fooAwardId,
        ]);
        $this->groupId = $this->createGroup($dbSocial, ['user_id' => $this->fooUserId]);
        $this->createGroupAssign($dbSocial, [
            'group_id'    => $this->groupId,
            'instance_id' => $this->fooPortalId,
            'entity_type' => GroupAssignTypes::LO,
            'entity_id'   => $this->fooAwardId,
            'user_id'     => $this->fooUserId,
        ]);
    }

    public function data200()
    {
        return [
            [null, [
                [PlanTypes::ENTITY_AWARD, $this->fooAwardId],
                [PlanTypes::ENTITY_LO, $this->barLoId],
                [PlanTypes::ENTITY_LO, $this->bazLoId],
            ]],
            [PlanTypes::ENTITY_AWARD, [
                [PlanTypes::ENTITY_AWARD, $this->fooAwardId],
            ]],
            [PlanTypes::ENTITY_LO, [
                [PlanTypes::ENTITY_LO, $this->barLoId],
                [PlanTypes::ENTITY_LO, $this->bazLoId],
            ]]
        ];
    }

    /** @dataProvider data200 */
    public function test200($type, $expected)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace(array_filter([
            'jwt'  => $this->fooUserJwt,
            'type' => $type,
        ]));

        $res = $app->handle($req);

        $plans = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(count($expected), $plans);
        foreach ($plans as $i => $plan) {
            [$expectedEntityType, $expectedEntityId] = $expected[$i];
            $this->assertEquals($this->fooUserId, $plan->user_id);
            $this->assertEquals($expectedEntityId, $plan->entity_id);
            $this->assertEquals($expectedEntityType, $plan->entity_type);
        }
    }

    public function test200All()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/all");
        $req->query->replace([
            'jwt' => $this->fooUserJwt,
        ]);

        $res = $app->handle($req);

        $plans = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(4, $plans);
    }

    public function test200Group()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace([
            'jwt'     => $this->fooUserJwt,
            'groupId' => $this->groupId,
        ]);

        $res = $app->handle($req);

        $plans = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $plans);
        $this->assertEquals($this->fooUserId, $plans[0]->user_id);
        $this->assertEquals($this->fooAwardId, $plans[0]->entity_id);
        $this->assertEquals(PlanTypes::ENTITY_AWARD, $plans[0]->entity_type);
    }

    public function test200HasDueDate()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace([
            'jwt'     => $this->fooUserJwt,
            'dueDate' => 1,
        ]);

        $res = $app->handle($req);

        $plans = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(2, $plans);
        $this->assertEquals($this->barPlanId, $plans[0]->id);
        $this->assertEquals($this->bazPlanId, $plans[1]->id);
    }

    public function test200NoDueDate()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace([
            'jwt'     => $this->fooUserJwt,
            'dueDate' => 0,
        ]);

        $res = $app->handle($req);

        $plans = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $plans);
        $this->assertEquals($this->fooPlanId, $plans[0]->id);
    }

    public function data200Sort()
    {
        $this->getApp();

        return [
            ['id', 'ASC', [$this->fooPlanId, $this->barPlanId, $this->bazPlanId]],
            ['id', 'DESC', [$this->bazPlanId, $this->barPlanId, $this->fooPlanId]],
            ['created_date', 'ASC', [$this->barPlanId, $this->fooPlanId, $this->bazPlanId]],
            ['created_date', 'DESC', [$this->bazPlanId, $this->fooPlanId, $this->barPlanId]],
            ['due_date', 'ASC', [$this->fooPlanId, $this->bazPlanId, $this->barPlanId]],
            ['due_date', 'DESC', [$this->barPlanId, $this->bazPlanId, $this->fooPlanId]],
        ];
    }

    /** @dataProvider data200Sort */
    public function test200Sort($sort, $direction, $expectedIds)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace([
            'jwt'       => $this->fooUserJwt,
            'sort'      => $sort,
            'direction' => $direction,
        ]);

        $res = $app->handle($req);

        $plans = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(count($expectedIds), $plans);
        foreach ($expectedIds as $i => $expectedId) {
            $this->assertEquals($expectedId, $plans[$i]->id);
        }
    }

    public function data200Id()
    {
        $this->getApp();

        return [
            [[$this->fooPlanId]],
            [[$this->barPlanId]],
            [[$this->fooPlanId, $this->barPlanId, $this->bazPlanId]],
        ];
    }

    /** @dataProvider data200Id */
    public function test200Id(array $ids)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace([
            'jwt' => $this->fooUserJwt,
            'id'  => $ids,
        ]);

        $res = $app->handle($req);

        $plans = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(count($ids), $plans);
        foreach ($ids as $i => $id) {
            $this->assertEquals($id, $plans[$i]->id);
        }
    }

    public function data200EntityId()
    {
        return [
            [$this->fooAwardId],
            [$this->barLoId],
            [$this->bazLoId],
        ];
    }

    /** @dataProvider data200EntityId */
    public function test200EntityId($entityId)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace([
            'jwt'      => $this->fooUserJwt,
            'entityId' => $entityId,
        ]);

        $res = $app->handle($req);

        $plans = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $plans);
        $this->assertEquals($entityId, $plans[0]->entity_id);
    }

    public function data200UserId()
    {
        $this->getApp();

        return [
            [$this->fooUserId, [$this->fooAwardId, $this->barLoId, $this->bazLoId]],
            [$this->fooUserId, [$this->fooAwardId, $this->barLoId, $this->bazLoId], $this->adminJwt],
            [$this->fooUserId, [$this->fooAwardId, $this->barLoId, $this->bazLoId], $this->managerJwt],
            [$this->barUserId, [$this->fooAwardId]],
            [null, []],
        ];
    }

    /** @dataProvider data200UserId */
    public function test200UserId($userId, $expectedLoIds, $jwt = UserHelper::ROOT_JWT)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace([
            'jwt'    => $jwt,
            'userId' => $userId,
        ]);

        $res = $app->handle($req);

        $plans = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(count($expectedLoIds), $plans);
        foreach ($expectedLoIds as $i => $expectedLoId) {
            $this->assertEquals($expectedLoId, $plans[$i]->entity_id);
        }
    }

    public function test403Jwt()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace([
            'jwt' => 'xxx',
        ]);

        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertStringContainsString('Missing or invalid JWT.', $res->getContent());
    }

    public function test404Portal()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/99");
        $req->query->replace([
            'jwt' => UserHelper::ROOT_JWT,
        ]);

        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
        $this->assertStringContainsString('Portal not found.', $res->getContent());
    }

    public function test400User()
    {
        $app     = $this->getApp();
        $db      = $app['dbs']['go1'];
        $userId  = $this->createUser($db, ['instance' => $app['accounts_name']]);
        $userJwt = $this->jwtForUser($db, $userId);

        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace([
            'jwt' => $userJwt,
        ]);

        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('User does not belong to the portal.', $res->getContent());
    }

    public function data400Assert()
    {
        return [
            [['userId' => 'a'], 'Value "a" is not an integer or a number castable to integer.'],
            [['userId' => 123], 'Only admin can use userId filter.'],
            [['entityId' => 'a'], 'Value "a" is not an integer or a number castable to integer.'],
            [['limit' => 'a'], 'Value "a" is not an integer or a number castable to integer.'],
            [['offset' => 'a'], 'Value "a" is not an integer or a number castable to integer.'],
        ];
    }

    /** @dataProvider data400Assert */
    public function test400Assert(array $filters, $expectedMsg)
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->fooPortalId}");
        $req->query->replace([
                'jwt' => $this->fooUserJwt,
            ] + $filters);

        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString($expectedMsg, json_decode($res->getContent())->message);
    }

    public function test400AssertAll()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/all");
        $req->query->replace([
            'jwt'     => $this->fooUserJwt,
            'groupId' => $this->groupId,
        ]);

        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('Can not use `groupId` filter when browse in all portal.', json_decode($res->getContent())->message);
    }
}
