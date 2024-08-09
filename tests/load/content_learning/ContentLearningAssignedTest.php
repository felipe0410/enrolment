<?php

namespace go1\enrolment\tests\load\content_learning;

use go1\enrolment\content_learning\ContentLearningQuery;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use Symfony\Component\HttpFoundation\Request;

class ContentLearningAssignedTest extends ContentLearningTestCase
{
    public function testGetAllAssigned()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($this->assignedEnrolmentIds['all']);
        $this->assertQueryResultHasPlanIds(array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds));
    }

    public function testGetAssignedWithSorting()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&sort[startedAt]=desc&sort[endedAt]=desc&sort[updatedAt]=desc&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($this->assignedEnrolmentIds['all']);
        $this->assertQueryResultHasPlanIds(array_merge_recursive($this->assignedPlanIds, $this->selfAssignedPlanIds));
    }

    public function testGetAssignedByManager()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&jwt=$this->managerJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($this->assignedEnrolmentIds['all']);
        $this->assertQueryResultHasPlanIds(array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds));
    }

    public function testGetAssignedByUserId()
    {
        $app = $this->getApp();
        $enrolment = EnrolmentHelper::load($this->go1, $this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED][0]);
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?userIds[]=$enrolment->user_id&activityType=assigned&jwt=$this->managerJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds([$this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED][0]]);
        $this->assertQueryResultHasPlanIds([]);
    }

    public function testGetAssignedByNotStartedEnrolmentStatus()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&status=not-started&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($this->assignedEnrolmentIds[EnrolmentStatuses::NOT_STARTED]);
        $this->assertQueryResultHasPlanIds(array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds));
    }

    public function testGetAssignedByEnrolmentStatus()
    {
        $app = $this->getApp();

        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&status=completed&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds(array_merge($this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED], $this->assignedEnrolmentIds[ContentLearningQuery::NOT_PASSED]));
        $this->assertQueryResultHasPlanIds([]);

        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&status=completed&passed=1&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds($this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED]);
        $this->assertQueryResultHasPlanIds([]);

        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&status=completed&passed=0&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds($this->assignedEnrolmentIds[ContentLearningQuery::NOT_PASSED]);
        $this->assertQueryResultHasPlanIds([]);
    }

    public function testGetAssignedFacet()
    {
        $app = $this->getApp();
        $summary = [
            'all'           => 6,
            "not_passed"    => 1,
            "in_progress"   => 1,
            "overdue"       => 2,
            "assigned"      => 1,
            "completed"     => 2,
            "self_directed" => 1,
            "not_started"   => 3,
        ];
        $this->mockReportDataService($app, $summary);
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&facet=true&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $learning = json_decode($res->getContent(), true);

        $this->assertNotEmpty($learning['data']['facet']);
        $this->assertEquals([
            'total'       => count($this->assignedEnrolmentIds['all']) + count($this->assignedPlanIds) + count($this->selfAssignedPlanIds),
            'not-started' => count($this->assignedEnrolmentIds[EnrolmentStatuses::NOT_STARTED]) + count($this->assignedPlanIds) + count($this->selfAssignedPlanIds),
            'in-progress' => count($this->assignedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS]),
            'completed'   => count($this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED]) + count($this->assignedEnrolmentIds[ContentLearningQuery::NOT_PASSED]),
            'not-passed'  => count($this->assignedEnrolmentIds[ContentLearningQuery::NOT_PASSED]),
            'overdue'     => 2,
        ], $learning['data']['facet']);
    }

    public function testGetOverdue()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?overdue=true&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds([]);
        $this->assertQueryResultHasPlanIds(array_merge([$this->overDuePlanId], $this->selfAssignedPlanIds));
    }

    public function filtersDataProvider()
    {
        $this->getApp();

        return [
            'assigners'              => [
                ['assignerIds' => [$this->adminUserId]],
                $this->assignedEnrolmentIds['all'],
                $this->assignedPlanIds,
            ],
            'Not existing assigners' => [
                ['assignerIds' => [3000]],
                [],
                [],
            ],
            'startedAt.from'         => [
                [
                    'startedAt' => [
                        'from' => (new \DateTime('-3 days'))->getTimestamp(),
                    ],
                ],
                $this->assignedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS],
                [],
            ],
            'startedAt.to'           => [
                [
                    'startedAt' => [
                        'to' => (new \DateTime('-2 days'))->getTimestamp(),
                    ],
                    'status'    => EnrolmentStatuses::IN_PROGRESS,
                ],
                $this->assignedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS],
                [],
            ],
            'startedAt'              => [
                [
                    'startedAt' => [
                        'from' => (new \DateTime('-3 days'))->getTimestamp(),
                        'to'   => (new \DateTime('-2 days'))->getTimestamp(),
                    ],
                    'status'    => EnrolmentStatuses::IN_PROGRESS,
                ],
                $this->assignedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS],
                [],
            ],
            'ended.from'             => [
                [
                    'endedAt' => [
                        'from' => (new \DateTime('-4 days'))->getTimestamp(),
                    ],
                ],
                array_merge($this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED], $this->assignedEnrolmentIds[ContentLearningQuery::NOT_PASSED]),
                [],
            ],
            'ended.to'               => [
                [
                    'endedAt' => [
                        'to' => (new \DateTime('-3 days'))->getTimestamp(),
                    ],
                    'status'  => EnrolmentStatuses::COMPLETED,
                    'passed'  => 1,
                ],
                $this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED],
                [],
            ],
            'ended'                  => [
                [
                    'endedAt' => [
                        'from' => (new \DateTime('-4 days'))->getTimestamp(),
                        'to'   => (new \DateTime('-3 days'))->getTimestamp(),
                    ],
                    'status'  => EnrolmentStatuses::COMPLETED,
                    'passed'  => 1,
                ],
                $this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED],
                [],
            ],
            'ended.to.notPassed'               => [
                [
                    'endedAt' => [
                        'to' => (new \DateTime('-3 days'))->getTimestamp(),
                    ],
                    'status'  => EnrolmentStatuses::COMPLETED,
                    'passed'  => 0,
                ],
                $this->assignedEnrolmentIds[ContentLearningQuery::NOT_PASSED],
                [],
            ],
            'ended.notPassed'                  => [
                [
                    'endedAt' => [
                        'from' => (new \DateTime('-4 days'))->getTimestamp(),
                        'to'   => (new \DateTime('-3 days'))->getTimestamp(),
                    ],
                    'status'  => EnrolmentStatuses::COMPLETED,
                    'passed'  => 0,
                ],
                $this->assignedEnrolmentIds[ContentLearningQuery::NOT_PASSED],
                [],
            ],
            'assignedAt.from'        => [
                [
                    'assignedAt' => [
                        'from' => (new \DateTime('-10 days'))->getTimestamp(),
                    ],
                ],
                $this->assignedEnrolmentIds['all'],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
            ],
            'assignedAt.to'          => [
                [
                    'assignedAt' => [
                        'to' => (new \DateTime('-9 days'))->getTimestamp(),
                    ],
                ],
                [],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
            ],
            'assignedAt'             => [
                [
                    'assignedAt' => [
                        'from' => (new \DateTime('-10 days'))->getTimestamp(),
                        'to'   => (new \DateTime('-9 days'))->getTimestamp(),
                    ],
                ],
                [],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
            ],
            'dueAt.from'             => [
                [
                    'dueAt' => [
                        'from' => (new \DateTime('-9 days'))->getTimestamp(),
                    ],
                ],
                [],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
            ],
            'dueAt.to'               => [
                [
                    'dueAt' => [
                        'to' => (new \DateTime('-8 days'))->getTimestamp(),
                    ],
                ],
                [],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
            ],
            'dueAt'                  => [
                [
                    'dueAt' => [
                        'from' => (new \DateTime('-9 days'))->getTimestamp(),
                        'to'   => (new \DateTime('-8 days'))->getTimestamp(),
                    ],
                ],
                [],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
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
                'activityType' => 'assigned',
                'jwt'          => $this->adminJwt,
            ] + $filters);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($expectedEnrolmentId);
        $this->assertQueryResultHasPlanIds($expectedPlanIds);
    }

    public function testGetFields()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&fields=legacyId,state.legacyId&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($this->assignedEnrolmentIds['all']);
        $this->assertQueryResultHasPlanIds(array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds));
    }
}
