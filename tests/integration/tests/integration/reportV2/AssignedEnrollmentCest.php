<?php

use Codeception\Util\HttpCode;

class AssignedEnrollmentCest
{
    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40079
     */
    public function getAssignedEnrollmentWithInCompletedStatusesByManager200(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrolment/content-learning?activityType=Assigned&status=completed&facet=true by manager');

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_for_assigned_2');
        $loId    = $I->haveFixture('course_for_assigned_enrollment_2')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($I->haveUser('manager_1')->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=assigned&facet=true&status=completed");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('assigned', $type);
        }

        $this->commonAssertions($I);

        $I->dontSeeResponseContainsJson(['status' => 'not-started']);
        $I->dontSeeResponseContainsJson(['status' => 'in-progress']);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40078
     */
    public function getSelfDirectedEnrollmentWithInCompletedStatusesByManager200(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrolment/content-learning?activityType=self-directed&status=completed&facet=true by manager');

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_self-directed');
        $loId    = $I->haveFixture('course_for_enrollment_self-directed')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($I->haveUser('manager_1')->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=self-directed&facet=true&status=completed");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('self-directed', $type);
        }

        $this->commonAssertions($I);

        $I->dontSeeResponseContainsJson(['status' => 'not-started']);
        $I->dontSeeResponseContainsJson(['status' => 'in-progress']);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40077
     */
    public function getSelfDirectedEnrollmentWithInProgressStatusesByManager200(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrolment/content-learning?activityType=self-directed&status=in-progress&facet=true by manager');

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_self-directed');
        $loId    = $I->haveFixture('course_for_enrollment_self-directed')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($I->haveUser('manager_1')->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=self-directed&facet=true&status=in-progress");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('self-directed', $type);
        }

        $this->commonAssertions($I);

        $I->dontSeeResponseContainsJson(['status' => 'not-started']);
        $I->dontSeeResponseContainsJson(['status' => 'completed']);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40076
     */
    public function getAssignedEnrollmentWithInProgressStatusesByAdmin200(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrolment/content-learning?activityType=assigned&status=in-progress&facet=true by admin');

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_self-directed');
        $loId    = $I->haveFixture('course_for_enrollment_self-directed')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($I->haveUser('manager_1')->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=self-directed&facet=true&status=in-progress");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('self-directed', $type);
        }

        $this->commonAssertions($I);

        $I->dontSeeResponseContainsJson(['status' => 'not-started']);
        $I->dontSeeResponseContainsJson(['status' => 'completed']);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40066
     */
    public function getAssignedEnrollmentWithInProgressStatusesByManager200(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrolment/content-learning?activityType=assigned&status=in-progress&facet=true by manager');

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_for_assigned_2');
        $loId    = $I->haveFixture('course_for_assigned_enrollment_2')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($I->haveUser('manager_1')->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=assigned&facet=true&status=in-progress");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('assigned', $type);
        }

        $this->commonAssertions($I);

        $I->dontSeeResponseContainsJson(['status' => 'not-started']);
        $I->dontSeeResponseContainsJson(['status' => 'completed']);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40065
     */
    public function getAssignedEnrollmentWithCompletedStatusesByAdmin200(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrolment/content-learning?activityType=assigned&status=completed&facet=true by admin');

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_for_assigned_1');
        $loId    = $I->haveFixture('course_for_assigned_enrollment_1')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($portal->admin->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=assigned&facet=true&status=completed");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('assigned', $type);
        }

        $this->commonAssertions($I);
        $I->dontSeeResponseContainsJson(['status' => 'not-started']);
        $I->dontSeeResponseContainsJson(['status' => 'in-progress']);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40064
     */
    public function getSelfDirectedEnrollmentWithCompletedStatusesByAdmin200(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrolment/content-learning?activityType=self-directed&status=completed&facet=true by admin');

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_self-directed');
        $loId    = $I->haveFixture('course_for_enrollment_self-directed')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($portal->admin->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=self-directed&facet=true&status=not-started");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('self-directed', $type);
        }

        $this->commonAssertions($I);

        $I->dontSeeResponseContainsJson(['status' => 'not-started']);
        $I->dontSeeResponseContainsJson(['status' => 'in-progress']);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40063
     */
    public function getSelfDirectedEnrollmentWithInProgressStatusesByAdmin200(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrolment/content-learning?activityType=self-directed&status=in-progress&facet=true by admin');

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_self-directed');
        $loId    = $I->haveFixture('course_for_enrollment_self-directed')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($portal->admin->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=self-directed&facet=true&status=not-started");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('self-directed', $type);
        }

        $this->commonAssertions($I);

        $I->dontSeeResponseContainsJson(['status' => 'not-started']);
        $I->dontSeeResponseContainsJson(['status' => 'completed']);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40043
     */
    public function getSelfDirectedEnrollmentByManager200(IntegrationTester $I)
    {
        $I->wantToTest("Send a GET  /enrolment/content-learning/portal_Id/lo_Id?offset=0&limit=20&activityType=self-directed&facet=true&status=not-started , then 200 status is shown");

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_self-directed');
        $loId    = $I->haveFixture('course_for_enrollment_self-directed')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($I->haveUser('manager_1')->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=self-directed&facet=true&status=not-started");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('self-directed', $type);
        }

        $this->commonAssertions($I);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40033
     */
    public function getSelfDirectedEnrollmentByAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Send a GET  /enrolment/content-learning/portal_Id/lo_Id?offset=0&limit=20&activityType=self-directed&facet=true&status=not-started , then 200 status is shown");

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_self-directed');
        $loId    = $I->haveFixture('course_for_enrollment_self-directed')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($portal->admin->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=self-directed&facet=true&status=not-started");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('self-directed', $type);
        }

        $this->commonAssertions($I);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40008
     */
    public function getAssignedEnrollmentByAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Send a GET /enrolment/content-learning/portal_Id/lo_Id?offset=0&limit=20&activityType=assigned&facet=true&status=not-started, then 200 status is shown");

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_for_assigned_1');
        $loId    = $I->haveFixture('course_for_assigned_enrollment_1')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($portal->admin->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=assigned&facet=true&status=not-started");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('assigned', $type);
        }

        $this->commonAssertions($I);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see GO1P-40009
     */
    public function getAssignedEnrollmentByManager200(IntegrationTester $I)
    {
        $I->wantToTest("Send a GET /enrolment/content-learning/portal_Id/lo_Id?offset=0&limit=20&activityType=assigned&facet=true&status=not-started by manager, then 200 status is shown");

        $portal  = $I->havePortal('portal_1');
        $learner = $I->haveUser('learner_for_assigned_2');
        $loId    = $I->haveFixture('course_for_assigned_enrollment_2')->id;
        $payload = ["due_date" => strtotime("now +5 days"), "status" => -2];

        $I->amBearerAuthenticated($I->haveUser('manager_1')->jwt);
        $I->assignPlan($portal->id, $loId, $learner->id, $payload);
        $I->sendGET("/enrolment/content-learning/{$portal->id}/{$loId}?offset=0&limit=20&activityType=assigned&facet=true&status=not-started");
        $I->seeResponseCodeIs(HttpCode::OK);

        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        foreach ($activityTypes as $type) {
            $I->assertEquals('assigned', $type);
        }

        $this->commonAssertions($I);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     */
    protected function commonAssertions(IntegrationTester $I): void
    {
        $legacyIds  = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.legacyId');
        $dueDates   = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.dueDate');
        $createdAts = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.createdAt');
        $updatedAts = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.updatedAt');
        foreach ($legacyIds as $legacyId) {
            $I->assertNotNull($legacyId);
        }
        foreach ($dueDates as $dueDate) {
            $I->assertNotNull($dueDate);
        }
        foreach ($createdAts as $createdAt) {
            $I->assertNotNull($createdAt);
        }
        foreach ($updatedAts as $updatedAt) {
            $I->assertNotNull($updatedAt);
        }

        $I->seeResponseMatchesJsonType([
            'total'       => 'integer',
            'not-started' => 'integer',
            'in-progress' => 'integer',
            'completed'   => 'integer',
        ], '$.data.facet');
    }
}
