<?php

namespace go1\enrolment\tests\load\content_learning;

use Doctrine\DBAL\Connection;
use go1\core\util\Roles;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use Symfony\Component\HttpFoundation\Request;
use go1\util\user\UserHelper;

use function array_merge;

class ContentLearningAllLearnerTest extends ContentLearningTestCase
{
    public function testGetAllLearnerWithRootJWT()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?jwt=" . UserHelper::ROOT_JWT);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds(array_merge($this->assignedEnrolmentIds['all'], $this->selfDirectedEnrolmentIds['all']));
    }

    public function testGetAllLearner()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds(array_merge($this->assignedEnrolmentIds['all'], $this->selfDirectedEnrolmentIds['all']));
    }

    public function testGetAllLearnerWithPagination()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?offset=6&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds($this->assignedEnrolmentIds['all']);
    }

    public function testGetAllLearnerWithSorting()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?sort[startedAt]=desc&sort[endedAt]=desc&sort[updatedAt]=desc&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds(array_merge($this->assignedEnrolmentIds['all'], $this->selfDirectedEnrolmentIds['all']));
    }

    public function testGetAllLearnerFilterByManager()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?facet=true&jwt=$this->managerJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds(array_merge($this->assignedEnrolmentIds['all'], $this->selfDirectedEnrolmentIds['all']));
    }

    public function testGetAllLearnerFilterByUserId()
    {
        $app = $this->getApp();
        $enrolment = EnrolmentHelper::load($this->go1, $this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED][0]);
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?userIds[]=$enrolment->user_id&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $this->assertQueryResultHasEnrolmentIds([$this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED][0]]);
        $this->assertQueryResultHasPlanIds([]);
    }

    public function dataFacet()
    {
        return [
            ['false', 10, 2, 6, 2, 4, 2, 4, 4],
            ['true', 19, 3, 11, 4, 8, 4, 8, 7],
        ];
    }

    /** @dataProvider dataFacet */
    public function testGetFacet($includeInactivePortalAccounts, $all, $overdue, $assigned, $inProgress, $completed, $notPassed, $selfDirected, $notStarted)
    {
        $app = $this->getApp();
        $summary = [
            'all'           => $all,
            "not_passed"    => $notPassed,
            "in_progress"   => $inProgress,
            "overdue"       => $overdue,
            "assigned"      => $assigned,
            "completed"     => $completed,
            "self_directed" => $selfDirected,
            "not_started"   => $notStarted,
        ];
        $this->mockReportDataService($app, $summary);
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?facet=true&jwt=$this->adminJwt&includeInactivePortalAccounts=$includeInactivePortalAccounts");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $learning = json_decode($res->getContent(), true);
        $this->assertNotEmpty($learning);
        $this->assertNotEmpty($learning['data']['facet']);
        $includeInactivePortalAccounts = 'true' === $includeInactivePortalAccounts;
        $this->assertEquals([
            'all'           => $all,
            'overdue'       => $overdue,
            'assigned'      => $assigned,
            'self-directed' => !$includeInactivePortalAccounts ? count($this->selfDirectedEnrolmentIds['all']) : 8,
            'not-started'   => !$includeInactivePortalAccounts ? count($this->assignedPlanIds)
                + count($this->selfAssignedPlanIds)
                + count($this->selfDirectedEnrolmentIds[EnrolmentStatuses::NOT_STARTED])
                + count($this->assignedEnrolmentIds[EnrolmentStatuses::NOT_STARTED]) : 7,
            'in-progress'   => $inProgress,
            'completed'     => $completed,
            'not-passed'    => $notPassed,

        ], $learning['data']['facet']);
    }

    public function filtersDataProvider()
    {
        $this->getApp();

        return [
            'startedAt.from'  => [
                [
                    'startedAt' => [
                        'from' => $this->createDateTime('- 3 days')->getTimestamp(),
                    ],
                ],
                array_merge(
                    $this->selfDirectedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS],
                    $this->assignedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS]
                ),
                [],
            ],
            'startedAt.to'    => [
                [
                    'startedAt' => [
                        'to' => $this->createDateTime('-2 days')->getTimestamp(),
                    ],
                ],
                array_merge(
                    $this->selfDirectedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS],
                    $this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED],
                    $this->assignedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS],
                    $this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED]
                ),
                [],
            ],
            'startedAt'       => [
                [
                    'startedAt' => [
                        'from' => (new \DateTime('-3 days'))->getTimestamp(),
                        'to'   => (new \DateTime('-2 days'))->getTimestamp(),
                    ],
                ],
                array_merge(
                    $this->selfDirectedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS],
                    $this->assignedEnrolmentIds[EnrolmentStatuses::IN_PROGRESS]
                ),
                [],
            ],
            'ended.from'      => [
                [
                    'endedAt' => [
                        'from' => (new \DateTime('-4 days'))->getTimestamp(),
                    ],
                ],
                array_merge(
                    $this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED],
                    $this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED]
                ),
                [],
            ],
            'ended.to'        => [
                [
                    'endedAt' => [
                        'to' => (new \DateTime('-3 days'))->getTimestamp(),
                    ],
                ],
                array_merge(
                    $this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED],
                    $this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED]
                ),
                [],
            ],
            'ended'           => [
                [
                    'endedAt' => [
                        'from' => (new \DateTime('-4 days'))->getTimestamp(),
                        'to'   => (new \DateTime('-3 days'))->getTimestamp(),
                    ],
                ],
                array_merge(
                    $this->selfDirectedEnrolmentIds[EnrolmentStatuses::COMPLETED],
                    $this->assignedEnrolmentIds[EnrolmentStatuses::COMPLETED]
                ),
                [],
            ],
            'assignedAt.from' => [
                [
                    'assignedAt' => [
                        'from' => (new \DateTime('-10 days'))->getTimestamp(),
                    ],
                ],
                $this->assignedEnrolmentIds['all'],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
            ],
            'assignedAt.to'   => [
                [
                    'assignedAt' => [
                        'to' => (new \DateTime('-9 days'))->getTimestamp(),
                    ],
                ],
                [],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
            ],
            'assignedAt'      => [
                [
                    'assignedAt' => [
                        'from' => (new \DateTime('-10 days'))->getTimestamp(),
                        'to'   => (new \DateTime('-9 days'))->getTimestamp(),
                    ],
                ],
                [],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
            ],
            'dueAt.from'      => [
                [
                    'dueAt' => [
                        'from' => (new \DateTime('-9 days'))->getTimestamp(),
                    ],
                ],
                [],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
            ],
            'dueAt.to'        => [
                [
                    'dueAt' => [
                        'to' => (new \DateTime('-8 days'))->getTimestamp(),
                    ],
                ],
                [],
                array_merge($this->assignedPlanIds, $this->selfAssignedPlanIds),
            ],
            'dueAt'           => [
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
    public function testFilters(array $filters, array $expectedEnrolmentIds, array $expectedPlanIds)
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId");
        $req->query->replace(['jwt' => $this->adminJwt] + $filters);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertTrue(true);
        //$this->assertQueryResultHasEnrolmentIds($expectedEnrolmentIds);
        $this->assertQueryResultHasPlanIds($expectedPlanIds);
    }

    public function testGetFields()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?fields=legacyId,state.legacyId&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $learning = json_decode($res->getContent(), true);
        $this->assertQueryResultHasEnrolmentIds(array_merge($this->assignedEnrolmentIds['all'], $this->selfDirectedEnrolmentIds['all']));
    }

    public function testManagerCanSee4Level()
    {
        $app = $this->getApp();
        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        $managerLevel1UserId = $this->createUser($db, ['instance' => $this->accountsName, 'mail' => 'manager-level-1@example.com']);
        $managerLevel2UserId = $this->createUser($db, ['instance' => $this->accountsName, 'mail' => 'manager-level-2@example.com']);
        $managerLevel3UserId = $this->createUser($db, ['instance' => $this->accountsName, 'mail' => 'manager-level-3@example.com']);
        $managerLevel4UserId = $this->createUser($db, ['instance' => $this->accountsName, 'mail' => 'manager-level-4@example.com']);
        $managerLevel1AccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'manager-level-1@example.com', 'user_id' => $managerLevel1UserId]);
        $managerLevel2AccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'manager-level-2@example.com', 'user_id' => $managerLevel2UserId]);
        $managerLevel3AccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'manager-level-3@example.com', 'user_id' => $managerLevel3UserId]);
        $managerLevel4AccountId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => 'manager-level-4@example.com', 'user_id' => $managerLevel4UserId]);

        $learnerUserId = $this->createUser($db, ['instance' => $this->accountsName, 'mail' => 'learner@example.com']);
        $leanerAccountId = $this->createUser($db, [
            'instance' => $this->portalName, 'mail' => 'learner@example.com',
            'user_id'  => $learnerUserId,
        ]);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $learnerUserId, $leanerAccountId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $managerLevel1UserId, $managerLevel1AccountId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $managerLevel2UserId, $managerLevel2AccountId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $managerLevel3UserId, $managerLevel3AccountId);
        $this->link($db, EdgeTypes::HAS_ACCOUNT, $managerLevel4UserId, $managerLevel4AccountId);

        $managerRoleId = $db->executeQuery('SELECT id FROM gc_role WHERE name = ?', [Roles::MANAGER])->fetchColumn();
        $this->link($db, EdgeTypes::HAS_ROLE, $managerLevel1AccountId, $managerRoleId);
        $this->link($db, EdgeTypes::HAS_ROLE, $managerLevel2AccountId, $managerRoleId);
        $this->link($db, EdgeTypes::HAS_ROLE, $managerLevel3AccountId, $managerRoleId);
        $this->link($db, EdgeTypes::HAS_ROLE, $managerLevel4AccountId, $managerRoleId);

        $enrolmentId = $this->createEnrolment($db, [
            'lo_id'             => $this->contentId,
            'user_id'           => $learnerUserId,
            'taken_instance_id' => $this->portalId,
        ]);
        $db->insert('gc_account_managers', ['account_id' => $leanerAccountId, 'manager_account_id' => $managerLevel4AccountId, 'created_at' => time()]);
        $db->insert('gc_account_managers', ['account_id' => $managerLevel4AccountId, 'manager_account_id' => $managerLevel3AccountId, 'created_at' => time()]);
        $db->insert('gc_account_managers', ['account_id' => $managerLevel3AccountId, 'manager_account_id' => $managerLevel2AccountId, 'created_at' => time()]);
        $db->insert('gc_account_managers', ['account_id' => $managerLevel2AccountId, 'manager_account_id' => $managerLevel1AccountId, 'created_at' => time()]);
        $db->insert('gc_account_managers', ['account_id' => $leanerAccountId, 'manager_account_id' => $managerLevel2AccountId, 'created_at' => time()]);

        $managerLevel1JWT = $this->jwtForUser($db, $managerLevel1UserId, $this->portalName);
        $managerLevel2JWT = $this->jwtForUser($db, $managerLevel2UserId, $this->portalName);
        $managerLevel3JWT = $this->jwtForUser($db, $managerLevel3UserId, $this->portalName);
        $managerLevel4JWT = $this->jwtForUser($db, $managerLevel4UserId, $this->portalName);

        $req = Request::create("/content-learning/$this->portalId/$this->contentId?jwt=$managerLevel1JWT");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds([$enrolmentId]);

        $req = Request::create("/content-learning/$this->portalId/$this->contentId?jwt=$managerLevel2JWT");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds([$enrolmentId]);

        $req = Request::create("/content-learning/$this->portalId/$this->contentId?jwt=$managerLevel3JWT");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds([$enrolmentId]);

        $req = Request::create("/content-learning/$this->portalId/$this->contentId?jwt=$managerLevel4JWT");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertQueryResultHasEnrolmentIds([$enrolmentId]);
    }
}
