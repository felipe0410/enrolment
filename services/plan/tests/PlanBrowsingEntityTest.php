<?php

namespace go1\core\learning_record\plan\tests;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\group\GroupAssignTypes;
use go1\util\schema\mock\GroupMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\schema\SocialSchema;
use go1\util\user\Roles;
use Symfony\Component\HttpFoundation\Request;

class PlanBrowsingEntityTest extends EnrolmentTestCase
{
    use LoMockTrait;
    use PortalMockTrait;
    use UserMockTrait;
    use GroupMockTrait;
    use PlanMockTrait;

    private $fooUserId = 33;
    private $fooUserJwt;
    private $adminJwt;
    private $fooPortalId;
    private $fooLoId = 5;
    private $barLoId = 6;
    private $fooGroupId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['social'], [function (Schema $schema) {
            SocialSchema::install($schema);
        }]);

        $db       = $app['dbs']['go1'];
        $dbSocial = $app['dbs']['social'];

        $this->fooPortalId = $this->createPortal($db, ['title' => $fooPortalName = 'foo.com']);

        $this->createUser($db, [
            'id'       => $this->fooUserId,
            'instance' => $app['accounts_name'],
            'mail'     => $fooMail = 'foo@mail.com',
        ]);
        $fooAccountId = $this->createUser($db, [
            'instance' => $fooPortalName,
            'mail'     => $fooMail,
        ]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->fooUserId, $fooAccountId);
        $this->fooUserJwt = $this->jwtForUser($db, $this->fooUserId, $fooPortalName);

        $adminUserId       = $this->createUser($db, [
            'instance' => $app['accounts_name'],
            'mail'     => $adminMail = 'admin@foo.com',
        ]);
        $adminAccId        = $this->createUser($db, [
            'instance' => $fooPortalName,
            'mail'     => $adminMail = 'admin@foo.com',
        ]);
        $portalAdminRoleId = $this->createRole($db, ['instance' => $fooPortalName, 'name' => Roles::ADMIN]);
        $this->link($db, EdgeTypes::HAS_ROLE, $adminAccId, $portalAdminRoleId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $adminUserId, $adminAccId);
        $this->adminJwt = $this->jwtForUser($db, $adminUserId, $fooPortalName);

        $this->createCourse($db, ['id' => $this->fooLoId]);
        $this->createCourse($db, ['id' => $this->barLoId]);
        $this->fooGroupId = $this->createGroup($dbSocial, ['user_id' => $this->fooUserId]);
        $this->createGroupAssign($dbSocial, [
            'group_id'    => $this->fooGroupId,
            'instance_id' => $this->fooPortalId,
            'entity_type' => GroupAssignTypes::LO,
            'entity_id'   => $this->fooLoId,
            'user_id'     => $this->fooUserId,
        ]);
        $this->createGroupAssign($dbSocial, [
            'group_id'    => $this->fooGroupId,
            'instance_id' => $this->fooPortalId,
            'entity_type' => GroupAssignTypes::LO,
            'entity_id'   => $this->barLoId,
            'user_id'     => $this->fooUserId,
        ]);
    }

    public function test200PortalAdmin()
    {
        $app = $this->getApp();
        $req = Request::create("/plan-entity/{$this->fooGroupId}?jwt={$this->adminJwt}");

        $res = $app->handle($req);

        $entities = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(2, $entities);
    }

    public function test200GroupUser()
    {
        $app = $this->getApp();
        $req = Request::create("/plan-entity/{$this->fooGroupId}?jwt={$this->adminJwt}");

        $res = $app->handle($req);

        $entities = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(2, $entities);
    }
}
