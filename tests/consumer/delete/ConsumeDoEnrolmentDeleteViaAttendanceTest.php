<?php

namespace go1\enrolment\tests\consumer\delete;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\event_publishing\Events;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class ConsumeDoEnrolmentDeleteViaAttendanceTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use PlanMockTrait;

    private $userId;
    private $portalId;
    private $accountId;
    private $profileId = 999;
    private $courseId;
    private $moduleId;
    private $li1Id;
    private $li2Id;
    private $courseEnrolmentId;
    private $moduleEnrolmentId;
    private $li1EnrolmentId;
    private $li2EnrolmentId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $portalName = 'az.mygo1.com']);
        $this->courseId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->moduleId = $this->createModule($go1, ['instance_id' => $this->portalId]);
        $this->li1Id = $this->createVideo($go1, ['instance_id' => $this->portalId]);
        $this->li2Id = $this->createVideo($go1, ['instance_id' => $this->portalId]);

        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleId, $this->li1Id);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleId, $this->li2Id);

        $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $mail = 'student@go1.com', 'profile_id' => $this->profileId]);
        $this->accountId = $this->createUser($go1, ['instance' => $portalName, 'mail' => $mail]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);
        $base = ['profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'user_id' => $this->userId];
        $this->courseEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->courseId, 'status' => EnrolmentStatuses::IN_PROGRESS]);
        $this->moduleEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->moduleId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'parent_lo_id' => $this->courseId, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->li1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->li1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_lo_id' => $this->moduleId, 'parent_enrolment_id' => $this->moduleEnrolmentId]);
        $this->li2EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $this->li2Id, 'status' => EnrolmentStatuses::IN_PROGRESS, 'parent_lo_id' => $this->moduleId, 'parent_enrolment_id' => $this->moduleEnrolmentId]);

        $coursePlanId = $this->createPlan($go1);
        $modulePlanId = $this->createPlan($go1);
        $li1PlanId = $this->createPlan($go1);
        $li2PlanId = $this->createPlan($go1);
        $this->createPlan($go1);
        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $this->courseEnrolmentId, 'plan_id' => $coursePlanId]);
        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $this->moduleEnrolmentId, 'plan_id' => $modulePlanId]);
        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $this->li1EnrolmentId, 'plan_id' => $li1PlanId]);
        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $this->li2EnrolmentId, 'plan_id' => $li2PlanId]);
    }

    public function test()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Events::EVENT_ATTENDANCE_DELETE,
            'body'       => (object) [
                'enrolment_id' => $this->li1EnrolmentId,
            ],
        ]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertNotEmpty($repository->load($this->courseEnrolmentId));
        $this->assertNotEmpty($repository->load($this->moduleEnrolmentId));
        $this->assertNull($repository->load($this->li1EnrolmentId));
        $this->assertNotEmpty($repository->load($this->li2EnrolmentId));

        $msgArchive = $this->queueMessages[Queue::ENROLMENT_DELETE];
        $this->assertEquals($this->li1EnrolmentId, $msgArchive[0]->id);

        foreach ($msgArchive as $archiveItem) {
            $req->request->replace([
                'routingKey' => Queue::ENROLMENT_DELETE,
                'body'       => $archiveItem,
            ]);

            $res = $app->handle($req);
            $this->assertEquals(204, $res->getStatusCode());
        }
    }
}
