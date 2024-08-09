<?php

use Codeception\Example;
use Codeception\Util\HttpCode;

class EnrollmentPlanReassignCest
{
    /**
     * @param IntegrationTester $I
     * @param int $loId
     * @param int $dueDate
     * @param int $learnerId
     * @return int
     */
    protected function assign(IntegrationTester $I, int $loId, int $dueDate, int $learnerId): int
    {
        $admin = $I->haveUser('admin');
        $I->amBearerAuthenticated($admin->jwt);
        $payload = ['due_date' => $dueDate, 'status' => -2];

        return $I->assignPlan($admin->portal->id, $loId, $learnerId, $payload)->id;
    }

    protected function actorRoles()
    {
        return [
            ['role' => 'admin', 'name' => 'Admin'],
            ['role' => 'manager_1', 'name' => 'Manager']
        ];
    }

    /**
     * @dataProvider  actorRoles
     * @param IntegrationTester $I
     * @param Example    $actor
     * @throws Exception
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/services/plan/resources/docs/plan-reassign.md
     * @see MGL-120, MGL-246
     */
    public function reassignNotStarted(IntegrationTester $I, Example $actor)
    {
        $actorName = $actor['name'];
        $I->wantToTest("$actorName send a POST /enrolment/plan/re-assign request with not started enrolment status, then 201 status code is shown");

        $portal = $I->havePortal('portal_1');
        $actor = $I->haveUser($actor['role']);
        $lo = $I->haveFixture('course_for_reassign_not_started');

        # actor assign learning
        {
            $learner = $I->haveUser('learner_reassign_1');
            $planId = $this->assign($I, $lo->id, strtotime('+30 days'), $learner->id);
        }

        # actor reassign learning
        {
            $now = strtotime('now');
            $payload = [
                'plan_ids' => [$planId],
                'due_date' => $expectedDueDate = strtotime('+45 days'),
            ];
            $I->amBearerAuthenticated($actor->jwt);
            $I->sendPOST('/enrolment/plan/re-assign', $payload);
            $I->seeResponseCodeIs(HttpCode::CREATED);
            $I->seeResponseMatchesJsonType(['id' => 'integer']);
            $reassignId = json_decode($I->grabResponse())[0]->id;
        }

        {
            $I->amGoingTo("$actorName going to manage page -> Assigned -> Not started Tab");
            $I->waitUntilSuccess(function () use ($I, $lo, $portal, $reassignId, $expectedDueDate, $learner) {
                $I->sendGET("/enrolment/content-learning/$portal->id/$lo->id?activityType=assigned&status=not-started");
                $I->seeResponseCodeIs(HttpCode::OK);
                $I->assertEquals(1, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
                $planIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.legacyId');
                $enrolmentIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.state.legacyId');
                $createdAt = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.createdAt');
                $dueDate = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.dueDate');
                $userIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.user.legacyId');
                $I->assertCount(1, $planIds);
                $I->assertEquals($reassignId, $planIds[0]);
                $I->assertEquals($expectedDueDate, $dueDate[0]);
                $I->assertEmpty($enrolmentIds);
                $I->assertEquals($learner->id, $userIds[0]);
            }, $I->getHighLatency(), 1000);
        }

        {
            $I->amGoingTo("$actorName going to manage page -> Assigned -> In progress Tab");
            $I->sendGET("/enrolment/content-learning/$portal->id/$lo->id?activityType=assigned&status=in-progress");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->assertEquals(0, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
        }

        {
            $I->amGoingTo("$actorName going to manage page -> Assigned -> In progress Tab");
            $I->sendGET("/enrolment/content-learning/$portal->id/$lo->id?activityType=assigned&status=completed");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->assertEquals(0, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
        }
    }

    /**
     * @dataProvider  actorRoles
     * @param IntegrationTester $I
     * @param Example $actor
     * @throws Exception
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/services/plan/resources/docs/plan-reassign.md
     * @see MGL-244, MGL-248
     */
    public function reassignInProgress(IntegrationTester $I, Example $actor)
    {
        $actorName = $actor['name'];
        $I->wantToTest("$actorName send a POST /enrolment/plan/re-assign request with in progress enrolment status, then 201 status code is shown");

        $portal = $I->havePortal('portal_1');
        $actor = $I->haveUser($actor['role']);
        $lo = $I->haveFixture('course_for_reassign_in_progress');
        $learner = $I->haveUser('learner_reassign_2');
        $planId = $this->assign($I, $lo->id, strtotime('+30 days'), $learner->id);

        # learner enrolled to learning
        {
            $module = $I->haveFixture('module_for_course_for_reassign_in_progress');
            $I->amBearerAuthenticated($learner->jwt);
            $I->sendPOST("/enrolment/$portal->id/0/$lo->id/enrolment");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseMatchesJsonType(['id' => 'integer']);
            $courseEnrolmentId = json_decode($I->grabResponse())->id;

            $I->sendPOST("/enrolment/$portal->id/$lo->id/$module->id/enrolment?parentEnrolmentId=$courseEnrolmentId");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseMatchesJsonType(['id' => 'integer']);
        }

        # actor reassign learning
        {
            $now = strtotime('now');
            $payload = [
                'plan_ids' => [$planId],
                'due_date' => $expectedDueDate = strtotime('+45 days')
            ];
            $I->amBearerAuthenticated($actor->jwt);
            $I->sendPOST('/enrolment/plan/re-assign', $payload);
            $I->seeResponseCodeIs(HttpCode::CREATED);
            $I->seeResponseMatchesJsonType(['id' => 'integer']);
            $reassignId = json_decode($I->grabResponse())[0]->id;
        }

        {
            $I->amGoingTo("$actorName going to manage page -> Assigned -> In progress Tab");
            $I->waitUntilSuccess(function () use ($I, $lo, $portal) {
                $I->sendGET("/enrolment/content-learning/$portal->id/$lo->id?activityType=assigned&status=in-progress");
                $I->seeResponseCodeIs(HttpCode::OK);
                $I->assertEquals(0, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
            }, $I->getHighLatency(), 1000);
        }

        {
            $I->amGoingTo("$actorName going to manage page -> Assigned -> Not started Tab");
            $I->waitUntilSuccess(function () use ($I, $lo, $portal, $learner, $reassignId, $expectedDueDate) {
                $I->sendGET("/enrolment/content-learning/$portal->id/$lo->id?activityType=assigned&status=not-started");
                $I->seeResponseCodeIs(HttpCode::OK);
                $I->assertEquals(1, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
                $planIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.legacyId');
                $enrolmentIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.state.legacyId');
                $createdAt = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.createdAt');
                $dueDate = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.dueDate');
                $userIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.user.legacyId');
                $I->assertCount(1, $planIds);
                $I->assertEquals($reassignId, $planIds[0]);
                $I->assertEquals($expectedDueDate, $dueDate[0]);
                $I->assertEmpty($enrolmentIds);
                $I->assertEquals($learner->id, $userIds[0]);
            }, $I->getHighLatency(), 1000);
        }

        {
            $I->amGoingTo("$actorName going to manage page -> Assigned -> Completed Tab");
            $I->sendGET("/enrolment/content-learning/$portal->id/$lo->id?activityType=assigned&status=completed");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->assertEquals(0, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
        }
    }

    /**
     * @dataProvider  actorRoles
     * @param IntegrationTester $I
     * @param Example    $actor
     * @throws Exception
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/services/plan/resources/docs/plan-reassign.md
     * @see MGL-245, MGL-249
     */
    public function reassignCompleted(IntegrationTester $I, Example $actor)
    {
        $actorName = $actor['name'];
        $I->wantToTest("$actorName send a POST /enrolment/plan/re-assign request with completed enrolment status, then 201 status code is shown");

        $portal = $I->havePortal('portal_1');
        $actor = $I->haveUser($actor['role']);
        $lo = $I->haveFixture('course_for_reassign_completed');
        $learner = $I->haveUser('learner_reassign_3');
        $planId = $this->assign($I, $lo->id, strtotime('+30 days'), $learner->id);

        # learner enrolled and completed learning
        {
            $module = $I->haveFixture('module_for_course_for_reassign_completed');
            $I->amBearerAuthenticated($learner->jwt);
            $I->sendPOST("/enrolment/$portal->id/0/$lo->id/enrolment");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseMatchesJsonType(['id' => 'integer']);
            $courseEnrolmentId = json_decode($I->grabResponse())->id;

            $I->sendPOST("/enrolment/$portal->id/$lo->id/$module->id/enrolment?parentEnrolmentId=$courseEnrolmentId");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseMatchesJsonType(['id' => 'integer']);

            $I->waitUntilSuccess(function () use ($I, $courseEnrolmentId) {
                $I->sendPUT("/enrolment/enrolment/$courseEnrolmentId", ['status' => 'completed']);
            }, $I->getHighLatency(), 1000);
        }

        # actor reassign learning
        {
            $now = strtotime('now');
            $payload = [
                'plan_ids' => [$planId],
                'due_date' => $expectedDueDate = strtotime('+45 days'),
            ];
            $I->amBearerAuthenticated($actor->jwt);
            $I->sendPOST('/enrolment/plan/re-assign', $payload);
            $I->seeResponseCodeIs(HttpCode::CREATED);
            $I->seeResponseMatchesJsonType(['id' => 'integer']);
            $reassignId = json_decode($I->grabResponse())[0]->id;
            sleep(2);
        }

        {
            $I->amGoingTo("$actorName going to manage page -> Assigned -> Completed Tab");
            $I->waitUntilSuccess(function () use ($I, $lo, $portal) {
                $I->sendGET("/enrolment/content-learning/$portal->id/$lo->id?activityType=assigned&status=completed");
                $I->seeResponseCodeIs(HttpCode::OK);
                $I->assertEquals(0, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
            }, $I->getHighLatency(), 1000);
        }

        {
            $I->amGoingTo("$actorName going to manage page -> Assigned -> In progress Tab");
            $I->sendGET("/enrolment/content-learning/$portal->id/$lo->id?activityType=assigned&status=in-progress");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->assertEquals(0, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
        }

        {
            $I->amGoingTo("$actorName going to manage page -> Assigned -> Not started Tab");
            $I->sendGET("/enrolment/content-learning/$portal->id/$lo->id?activityType=assigned&status=not-started");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->assertEquals(1, $I->grabDataFromResponseByJsonPath('$data.totalCount')[0]);
            $planIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.legacyId');
            $enrolmentIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.state.legacyId');
            $createdAt = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.createdAt');
            $dueDate = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.dueDate');
            $userIds = $I->grabDataFromResponseByJsonPath('$data.edges[*].node.user.legacyId');
            $I->assertCount(1, $planIds);
            $I->assertEquals($reassignId, $planIds[0]);
            $I->assertEquals($expectedDueDate, $dueDate[0]);
            $I->assertEmpty($enrolmentIds);
            $I->assertEquals($learner->id, $userIds[0]);
        }
    }
}
