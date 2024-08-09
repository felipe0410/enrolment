<?php

namespace go1\enrolment\tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use go1\util\DB;
use go1\util\DateTime;
use go1\util\plan\PlanTypes;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class InstallTest extends EnrolmentTestCase
{
    use PlanMockTrait;

    public function test()
    {
        /** @var Connection $db */
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        $schema = $db->getSchemaManager()->createSchema();

        {
            // table: gc_enrolment
            $table = $schema->getTable('gc_enrolment');
            $this->assertTrue($table->hasColumn('id'));
            $this->assertTrue($table->hasColumn('profile_id'));
            $this->assertTrue($table->hasColumn('parent_lo_id'));
            $this->assertTrue($table->hasColumn('lo_id'));
        }

        {
            // table: gc_plan & gc_enrolment_plans
            $this->assertTrue($schema->getTable('gc_plan')->hasColumn('updated_at'));

            $table = $schema->getTable('gc_enrolment_plans');
            $this->assertTrue($schema->hasTable('gc_enrolment_plans'));
            $this->assertTrue($table->hasColumn('id'));
            $this->assertTrue($table->hasColumn('enrolment_id'));
            $this->assertTrue($table->hasColumn('plan_id'));
        }
    }

    public function testNoDuplication()
    {
        $this->expectException(UniqueConstraintViolationException::class);
        /** @var Connection $db */
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        // Make sure we can't create duplication!
        $db->insert('gc_enrolment', $row = ['profile_id' => 1, 'lo_id' => 2, 'instance_id' => 3, 'taken_instance_id' => 4, 'start_date' => 5, 'status' => 'in-progress', 'pass' => 0, 'changed' => time(), 'timestamp' => time(), 'data' => '']);
        $db->insert('gc_enrolment', $row);
    }

    public function testMigrateSuggestedPlanWithNoType1()
    {
        /** @var Connection $db */
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $fooPlanId = $this->createPlan($db, [
            'type'        => 3,
            'assigner_id' => 1000,
            'instance_id' => 9999,
            'entity_id'   => 8888,
            'due_date'    => $expectedDue = time() + (24 * 3600),
        ]);

        $db->insert('gc_enrolment_plans', [
            'enrolment_id' => 77777,
            'plan_id'      => $fooPlanId,
        ]);

        $req = Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace(['compliance_uplift_suggested_plan' => 1]);
        $app->handle($req);

        $edges = $db->executeQuery('SELECT * FROM gc_enrolment_plans WHERE enrolment_id = ?', [77777])
            ->fetchAll(DB::OBJ);
        $this->assertCount(1, $edges);

        $plan = $db->executeQuery('SELECT * FROM gc_plan WHERE id = ?', [$edges[0]->plan_id], [DB::OBJ])
            ->fetch(DB::OBJ);
        $this->assertEquals(PlanTypes::ASSIGN, $plan->type);
        $this->assertEquals(DateTime::create($expectedDue)->format(DATE_ISO8601), $plan->due_date);
    }

    public function testMigrateSuggestedPlanWithType1Latest()
    {
        /** @var Connection $db */
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $fooPlanId = $this->createPlan($db, [
            'type'        => 3,
            'assigner_id' => 1000,
            'instance_id' => 9999,
            'entity_id'   => 8888,
            'due_date'    => time() + (24 * 3600),
        ]);

        $db->insert('gc_enrolment_plans', [
            'enrolment_id' => 77777,
            'plan_id'      => $fooPlanId,
        ]);

        $barPlanId = $this->createPlan($db, [
            'assigner_id' => 1000,
            'instance_id' => 9999,
            'entity_id'   => 8888,
            'due_date'    => $expectedDue = time() + (3 * 24 * 3600),
        ]);

        $db->insert('gc_enrolment_plans', [
            'enrolment_id' => 77777,
            'plan_id'      => $barPlanId,
        ]);

        $req = Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace(['compliance_uplift_suggested_plan' => 1]);
        $app->handle($req);

        $edges = $db->executeQuery('SELECT * FROM gc_enrolment_plans WHERE enrolment_id = ?', [77777])
            ->fetchAll(DB::OBJ);
        $this->assertCount(1, $edges);

        $plan = $db->executeQuery('SELECT * FROM gc_plan WHERE id = ?', [$edges[0]->plan_id], [DB::INTEGER])
            ->fetch(DB::OBJ);
        $this->assertEquals(PlanTypes::ASSIGN, $plan->type);
        $this->assertEquals(DateTime::create($expectedDue)->format(DATE_ISO8601), $plan->due_date);
    }

    public function testMigrateSuggestedPlanWithType3Latest()
    {
        /** @var Connection $db */
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        $fooPlanId = $this->createPlan($db, [
            'type'        => 3,
            'assigner_id' => 1000,
            'instance_id' => 9999,
            'entity_id'   => 8888,
            'due_date'    => $expectedDue = time() + (24 * 3600),
        ]);

        $db->insert('gc_enrolment_plans', [
            'enrolment_id' => 77777,
            'plan_id'      => $fooPlanId,
        ]);

        $barPlanId = $this->createPlan($db, [
            'assigner_id' => 1000,
            'instance_id' => 9999,
            'entity_id'   => 8888,
            'due_date'    => time() - (3 * 24 * 3600),
        ]);

        $db->insert('gc_enrolment_plans', [
            'enrolment_id' => 77777,
            'plan_id'      => $barPlanId,
        ]);

        $req = Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace(['compliance_uplift_suggested_plan' => 1]);
        $app->handle($req);

        $edges = $db->executeQuery('SELECT * FROM gc_enrolment_plans WHERE enrolment_id = ?', [77777])
            ->fetchAll(DB::OBJ);
        $this->assertCount(1, $edges);

        $plan = $db->executeQuery('SELECT * FROM gc_plan WHERE id = ?', [$edges[0]->plan_id], [DB::INTEGER])
            ->fetch(DB::OBJ);
        $this->assertEquals(PlanTypes::ASSIGN, $plan->type);
        $this->assertEquals(DateTime::create($expectedDue)->format(DATE_ISO8601), $plan->due_date);
    }
}
