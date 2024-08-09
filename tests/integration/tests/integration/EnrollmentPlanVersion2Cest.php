<?php

use Codeception\Util\HttpCode;

class EnrollmentPlanVersion2Cest
{
    public function _before(IntegrationTester $I)
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    /**
     * @param IntegrationTester $I
     * @see MGL-387
     * @link https://code.go1.com.au/domain-learning-activity/enrolment/-/blob/master/services/plan/resources/docs/plan-create.md
     */
    public function assignNonLearners(IntegrationTester $I)
    {
        $I->wantToTest("Assigner send a POST /enrolment/plan/portal_Id/LO_Id/user/user_Id request for assign learning, then 200 status code is shown");
        $assigner = $I->haveUser('manager_1');
        $lo = $I->haveFixture('course_for_assign_v2');
        $learner = $I->haveUser('learner_for_assigned_v2_1');
        $expectedDueDate = strtotime('+30 days');
        $I->amBearerAuthenticated($assigner->jwt);
        $I->sendPOST("enrolment/plan/{$assigner->portal->id}/{$lo->id}/user/{$learner->id}", [
            'due_date' => $expectedDueDate,
            'status' => -2,
            'version' => 2
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $assignId = json_decode($I->grabResponse())->id;

        $I->amGoingTo('Assigner going to manage page -> Assigned -> Not started Tab');
        $I->waitUntilSuccess(function () use ($I, $assigner, $lo, $learner, $assignId, $expectedDueDate) {
            $I->sendGET("/enrolment/content-learning/{$assigner->portal->id}/{$lo->id}?activityType=assigned&status=not-started");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->assertEquals(1, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
            $planIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.legacyId');
            $enrolmentIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.state.legacyId');
            $dueDate = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.dueDate');
            $userIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.user.legacyId');
            $I->assertCount(1, $planIds);
            $I->assertEquals($assignId, $planIds[0]);
            $I->assertEquals($expectedDueDate, $dueDate[0]);
            $I->assertEmpty($enrolmentIds);
            $I->assertEquals($learner->id, $userIds[0]);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @see MGL-387
     * @link https://code.go1.com.au/domain-learning-activity/enrolment/-/blob/master/services/plan/resources/docs/plan-create.md
     */
    public function reassignNotStarted(IntegrationTester $I)
    {
        $I->wantToTest("Assigner send a POST /enrolment/plan/portal_Id/LO_Id/user/user_Id request for reassign learning with not started status, then 200 status code is shown");
        $assigner = $I->haveUser('manager_1');
        $lo = $I->haveFixture('course_for_assign_v2_not_started');
        $learner = $I->haveUser('learner_for_assigned_v2_2');

        # assigner assign learning
        {
            $I->amBearerAuthenticated($assigner->jwt);
            $I->sendPOST("enrolment/plan/{$assigner->portal->id}/{$lo->id}/user/{$learner->id}", [
                'due_date' => strtotime('+30 days'),
                'status' => -2,
                'version' => 2
            ]);
            $I->seeResponseCodeIs(HttpCode::OK);
            $assignId = json_decode($I->grabResponse())->id;
        }

        # assigner reassign learning
        {
            $expectedDueDate = strtotime('+60 days');
            $I->amBearerAuthenticated($assigner->jwt);
            $I->sendPOST("enrolment/plan/{$assigner->portal->id}/{$lo->id}/user/{$learner->id}", [
                'due_date' => $expectedDueDate,
                'status' => -2,
                'version' => 2
            ]);
            $I->seeResponseCodeIs(HttpCode::OK);
            $reassignId = json_decode($I->grabResponse())->id;
            $I->assertNotEquals($assignId, $reassignId);
        }

        $I->amGoingTo('Assigner going to manage page -> Assigned -> Not started Tab');
        $I->waitUntilSuccess(function () use ($I, $assigner, $lo, $reassignId, $expectedDueDate, $learner) {
            $I->sendGET("/enrolment/content-learning/{$assigner->portal->id}/{$lo->id}?activityType=assigned&status=not-started");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->assertEquals(1, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
            $planIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.legacyId');
            $enrolmentIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.state.legacyId');
            $dueDate = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.dueDate');
            $userIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.user.legacyId');
            $I->assertCount(1, $planIds);
            $I->assertEquals($reassignId, $planIds[0]);
            $I->assertEquals($expectedDueDate, $dueDate[0]);
            $I->assertEmpty($enrolmentIds);
            $I->assertEquals($learner->id, $userIds[0]);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @see  MGL-387
     * @link https://code.go1.com.au/domain-learning-activity/enrolment/-/blob/master/services/plan/resources/docs/plan-create.md
     */
    public function reassignInProgress(IntegrationTester $I)
    {
        $I->wantToTest("Assigner send a POST /enrolment/plan/portal_Id/LO_Id/user/user_Id request for reassign learning, then 200 status code is shown");
        $assigner = $I->haveUser('manager_1');
        $lo = $I->haveFixture('course_for_assign_v2_in_progress');
        $learner = $I->haveUser('learner_for_assigned_v2_3');

        # assigner assign learning
        {
            $I->amBearerAuthenticated($assigner->jwt);
            $I->sendPOST("enrolment/plan/{$assigner->portal->id}/{$lo->id}/user/{$learner->id}", [
                'due_date' => strtotime('+30 days'),
                'status' => -2,
                'version' => 2
            ]);
            $I->seeResponseCodeIs(HttpCode::OK);
            $assignId = json_decode($I->grabResponse())->id;
        }

        # learner enrolled to learning
        {
            $module = $I->haveFixture('module_for_course_for_assign_v2_in_progress');
            $I->amBearerAuthenticated($learner->jwt);
            $I->sendPOST("/enrolment/{$learner->portal->id}/0/{$lo->id}/enrolment");
            $I->seeResponseCodeIs(HttpCode::OK);
            $courseEnrolmentId = json_decode($I->grabResponse())->id;

            $I->sendPOST("/enrolment/{$learner->portal->id}/{$lo->id}/{$module->id}/enrolment?parentEnrolmentId=$courseEnrolmentId");
            $I->seeResponseCodeIs(HttpCode::OK);
            $enrolmentId = json_decode($I->grabResponse())->id;
        }

        # assigner reassign learning
        {
            $expectedDueDate = strtotime('+60 days');
            $I->amBearerAuthenticated($assigner->jwt);
            $I->sendPOST("enrolment/plan/{$assigner->portal->id}/{$lo->id}/user/{$learner->id}", [
                'due_date' => $expectedDueDate,
                'status' => -2,
                'version' => 2
            ]);
            $I->seeResponseCodeIs(HttpCode::OK);
            $reassignId = json_decode($I->grabResponse())->id;
            $I->assertNotEquals($assignId, $reassignId);
        }

        $I->amGoingTo('Assigner going to manage page -> Assigned -> Not started Tab');
        $I->waitUntilSuccess(function () use ($I, $assigner, $lo, $learner, $reassignId, $expectedDueDate) {
            $I->sendGET("/enrolment/content-learning/{$assigner->portal->id}/{$lo->id}?activityType=assigned&status=not-started");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->assertEquals(1, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
            $planIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.legacyId');
            $enrolmentIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.state.legacyId');
            $dueDate = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.dueDate');
            $userIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.user.legacyId');
            $I->assertCount(1, $planIds);
            $I->assertEquals($reassignId, $planIds[0]);
            $I->assertEquals($expectedDueDate, $dueDate[0]);
            $I->assertEmpty($enrolmentIds);
            $I->assertEquals($learner->id, $userIds[0]);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @see  MGL-387
     * @link https://code.go1.com.au/domain-learning-activity/enrolment/-/blob/master/services/plan/resources/docs/plan-create.md
     */
    public function selfDirected(IntegrationTester $I)
    {
        $I->wantToTest("Assigner send a POST /enrolment/plan/portal_Id/LO_Id/user/user_Id request for self directed learning, then 200 status code is shown");
        $assigner = $I->haveUser('manager_1');
        $lo = $I->haveFixture('course_for_assign_v2_self_directed');
        $learner  = $I->haveUser('learner_for_assigned_v2_4');

        # learner enrolled to learning
        {
            $module = $I->haveFixture('module_for_course_for_assign_v2_self_directed');
            $I->amBearerAuthenticated($learner->jwt);
            $I->sendPOST("/enrolment/{$learner->portal->id}/0/{$lo->id}/enrolment");
            $I->seeResponseCodeIs(HttpCode::OK);
            $courseEnrolmentId = json_decode($I->grabResponse())->id;

            $I->sendPOST("/enrolment/{$learner->portal->id}/{$lo->id}/{$module->id}/enrolment?parentEnrolmentId=$courseEnrolmentId");
            $I->seeResponseCodeIs(HttpCode::OK);
            $enrolmentId = json_decode($I->grabResponse())->id;
        }

        # assigner reassign learning
        {
            $expectedDueDate = strtotime('+60 days');
            $I->amBearerAuthenticated($assigner->jwt);
            $I->sendPOST("enrolment/plan/{$assigner->portal->id}/{$lo->id}/user/{$learner->id}", [
                'due_date' => $expectedDueDate,
                'status' => -2,
                'version' => 2
            ]);
            $I->seeResponseCodeIs(HttpCode::OK);
            $reassignId = json_decode($I->grabResponse())->id;
        }

        $I->amGoingTo('Assigner going to manage page -> Assigned -> In Progress Tab');
        $I->waitUntilSuccess(function () use ($I, $assigner, $lo, $learner, $reassignId, $courseEnrolmentId, $expectedDueDate) {
            $I->sendGET("/enrolment/content-learning/{$assigner->portal->id}/{$lo->id}?activityType=assigned&status=in-progress");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->assertEquals(1, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
            $planIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.legacyId');
            $enrolmentIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.state.legacyId');
            $dueDate = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.dueDate');
            $userIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.user.legacyId');
            $I->assertCount(1, $planIds);
            $I->assertEquals($reassignId, $planIds[0]);
            $I->assertEquals($expectedDueDate, $dueDate[0]);
            $I->assertEquals($courseEnrolmentId, $enrolmentIds[0]);
            $I->assertEquals($learner->id, $userIds[0]);
        }, $I->getHighLatency(), 1000);
    }
}
