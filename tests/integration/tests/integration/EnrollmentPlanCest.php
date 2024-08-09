<?php

use Codeception\Util\HttpCode;

class EnrollmentPlanCest
{
    /**
     * @param IntegrationTester $I
     * @see  GO1P-39843
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/services/plan/resources/docs/plan-update.md
     */
    public function updateEnrollmentAssign204(IntegrationTester $I)
    {
        $I->wantToTest("Send a PUT /enrolment/plan/Plan_Id?jwt=AdminJWT/managerJWT request with valid data, then 204 status code is shown");

        $manager  = $I->haveUser("manager_1");
        $loId     = $I->haveFixture("course_for_assign_plan");
        $learner  = $I->haveUser("learner_11");
        $assignID = $this->createEnrollmentAssign($I, $loId, $learner);

        // Portal admin updates due date
        $payload = ["due_date" => strtotime("now +5 days")];
        $I->sendPUT("/enrolment/plan/{$assignID}", $payload);
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        // Manager updates due date
        $payload = ["due_date" => strtotime("now +10 days")];
        $I->amBearerAuthenticated($manager->jwt);
        $I->sendPUT("/enrolment/plan/{$assignID}", $payload);
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
    }

    /**
     * @param IntegrationTester $I
     * @see  GO1P-39844
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/services/plan/resources/docs/plan-update.md
     */
    public function updateEnrollmentAssign403(IntegrationTester $I)
    {
        $I->wantToTest("Send a PUT /enrolment/plan/Plan_Id?jwt=managerJWT request with without management, then 403 status code is shown");

        $manager  = $I->haveUser("manager_2");
        $loId     = $I->haveFixture("course_for_assign_plan");
        $learner  = $I->haveUser("learner_11");
        $assignID = $this->createEnrollmentAssign($I, $loId, $learner);

        // Manager updates due date that he does not manage
        $payload = ["due_date" => strtotime("now +10 days")];
        $I->amBearerAuthenticated($manager->jwt);
        $I->sendPUT("/enrolment/plan/{$assignID}", $payload);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContains("Only portal administrator and user\u0027s manager can update assign learning.");
    }

    /**
     * @param IntegrationTester $I
     * @see  GO1P-39845, GO1P-39846, GO1P-39847, GO1P-39851
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/services/plan/resources/docs/plan-update.md
     */
    public function updateEnrollmentAssign400And404(IntegrationTester $I)
    {
        $I->wantToTest("Send a PUT /enrolment/plan/Plan_Id?jwt=adminJWT request, then 400 status code is shown");

        $loId     = $I->haveFixture("course_for_assign_plan");
        $learner  = $I->haveUser("learner_11");
        $assignID = $this->createEnrollmentAssign($I, $loId, $learner);

        // Invalid due date
        $I->wantToTest("Send a PUT /enrolment/plan/Plan_Id?jwt=managerJWT request with invalid due date, then 400 status code is shown");
        $payload = ["due_date" => "test"];
        $I->sendPUT("/enrolment/plan/{$assignID}", $payload);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->canSeeResponseContainsJson(["error" => ["path" => 'due_date']]);

        // Invalid field name
        $I->sendPUT(
            "/enrolment/plan/{$assignID}",
            ["due_date_invalid_name" => (new \DateTime(("now +2 days")))->getTimestamp()]
        );
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains("Unknown field");

        // Non-existing plan ID
        $I->wantToTest("Send a PUT /enrolment/plan/Plan_Id?jwt=managerJWT request with non-existing plan_ID, then 404 status code is shown");
        $I->sendPUT("/enrolment/plan/404", $payload);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(["message" => "Plan object is not found."]);

        // Not allow to update past time
        $I->wantToTest("Send a PUT /enrolment/plan/Plan_Id?jwt=manager/adminJWT request with past time, then 400 status code is shown");
        $I->sendPUT("/enrolment/plan/{$assignID}", ["due_date" => (new \DateTime("now -2 days"))->getTimestamp()]);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains("Due date can not be in the past");
    }

