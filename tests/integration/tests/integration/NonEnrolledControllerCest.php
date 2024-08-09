<?php

use Codeception\Util\HttpCode;

class NonEnrolledControllerCest
{
    /**
     * @param IntegrationTester $I
     * @see GO1P-40236
     */
    public function managerSearchThemself200(IntegrationTester $I)
    {
        $I->wantToTest('Manager sends a GET /enrolment/learning-objects/course_Id/non-enrolled?keyword=firstname&sort[0][field]=name&sort[0][direction]=asc&limit=20 request by themself, then 200 status code is shown');

        $manager = $I->haveUser('manager_1');
        $courseId = $I->haveFixture('course_1')->id;
        $I->amBearerAuthenticated($manager->jwt);

        $I->waitUntilSuccess(function () use ($I, $manager, $courseId) {
            $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$manager->firstName}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseContains($manager->firstName);

            $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$manager->email}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseContainsJson(['mail' => $manager->email]);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-40239
     */
    public function managerSearchTheirLearners(IntegrationTester $I)
    {
        $I->wantToTest('Manager sends a GET /enrolment/learning-objects/course_Id/non-enrolled?keyword=email&sort[0][field]=name&sort[0][direction]=asc&limit=20 request with their learners, then 200 status code is shown');

        $I->waitUntilSuccess(function () use ($I) {
            $manager = $I->haveUser('manager_1');
            $courseId = $I->haveFixture('course_1')->id;
            $learner = $I->haveUser('learner_with_manager_2');
            $I->amBearerAuthenticated($manager->jwt);
            $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$learner->firstName}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseContains($learner->firstName);

            $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$learner->email}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseContainsJson(['mail' => $learner->email]);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @sse GO1P-40240
     */
    public function managerSearchNotTheirLearners(IntegrationTester $I)
    {
        $I->wantToTest('Manager sends a GET /enrolment/learning-objects/course_Id/non-enrolled?keyword=email&sort[0][field]=name&sort[0][direction]=asc&limit=20 request with not their learners, then 200 status code is shown');

        $manager = $I->haveUser('manager_1');
        $courseId = $I->haveFixture('course_1')->id;
        $learner = $I->haveUser('student_1');
        $I->amBearerAuthenticated($manager->jwt);
        $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$learner->firstName}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
        $I->seeResponseCodeIs(HttpCode::OK);
        $response = $I->grabResponse();
        $I->seeResponseContainsJson(['data' => []]);

        $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$learner->email}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => []]);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-40237
     */
    public function adminSearchThemself200(IntegrationTester $I)
    {
        $I->wantToTest('Admin send a GET /enrolment/learning-objects/course_Id/non-enrolled?keyword=email&sort[0][field]=name&sort[0][direction]=asc&limit=20 request by themself, then 200 status code is shown');

        $admin = $I->haveUser('admin');
        $courseId = $I->haveFixture('course_1')->id;
        $I->amBearerAuthenticated($admin->jwt);
        $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$admin->firstName}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContains($admin->firstName);

        $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$admin->email}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['mail' => $admin->email]);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-40238
     */
    public function adminSearchUser200(IntegrationTester $I)
    {
        $I->wantToTest('Admin send a GET /enrolment/learning-objects/course_Id/non-enrolled?keyword=email&sort[0][field]=name&sort[0][direction]=asc&limit=20 request with learners in a portal, then 200 status code is shown');

        $admin = $I->haveUser('admin');
        $courseId = $I->haveFixture('course_1')->id;
        $I->amBearerAuthenticated($admin->jwt);
        $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$admin->firstName}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContains($admin->firstName);

        $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$admin->email}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['mail' => $admin->email]);
    }

    /**
     * @param IntegrationTester $I
     * @see PSE-335
     */
    public function searchUserByLastName(IntegrationTester $I)
    {
        $I->wantToTest('Admin send a GET /enrolment/learning-objects/course_Id/non-enrolled?keyword=lastname&sort[0][field]=name&sort[0][direction]=asc&limit=20 request, then 200 status code is shown');

        $admin = $I->haveUser('admin');
        $courseId = $I->haveFixture('course_1')->id;
        $I->amBearerAuthenticated($admin->jwt);
        $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$admin->lastName}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContains($admin->lastName);
    }

    /**
     * @param IntegrationTester $I
     * @see PSE-336
     */
    public function searchUserByFirstNameAndLastName(IntegrationTester $I)
    {
        $I->wantToTest('Admin send a GET /enrolment/learning-objects/course_Id/non-enrolled?keyword=last_name&sort[0][field]=name&sort[0][direction]=asc&limit=20 request, then 200 status code is shown');

        $admin = $I->haveUser('admin');
        $courseId = $I->haveFixture('course_1')->id;
        $I->amBearerAuthenticated($admin->jwt);
        $I->sendGET(sprintf(
            "/enrolment/learning-objects/%s/non-enrolled?keyword=%s %s&sort[0][field]=name&sort[0][direction]=asc&limit=20",
            $courseId,
            $admin->firstName,
            $admin->lastName
        ));
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContains($admin->firstName);
        $I->seeResponseContains($admin->lastName);
    }

    /**
     * @param IntegrationTester $I
     * @see PSE-339
     */
    public function searchThemselfByManager(IntegrationTester $I)
    {
        $I->wantToTest('Manager sends a GET /enrolment/learning-objects/course_Id/non-enrolled?keyword=email&sort[0][field]=name&sort[0][direction]=asc&limit=20 request, then 200 status code is shown');

        $manager = $I->haveUser('manager_1');
        $courseId = $I->haveFixture('course_1')->id;
        $I->amBearerAuthenticated($manager->jwt);
        $I->sendGET("/enrolment/learning-objects/{$courseId}/non-enrolled?keyword={$manager->email}&sort[0][field]=name&sort[0][direction]=asc&limit=20");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['mail' => $manager->email]);
    }
}
