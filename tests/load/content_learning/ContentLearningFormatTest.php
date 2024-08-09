<?php

namespace go1\enrolment\tests\load\content_learning;

use go1\core\util\client\federation_api\v1\GraphQLClient;
use go1\core\util\client\federation_api\v1\Query;
use go1\enrolment\controller\ContentLearningController;
use Prophecy\Argument;
use Symfony\Component\HttpFoundation\Request;

class ContentLearningFormatTest extends ContentLearningTestCase
{
    protected bool $queryResultLog = false;

    public function testGetLearningByIdQuery()
    {
        $app = $this->getApp();
        $app->extend('go1.client.federation_api.v1', function () {
            $gqlClient = $this->prophesize(GraphQLClient::class);
            $gqlClient
                ->execute(
                    Argument::that(function (Query $query) {
                        # Expected that `userAndStateFields` fragment is being used if load enrolment
                        $expectedGql = 'getLearningPlans(ids: $getLearningPlans__ids) { legacyId dueDate createdAt updatedAt state { legacyId status passed startedAt endedAt updatedAt } user { legacyId firstName lastName email avatarUri status account(portal: $account__portal) { legacyId status } } author { legacyId firstName lastName email avatarUri status } }';
                        $this->assertEquals($expectedGql, $query->getGql());

                        return true;
                    })
                )
                ->willReturn(json_encode([
                    'data' => [],
                ]));

            return $gqlClient->reveal();
        });
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&status=in-progress&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testFormatActivityType()
    {
        # Self-directed
        {
            $app = $this->getApp();
            $summary = [
                'all'           => 6,
                "not_passed"    => 1,
                "in_progress"   => 1,
                "overdue"       => 1,
                "assigned"      => 1,
                "completed"     => 1,
                "self_directed" => 1,
                "not_started"   => 1,
            ];
            $this->mockReportDataService($app, $summary);
            $app->extend('go1.client.federation_api.v1', function () {
                $gqlClient = $this->prophesize(GraphQLClient::class);
                $gqlClient
                    ->execute(Argument::any())
                    ->willReturn(json_encode([
                        'data' => [
                            'data' => [
                                [
                                    'legacyId'  => 10,
                                    'dueDate'   => '2019-04-11T09:50:28.000Z',
                                    'createdAt' => '2019-04-12T09:50:28.000Z',
                                    'updatedAt' => '2019-04-12T09:50:28.000Z',
                                    'state'     => [
                                        'legacyId'  => 10,
                                        'status'    => 'COMPLETED',
                                        'startedAt' => '2019-04-11T09:50:28.000Z',
                                        'endedAt'   => '2019-04-12T09:50:28.000Z',
                                        'updatedAt' => '2019-04-12T09:50:28.000Z',
                                        'passed'    => true,
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
                    ]));

                return $gqlClient->reveal();
            });
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(200, $res->getStatusCode());
            $learning = json_decode($res->getContent(), true);
            $this->assertNotEmpty($learning);
            $this->assertEquals(6, $learning['data']['totalCount']);
            $this->assertEquals(ContentLearningController::ACTIVITY_TYPE_SELF_DIRECTED, $learning['data']['edges'][0]['node']['activityType']);
        }

        # Assigned
        {
            $app = $this->getApp();
            $this->mockReportDataService($app, $summary);
            $app->extend('go1.client.federation_api.v1', function () {
                $gqlClient = $this->prophesize(GraphQLClient::class);
                $gqlClient
                    ->execute(Argument::any())
                    ->willReturn(json_encode([
                        'data' => [
                            'data' => [
                                [
                                    'legacyId'  => 10,
                                    'dueDate'   => '2019-04-11T09:50:28.000Z',
                                    'createdAt' => '2019-04-12T09:50:28.000Z',
                                    'updatedAt' => '2019-04-12T09:50:28.000Z',
                                    'state'     => [
                                        'legacyId'  => 1,
                                        'status'    => 'COMPLETED',
                                        'startedAt' => '2019-04-11T09:50:28.000Z',
                                        'endedAt'   => '2019-04-12T09:50:28.000Z',
                                        'updatedAt' => '2019-04-12T09:50:28.000Z',
                                        'passed'    => true,
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
                                    'author'    => [
                                        'legacyId'  => 3,
                                        'firstName' => 'Joe',
                                        'lastName'  => 'Manager',
                                        'email'     => 'manager@go1.com',
                                        'avatarUri' => '//a.png',
                                        'status'    => 'ACTIVE',
                                        'account'   => [
                                            'legacyId' => 4,
                                            'status'   => 'INACTIVE',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]));

                return $gqlClient->reveal();
            });
            $req = Request::create("/content-learning/$this->portalId/$this->contentId?activityType=assigned&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(200, $res->getStatusCode());
            $learning = json_decode($res->getContent(), true);
            $this->assertNotEmpty($learning);
            $this->assertEquals(6, $learning['data']['totalCount']);
            $this->assertEquals(ContentLearningController::ACTIVITY_TYPE_ASSIGNED, $learning['data']['edges'][0]['node']['activityType']);
        }
    }
}
