<?php

namespace go1\reportv2\tests\integration\tests;

use Codeception\Util\HttpCode;
use IntegrationTester;

class GetEnrolmentContentLearningCest
{
    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-39128
     */
    public function getEnrolmentContentLearning200(IntegrationTester $I)
    {
        $I->wantToTest("Send a Get /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=2&sort[updatedAt]=desc request, then 200 status code is shown");

        // Portal admin
        $I->amBearerAuthenticated($I->haveUser('admin')->jwt);
        $this->status200($I);

        // Content Admin
        $I->amBearerAuthenticated($I->haveUser('content_admin_1')->jwt);
        $this->status200($I);

        // Manager
        $I->amBearerAuthenticated($I->haveUser('manager_1')->jwt);
        $this->status200($I);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-39129
     */
    public function getEnrolmentContentLearning403(IntegrationTester $I)
    {
        $I->wantToTest("Send a Get /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=2&sort[updatedAt]=desc request with invalid jwt, then 403 status code is shown");

        // Anonymous user
        $this->status403($I);

        // Learner
        $I->amBearerAuthenticated($I->haveUser('learner_1')->jwt);
        $this->status403($I);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     */
    private function status200(IntegrationTester $I): void
    {
        $portal  = $I->havePortal('portal_1');
        $content = $I->haveFixture('course_1');
        $option  = [
            'offset'          => 0,
            'limit'           => 2,
            'sort[updatedAt]' => 'desc'
        ];

        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$content->id}", $option);
        $I->seeResponseCodeIs(HttpCode::OK);
        $legacyIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.state.legacyId');
        $I->assertEquals(2, count($legacyIds));

        // Assertions for response data
        $I->seeResponseMatchesJsonType(['totalCount' => 'integer'], '$data');
        $I->seeResponseMatchesJsonType([
            'node' => [
                'state' => [
                    'legacyId'  => 'integer',
                    'status'    => 'string',
                    'startedAt' => 'integer',
                    'endedAt'   => 'null|integer',
                    'updatedAt' => 'integer',
                ],
                'user'  => [
                    'legacyId'  => 'integer',
                    'firstName' => 'string',
                    'lastName'  => 'string',
                    'avatarUri' => 'null|string',
                ],
            ],
        ], '$data.edges[*]');
    }

    /**
     * @param IntegrationTester $I
     */
    private function status403(IntegrationTester $I)
    {
        $portal  = $I->havePortal('portal_1');
        $content = $I->haveFixture('course_1');
        $option  = [
            'offset'          => 0,
            'limit'           => 2,
            'sort[updatedAt]' => 'desc',
        ];
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$content->id}", $option);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(["message" => "Missing or invalid JWT."]);
    }
}
