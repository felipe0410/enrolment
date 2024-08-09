<?php

use Codeception\Util\HttpCode;

class ManagerSelfViewEditDeleteAssignmentCest
{
    private $manager;
    private $assignmentId;

    public function _before(IntegrationTester $I)
    {
        $this->manager = $I->haveUser('manager_1');
    }

    /**
     * @param IntegrationTester $I
     * @throws Exception
     * @see PSE-398
     */
    public function viewAssignment(IntegrationTester $I)
    {
        $I->wantToTest('Manager send a GET /enrolment/content-learning/portal_Id/course_id?offset=0&limit=20&facet=true request, then 200 status code is shown');

        $course = $I->haveFixture('course_for_manager_self_view_edit_delete_assignment');
        $I->amBearerAuthenticated($I->haveUser('admin_2')->jwt);
        $payload = ["due_date" => strtotime('+5 days'), "status" => -2];
        $this->assignmentId = $I->assignPlan($course->portal->id, $course->id, $this->manager->id, $payload)->id;

        $I->amBearerAuthenticated($this->manager->jwt);
        $I->waitUntilSuccess(function () use ($I, $course) {
            $I->sendGET("/enrolment/content-learning/{$this->manager->portal->id}/{$course->id}?offset=0&limit=20&facet=true");
            $I->seeResponseCodeIs(HttpCode::OK);
            $userEmail = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.user.email');
            $I->assertContains($this->manager->email, $userEmail);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @throws Exception
     * @depends viewAssignment
     * @see PSE-399
     */
    public function editAssignment(IntegrationTester $I)
    {
        $I->wantToTest('Manager send a PUT /enrolment/plan/assignment_Id request with manager Jwt, then 204 status code is shown');

        $I->amBearerAuthenticated($this->manager->jwt);
        $I->sendPUT("/enrolment/plan/{$this->assignmentId}", ['due_date' => strtotime('+3 days')]);
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
    }

    /**
     * @param IntegrationTester $I
     * @throws Exception
     * @depends viewAssignment
     * @see PSE-400
     */
    public function deleteAssignment(IntegrationTester $I)
    {
        $I->wantToTest('Manager send a DELETE /enrolment/plan/assignment_Id request with manager Jwt, then 204 status code is shown');

        $I->amBearerAuthenticated($this->manager->jwt);
        $I->sendDELETE("/enrolment/plan/{$this->assignmentId}");
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
    }
}
