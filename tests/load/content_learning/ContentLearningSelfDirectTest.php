<?php

namespace go1\enrolment\tests\load\content_learning;

use go1\enrolment\content_learning\ContentLearningQuery;
use go1\util\enrolment\EnrolmentStatuses;
use Symfony\Component\HttpFoundation\Request;

class ContentLearningSelfDirectTest extends ContentLearningTestCase
{
    public function testGetAllSelfDirected()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=self-directed&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($this->selfDirectedEnrolmentIds['all']);
    }

    public function testGetSelfDirectedFilterByNotStartedEnrolmentStatus()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=self-directed&status=not-started&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($this->selfDirectedEnrolmentIds[EnrolmentStatuses::NOT_STARTED]);
    }

    public function testGetSelfDirectedFilterByEnrolmentStatus()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=self-directed&status=completed&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds(array_merge($this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED], $this->selfDirectedEnrolmentIds[ContentLearningQuery::NOT_PASSED]));
        $this->assertQueryResultHasPlanIds([]);

        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=self-directed&status=completed&passed=1&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds($this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED]);
        $this->assertQueryResultHasPlanIds([]);

        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=self-directed&status=completed&passed=0&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds($this->selfDirectedEnrolmentIds[ContentLearningQuery::NOT_PASSED]);
        $this->assertQueryResultHasPlanIds([]);
    }

    public function testGetSelfDirectedFacet()
    {
        $app = $this->getApp();
        $summary = [
            'all'           => 4,
            "not_passed"    => 1,
            "in_progress"   => 1,
            "overdue"       => 0,
            "assigned"      => 1,
            "completed"     => 2,
            "self_directed" => 1,
            "not_started"   => 1,
        ];
        $this->mockReportDataService($app, $summary);
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=self-directed&facet=true&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $learning = json_decode($res->getContent(), true);
        $this->assertNotEmpty($learning['data']['facet']);

        $this->assertEquals([
            'total'       => count($this->selfDirectedEnrolmentIds['all']),
            'not-started' => count($this->selfDirectedEnrolmentIds[EnrolmentStatuses::NOT_STARTED]),
            'in-progress' => count($this->selfDirectedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS]),
            'completed'   => count($this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED]) + count($this->selfDirectedEnrolmentIds[ContentLearningQuery::NOT_PASSED]),
            'not-passed'  => count($this->selfDirectedEnrolmentIds[ContentLearningQuery::NOT_PASSED]),
            'overdue'     => 0,
        ], $learning['data']['facet']);
    }

    public function filtersDataProvider()
    {
        $this->getApp();

        return [
            'startedAt.from'  => [
                [
                    'startedAt' => [
                        'from' => $this->createDateTime('-3 days')->getTimestamp(),
                    ],
                ],
                $this->selfDirectedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS],
                [],
            ],
            'startedAt.to'    => [
                [
                    'startedAt' => [
                        'to' => $this->createDateTime('-2 days')->getTimestamp(),
                    ],
                    'status'    => EnrolmentStatuses::IN_PROGRESS,
                ],
                $this->selfDirectedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS],
                [],
            ],
            'startedAt'       => [
                [
                    'startedAt' => [
                        'from' => $this->createDateTime('-3 days')->getTimestamp(),
                        'to' => $this->createDateTime('-2 days')->getTimestamp(),
                    ],
                    'status'    => EnrolmentStatuses::IN_PROGRESS,
                ],
                $this->selfDirectedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS],
                [],
            ],
            'ended.from.completed'      => [
                [
                    'endedAt' => [
                        'from' => $this->createDateTime('-4 days')->getTimestamp(),
                    ],
                    'status'  => EnrolmentStatuses::COMPLETED,
                ],
                array_merge($this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED], $this->selfDirectedEnrolmentIds[ContentLearningQuery::NOT_PASSED]),
                [],
            ],
            'ended.from'      => [
                [
                    'endedAt' => [
                        'from' => $this->createDateTime('-4 days')->getTimestamp(),
                    ],
                ],
                array_merge($this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED], $this->selfDirectedEnrolmentIds[ContentLearningQuery::NOT_PASSED]),
                [],
            ],
            'ended.to'        => [
                [
                    'endedAt' => [
                        'to' => $this->createDateTime('-3 days')->getTimestamp(),
                    ],
                    'status'  => EnrolmentStatuses::COMPLETED,
                    'passed'  => 1,
                ],
                $this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED],
                [],
            ],
            'ended'           => [
                [
                    'endedAt' => [
                        'from' => $this->createDateTime('-4 days')->getTimestamp(),
                        'to'   => $this->createDateTime('-3 days')->getTimestamp(),
                    ],
                    'status'  => EnrolmentStatuses::COMPLETED,
                    'passed'  => 1,
                ],
                $this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED],
                [],
            ],
            'ended.to.notPassed'        => [
                [
                    'endedAt' => [
                        'to' => $this->createDateTime('-3 days')->getTimestamp(),
                    ],
                    'status'  => EnrolmentStatuses::COMPLETED,
                    'passed'  => 0,
                ],
                $this->selfDirectedEnrolmentIds[ContentLearningQuery::NOT_PASSED],
                [],
            ],
            'ended.notPassed'           => [
                [
                    'endedAt' => [
                        'from' => $this->createDateTime('-4 days')->getTimestamp(),
                        'to'   => $this->createDateTime('-3 days')->getTimestamp(),
                    ],
                    'status'  => EnrolmentStatuses::COMPLETED,
                    'passed'  => 0,
                ],
                $this->selfDirectedEnrolmentIds[ContentLearningQuery::NOT_PASSED],
                [],
            ],
            'assignedAt.from' => [
                [
                    'assignedAt' => [
                        'from' => $this->createDateTime('-10 days')->getTimestamp(),
                    ],
                ],
                [],
                [],
            ],
            'assignedAt.to'   => [
                [
                    'assignedAt' => [
                        'to' => $this->createDateTime('-9 days')->getTimestamp(),
                    ],
                ],
                [],
                [],
            ],
            'assignedAt'      => [
                [
                    'assignedAt' => [
                        'from' => $this->createDateTime('-10 days')->getTimestamp(),
                        'to'   => $this->createDateTime('-9 days')->getTimestamp(),
                    ],
                ],
                [],
                [],
            ],
            'dueAt.from'      => [
                [
                    'dueAt' => [
                        'to'   => $this->createDateTime('-9 days')->getTimestamp(),
                    ],
                ],
                [],
                [],
            ],
            'dueAt.to'        => [
                [
                    'dueAt' => [
                        'to' => $this->createDateTime('-8 days')->getTimestamp(),
                    ],
                ],
                [],
                [],
            ],
            'dueAt'           => [
                [
                    'dueAt' => [
                        'from' => $this->createDateTime('-9 days')->getTimestamp(),
                        'to'   => $this->createDateTime('-8 days')->getTimestamp(),
                    ],
                ],
                [],
                [],
            ],
        ];
    }

    /**
     * @dataProvider filtersDataProvider
     */
    public function testFilters(array $filters, array $expectedEnrolmentId, array $expectedPlanIds)
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId");
        $req->query->replace([
                'activityType' => 'self-directed',
                'jwt'          => $this->adminJwt,
            ] + $filters);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($expectedEnrolmentId);
        $this->assertQueryResultHasPlanIds($expectedPlanIds);
    }
}
