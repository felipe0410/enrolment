<?php

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\controller\CronController;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class PendingPublishingTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private int    $portalId;
    private int    $courseId;
    private string $jwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];

        // Setup the course, with start date & schedule publishing pending-enrolments 10 minutes before the course is started.
        $portalId = $this->portalId = $this->createPortal($go1, ['title' => 'qa.mygo1.com']);
        $this->createPortalPublicKey($go1, ['instance' => 'qa.mygo1.com']);
        $this->createPortalPrivateKey($go1, ['instance' => 'qa.mygo1.com']);

        $start = strtotime('+ 2 months');
        $courseId = $this->courseId = $this->createCourse($go1, ['instance_id' => $portalId, 'event' => ['start' => $start]]);
        $this->link($go1, EdgeTypes::PUBLISH_ENROLMENT_LO_START_BASE, $courseId, strtotime('- 15 minutes', $start));

        $userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'uuid' => 'USER_UUID', 'mail' => 'foo@bar.baz']);
        $accountId = $this->createUser($go1, ['instance' => 'qa.mygo1.com', 'mail' => 'foo@bar.baz']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $userId, $accountId);
        $this->jwt = $this->jwtForUser($go1, $userId, 'qa.mygo1.com');

        // Create some pending enrolments
        $_ = ['status' => EnrolmentStatuses::PENDING, 'lo_id' => $courseId, 'taken_instance_id' => $portalId, 'user_id' => $userId];

        foreach ([111, 222, 333] as $profileId) {
            $this->link(
                $go1,
                EdgeTypes::HAS_ACCOUNT,
                $this->createUser($go1, ['mail' => "{$profileId}@qa.com", 'instance' => $app['accounts_name'], 'profile_id' => $profileId]),
                $this->createUser($go1, ['mail' => "{$profileId}@qa.com", 'instance' => 'qa.mygo1.com'])
            );

            $this->createEnrolment($go1, $_ + ['profile_id' => $profileId, 'user_id' => $userId]);
        }

        // Change base time for controller to 5 minutes before next 2 months.
        $app->extend(CronController::class, function (CronController $cron) {
            $rCron = new ReflectionObject($cron);
            $rTime = $rCron->getProperty('time');
            $rTime->setAccessible(true);
            $rTime->setValue($cron, strtotime('- 5 minutes', strtotime('+ 2 months')));

            return $cron;
        });
    }

    public function testBasic()
    {
        $app = $this->getApp();

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];

        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::DO_ENROLMENT_CRON,
            'body'       => json_encode(['task' => CronController::TASK_ENABLE_PENDING]),
        ]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(0, $go1->fetchColumn('SELECT COUNT(*) FROM gc_enrolment WHERE lo_id = ? AND status = ?', [$this->courseId, EnrolmentStatuses::PENDING]));
        $this->assertEquals(3, $go1->fetchColumn('SELECT COUNT(*) FROM gc_enrolment WHERE lo_id = ? AND status = ?', [$this->courseId, EnrolmentStatuses::IN_PROGRESS]));
    }
}
