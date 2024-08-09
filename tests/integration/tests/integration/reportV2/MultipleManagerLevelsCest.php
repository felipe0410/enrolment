<?php

use Codeception\Util\HttpCode;

class MultipleManagerLevelsCest
{
    /**
     * @param IntegrationTester $I
     * @see PSE-344
     */
    public function viewEnrolmentByManagerLevel1200(IntegrationTester $I)
    {
        $I->wantToTest('Manager sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20 with multiple manager levels, then 200 status code is shown');

        $managerLevel_1 = $I->haveUser('manager_level_1');
        $managerLevel_2 = $I->haveUser('manager_level_2');
        $managerLevel_3 = $I->haveUser('manager_level_3');
        $managerLevel_4 = $I->haveUser('manager_level_4');
        $managerLevel_5 = $I->haveUser('manager_level_5');
        $managerLevel_6 = $I->haveUser('manager_level_6');
        $course = $I->haveFixture('course_1');
        $uri = "/enrolment/content-learning/{$managerLevel_1->portal->id}/{$course->id}?offset=0&limit=20";
        $payload = ["due_date" => strtotime('today'),"status" => -2,"version" => 2];
        $I->amBearerAuthenticated($I->haveUser('admin')->jwt);
        $I->assignPlan($managerLevel_1->portal->id, $course->id, $managerLevel_5->id, $payload);
        $I->assignPlan($managerLevel_1->portal->id, $course->id, $managerLevel_6->id, $payload);

        $I->amBearerAuthenticated($managerLevel_1->jwt);
        $I->waitUntilSuccess(function () use ($I, $managerLevel_5, $managerLevel_6, $uri) {
            $I->sendGET($uri);
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseContains($managerLevel_5->email);
            $I->dontSeeResponseContains($managerLevel_6->email);
        }, $I->getHighLatency(), 1000);

        $I->amBearerAuthenticated($managerLevel_2->jwt);
        $I->sendGET($uri);
        $I->seeResponseContains($managerLevel_5->email);
        $I->seeResponseContains($managerLevel_6->email);

        $I->amBearerAuthenticated($managerLevel_3->jwt);
        $I->sendGET($uri);
        $I->seeResponseContains($managerLevel_5->email);
        $I->seeResponseContains($managerLevel_6->email);

        $I->amBearerAuthenticated($managerLevel_4->jwt);
        $I->sendGET($uri);
        $I->seeResponseContains($managerLevel_5->email);
        $I->seeResponseContains($managerLevel_6->email);

        $I->amBearerAuthenticated($managerLevel_5->jwt);
        $I->sendGET($uri);
        $I->seeResponseContains($managerLevel_6->email);
    }
}
