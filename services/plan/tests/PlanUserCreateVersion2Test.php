<?php

namespace go1\core\learning_record\plan\tests;

use DateTime as DefaultDateTime;
use go1\enrolment\EnrolmentRepository;
use go1\util\DateTime;
use go1\util\plan\Plan;
use go1\util\plan\PlanStatuses;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use Symfony\Component\HttpFoundation\Request;

class PlanUserCreateVersion2Test extends PlanUserCreateTest
{
    use EnrolmentMockTrait;

    public function data200()
    {
        $this->getApp();

        return [
            [$this->fooManagerUserJwt, $this->fooManagerUserId],
            [$this->adminUserJwt, $this->adminUserId],
            [$this->authorUserJwt, $this->authorUserId],
            [$this->fooUserJwt, $this->fooUserId],
        ];
    }

    /** @dataProvider data200 */
    public function test200NonLearners($jwt, $assignerId)
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$jwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => $dueDate = (new DefaultDateTime('+30 days'))->format(DATE_ISO8601),
            'version'  => 2,
        ]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $planId = json_decode($res->getContent())->id;
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$planId]));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(assigner_id) FROM gc_plan WHERE assigner_id = ?', [$assignerId]));
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(id) FROM gc_plan_revision'));
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $plan = Plan::create($msg);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
    }

    public function test200ReassignNotStarted()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $originalAssignerId = $this->createUser($go1, [
            'mail'     => 'assigner@foo.bar',
            'instance' => $app['accounts_name'],
        ]);
        $planId = $this->createPlan($go1, [
            'user_id'     => $this->fooUserId,
            'assigner_id' => $originalAssignerId,
            'entity_id'   => $this->loId,
            'instance_id' => $this->portalId,
        ]);

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$this->fooManagerUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => $dueDate = (new DefaultDateTime('+30 days'))->format(DATE_ISO8601),
            'version'  => 2,
        ]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $newPlanId = json_decode($res->getContent())->id;
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_DELETE]);
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$planId]));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$newPlanId]));
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(id) FROM gc_enrolment_plans'));
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(id) FROM gc_enrolment'));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(assigner_id) FROM gc_plan WHERE assigner_id = ?', [$this->fooManagerUserId]));
        $this->assertEquals($originalAssignerId, $go1->fetchColumn('SELECT assigner_id FROM gc_plan_revision WHERE plan_id = ?', [$planId]));
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $plan = Plan::create($msg);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
        $this->assertEquals($originalAssignerId, $msg->embedded->original->assigner_id);
    }

    public function test200Reassign()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $originalAssignerId = $this->createUser($go1, [
            'mail'     => 'assigner@foo.bar',
            'instance' => $app['accounts_name'],
        ]);
        $planId = $this->createPlan($go1, [
            'user_id'     => $this->fooUserId,
            'assigner_id' => $originalAssignerId,
            'entity_id'   => $this->loId,
            'instance_id' => $this->portalId,
        ]);

        $enrolmentId = $this->createEnrolment($go1, ['user_id' => $this->fooUserId, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId]);
        $go1->insert('gc_enrolment_plans', ['enrolment_id' => $enrolmentId, 'plan_id' => $planId]);

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$this->fooManagerUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => $dueDate = (new DefaultDateTime('+30 days'))->format(DATE_ISO8601),
            'version'  => 2,
        ]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $newPlanId = json_decode($res->getContent())->id;
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $this->assertCount(1, $this->queueMessages[Queue::PLAN_DELETE]);
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_DELETE]);
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$planId]));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$newPlanId]));
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(id) FROM gc_enrolment_plans'));
        $this->assertEquals(0, $go1->fetchColumn('SELECT count(id) FROM gc_enrolment'));
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(assigner_id) FROM gc_plan WHERE assigner_id = ?', [$this->fooManagerUserId]));
        $this->assertEquals($originalAssignerId, $go1->fetchColumn('SELECT assigner_id FROM gc_plan_revision WHERE plan_id = ?', [$planId]));
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $plan = Plan::create($msg);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
        $this->assertEquals($originalAssignerId, $msg->embedded->original->assigner_id);
    }

    public function test200SelfDirected()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $newEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->fooUserId, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId]);

        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$this->fooManagerUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => $dueDate = (new DefaultDateTime('+30 days'))->format(DATE_ISO8601),
            'version'  => 2,
        ]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $newPlanId = json_decode($res->getContent())->id;

        $this->assertEquals(
            1,
            $go1->fetchColumn('SELECT 1 FROM gc_enrolment_plans WHERE enrolment_id = ? AND plan_id = ?', [$newEnrolmentId, $newPlanId])
        );

        $this->assertCount(1, $this->queueMessages[Queue::PLAN_CREATE]);
        $msg = json_decode(json_encode($this->queueMessages[Queue::PLAN_CREATE][0]));
        $plan = Plan::create($msg);
        $this->assertEquals(DateTime::create($dueDate), $plan->due);
        $this->assertEquals(1, $go1->fetchColumn('SELECT count(id) FROM gc_plan WHERE id = ?', [$newPlanId]));
    }

    public function test400AssertVersion()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$this->barUserJwt}", 'POST');
        $req->request->replace([
            'status'   => PlanStatuses::ASSIGNED,
            'due_date' => (new DefaultDateTime('+30 days'))->format(DATE_ISO8601),
            'notify'   => true,
            'data'     => ['note' => 'foo'],
            'version'  => 1,
        ]);

        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('Value "1" does not equal expected value "2".', json_decode($res->getContent())->message);
    }

    public function test400AssertDueDate()
    {
        $app = $this->getApp();
        $req = Request::create("/plan/{$this->portalId}/{$this->loId}/user/{$this->fooUserId}?jwt={$this->fooManagerUserJwt}", 'POST');

        { # due_date null
            $req->request->replace([
                'status'   => PlanStatuses::ASSIGNED,
                'due_date' => null,
                'notify'   => true,
                'data'     => ['note' => 'foo'],
                'version'  => 2,
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString("The following 1 assertions failed:\n1) due_date: Invalid due date.\n", json_decode($res->getContent())->message);
        }

        { # without due_date
            $req->request->replace([
                'status'   => PlanStatuses::ASSIGNED,
                'notify'   => true,
                'data'     => ['note' => 'foo'],
                'version'  => 2,
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString("The following 1 assertions failed:\n1) due_date: Invalid due date.\n", json_decode($res->getContent())->message);
        }

        { # due_date invalid
            $req->request->replace([
                'status'   => PlanStatuses::ASSIGNED,
                'due_date' => 'foo',
                'notify'   => true,
                'data'     => ['note' => 'foo'],
                'version'  => 2,
            ]);

            $res = $app->handle($req);

            $this->assertEquals(400, $res->getStatusCode());
            $this->assertStringContainsString("The following 1 assertions failed:\n1) due_date: Invalid due date.\n", json_decode($res->getContent())->message);
        }
    }
}
