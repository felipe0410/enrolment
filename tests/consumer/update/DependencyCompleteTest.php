<?php

namespace go1\enrolment\tests\consumer\update;

use Doctrine\DBAL\Connection;
use go1\app\App;
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
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class DependencyCompleteTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $profileId = 555;
    private $userId;
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
        parent::appInstall($app);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];

        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'learner@qa.com', 'profile_id' => $this->profileId]),
            $this->createUser($go1, ['instance' => 'az.mygo1.com', 'mail' => 'learner@qa.com'])
        );
        $this->jwt = $this->jwtForUser($go1, $userId, 'az.mygo1.com');

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
            'user_id'           => $userId,
            'status'            => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $this->module1EnrolmentId = $this->createEnrolment($go1, [
            'taken_instance_id'   => $this->portalId,
            'lo_id'               => $this->module1Id,
            'profile_id'          => 555,
            'user_id'             => $userId,
            'parent_enrolment_id' => $this->courseEnrolmentId,
            'status'              => EnrolmentStatuses::PENDING,
        ]);

        $this->module2EnrolmentId = $this->createEnrolment($go1, [
            'taken_instance_id'   => $this->portalId,
            'lo_id'               => $this->module2Id,
            'profile_id'          => 555,
            'user_id'             => $userId,
            'parent_enrolment_id' => $this->courseEnrolmentId,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $this->videoEnrolmentId = $this->createEnrolment($go1, [
            'taken_instance_id'   => $this->portalId,
            'lo_id'               => $this->videoId,
            'profile_id'          => 555,
            'user_id'             => $userId,
            'parent_enrolment_id' => $this->module2EnrolmentId,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
        ]);
        $this->userId = $userId;
    }

    public function testCompleteDependency()
    {
        $app = $this->getApp();

        /** @var EnrolmentRepository $r */
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

    public function testMessage1Consuming()
    {
        /** @var App $app */
        [$app, $routingKey, $body] = $this->testCompleteDependency();

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];

        # This is internal processing, an other logic.
        $body['pass'] = 1;
        $go1->executeQuery('UPDATE gc_enrolment SET pass = 1 WHERE status = ?', [EnrolmentStatuses::COMPLETED]);

        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace(['routingKey' => $routingKey, 'body' => json_encode($body)]);
        $res = $app->handle($req);
        $msg = &$this->queueMessages[Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENTS][0];

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($this->module1Id, $msg['moduleId'], "There's a message published");

        return [$app, Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENTS, $msg];
    }

    public function testMessage2Consuming()
    {
        /** @var App $app */
        [$app, $routingKey, $body] = $this->testMessage1Consuming();
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace(['routingKey' => $routingKey, 'body' => json_encode($body)]);
        $res = $app->handle($req);
        $msg = &$this->queueMessages[Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENT][0];

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($this->module1Id, $msg['moduleId'], "There's a message published");
        $this->assertEquals($this->module1EnrolmentId, $msg['enrolmentId']);

        return [$app, Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENT, $msg];
    }

    public function testMessage3Consuming()
    {
        /** @var App $app */
        [$app, $routingKey, $body] = $this->testMessage2Consuming();

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace(['routingKey' => $routingKey, 'body' => json_encode($body)]);
        $res = $app->handle($req);

        // All messages are processed, the enrolment for module 1 should now be "in-progress".
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $go1->fetchColumn('SELECT status FROM gc_enrolment WHERE id = ?', [$this->module1EnrolmentId]));
    }
}
