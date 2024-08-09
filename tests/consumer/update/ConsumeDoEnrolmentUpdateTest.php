<?php

namespace go1\enrolment\tests\consumer\update;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\EnrolmentLegacy;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\plan\Plan;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class ConsumeDoEnrolmentUpdateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $profileId = 555;
    private $userId;
    private $jwt;
    private $enrolmentId;

    private function repository(DomainService $app): EnrolmentRepository
    {
        return $app[EnrolmentRepository::class];
    }

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];

        $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'profile_id' => $this->profileId, 'mail' => 'learner@qa.com']);
        $accountId = $this->createUser($go1, ['instance' => 'az.mygo1.com', 'mail' => 'learner@qa.com']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $accountId);
        $this->jwt = $this->jwtForUser($go1, $this->userId, 'az.mygo1.com');
        $this->portalId = $this->createPortal($go1, []);
        $courseId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->enrolmentId = $this->createEnrolment($go1, [
            'taken_instance_id' => $this->portalId,
            'lo_id'             => $courseId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'parent_lo_id'      => 111,
            'status'            => EnrolmentStatuses::PENDING,
            'data'              => json_encode(['foo' => 'bar', 'history' => ['baz']]),
        ]);
    }

    public function test()
    {
        $app = $this->getApp();
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::DO_ENROLMENT,
            'body' => (object) [
                'action' => Queue::ENROLMENT_UPDATE,
                'body'       => (object) [
                    'id'         => $this->enrolmentId,
                    'user_id'    => $this->userId,
                    'status'     => EnrolmentStatuses::COMPLETED,
                    'start_date' => $startDate = (new \DateTime('-1 day'))->format(DATE_ISO8601),
                    'end_date'   => $endDate = (new \DateTime())->format(DATE_ISO8601),
                    'result'     => 80,
                    'pass'       => EnrolmentStatuses::PENDING_REVIEW,
                    'action'     => 'manual update',
                    'context'    => ['foo' => 'bar'],
                ],
            ]
        ]);

        $res = $app->handle($req);
        $enrolment = $this->repository($app)->load($this->enrolmentId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals(80, $enrolment->result);
        $this->assertEquals(EnrolmentStatuses::PENDING_REVIEW, $enrolment->pass);
        $this->assertEquals($startDate, $enrolment->start_date);
        $this->assertEquals($endDate, $enrolment->end_date);

        $this->assertEquals('bar', $enrolment->data->foo);
        $this->assertEquals('baz', $enrolment->data->history[0]);
        $this->assertEquals('manual update', $enrolment->data->history[1]->action);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->data->history[1]->status);
        $this->assertEquals(EnrolmentStatuses::PENDING, $enrolment->data->history[1]->original_status);
        $this->assertEquals('bar', $this->queueMessages[Queue::ENROLMENT_UPDATE][0]['_context']['foo']);
    }

    public function testDueDate()
    {
        $app = $this->getApp();
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::DO_ENROLMENT,
            'body'       => (object) [
                'action' => Queue::ENROLMENT_UPDATE,
                'body' => (object) [
                    'id'       => $this->enrolmentId,
                    'user_id'    => $this->userId,
                    'status'   => EnrolmentStatuses::IN_PROGRESS,
                    'action'   => 'update due date',
                    'due_date' => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
                ]
            ],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_CREATE][0]);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);

        // consume plan create
        $req->request->replace([
            'routingKey' => Queue::PLAN_CREATE,
            'body'       => $plan->jsonSerialize(),
        ]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
    }

    public function testDueDateNull()
    {
        $app = $this->getApp();
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::DO_ENROLMENT,
            'body'       => (object) [
                'action' => Queue::ENROLMENT_UPDATE,
                'body' => (object) [
                    'id'       => $this->enrolmentId,
                    'user_id'    => $this->userId,
                    'status'   => EnrolmentStatuses::IN_PROGRESS,
                    'action'   => 'update due date',
                    'due_date' => null,
                ]
            ],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertArrayNotHasKey(Queue::PLAN_CREATE, $this->queueMessages);
    }

    public function testDependencyModule()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $courseId = $this->createCourse($go1);
        $module1Id = $this->createModule($go1);
        $module2Id = $this->createModule($go1);
        $module3Id = $this->createModule($go1);
        $this->link($go1, EdgeTypes::HAS_MODULE, $courseId, $module1Id);
        $this->link($go1, EdgeTypes::HAS_MODULE, $courseId, $module2Id);
        $this->link($go1, EdgeTypes::HAS_MODULE, $courseId, $module3Id);
        $this->link($go1, EdgeTypes::HAS_MODULE_DEPENDENCY, $module2Id, $module1Id);
        $this->link($go1, EdgeTypes::HAS_MODULE_DEPENDENCY, $module3Id, $module1Id);

        $courseEnrolmentId = $this->createEnrolment($go1, ['lo_id' => $courseId]);
        $module1EnrolmentId = $this->createEnrolment($go1, ['lo_id' => $module1Id]);

        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::ENROLMENT_UPDATE,
            'body'       => (object) [
                'id'       => $module1EnrolmentId,
                'user_id'  => $this->userId,
                'lo_id'    => $module1Id,
                'status'   => EnrolmentStatuses::COMPLETED,
                'pass'     => 1,
                'action'   => 'completed dependency module',
                'original' => (object) [
                    'id'     => $module1EnrolmentId,
                    'lo_id'  => $module1Id,
                    'status' => EnrolmentStatuses::IN_PROGRESS,
                ],
            ],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());

        $this->assertCount(2, $this->queueMessages[Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENTS]);
        $this->assertEquals($module2Id, $this->queueMessages[Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENTS][0]['moduleId']);
        $this->assertEquals($module3Id, $this->queueMessages[Queue::DO_ENROLMENT_CHECK_MODULE_ENROLMENTS][1]['moduleId']);
    }

    public function testDoEnrolment()
    {
        $app = $this->getApp();
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::DO_ENROLMENT,
            'body'       => [
                'action' => Queue::ENROLMENT_UPDATE,
                'body'   => (object) [
                    'id'         => $this->enrolmentId,
                    'status'     => EnrolmentStatuses::COMPLETED,
                    'start_date' => $startDate = (new \DateTime('-1 day'))->format(DATE_ISO8601),
                    'end_date'   => $endDate = (new \DateTime())->format(DATE_ISO8601),
                    'result'     => 80,
                    'pass'       => EnrolmentStatuses::PENDING_REVIEW,
                    'action'     => 'manual update',
                ],
            ]]);

        $res = $app->handle($req);
        $enrolment = $this->repository($app)->load($this->enrolmentId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals(80, $enrolment->result);
        $this->assertEquals(EnrolmentStatuses::PENDING_REVIEW, $enrolment->pass);
        $this->assertEquals($startDate, $enrolment->start_date);
        $this->assertEquals($endDate, $enrolment->end_date);

        $this->assertEquals('bar', $enrolment->data->foo);
        $this->assertEquals('baz', $enrolment->data->history[0]);
        $this->assertEquals('manual update', $enrolment->data->history[1]->action);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->data->history[1]->status);
        $this->assertEquals(EnrolmentStatuses::PENDING, $enrolment->data->history[1]->original_status);
    }

    public function testDoEnrolmentWithDueDate()
    {
        $app = $this->getApp();
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'routingKey' => Queue::DO_ENROLMENT,
            'body'       => [
                'action' => Queue::ENROLMENT_UPDATE,
                'body'   => (object) [
                    'id'       => $this->enrolmentId,
                    'status'   => EnrolmentStatuses::IN_PROGRESS,
                    'action'   => 'update due date',
                    'due_date' => $dueDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
                ],
            ]]);

        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $plan = Plan::create((object) $this->queueMessages[Queue::PLAN_CREATE][0]);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);

        // consume plan create
        $req->request->replace([
            'routingKey' => Queue::PLAN_CREATE,
            'body'       => $plan->jsonSerialize(),
        ]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
    }
}
