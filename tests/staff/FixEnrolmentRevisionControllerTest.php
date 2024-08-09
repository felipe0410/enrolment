<?php

use Doctrine\DBAL\Connection;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class FixEnrolmentRevisionControllerTest extends EnrolmentTestCase
{
    public function testFixEnrolmentRevisionParentLoId()
    {
        $app = $this->getApp();

        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        for ($i = 2; $i <= 10; $i++) {
            $db->insert('gc_enrolment_revision', [
                'id' => 10 * $i,
                'user_id' => $i,
                'profile_id' => $i * 5,
                'instance_id' => 0,
                'status' => 'completed',
                'pass' => 1,
                'note' => '',
                'timestamp' => time(),
                'taken_instance_id' => $i * 2,
                'lo_id' => $i * 3,
                'enrolment_id' => 10 * $i,
                'parent_lo_id' => $i * 3,
                'parent_enrolment_id' => 10 * $i,
            ]);
        }

        $req = Request::create('/staff/fix-enrolment-revision-parent-lo/5?fix=1&jwt=' . UserHelper::ROOT_JWT, 'POST');
        $res = $app->handle($req);

        $ids = $db->createQueryBuilder()->select('parent_lo_id')->from('gc_enrolment_revision')->execute()->fetchAll(DB::COL);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0], array_map('intval', $ids));

        $ids = $db->createQueryBuilder()->select('parent_enrolment_id')->from('gc_enrolment_revision')->execute()->fetchAll(DB::COL);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0], array_map('intval', $ids));

        $responseArr = json_decode($res->getContent(), true);
        $this->assertEquals(['new_offset' => 10, 'count' => 2, 'new_offset' => 100, 'count' => 9, 'fixed' => 9], $responseArr);
        $this->assertEquals(200, $res->getStatusCode());
    }
}
