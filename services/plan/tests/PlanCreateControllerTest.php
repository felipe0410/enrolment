<?php

namespace go1\core\learning_record\plan\tests;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\core\learning_record\plan\PlanBrowsingOptions;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\plan\PlanStatuses;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class PlanCreateControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use PlanMockTrait;
    use EnrolmentMockTrait;

    private int    $portalId;
    private int    $portalBId;
    private int    $loId;
    private int    $moduleId;
    private int    $singleLiId;
    private int    $nonSingleLiId;
    private int    $learnerUserId;
    private int    $learnerAccountId;
    private string $learnerJwt;
    private string $portalName = 'qa.local';
    private int    $managerId;
    private string $portalBName = 'qa-b.local';
    private int    $adminId;
    private string $adminJwt;
    private string $adminBJwt;
    private string $managerJwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));

        $db = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName]);
        $this->portalBId = $this->createPortal($db, ['title' => $this->portalBName]);
        $this->loId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $this->moduleId = $this->createModule($db, ['instance_id' => $this->portalId]);
        $this->singleLiId = $this->createVideo($db, ['instance_id' => $this->portalId, 'single_li' => true]);
        $this->nonSingleLiId = $this->createVideo($db, ['instance_id' => $this->portalId]);
        $this->link($db, EdgeTypes::HAS_MODULE, $this->loId, $this->moduleId);
        $this->link($db, EdgeTypes::HAS_LI, $this->loId, $this->moduleId);

        $this->adminId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => 'admin@go1.com']);
        $adminAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'admin@go1.com']);
        $adminAccountBId = $this->createUser($db, ['instance' => $this->portalBName, 'mail' => 'admin@go1.com']);
        $this->link($db, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($db, ['instance' => $this->portalName]));
        $this->link($db, EdgeTypes::HAS_ROLE, $adminAccountBId, $this->createPortalAdminRole($db, ['instance' => $this->portalName]));
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->adminId, $adminAccountId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->adminId, $adminAccountBId);
        $this->adminJwt = $this->jwtForUser($db, $this->adminId, $this->portalName);
        $this->adminBJwt = $this->jwtForUser($db, $this->adminId, $this->portalBName);

        $this->managerId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => 'manager@go1.com']);
        $managerAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'manager@go1.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->managerId, $managerAccountId);
        $this->managerJwt = $this->jwtForUser($db, $this->managerId, $this->portalName);

        $this->learnerUserId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => 'learner@go1.com']);
        $this->learnerAccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'learner@go1.com']);
        $learnerAccountBId = $this->createUser($db, ['instance' => $this->portalBName, 'mail' => 'learner@go1.com']);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->learnerUserId, $this->learnerAccountId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $this->learnerUserId, $learnerAccountBId);
        $this->link($db, EdgeTypes::HAS_MANAGER, $this->learnerAccountId, $this->managerId);
        $this->learnerJwt = $this->jwtForUser($db, $this->learnerUserId, $this->portalName);
    }

    /**
     * @see PSE-455
     */
    public function testEdgeCreated()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        /** @var EnrolmentRepository $re */
        $re = $app[EnrolmentRepository::class];

        // learned started learning
        $enrolmentId = $this->createEnrolment($go1, ['user_id' => $this->learnerUserId, 'profile_id' => 999, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId]);

        // manager assign
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->learnerUserId}?jwt={$this->managerJwt}", 'POST');
        $req->query->set('due_date', strtotime('+60 days'));
        $req->query->set('status', -2);
        $req->query->set('version', 2);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $planId = json_decode($res->getContent())->id;
        $this->assertTrue($re->foundLink($planId, $enrolmentId));
        $message = $this->queueMessages[Queue::PLAN_CREATE][0];
        $this->assertEquals('assigned', $message['_context']['action']);
    }

    /**
     * @see MGL-815
     */
    public function testPlanCreateOnOtherPortal()
    {
        $app = $this->getApp();
        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];
        /** @var EnrolmentRepository $rEnrolment */
        $rEnrolment = $app[EnrolmentRepository::class];

        // admin assign lo to learner on Portal A
        $planId = $this->createPlan($go1, [
            'entity_id'    => $this->loId,
            'instance_id'  => $this->portalId,
            'user_id'      => $this->learnerUserId,
            'assigner_id'  => $this->adminId,
            'due_date'     => (new \DateTime('-9 days'))->format(DATE_ISO8601),
            'created_date' => (new \DateTime('-10 days'))->format(DATE_ISO8601),
            'status'       => PlanStatuses::ASSIGNED
        ]);

        // learner started learning on Portal A
        $enrolmentId = $this->createEnrolment($go1, ['user_id' => $this->learnerUserId, 'profile_id' => 999, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId]);
        $rEnrolment->linkPlan($planId, $enrolmentId);

        // admin assign lo to learner on Portal B
        $req = Request::create("/plan/$this->portalBId/$this->loId/user/$this->learnerUserId?jwt={$this->adminBJwt}", 'POST');
        $req->query->set('due_date', strtotime('+3 days'));
        $req->query->set('status', -2);
        $req->query->set('version', 2);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $planId = json_decode($res->getContent())->id;
        $this->assertFalse($rEnrolment->foundLink($planId, $enrolmentId));
        $message = $this->queueMessages[Queue::PLAN_CREATE][0];
        $this->assertEquals('assigned', $message['_context']['action']);
    }

    public function testPlanCreateWithSource()
    {
        $app = $this->getApp();
        /** @var EnrolmentRepository $rEnrolment */
        $rEnrolment = $app[EnrolmentRepository::class];

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->learnerUserId}?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->query->set('due_date', strtotime('+3 days'));
        $req->query->set('status', PlanStatuses::ASSIGNED);
        $req->query->set('source_type', 'group');
        $req->query->set('source_id', '1234');
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());

        $planId = json_decode($res->getContent())->id;
        $this->assertTrue($rEnrolment->findPlanReference($planId, 'group', 1234));

        $message = $this->queueMessages[Queue::PLAN_CREATE][0];
        $this->assertEquals(1234, $message["_context"]["group"]);
        $this->assertEquals('assigned', $message['_context']['action']);
    }

    public function testPlanCreateWithSourceWithoutGo1Staff()
    {
        $app = $this->getApp();

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->learnerUserId}?jwt=" . $this->adminJwt, 'POST');
        $req->query->set('due_date', strtotime('+3 days'));
        $req->query->set('status', PlanStatuses::ASSIGNED);
        $req->query->set('source_type', 'group');
        $req->query->set('source_id', '1234');
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());

        $error = json_decode($res->getContent());
        $this->assertEquals("Require Go1 Staff permission.", $error->message);
    }

    public function testPlanCreateWithAssigner()
    {
        $app = $this->getApp();
        /** @var EnrolmentRepository $rEnrolment */
        $rEnrolment = $app[EnrolmentRepository::class];

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->learnerUserId}?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->query->set('due_date', strtotime('+3 days'));
        $req->query->set('status', PlanStatuses::ASSIGNED);
        $req->query->set('assigner_id', $this->managerId);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());

        $planId = json_decode($res->getContent())->id;
        $opt = new PlanBrowsingOptions();
        $opt->id[] = $planId;
        $plans = $rEnrolment->findPlan($opt);
        $this->assertCount(1, $plans);
        $this->assertEquals($this->managerId, $plans[0]->assigner_id);
        $message = $this->queueMessages[Queue::PLAN_CREATE][0];
        $this->assertEquals('assigned', $message['_context']['action']);
    }

    public function testPlanCreateWithModule400()
    {
        $app = $this->getApp();

        $req = Request::create("/plan/{$this->portalId}/{$this->moduleId}/user/{$this->learnerUserId}?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }

    public function testPlanCreateWithSingleLi200()
    {
        $app = $this->getApp();
        /** @var EnrolmentRepository $rEnrolment */
        $rEnrolment = $app[EnrolmentRepository::class];

        $req = Request::create("/plan/{$this->portalId}/{$this->singleLiId}/user/{$this->learnerUserId}?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->query->set('due_date', strtotime('+3 days'));
        $req->query->set('status', PlanStatuses::ASSIGNED);
        $req->query->set('assigner_id', $this->managerId);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $planId = json_decode($res->getContent())->id;
        $opt = new PlanBrowsingOptions();
        $opt->id[] = $planId;
        $plans = $rEnrolment->findPlan($opt);
        $this->assertCount(1, $plans);
        $this->assertEquals($this->managerId, $plans[0]->assigner_id);
        $message = $this->queueMessages[Queue::PLAN_CREATE][0];
        $this->assertEquals('assigned', $message['_context']['action']);
    }

    public function testPlanCreateWithNonSingleLi400()
    {
        $app = $this->getApp();

        $req = Request::create("/plan/{$this->portalId}/{$this->nonSingleLiId}/user/{$this->learnerUserId}?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }
}
