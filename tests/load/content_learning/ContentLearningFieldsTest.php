<?php

namespace go1\enrolment\tests\load\content_learning;

use go1\core\util\client\federation_api\v1\GraphQLClient;
use go1\core\util\client\federation_api\v1\Query;
use Prophecy\Argument;
use Symfony\Component\HttpFoundation\Request;

class ContentLearningFieldsTest extends ContentLearningTestCase
{
    protected bool $queryResultLog = false;

    public function testHasSupportFields()
    {
        $app = $this->getApp();
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?fields=userId,legacyId,state.legacyId&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());

        $this->assertEquals(
            "The following 1 assertions failed:\n1) fields: Value \"userId\" is not an element of the valid values: legacyId, state.legacyId, user.email, user.legacyId\n",
            json_decode($res->getContent())->message
        );
    }

    public function testGetLearningByLegacyIdFieldsQuery()
    {
        $app = $this->getApp();
        $app->extend('go1.client.federation_api.v1', function () {
            $gqlClient = $this->prophesize(GraphQLClient::class);
            $gqlClient
                ->execute(
                    Argument::that(function (Query $query) {
                        $expectedGql = 'getLearningPlans(ids: $getLearningPlans__ids) { legacyId }';
                        $this->assertEquals($expectedGql, $query->getGql());

                        return true;
                    })
                )
                ->willReturn(json_encode([
                    'data' => [],
                ]));

            return $gqlClient->reveal();
        });
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?fields=legacyId&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testGetLearningByUserMail()
    {
        $app = $this->getApp();
        $app->extend('go1.client.federation_api.v1', function () {
            $gqlClient = $this->prophesize(GraphQLClient::class);
            $gqlClient
                ->execute(
                    Argument::that(function (Query $query) {
                        $expectedGql = 'getLearningPlans(ids: $getLearningPlans__ids) { user { email } }';
                        $this->assertEquals($expectedGql, $query->getGql());

                        return true;
                    })
                )
                ->willReturn(json_encode([
                    'data' => [],
                ]));

            return $gqlClient->reveal();
        });
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?fields=user.email&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testGetLearningByUserId()
    {
        $app = $this->getApp();
        $app->extend('go1.client.federation_api.v1', function () {
            $gqlClient = $this->prophesize(GraphQLClient::class);
            $gqlClient
                ->execute(
                    Argument::that(function (Query $query) {
                        $expectedGql = 'getLearningPlans(ids: $getLearningPlans__ids) { user { legacyId } }';
                        $this->assertEquals($expectedGql, $query->getGql());

                        return true;
                    })
                )
                ->willReturn(json_encode([
                    'data' => [],
                ]));

            return $gqlClient->reveal();
        });
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?fields=user.legacyId&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testGetLearningByFieldsQuery()
    {
        $app = $this->getApp();
        $app->extend('go1.client.federation_api.v1', function () {
            $gqlClient = $this->prophesize(GraphQLClient::class);
            $gqlClient
                ->execute(
                    Argument::that(function (Query $query) {
                        # Expected that `userAndStateFields` fragment is being used if load enrolment
                        $expectedGql = 'getLearningPlans(ids: $getLearningPlans__ids) { legacyId state { legacyId } }';
                        $this->assertEquals($expectedGql, $query->getGql());

                        return true;
                    })
                )
                ->willReturn(json_encode([
                    'data' => [],
                ]));

            return $gqlClient->reveal();
        });
        $req = Request::create("/content-learning/$this->portalId/$this->contentId?fields=legacyId,state.legacyId&jwt=$this->adminJwt");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }
}
