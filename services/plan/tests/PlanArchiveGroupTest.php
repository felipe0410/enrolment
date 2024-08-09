<?php

namespace go1\core\learning_record\plan\tests;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\group\GroupAssignTypes;
use go1\util\queue\Queue;
use go1\util\schema\mock\GroupMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\schema\SocialSchema;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class PlanArchiveGroupTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use GroupMockTrait;

    private $portalId;
    private $userId = 33;
    private $userJwt;
    private $loId;
    private $groupId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['social'], [function (Schema $schema) {
            SocialSchema::install($schema);
        }]);

        $db       = $app['dbs']['go1'];
        $dbSocial = $app['dbs']['social'];

        $this->portalId = $this->createPortal($db, ['title' => $portalName = 'foo.com']);

        $this->createUser($db, ['id' => $this->userId, 'instance' => $app['accounts_name'], 'mail' => 'user@user.com']);
        $userAccountId = $this->createUser($db, ['instance' => $portalName, 'mail' => 'user@user.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->userId, $userAccountId);
        $this->userJwt = $this->jwtForUser($db, $this->userId, $portalName);

        $this->loId    = $this->createLO($db, ['title' => 'foo lo']);
        $this->groupId = $this->createGroup($dbSocial);
        $this->createGroupAssign($dbSocial, [
            'group_id'    => $this->groupId,
            'instance_id' => $this->portalId,
            'entity_type' => GroupAssignTypes::LO,
            'entity_id'   => $this->loId,
            'user_id'     => 99,
        ]);
    }

    public function test200()
    {
        $app = $this->getApp();

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/group/{$this->groupId}?jwt=" . UserHelper::ROOT_JWT, 'DELETE');

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::GROUP_ASSIGN_DELETE]);
    }
}
