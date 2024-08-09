<?php

namespace go1\enrolment\tests\delete;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class UnenrolTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $loId;
    private $userId;
    private $profileId = 274;
    private $accountId;
    private $jwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        $db = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($db, ['title' => 'az.mygo1.com']);
        $this->loId = $this->createCourse($db, ['instance_id' => $this->portalId]);

        $this->userId = $this->createUser($db, ['instance' => $app['accounts_name'], 'profile_id' => $this->profileId]);
        $this->accountId = $this->createUser($db, ['instance' => 'az.mygo1.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);
        $this->jwt = $this->jwtForUser($db, $this->userId, 'az.mygo1.com');
    }

    public function testEnrolment404()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->loId}", 'DELETE');
        $req->query->set('jwt', $this->jwt);
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testWIP()
    {
        /** @var Connection $db */
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $this->createEnrolment($app['dbs']['go1'], ['user_id' => $this->userId, 'lo_id' => $this->loId, 'profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId]);
        $req = Request::create("/{$this->loId}", 'DELETE');
        $req->query->set('jwt', $this->jwt);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(0, $db->fetchColumn('SELECT COUNT(*) FROM gc_enrolment'));

        $msg = $this->queueMessages[Queue::ENROLMENT_DELETE];
        $this->assertEquals($this->profileId, $msg[0]->profile_id);
        $this->assertEquals($this->loId, $msg[0]->lo_id);

        $this->assertEquals(
            1,
            $db->fetchColumn(
                'SELECT 1 FROM enrolment_stream WHERE action = ? AND portal_id = ? AND enrolment_id = ?',
                [
                    'delete',
                    $this->portalId,
                    $msg[0]->id
                ]
            )
        );
    }
}
