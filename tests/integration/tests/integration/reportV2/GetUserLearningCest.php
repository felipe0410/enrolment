<?php

namespace go1\reportv2\tests\integration\tests;

use Codeception\Util\HttpCode;
use IntegrationTester;

class GetUserLearningCest
{
    /**
     * @param IntegrationTester $I
     * @sse GO1P-39580
     */
    public function getUserLearning(IntegrationTester $I)
    {
        $I->wantToTest("");

        $learner = $I->haveUser('learner_1');
        $I->haveFixture('course_1');

        $params = [
            'userId'        => $learner->id,
            'offset'        => 0,
            'limit'         => 20,
            'sort[endedAt]' => 'asc'
        ];

        $I->amBearerAuthenticated($I->haveUser('admin')->jwt);
        $I->sendGET("/enrolment/user-learning/{$learner->portal->id}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseMatchesJsonType([
            'node' => [
                'state' => [
                    'legacyId'  => 'integer',
                    'status'    => 'string',
                    'startedAt' => 'integer|null',
                    'endedAt'   => 'integer|null',
                    'updatedAt' => 'integer',
                ],
                'lo'    => [
                    'id'        => 'string',
                    'title'     => 'string',
                    'label'     => "string",
                    'image'     => 'string',
                    'publisher' => ['subDomain' => 'string'],
                ],
            ],
        ], '$data.edges[*]');
    }
}
