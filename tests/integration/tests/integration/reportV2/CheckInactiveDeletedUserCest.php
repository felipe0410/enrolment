<?php

use Codeception\Util\HttpCode;

class CheckInactiveDeletedUserCest
{
    public function _before(IntegrationTester $I)
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    /**
     * @param IntegrationTester $I
     * @see PSE-408
     */
    public function getInactiveUser(IntegrationTester $I)
    {
        $I->wantToTest('Admin send a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&facet=true request, then 200 status code is shown and no inactive users in return');

        $course    = $I->haveFixture('course_for_manager_self_view_edit_delete_assignment');
        $admin     = $I->haveUser('admin_2');
        $learner   = $I->haveUser('learner_for_inactive_user_1');
        $accountId = $learner->accounts[0]->id;
        $I->amBearerAuthenticated($admin->jwt);
        $I->assignPlan(
            $admin->portal->id,
            $course->id,
            $learner->id,
            ["due_date" => strtotime('+5 days'), "status" => -2]
        );

        // Deactivate the learner
        $I->sendPUT("/user/account/{$admin->portal->id}/$accountId", ["status" => false]);

        // Wait for surrogate table to update,  10 seconds was too quick
        sleep(15);

        // Verify: Not see the inactivate user in report
        $I->waitUntilSuccess(function () use ($I, $admin, $learner, $course) {
            $I->sendGET("/enrolment/content-learning/{$admin->portal->id}/{$course->id}?offset=0&limit=20&facet=true");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->dontSeeResponseContains($learner->email);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @see MGL-755
     */
    public function getInactiveUserWithFilter(IntegrationTester $I)
    {
        $I->wantToTest('Admin send a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&facet=true&status=in-progress request, then 200 status code is shown and no inactive users in return');

        $course    = $I->haveFixture('course_for_manager_self_view_edit_delete_assignment');
        $admin     = $I->haveUser('admin_2');
        $learner   = $I->haveUser('learner_for_inactive_user_2');
        $accountId = $learner->accounts[0]->id;
        $I->amBearerAuthenticated($admin->jwt);

        // Deactivate the learner
        $I->sendPUT("/user/account/{$admin->portal->id}/$accountId", ["status" => false]);

        // Wait for surrogate table to update,  10 seconds was too quick
        sleep(15);

        // Verify: Not see the inactivate user in report
        $I->waitUntilSuccess(function () use ($I, $admin, $learner, $course) {
            $I->sendGET("/enrolment/content-learning/{$admin->portal->id}/{$course->id}?offset=0&limit=20&facet=true&status=in-progress");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->dontSeeResponseContains($learner->email);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @see PSE-409
     */
    public function getDeletedUser(IntegrationTester $I)
    {
        $I->wantToTest('Admin send a GET /enrolment/content-learning/portal_Id/course_id?offset=0&limit=20&facet=true request, then 200 status code is shown and no deleted users in return');

        $course  = $I->haveFixture('course_for_manager_self_view_edit_delete_assignment');
        $admin   = $I->haveUser('admin_2');
        $learner = $I->haveUser('learner_for_deleted_user');
        $I->amBearerAuthenticated($admin->jwt);
        $I->assignPlan(
            $admin->portal->id,
            $course->id,
            $learner->id,
            ["due_date" => strtotime('+5 days'), "status" => -2]
        );

        // Delete the learner
        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $I->sendPOST("/user/masking/{$learner->id}");
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        // Verified the deleted user
        $I->waitUntilSuccess(function () use ($learner, $I) {
            $I->sendGET('/user/users/' . $learner->portal->domain, ['mail' => $learner->email]);
            $I->seeResponseCodeIs(HttpCode::OK);
            $response = $I->grabResponse();
            $I->assertEquals('[]', $response);
        }, $I->getHighLatency(), 1000);


        // Verify: Not see the deleted user in report
        $I->amBearerAuthenticated($admin->jwt);
        $I->waitUntilSuccess(function () use ($I, $learner, $admin, $course) {
            $I->sendGET("/enrolment/content-learning/{$admin->portal->id}/{$course->id}?offset=0&limit=20&facet=true");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->dontSeeResponseContains($learner->email);
        }, $I->getHighLatency(), 1000);
    }
}
