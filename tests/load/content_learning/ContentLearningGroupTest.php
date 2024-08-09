<?php

namespace go1\enrolment\tests\load\content_learning;

use go1\enrolment\content_learning\ContentLearningQuery;
use go1\util\enrolment\EnrolmentStatuses;
use Symfony\Component\HttpFoundation\Request;

class ContentLearningGroupTest extends ContentLearningTestCase
{
    public function testGetAllLearner()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?jwt=$this->adminJwt&groupId={$this->groupId}");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($this->assignedEnrolmentIds['all']);
    }

    public function testAssignedLearners()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&jwt=$this->adminJwt&groupId={$this->groupId}");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($this->assignedEnrolmentIds['all']);
    }

    public function testGetAllSelfDirected()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=self-directed&jwt=$this->adminJwt&groupId={$this->groupId}");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds([]);
    }

    public function enrolmentStatus()
    {
        $this->getApp();

        return [
            [
                'not-started',
                null,
                $this->assignedEnrolmentIds[EnrolmentStatuses::NOT_STARTED]
            ],
            [
                'in-progress',
                null,
                $this->assignedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS]
            ],
            [
                'completed',
                null,
                array_merge($this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED], $this->assignedEnrolmentIds[ContentLearningQuery::NOT_PASSED])
            ],
            [
                'completed',
                1,
                $this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED]
            ],
            [
                'completed',
                0,
                $this->assignedEnrolmentIds[ContentLearningQuery::NOT_PASSED]
            ],
        ];
    }

    /** @dataProvider enrolmentStatus */
    public function testGetWithStatuses($status, $passStatus, $expectedList)
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId");
        $req->query->replace([
            'status'        => $status,
            'passed'        => $passStatus,
            'jwt'           => $this->adminJwt,
            'groupId'       => $this->groupId,
        ]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds($expectedList);
    }

    public function facetStatuses()
    {
        $this->getApp();

        $facetWithGroup = [
            'all'           => 4,
            'overdue'       => 0,
            'assigned'      => 4,
            'self-directed' => 0,
            'not-started'   => 1,
            'in-progress'   => 1,
            'completed'     => 2,
            'not-passed'    => 1,
        ];

        $facetWithOutGroup = [
            'all'           => 10,
            'overdue'       => 2,
            'assigned'      => 6,
            'self-directed' => 4,
            'not-started'   => 4,
            'in-progress'   => 2,
            'completed'     => 4,
            'not-passed'    => 2,
        ];

        return [
            [null, null, $this->groupId, $facetWithGroup],
            ['not-started', null, $this->groupId, $facetWithGroup],
            ['in-progress', null, $this->groupId, $facetWithGroup],
            ['completed', null, $this->groupId, $facetWithGroup],
            ['completed', 1, $this->groupId, $facetWithGroup],
            ['completed', 0, $this->groupId, $facetWithGroup],
            [null, null, null, $facetWithOutGroup],
            ['not-started', null, null, $facetWithOutGroup],
            ['in-progress', null, null, $facetWithOutGroup],
            ['completed', null, null, $facetWithOutGroup],
            ['completed', 1, null, $facetWithOutGroup],
            ['completed', 0, null, $facetWithOutGroup],
        ];
    }

    /** @dataProvider facetStatuses */
    public function testGetGroupWithFacet($status, $passStatus, $groupId, $expectedFacet)
    {
        $app = $this->getApp();
        $summary = [
            'all'           => $expectedFacet['all'],
            "not_passed"    => $expectedFacet['not-passed'],
            "in_progress"   => $expectedFacet['in-progress'],
            "overdue"       => $expectedFacet['overdue'],
            "assigned"      => $expectedFacet['assigned'],
            "completed"     => $expectedFacet['completed'],
            "self_directed" => $expectedFacet['self-directed'],
            "not_started"   => $expectedFacet['not-started'],
        ];
        $this->mockReportDataService($app, $summary);
        $req = Request::create("/content-learning/$this->portalId/$this->contentId");
        $req->query->replace([
            'status'  => $status,
            'passed'  => $passStatus,
            'groupId' => $groupId,
            'jwt'     => $this->adminJwt,
            'facet'   => true,
        ]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertNotEmpty($body);
        $this->assertNotEmpty($body['data']['facet']);
        $this->assertEquals($expectedFacet, $body['data']['facet']);
    }
}
