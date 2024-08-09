<?php

namespace go1\enrolment\tests\load\content_learning;

use Doctrine\DBAL\Connection;
use go1\enrolment\content_learning\ContentLearningFilterOptions;
use go1\enrolment\content_learning\ContentLearningQuery;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\UserService;
use go1\util\enrolment\EnrolmentStatuses;
use Symfony\Component\HttpFoundation\Request;

class ContentLearningEnrolmentTest extends ContentLearningTestCase
{
    public function testNoJWT()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId");
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());

        $this->assertStringContainsString('Missing or invalid JWT', $res->getContent());
    }

    public function testStudentCANNOTViewContentLearning()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?jwt=$this->studentJwt");
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testAdminViewContentLearning()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=in-progress&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $learning = json_decode($res->getContent(), true);
        $this->assertNotEmpty($learning);
        $this->assertQueryResultHasEnrolmentIds(array_merge($this->assignedEnrolmentIds['in-progress'], $this->selfDirectedEnrolmentIds['in-progress']));
    }

    public function testFilterByStatus()
    {
        {
            $app = $this->getApp();
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=in-progress&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(200, $res->getStatusCode());
            $learning = json_decode($res->getContent(), true);
            $this->assertNotEmpty($learning);
        }

        # Invalid status
        {
            $app = $this->getApp();
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=xxx&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }
    }

    public function testFilterByManager()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=in-progress&jwt=$this->managerJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $learning = json_decode($res->getContent(), true);
        $this->assertNotEmpty($learning);
    }

    public function testPagination()
    {
        # Can use offset & limit
        {
            $app = $this->getApp();
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=in-progress&offset=10&limit=100&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(200, $res->getStatusCode());
            $learning = json_decode($res->getContent(), true);
            $this->assertNotEmpty($learning);
        }

        # Offset must great than 0
        {
            $app = $this->getApp();
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=in-progress&offset=-1&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }

        # Offset must not great than 100
        {
            $app = $this->getApp();
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=in-progress&limit=101&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }
    }

    public function testSort()
    {
        # Can use offset & limit
        {
            $app = $this->getApp();
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=in-progress&sort[startedAt]=desc&sort[endedAt]=asc&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(200, $res->getStatusCode());

            $learning = json_decode($res->getContent(), true);
            $this->assertNotEmpty($learning);
        }

        # Invalid field name
        {
            $app = $this->getApp();
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=in-progress&sort[foo]=desc&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }
        # Invalid operation
        {
            $app = $this->getApp();
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?sort[startedAt]=foo&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }
    }

    public function testFilterByUserId()
    {
        {
            $app = $this->getApp();
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=in-progress&userIds[]=10&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(200, $res->getStatusCode());

            $learning = json_decode($res->getContent(), true);
            $this->assertNotEmpty($learning);
        }

        # Too many userIds
        {
            $app = $this->getApp();
            $userIds = range(1, 21);
            $req = Request::create("/content-learning/$this->portalId/$this->contentId");
            $req->query->replace([
                'status'  => 'in-progress',
                'jwt'     => $this->adminJwt,
                'userIds' => $userIds
            ]);

            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }
    }

    public function testFilterByAssignerId()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?status=in-progress&assignerIds[]=10&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $learning = json_decode($res->getContent(), true);
        $this->assertNotEmpty($learning);
    }

    public function testFilterDateFields()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId");
        $req->query->replace([
            'status'     => EnrolmentStatuses::IN_PROGRESS,
            'jwt'        => $this->adminJwt,
            'assignedAt' => [
                'from' => 1,
                'to'   => 2
            ],
            'dueAt' => [
                'from' => 3,
                'to'   => 4
            ],
            'startedAt' => [
                'from' => 5,
                'to'   => 6
            ],
            'endedAt' => [
                'from' => 7,
                'to'   => 8
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $learning = json_decode($res->getContent(), true);
        $this->assertNotEmpty($learning);
    }

    public function testFindPlansAndLearningRecordsWithDeletedAccount()
    {
        $app = $this->getApp();
        /** @var ConnectionWrapper $db */
        $db = $app['lazy_wrapper']['go1'];
        $db->get()->update('gc_user', ['status' => 0], ['status' => 1]);

        $o = new ContentLearningFilterOptions();
        $o->accountStatus = 1;
        $o->loId = $this->contentId;
        $o->portal = (object)[ 'id' => $this->portalId, 'title' => $this->portalName];

        $q = new ContentLearningQuery($db, true, $app[UserService::class]);
        $result = $q->findPlansAndLearningRecords($o, 0);
        $this->assertEquals(0, $result->getCount());

        $o->accountStatus = null;
        $q = new ContentLearningQuery($db, true, $app[UserService::class]);
        $result = $q->findPlansAndLearningRecords($o, 0);
        $this->assertGreaterThan(0, $result->getCount());
    }

    public function testFindPlansWithDeletedAccount()
    {
        $app = $this->getApp();
        /** @var ConnectionWrapper $db */
        $db = $app['lazy_wrapper']['go1'];
        $db->get()->update('gc_user', ['status' => 0], ['status' => 1]);

        $o = new ContentLearningFilterOptions();
        $o->accountStatus = 1;
        $o->loId = $this->contentId;
        $o->portal = (object)[ 'id' => $this->portalId, 'title' => $this->portalName ];

        $q = new ContentLearningQuery($db, true, $app[UserService::class]);
        $result = $q->findPlans($o, 0);
        $this->assertEquals(0, $result->getCount());

        $o->accountStatus = null;
        $q = new ContentLearningQuery($db, true, $app[UserService::class]);
        $result = $q->findPlans($o, 0);
        $this->assertGreaterThan(0, $result->getCount());
    }

    public function passedFilter()
    {
        return [
            [null, 1],
            [null, 0],
            ['in-progress', 1],
            ['in-progress', 0],
        ];
    }

    /**
     * @dataProvider passedFilter
     */
    public function testPassedFilter400($enrolmentStatus, $passedStatus)
    {
        $app = $this->getApp();

        $req = Request::create("/content-learning/$this->portalId/$this->contentId");
        $req->query->replace([
            'status' => $enrolmentStatus,
            'jwt'    => $this->adminJwt,
            'passed' => $passedStatus,
        ]);
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }
}
