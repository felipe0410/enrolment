<?php

namespace go1\enrolment\tests\update;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;

class DependencyCompleteTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $userId;
    private $profileId = 555;
    private $jwt;
    private $courseEnrolmentId;
    private $module1Id;
    private $module1EnrolmentId;
    private $module2Id;
    private $module2EnrolmentId;
    private $videoId;
    private $videoEnrolmentId;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        $go1 = $app['dbs']['go1'];
        $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'learner@qa.com', 'profile_id' => $this->profileId]);
        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $this->userId,
            $this->createUser($go1, ['instance' => 'az.mygo1.com', 'mail' => 'learner@qa.com'])
        );
        $this->jwt = $this->jwtForUser($go1, $this->userId, 'az.mygo1.com');

        $this->portalId = $this->createPortal($go1, []);
        $courseId = $this->createCourse($go1, $base = ['instance_id' => $this->portalId]);
        $this->link($go1, EdgeTypes::HAS_MODULE, $courseId, $this->module1Id = $this->createModule($go1, $base));
        $this->link($go1, EdgeTypes::HAS_MODULE, $courseId, $this->module2Id = $this->createModule($go1, $base));
        $this->link($go1, EdgeTypes::HAS_LI, $this->module2Id, $this->videoId = $this->createVideo($go1, $base));
        $this->link($go1, EdgeTypes::HAS_MODULE_DEPENDENCY, $this->module1Id, $this->module2Id);

        $this->courseEnrolmentId = $this->createEnrolment($go1, [
            'taken_instance_id' => $this->portalId,
            'lo_id'             => $courseId,
            'profile_id'        => 555,
            'user_id'           => $this->userId,
            'status'            => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $this->module1EnrolmentId = $this->createEnrolment($go1, [
            'taken_instance_id'   => $this->portalId,
            'lo_id'               => $this->module1Id,
            'profile_id'          => 555,
            'user_id'             => $this->userId,
            'parent_enrolment_id' => $this->courseEnrolmentId,
            'status'              => EnrolmentStatuses::PENDING,
        ]);

        $this->module2EnrolmentId = $this->createEnrolment($go1, [
            'taken_instance_id'   => $this->portalId,
            'lo_id'               => $this->module2Id,
            'profile_id'          => 555,
            'user_id'             => $this->userId,
            'parent_enrolment_id' => $this->courseEnrolmentId,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $this->videoEnrolmentId = $this->createEnrolment($go1, [
            'taken_instance_id'   => $this->portalId,
            'lo_id'               => $this->videoId,
            'profile_id'          => 555,
            'user_id'             => $this->userId,
            'parent_enrolment_id' => $this->module2EnrolmentId,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
        ]);
    }

    public function testCompleteDependency()
    {
        /** @var EnrolmentRepository $r */
        $app = $this->getApp();
        $r = $app[EnrolmentRepository::class];
        $videoEnrolment = EnrolmentHelper::findEnrolment($app['dbs']['go1'], $this->portalId, $this->userId, $this->videoId);
        $r->changeStatus($videoEnrolment, EnrolmentStatuses::COMPLETED);

        // There's a message published
        $this->assertEquals($this->module2Id, $this->queueMessages[Queue::ENROLMENT_UPDATE][0]['id']);
        $this->assertEquals($this->videoId, $this->queueMessages[Queue::ENROLMENT_UPDATE][1]['id']);
        $this->assertEquals(555, $this->queueMessages[Queue::ENROLMENT_UPDATE][0]['profile_id']);
        $this->assertEquals(555, $this->queueMessages[Queue::ENROLMENT_UPDATE][1]['profile_id']);

        return [$app, Queue::ENROLMENT_UPDATE, $this->queueMessages[Queue::ENROLMENT_UPDATE][0]];
    }
}