    /**
     * @param IntegrationTester    $I
     * @param object        $loId
     * @param \go1\integrationTest\Model\User $learner
     * @return int
     */
    protected function createEnrollmentAssign(IntegrationTester $I, object $loId, \go1\integrationTest\Model\User $learner): int
    {
        $admin = $I->haveUser("admin");
        $I->amBearerAuthenticated($admin->jwt);
        $payload = ["due_date" => strtotime("now"), "status" => -2];

        return $I->assignPlan($admin->portal->id, $loId->id, $learner->id, $payload)->id;
    }

    /**
     * @param IntegrationTester $I
     * @see  MGL-157
     */
    public function crudPlanOfInactiveAssignee(IntegrationTester $I)
    {
        $lo      = $I->haveFixture("course_for_assign_plan");
        $learner = $I->haveUser("inactive_user");
        $planId  = $this->createEnrollmentAssign($I, $lo, $learner);

        $I->amGoingTo('Deactivate user account');
        $portalId  = $learner->portal->id;
        $accountId = $learner->accounts[0]->id;
        $adminJWT  = $learner->portal->getAdminJwt();
        $I->sendPUT("/user/account/$portalId/$accountId?jwt=$adminJWT", ['status' => 0]);

        $I->amBearerAuthenticated($adminJWT);

        $I->wantToTest("Send a POST /enrolment/plan/portal_Id/LO_Id/user/user_Id request, then 400 status code is shown");
        $I->sendPOST(
            "enrolment/plan/{$portalId}/{$lo->id}/user/{$learner->id}",
            ["due_date" => strtotime("+30 days"), "status" => -2]
        );
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains("The account connected to this plan is deactivated.");

        $I->wantToTest("Send a PUT /enrolment/plan/plan_Id request, then 400 status code is shown");
        $I->sendPUT("enrolment/plan/{$planId}", ["due_date" => strtotime("+30 days"), "status" => -2]);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains("The account connected to this plan is deactivated.");

        $I->wantToTest("Send a POST /enrolment/plan/re-assign request, then 400 status code is shown");
        $I->sendPOST('/enrolment/plan/re-assign', ['plan_ids' => [$planId], 'due_date' => strtotime('+45 days')]);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains("The account connected to this plan is deactivated.");

        $I->wantToTest("Send a DELETE /enrolment/plan/plan_Id request, then 204 status code is shown");
        $I->sendDELETE("enrolment/plan/{$planId}");
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
    }

    /**
     * @param IntegrationTester $I
     * @see MGL-854
     */
    public function assignContentForGroupMember(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/plan/Portal_Id/lo_Id/user/user_Id request with user in group, then 204 status code is shown');
        $portal  = $I->havePortal("portal_1");
        $learner = $I->haveUser("learner_in_group_1");
        $lo      = $I->haveFixture("course_for_assign_plan");
        $group   = $I->haveFixture("group_public_for_membership_1");
        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $I->haveHttpHeader('Content-Type', 'application/json');
        $payload = [
            'due_date'    => strtotime('now'),
            'status'      => -2,
            'source_type' => 'group',
            'source_id'   => $group->id
        ];
        $I->sendPOST("/enrolment/plan/$portal->id/$lo->id/user/$learner->id", $payload);
        $I->seeResponseCodeIs(HttpCode::OK);
        $assignId = json_decode($I->grabResponse(), false)->id;
        $I->waitUntilSuccess(function () use ($I, $lo, $portal, $assignId, $group) {
            $data = "{\"query\":{\"bool\":{\"must\":[{\"match\": {\"lo.id\": $lo->id}}]}},\"sort\":[],\"from\":0,\"size\":200}";
            $I->sendPOST(
                "/report-data/enrolments/$portal->domain?" . http_build_query(['use-lr-index' => true]),
                json_decode($data, true)
            );
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseContainsJson(["_id" => "plan-assigned:$assignId"]);
            $I->seeResponseContainsJson([
                'groupIds' => [$group->id],
                'groups'   => [$group->title]
            ]);
        }, 60, 1000);
    }
}
