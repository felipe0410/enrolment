<?php

namespace go1\enrolment\tests\schedule;

use Doctrine\DBAL\Connection;
use go1\app\App;
use go1\app\DomainService;
use go1\enrolment\controller\CronController;
use go1\enrolment\services\EnrolmentCreateService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\model\Enrolment;
use go1\util\queue\Queue;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use ReflectionObject;
use Symfony\Component\HttpFoundation\Request;

class ExpiringEnrolmentCronRunTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $courseId;
    private $moduleId;
    private $jwt;
    private $enrolmentId;
    private $enrolment;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $createService = $app[EnrolmentCreateService::class];

        $app->extend(CronController::class, function (CronController $cron) {
            $rCron = new ReflectionObject($cron);
            $rTime = $rCron->getProperty('time');
            $rTime->setAccessible(true);
            $rTime->setValue($cron, strtotime('+ 2 years'));

            return $cron;
        });

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];

        $portalId = $this->createPortal($go1, ['title' => 'qa.mygo1.com']);
        $userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'foo@bar.baz', 'profile_id' => 555]);
        $accountId = $this->createUser($go1, ['instance' => 'qa.mygo1.com', 'mail' => 'foo@bar.baz']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $userId, $accountId);
        $this->jwt = $this->jwtForUser($go1, $userId, 'qa.mygo1.com');

        $this->courseId = $this->createCourse($go1, ['instance_id' => $portalId]);
        $this->moduleId = $this->createCourse($go1, ['instance_id' => $portalId]);
        $hasModuleEdgeId = $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
        $this->link($go1, EdgeTypes::HAS_ENROLMENT_EXPIRATION, $hasModuleEdgeId, $hasModuleEdgeId, 0, ['expiration' => '+ 2 years']);

        $courseEnrolment = Enrolment::create((object) [
            'profile_id'        => 555,
            'user_id'           => $userId,
            'lo_id'             => $this->courseId,
            'taken_instance_id' => $portalId,
            'status'            => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $moduleEnrolment = Enrolment::create((object) [
            'profile_id'          => 555,
            'user_id'             => $userId,
            'parent_enrolment_id' => $courseEnrolment->id,
            'lo_id'               => $this->moduleId,
            'taken_instance_id'   => $portalId,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $this->enrolment = $createService->create($moduleEnrolment);

        $go1->insert('gc_ro', [
            'type'      => EdgeTypes::SCHEDULE_EXPIRE_ENROLMENT,
            'source_id' => $this->enrolment->enrolment->id,
            'target_id' => strtotime('+ 23 months'),
            'weight'    => 0,
        ]);
    }

    public function test()
    {
        /** @var App $app */
        $app = $this->getApp();

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];

        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST', [
            'routingKey' => Queue::DO_ENROLMENT_CRON,
            'body'       => json_encode(['task' => CronController::TASK_CHECK_EXPIRING]),
        ]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(0, $go1->fetchColumn('SELECT COUNT(*) FROM gc_ro WHERE type = ? AND source_id = ?', [EdgeTypes::SCHEDULE_EXPIRE_ENROLMENT, $this->enrolment->enrolment->id]));
        $this->assertEquals(1, $go1->fetchColumn('SELECT COUNT(*) FROM gc_ro WHERE type = ? AND source_id = ?', [EdgeTypes::SCHEDULE_EXPIRE_ENROLMENT_DONE, $this->enrolment->enrolment->id]));
    }

    public function testCron()
    {
        /** @var App $app */
        $app = $this->getApp();
        $req = Request::create('/cron', 'POST');
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(2, $this->queueMessages[Queue::DO_ENROLMENT_CRON]);
    }
}
