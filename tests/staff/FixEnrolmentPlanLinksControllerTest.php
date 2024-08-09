<?php

use Doctrine\DBAL\Connection;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\plan\PlanStatuses;
use go1\util\plan\PlanTypes;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class FixEnrolmentPlanLinksControllerTest extends EnrolmentTestCase
{
    public function testAddMissingEnrolmentPlanLinksWithOffset()
    {
        $app = $this->getApp();

        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        for ($i = 2; $i <= 10; $i++) {
            $db->insert('gc_plan', [
                'id' => $i,
                'user_id' => $i,
                'instance_id' => $i * 2,
                'entity_id' => $i * 3,
                'created_date' => date('Y-m-d H:i:s'),
                'entity_type' => PlanTypes::ENTITY_LO,
                'type' => PlanTypes::ASSIGN,
                'status' => PlanStatuses::ASSIGNED
            ]);
            $db->insert('gc_enrolment', [
                'id' => 10 * $i,
                'user_id' => $i,
                'profile_id' => $i * 5,
                'instance_id' => 0,
                'status' => 'completed',
                'pass' => 1,
                'changed' => date('Y-m-d H:i:s'),
                'timestamp' => time(),
                'taken_instance_id' => $i * 2,
                'lo_id' => $i * 3,
                'parent_enrolment_id' => $i == 8 ? 1 : 0
            ]);
        }

        $db->insert('gc_enrolment_plans', ['plan_id' => 2, 'enrolment_id' => 20]);
        $db->insert('gc_enrolment_plans', ['plan_id' => 4, 'enrolment_id' => 40]);
        $db->insert('gc_enrolment_plans', ['plan_id' => 6, 'enrolment_id' => 60]);
        $db->insert('gc_enrolment_plans', ['plan_id' => 9, 'enrolment_id' => 90]);

        $req = Request::create('/staff/add-missing-enrolment-plan-links/5?fix=1&jwt=' . UserHelper::ROOT_JWT, 'POST');
        $res = $app->handle($req);

        $ids = $db->createQueryBuilder()->select('plan_id')->from('gc_enrolment_plans')->execute()->fetchAll(DB::COL);

        $this->assertSame([2, 4, 6, 7, 9, 10], array_map('intval', $ids));
        $responseArr = json_decode($res->getContent(), true);
        $this->assertEquals(['new_offset' => 10, 'count' => 2], $responseArr);
        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testRemoveExtraEnrolmentPlanLinksWithOffset()
    {
        $app = $this->getApp();

        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        for ($i = 2; $i <= 10; $i++) {
            $db->insert('gc_plan', [
                'id' => $i,
                'user_id' => $i,
                'instance_id' => $i * 2,
                'entity_id' => $i * 3,
                'created_date' => date('Y-m-d H:i:s'),
                'entity_type' => PlanTypes::ENTITY_LO,
                'type' => PlanTypes::ASSIGN,
                'status' => PlanStatuses::ASSIGNED
            ]);
            $db->insert('gc_enrolment', [
                'id' => 10 * $i,
                'user_id' => $i,
                'profile_id' => $i * 5,
                'instance_id' => 0,
                'status' => 'completed',
                'pass' => 1,
                'changed' => date('Y-m-d H:i:s'),
                'timestamp' => time(),
                'taken_instance_id' => $i * 2,
                'lo_id' => $i * 3,
                'parent_enrolment_id' => $i == 8 ? 1 : 0
            ]);
        }

        $db->insert('gc_enrolment_plans', ['plan_id' => 2, 'enrolment_id' => 20]);
        $db->insert('gc_enrolment_plans', ['plan_id' => 4, 'enrolment_id' => 40]);
        $db->insert('gc_enrolment_plans', ['plan_id' => 6, 'enrolment_id' => 60]);
        $db->insert('gc_enrolment_plans', ['plan_id' => 8, 'enrolment_id' => 80]);
        $db->insert('gc_enrolment_plans', ['plan_id' => 9, 'enrolment_id' => 90]);

        $req = Request::create('/staff/remove-extra-enrolment-plan-links/5?fix=1&jwt=' . UserHelper::ROOT_JWT, 'POST');
        $res = $app->handle($req);

        $ids = $db->createQueryBuilder()->select('plan_id')->from('gc_enrolment_plans')->execute()->fetchAll(DB::COL);

        $this->assertSame([2, 4, 6, 9], array_map('intval', $ids));
        $responseArr = json_decode($res->getContent(), true);
        $this->assertEquals(['new_offset' => 8, 'count' => 1], $responseArr);
        $this->assertEquals(200, $res->getStatusCode());
    }
}
