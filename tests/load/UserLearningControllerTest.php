<?php

namespace go1\enrolment\tests\load;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\core\util\client\federation_api\v1\GraphQLClient;
use go1\core\util\client\federation_api\v1\schema\query\findLearningPlans;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\Request;
use Prophecy\Argument;

class UserLearningControllerTest extends EnrolmentTestCase
{
    use ProphecyTrait;
    use PortalMockTrait;
    use UserMockTrait;

    private $portalName = 'qa.go1.co';
    private $portalId;
    private $studentUserId;
    private $studentAccountId;
    private $studentJwt;

    private $adminUserId;
    private $adminAccountId;
    private $adminJwt;
    private $learning = [];

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $adminRoleId = $this->createRole($go1, ['instance' => $this->portalName, 'name' => 'administrator']);

        $this->studentUserId = $this->createUser($go1, ['mail' => 'student@example.com', 'instance' => $app['accounts_name']]);
        $this->studentAccountId = $this->createUser($go1, ['mail' => 'student@example.com', 'instance' => $this->portalName]);
        $this->adminUserId = $this->createUser($go1, ['mail' => 'admin@example.com', 'instance' => $app['accounts_name']]);
        $this->adminAccountId = $this->createUser($go1, ['mail' => 'admin@example.com', 'instance' => $this->portalName]);

        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $this->studentAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->adminUserId, $this->adminAccountId);
        $this->link($go1, EdgeTypes::HAS_ROLE, $this->adminAccountId, $adminRoleId);

        $this->studentJwt = $this->jwtForUser($go1, $this->studentUserId, $this->portalName);
        $this->adminJwt = $this->jwtForUser($go1, $this->adminUserId, $this->portalName);
        $this->learning = [
            'data' => [
                'data' => [
                    'totalCount' => 10,
                    'pageInfo'   => [
                        'hasNextPage' => true
                    ],
                    'edges'      => [
                        [
                            'node' => [
                                'state' => [
                                    'legacyId'  => 1,
                                    'status'    => 'COMPLETED',
                                    'startedAt' => '2019-04-11T09:50:28.000Z',
                                    'endedAt'   => '2019-04-12T09:50:28.000Z',
                                    'updatedAt' => '2019-04-12T09:50:28.000Z',
                                ],
                                'lo'    => [
                                    'legacyId'  => 2,
                                    'title'     => 'Leadership',
                                    'label'     => 'Course',
                                    'image'     => '//a.png',
                                    'publisher' => [
                                        'subDomain' => 'go1.com'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    public function testLearnerViewHerLearning()
    {
        $app = $this->getApp();
        $app->extend('go1.client.federation_api.v1', function () {
            $gqlClient = $this->prophesize(GraphQLClient::class);
            $gqlClient
                ->execute(Argument::that(function (findLearningPlans $q) {
                    $vars = $q->getVariables();
                    $gql = $q->getGql();
                    $this->assertEquals('findLearningPlans(first: $findLearningPlans__first, after: $findLearningPlans__after, filters: $findLearningPlans__filters, orderBy: $findLearningPlans__orderBy) { totalCount pageInfo { hasNextPage } edges { node { state { legacyId status startedAt endedAt updatedAt } lo { legacyId title label image publisher { subDomain } } } } }', $gql);

                    $this->assertEquals(20, $vars['findLearningPlans__first']['value']);
                    $this->assertEquals(base64_encode("go1:Offset:0"), $vars['findLearningPlans__after']['value']);
                    $this->assertEquals($this->studentUserId, $vars['findLearningPlans__filters']['value']->userLegacyId->eq);
                    $this->assertEquals(base64_encode("go1:Space:Portal.$this->portalId"), $vars['findLearningPlans__filters']['value']->spaceId->eq);

                    return true;
                }))
                ->willReturn(json_encode($this->learning));

            return $gqlClient->reveal();
        });

        $req = Request::create("/user-learning/$this->portalId?jwt=$this->studentJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $learning = json_decode($res->getContent(), true);

        $this->assertEquals([
            'data' => [
                'totalCount' => 10,
                'pageInfo'   => [
                    'hasNextPage' => true,
                ],
                'edges'      => [
                    [
                        'node' => [
                            'state' => [
                                'legacyId'  => 1,
                                'status'    => 'completed',
                                'startedAt' => 1554976228,
                                'endedAt'   => 1555062628,
                                'updatedAt' => 1555062628,
                            ],
                            'lo'    => [
                                'id'        => '2',
                                'title'     => 'Leadership',
                                'label'     => 'Course',
                                'image'     => '//a.png',
                                'publisher' =>
                                    [
                                        'subDomain' => 'go1.com',
                                    ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $learning);
    }

    public function testNoJWT()
    {
        $app = $this->getApp();
        $req = Request::create("/user-learning/$this->portalId");
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());

        $this->assertStringContainsString('Missing or invalid JWT', $res->getContent());
    }

    public function testLearnerCanNotViewOther()
    {
        $app = $this->getApp();
        $req = Request::create("/user-learning/$this->portalId?userId=$this->adminUserId&jwt=$this->studentJwt");
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());

        $this->assertStringContainsString('Access denied', $res->getContent());
    }

    public function testAdminViewLearnerLearning()
    {
        $app = $this->getApp();
        $app->extend('go1.client.federation_api.v1', function () {
            $gqlClient = $this->prophesize(GraphQLClient::class);
            $gqlClient
                ->execute(Argument::that(function (findLearningPlans $q) {
                    $vars = $q->getVariables();
                    $this->assertEquals($this->studentUserId, $vars['findLearningPlans__filters']['value']->userLegacyId->eq);

                    return true;
                }))
                ->willReturn(json_encode($this->learning));

            return $gqlClient->reveal();
        });
        $req = Request::create("/user-learning/$this->portalId?userId=$this->studentUserId&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $learning = json_decode($res->getContent(), true);
        $this->assertNotEmpty($learning);
    }

    public function testFilterByStatus()
    {
        {
            $app = $this->getApp();
            $app->extend('go1.client.federation_api.v1', function () {
                $gqlClient = $this->prophesize(GraphQLClient::class);
                $gqlClient
                    ->execute(Argument::that(function (findLearningPlans $q) {
                        $vars = $q->getVariables();
                        $this->assertEquals(['IN_PROGRESS'], $vars['findLearningPlans__filters']['value']->status->oneOf);

                        return true;
                    }))
                    ->willReturn(json_encode($this->learning));

                return $gqlClient->reveal();
            });

            $req = Request::create("/user-learning/$this->portalId?userId=$this->studentUserId&status=in-progress&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(200, $res->getStatusCode());
            $learning = json_decode($res->getContent(), true);
            $this->assertNotEmpty($learning);
        }

        # Invalid status
        {
            $app = $this->getApp();
            $req = Request::create("/user-learning/$this->portalId?userId=$this->studentUserId&status=xxx&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }
    }

    public function testPagination()
    {
        # Can use offset & limit
        {
            $app = $this->getApp();
            $app->extend('go1.client.federation_api.v1', function () {
                $gqlClient = $this->prophesize(GraphQLClient::class);
                $gqlClient
                    ->execute(Argument::that(function (findLearningPlans $q) {
                        $vars = $q->getVariables();
                        $this->assertEquals(100, $vars['findLearningPlans__first']['value']);
                        $this->assertEquals(base64_encode("go1:Offset:10"), $vars['findLearningPlans__after']['value']);

                        return true;
                    }))
                    ->willReturn(json_encode($this->learning));

                return $gqlClient->reveal();
            });

            $req = Request::create("/user-learning/$this->portalId?userId=$this->studentUserId&offset=10&limit=100&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(200, $res->getStatusCode());
            $learning = json_decode($res->getContent(), true);
            $this->assertNotEmpty($learning);
        }

        # Offset must great than 0
        {
            $app = $this->getApp();
            $req = Request::create("/user-learning/$this->portalId?userId=$this->studentUserId&offset=-1&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }

        # Offset must not great than 100
        {
            $app = $this->getApp();
            $req = Request::create("/user-learning/$this->portalId?userId=$this->studentUserId&limit=101&jwt=$this->adminJwt");
            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }
    }

    public function testSort()
    {
        # Can use offset & limit
        {
            $app = $this->getApp();
            $app->extend('go1.client.federation_api.v1', function () {
                $gqlClient = $this->prophesize(GraphQLClient::class);
                $gqlClient
                    ->execute(Argument::that(function (findLearningPlans $q) {
                        $vars = $q->getVariables();
                        $this->assertEquals('ASC', $vars['findLearningPlans__orderBy']['value']->endedAt);
                        $this->assertEquals('DESC', $vars['findLearningPlans__orderBy']['value']->startedAt);

                        return true;
                    }))
                    ->willReturn(json_encode($this->learning));

                return $gqlClient->reveal();
            });

            $req = Request::create("/user-learning/$this->portalId?sort[startedAt]=desc&sort[endedAt]=asc&jwt=$this->studentJwt");
            $res = $app->handle($req);
            $this->assertEquals(200, $res->getStatusCode());

            $learning = json_decode($res->getContent(), true);
            $this->assertNotEmpty($learning);
        }

        # Invalid field name
        {
            $app = $this->getApp();
            $req = Request::create("/user-learning/$this->portalId?sort[foo]=desc&jwt=$this->studentJwt");
            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }
        # Invalid operation
        {
            $app = $this->getApp();
            $req = Request::create("/user-learning/$this->portalId?sort[startedAt]=foo&jwt=$this->studentJwt");
            $res = $app->handle($req);
            $this->assertEquals(400, $res->getStatusCode());
        }
    }

    public function testFilterByContentId()
    {
        $app = $this->getApp();
        $app->extend('go1.client.federation_api.v1', function () {
            $gqlClient = $this->prophesize(GraphQLClient::class);
            $gqlClient
                ->execute(Argument::that(function (findLearningPlans $q) {
                    $vars = $q->getVariables();
                    $this->assertEquals(10, $vars['findLearningPlans__filters']['value']->loLegacyId->eq);

                    return true;
                }))
                ->willReturn(json_encode($this->learning));

            return $gqlClient->reveal();
        });

        $req = Request::create("/user-learning/$this->portalId?contentId=10&jwt=$this->studentJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());

        $learning = json_decode($res->getContent(), true);
        $this->assertNotEmpty($learning);
    }
}
