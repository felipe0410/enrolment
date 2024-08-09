<?php

use Doctrine\DBAL\Connection;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class FixEnrolmentControllerTest extends EnrolmentTestCase
{
    public function testFixEnrolmentParentLoId()
    {
        $app = $this->getApp();

        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        for ($i = 2; $i <= 10; $i++) {
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
                'parent_lo_id' => $i * 3,
                'parent_enrolment_id' => $i == 8 ? 1 : 0
            ]);
            $db->insert('gc_lo', [
                'id' => $i * 3,
                'single_li' => 1,
                'type' => 'quize',
                'language' => 'en',
                'instance_id' => $i * 2,
                'published' => 1,
                'remote_id' => 123,
                'origin_id' => 0,
                'title' => 'test',
                'description' => 'test2',
                'private' => 1,
                'marketplace' => 0,
                'tags' => 'test',
                'timestamp' => time(),
                'data' => 'terter',
                'created' => 1,
                'sharing' => 1
            ]);
        }

        $req = Request::create('/staff/fix-enrolment-parent-lo/1?fix=1&jwt=' . UserHelper::ROOT_JWT, 'POST');
        $res = $app->handle($req);

        $ids = $db->createQueryBuilder()->select('parent_lo_id')->from('gc_enrolment')->execute()->fetchAll(DB::COL);

        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0], array_map('intval', $ids));
        $responseArr = json_decode($res->getContent(), true);
        $this->assertEquals(['new_offset' => 10, 'count' => 2,  'new_offset' => 100, 'count' => 9, 'fixed' => 9], $responseArr);
        $this->assertEquals(200, $res->getStatusCode());
    }
}
