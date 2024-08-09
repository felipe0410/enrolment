<?php

namespace go1\enrolment\tests\load\content_learning;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\core\util\client\federation_api\v1\GraphQLClient;
use go1\core\util\client\federation_api\v1\Query;
use go1\core\util\Roles;
use go1\enrolment\content_learning\ContentLearningQuery;
use go1\enrolment\services\ReportDataService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use go1\util\user\UserStatus;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\Request;

const DATE_ISO8601_SPACE = 'Y-m-d H:i:s';

class ContentLearningTestCase extends EnrolmentTestCase
{
    use ProphecyTrait;
    use PortalMockTrait;
    use UserMockTrait;
    use EnrolmentMockTrait;
    use PlanMockTrait;

    protected string $portalName = 'qa.go1.co';
    protected int    $portalId;
    protected int    $student1UserId;
    protected int    $student1AccountId;
    protected string $studentJwt;

    protected int        $adminUserId;
    protected int        $adminAccountId;
    protected int        $managerUserId;
    protected int        $managerAccountId;
    protected string     $adminJwt;
    protected string     $managerJwt;
    protected array      $learning                 = [];
    protected int        $contentId                = 1;
    protected int        $overDuePlanId;
    protected array      $selfDirectedEnrolmentIds = [];
    protected array      $assignedPlanIds          = [];
    protected array      $selfAssignedPlanIds      = [];
    protected array      $assignedEnrolmentIds     = [];
    protected array      $assignedGroupPlanIds     = [];
    protected string     $accountsName;
    protected Connection $go1;
    protected array      $queryResult              = [];
    protected bool       $queryResultLog           = true;
    protected int        $groupId                  = 12345;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));

        $this->go1 = $go1 = $app['dbs']['go1'];
        $this->accountsName = $app['accounts_name'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $adminRoleId = $this->createRole($go1, ['instance' => $this->portalName, 'name' => 'administrator']);
        $managerRoleId = $this->createRole($go1, ['instance' => $this->portalName, 'name' => Roles::MANAGER]);

        $this->student1UserId = $this->createUser($go1, ['mail' => 'student1@example.com', 'instance' => $app['accounts_name']]);
        $this->student1AccountId = $this->createUser($go1, ['mail' => 'student1@example.com', 'instance' => $this->portalName, 'user_id' => $this->student1UserId]);
        $this->adminUserId = $this->createUser($go1, ['mail' => 'admin@example.com', 'instance' => $app['accounts_name']]);
        $this->adminAccountId = $this->createUser($go1, ['mail' => 'admin@example.com', 'instance' => $this->portalName, 'user_id' => $this->adminUserId]);
        $this->managerUserId = $this->createUser($go1, ['mail' => 'manager@example.com', 'instance' => $app['accounts_name']]);
        $this->managerAccountId = $this->createUser($go1, ['mail' => 'manager@example.com', 'instance' => $this->portalName, 'user_id' => $this->managerUserId]);

        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->student1UserId, $this->student1AccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->adminUserId, $this->adminAccountId);
        $this->link($go1, EdgeTypes::HAS_ROLE, $this->adminAccountId, $adminRoleId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->managerUserId, $this->managerAccountId);
        $this->link($go1, EdgeTypes::HAS_ROLE, $this->managerAccountId, $managerRoleId);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $this->student1AccountId, $this->managerUserId);
        $go1->insert('gc_account_managers', [
            'account_id'         => $this->student1AccountId,
            'manager_account_id' => $this->managerAccountId,
            'created_at'         => time(),
        ]);

        $this->studentJwt = $this->jwtForUser($go1, $this->student1UserId, $this->portalName);
        $this->adminJwt = $this->jwtForUser($go1, $this->adminUserId, $this->portalName);
        $this->managerJwt = $this->jwtForUser($go1, $this->managerUserId, $this->portalName);
        $this->learning = [
            'data' => [
                'data' => [
                    'totalCount' => 10,
                    'pageInfo'   => [
                        'hasNextPage' => true,
                    ],
                    'edges'      => [
                        [
                            'node' => [
                                'legacyId'  => 10,
                                'dueDate'   => '2019-04-11T09:50:28.000Z',
                                'createdAt' => '2019-04-12T09:50:28.000Z',
                                'updatedAt' => '2019-04-12T09:50:28.000Z',
                                'state'     => [
                                    'legacyId'  => 1,
                                    'status'    => 'COMPLETED',
                                    'passed'    => true,
                                    'startedAt' => '2019-04-11T09:50:28.000Z',
                                    'endedAt'   => '2019-04-12T09:50:28.000Z',
                                    'updatedAt' => '2019-04-12T09:50:28.000Z',
                                ],
                                'user'      => [
                                    'legacyId'  => 2,
                                    'firstName' => 'Joe',
                                    'lastName'  => 'Doe',
                                    'email'     => 'test@go1.com',
                                    'avatarUri' => '//a.png',
                                    'status'    => 'ACTIVE',
                                    'account'   => [
                                        'legacyId' => 2,
                                        'status'   => 'INACTIVE',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->createAssignedData();
        $this->createSelfDirectedData();
        $summary = [
            'all'           => 1,
            "not_passed"    => 1,
            "in_progress"   => 1,
            "overdue"       => 1,
            "assigned"      => 1,
            "completed"     => 1,
            "self_directed" => 1,
            "not_started"   => 1,
        ];
        $this->mockReportDataService($app, $summary);

        if ($this->queryResultLog) {
            $this->mockGraphqlClient($app);
        }
    }

    protected function mockReportDataService(DomainService $app, $summaryCounts = []): void
    {
        $app->extend(ReportDataService::class, function () use ($app, $summaryCounts) {
            $testClient = $this
                ->getMockBuilder(ReportDataService::class)
                ->disableOriginalConstructor()
                ->setMethods(['getReportCounts'])
                ->getMock();

            $testClient
                ->expects($this->any())
                ->method('getReportCounts')
                ->willReturn([
                    'summary_counts' => $summaryCounts
                ]);
            return $testClient;
        });
    }

    protected function createUserAndAccount(string $email, int $status = UserStatus::ACTIVE)
    {
        $userId = $this->createUser($this->go1, ['mail' => $email, 'instance' => $this->accountsName]);
        $accountId = $this->createUser($this->go1, ['mail' => $email, 'instance' => $this->portalName, 'user_id' => $userId, 'status' => $status]);
        $this->link($this->go1, EdgeTypes::HAS_ACCOUNT, $userId, $accountId);
        $this->link($this->go1, EdgeTypes::HAS_MANAGER, $accountId, $this->managerUserId);
        $this->go1->insert('gc_account_managers', [
            'account_id'         => $accountId,
            'manager_account_id' => $this->managerAccountId,
            'created_at'         => time(),
        ]);

        return $userId;
    }

    protected function createDateTime(string $input): DateTimeImmutable
    {
        return new DateTimeImmutable($input);
    }

    protected function createSelfDirectedData()
    {
        foreach ([EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED, ContentLearningQuery::NOT_PASSED] as $i => $status) {
            $userId = $this->createUserAndAccount("self-direct-enrolment-$i-$status@example.com");
            $inactiveUserId = $this->createUserAndAccount("self-direct-enrolment-$i-$status-inactive@example.com", UserStatus::INACTIVE);
            $inactiveObj = $o = [
                'lo_id'             => $this->contentId,
                'taken_instance_id' => $this->portalId,
                'user_id'           => $userId,
                'profile_id'        => $userId,
                'status'            => $status == ContentLearningQuery::NOT_PASSED ? EnrolmentStatuses::COMPLETED : $status,
            ];

            $inactiveObj['user_id'] = $inactiveObj['profile_id'] = $inactiveUserId;
            if ($status == EnrolmentStatuses::IN_PROGRESS) {
                $inactiveObj['start_date'] = $o['start_date'] = $this->createDateTime('-71 hours')->format(DATE_ISO8601_SPACE);
            }

            if ($status == EnrolmentStatuses::COMPLETED || $status == ContentLearningQuery::NOT_PASSED) {
                $inactiveObj['start_date'] = $o['start_date'] = $this->createDateTime('-5 days')->format(DATE_ISO8601_SPACE);
                $inactiveObj['end_date'] =  $o['end_date'] = $this->createDateTime('-4 days')->format(DATE_ISO8601_SPACE);
                $inactiveObj['pass'] =  $o['pass'] = $status == ContentLearningQuery::NOT_PASSED ? 0 : 1;
            }
            $enrolmentId = $this->createEnrolment($this->go1, $o);
            $this->createEnrolment($this->go1, $inactiveObj);
            $this->selfDirectedEnrolmentIds[$status][] = $enrolmentId;
            $this->selfDirectedEnrolmentIds['all'][] = $enrolmentId;
        }
    }

    protected function createAssignedData()
    {
        $userId = $this->createUserAndAccount('assigned-1@example.com');
        $inactiveUserId = $this->createUserAndAccount('assigned-1-inactive@example.com', UserStatus::INACTIVE);
        $planId = $this->createPlan($this->go1, [
            'entity_id'    => $this->contentId,
            'instance_id'  => $this->portalId,
            'user_id'      => $userId,
            'assigner_id'  => $this->adminUserId,
            'due_date'     => $this->createDateTime('-9 days')->format(DATE_ISO8601_SPACE),
            'created_date' => $this->createDateTime('-10 days')->format(DATE_ISO8601_SPACE),
        ]);

        $this->createPlan($this->go1, [
            'entity_id'    => $this->contentId,
            'instance_id'  => $this->portalId,
            'user_id'      => $inactiveUserId,
            'assigner_id'  => $this->adminUserId,
            'due_date'     => $this->createDateTime('-9 days')->format(DATE_ISO8601_SPACE),
            'created_date' => $this->createDateTime('-10 days')->format(DATE_ISO8601_SPACE),
        ]);

        $this->overDuePlanId = $planId;
        $this->assignedPlanIds[] = $planId;

        // manager assign the content to himself
        $this->selfAssignedPlanIds[] = $this->createPlan($this->go1, [
            'entity_id'    => $this->contentId,
            'instance_id'  => $this->portalId,
            'user_id'      => $this->managerUserId,
            'assigner_id'  => $this->managerUserId,
            'due_date'     => $this->createDateTime('-9 days')->format(DATE_ISO8601_SPACE),
            'created_date' => $this->createDateTime('-10 days')->format(DATE_ISO8601_SPACE),
        ]);

        foreach ([EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED, ContentLearningQuery::NOT_PASSED] as $i => $status) {
            $userId = $this->createUserAndAccount("assigned-enrolment-$i-$status-@example.com");
            $inactiveUserId = $this->createUserAndAccount("assigned-enrolment-$i-$status-inactive@example.com", UserStatus::INACTIVE);
            $planId = $this->createPlan($this->go1, [
                'entity_id'   => $this->contentId,
                'instance_id' => $this->portalId,
                'user_id'     => $userId,
                'assigner_id' => $this->adminUserId,
            ]);

            $inactivePlanId = $this->createPlan($this->go1, [
                'entity_id'   => $this->contentId,
                'instance_id' => $this->portalId,
                'user_id'     => $inactiveUserId,
                'assigner_id' => $this->adminUserId,
            ]);

            $inactiveObj = $o = [
                'lo_id'             => $this->contentId,
                'taken_instance_id' => $this->portalId,
                'user_id'           => $userId,
                'profile_id'        => $userId,
                'status'            => $status == ContentLearningQuery::NOT_PASSED ? EnrolmentStatuses::COMPLETED : $status,
            ];

            $inactiveObj['user_id'] = $inactiveObj['profile_id'] = $inactiveUserId;

            if ($status == EnrolmentStatuses::IN_PROGRESS) {
                $inactiveObj['start_date'] = $o['start_date'] = $this->createDateTime('-3 days')->format(DATE_ISO8601_SPACE);
            }

            if ($status == EnrolmentStatuses::COMPLETED || $status == ContentLearningQuery::NOT_PASSED) {
                $o['start_date'] = $this->createDateTime('-5 days')->format(DATE_ISO8601_SPACE);
                $o['end_date'] = $this->createDateTime('-4 days')->format(DATE_ISO8601_SPACE);
                $inactiveObj['start_date'] = $this->createDateTime('-5 days')->format(DATE_ISO8601_SPACE);
                $inactiveObj['end_date'] = $this->createDateTime('-4 days')->format(DATE_ISO8601_SPACE);
                $inactiveObj['pass'] =  $o['pass'] = $status == ContentLearningQuery::NOT_PASSED ? 0 : 1;
            }
            $enrolmentId = $this->createEnrolment($this->go1, $o);
            $inactiveEnrolmentId = $this->createEnrolment($this->go1, $inactiveObj);
            $this->go1->insert('gc_enrolment_plans', [
                'enrolment_id' => $enrolmentId,
                'plan_id'      => $planId,
            ]);
            $this->go1->insert('gc_plan_reference', [
                'source_type' => 'group',
                'source_id'   => $this->groupId,
                'plan_id'     => $planId,
                'status'      => 1
            ]);
            $this->assignedGroupPlanIds[] = $planId;

            $this->go1->insert('gc_enrolment_plans', [
                'enrolment_id' => $inactiveEnrolmentId,
                'plan_id'      => $inactivePlanId,
            ]);
            $this->assignedEnrolmentIds[$status][] = $enrolmentId;
            $this->assignedEnrolmentIds['all'][] = $enrolmentId;
        }
    }

    protected function assertQueryResultHasEnrolmentIds(array $expectedIds)
    {
        $enrolmentIds = [];
        foreach ($this->queryResult as $id) {
            $id = base64_decode($id);
            $_ = explode('go1:LearningPlan:gc_enrolment.', $id);
            if (count($_) == 2) {
                $enrolmentIds[] = $_[1];
            }
        }

        $this->assertEqualsCanonicalizing($expectedIds, $enrolmentIds);
        $this->assertEquals(count($enrolmentIds), count($expectedIds));
    }

    protected function assertQueryResultHasPlanIds(array $expectedIds)
    {
        $actualIds = [];
        foreach ($this->queryResult as $id) {
            $id = base64_decode($id);
            $_ = explode('go1:LearningPlan:gc_plan.', $id);
            if (count($_) == 2) {
                $actualIds[] = $_[1];
            }
        }

        $this->assertEqualsCanonicalizing($expectedIds, $actualIds);
    }

    private function mockGraphqlClient(DomainService $app)
    {
        $app->extend('go1.client.federation_api.v1', function () {
            $gqlClient = $this->prophesize(GraphQLClient::class);
            $gqlClient
                ->execute(
                    Argument::that(function (Query $query) {
                        $vars = $query->getVariables();
                        $this->queryResult = $vars['getLearningPlans__ids']['value'] ?? [];

                        return true;
                    }),
                )
                ->willReturn(json_encode([]));

            return $gqlClient->reveal();
        });
    }
}
