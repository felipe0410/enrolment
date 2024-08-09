<?php

use Codeception\Util\HttpCode;
use go1\integrationTest\Model\GroupMembership;

class AssignmentFilterCest
{
    protected $assignedAtFrom;
    protected $assignedAtTo;
    protected $portalID;
    protected $courseID;
    protected $dueDate1;
    protected $dueDate2;
    protected $courseID2;

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see  GO1P-40140
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentByAssignedDateWithAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&activityType=assigned&facet=true&assignedAt[from]=timestamp&assignedAt[to]=timestamp");

        $this->portalID = $I->havePortal("portal_1")->id;
        $learner1 = $I->haveUser("learner_assigned_filter_1");
        $learner2 = $I->haveUser("learner_assigned_filter_2");
        $this->courseID = $I->haveFixture("course_for_assignment_filter_1")->id;
        $this->assignedAtFrom = strtotime("-5 days");
        $this->assignedAtTo = strtotime("+2 days");
        $dueDate1 = strtotime("+20 days");
        $payload1 = ["due_date" => $dueDate1, "status" => -2];
        $payload2 = ["due_date" => $this->assignedAtTo, "status" => -2];
        $I->amBearerAuthenticated($learner1->portal->admin->jwt);
        $I->assignPlan($this->portalID, $this->courseID, $learner1->id, $payload1);
        $I->assignPlan($this->portalID, $this->courseID, $learner2->id, $payload2);

        $params = [
            "offset" => 0,
            "limit" => 20,
            "activityType" => "assigned",
            "facet" => true,
            "assignedAt[from]" => $this->assignedAtFrom,
            "assignedAt[to]" => $this->assignedAtTo,
        ];
        $I->waitUntilSuccess(function () use ($I, $params) {
            $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID}", $params);
            $I->seeResponseCodeIs(HttpCode::OK);
            $this->assertProperties($I);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @depends filterAssignmentByAssignedDateWithAdmin200
     * @throws \Exception
     * @see     GO1P-40141
     * @link    https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentByAssignedDateWithManager200(IntegrationTester $I)
    {
        $I->wantToTest("Manager sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&activityType=assigned&facet=true&assignedAt[from]=timestamp&assignedAt[to]=timestamp");

        $I->amBearerAuthenticated($I->haveUser("manager_2")->jwt);

        // Having results
        $params = [
            "offset" => 0,
            "limit" => 20,
            "activityType" => "assigned",
            "facet" => true,
            "assignedAt[from]" => $this->assignedAtFrom,
            "assignedAt[to]" => $this->assignedAtTo,
        ];
        $I->waitUntilSuccess(function () use ($I, $params) {
            $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID}", $params);
            $I->seeResponseCodeIs(HttpCode::OK);
            $this->assertProperties($I);
        }, $I->getHighLatency(), 1000);

        // Having no results
        $params['assignedAt[from]'] = strtotime('-10 days');
        $params['assignedAt[to]'] = strtotime('-5 days');
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
        $I->assertEquals(0, $totalCount);
    }

    /**
     * @param IntegrationTester $I
     * @depends filterAssignmentByAssignedDateWithAdmin200
     * @throws \Exception
     * @see     GO1P-40142
     * @link    https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentByAssignedDueDateWithAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&activityType=assigned&facet=true&dueAt[from]=timestamp&dueAt[to]=timestamp");

        $I->amBearerAuthenticated($I->haveUser("admin")->jwt);

        // Having results
        $params = [
            "offset" => 0,
            "limit" => 20,
            "activityType" => "assigned",
            "facet" => true,
            "dueAt[from]" => $this->dueDate1,
            "dueAt[to]" => $this->dueDate2,
        ];
        $I->waitUntilSuccess(function () use ($I, $params) {
            $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID}", $params);
            $I->seeResponseCodeIs(HttpCode::OK);
            $this->assertProperties($I);
        }, $I->getHighLatency(), 1000);

        // Having no results
        $params['dueAt[from]'] = strtotime('+31 days');
        $params['dueAt[to]'] = strtotime('+40 days');
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $facetAll = $I->grabDataFromResponseByJsonPath('$.edges');
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
        $I->assertEmpty($facetAll);
        $I->assertEquals(0, $totalCount);
    }

    /**
     * @param IntegrationTester $I
     * @depends filterAssignmentByAssignedDateWithAdmin200
     * @throws \Exception
     * @see     GO1P-40143
     * @link    https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentByAssignerIdWithAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&activityType=assigned&facet=true&assignerIds[]=authorID");

        $admin = $I->haveUser("admin");
        $I->amBearerAuthenticated($admin->jwt);
        $authorId = $admin->id;
        $params = [
            "offset" => 0,
            "limit" => 20,
            "activityType" => "assigned",
            "facet" => true,
            "assignerIds[]" => $authorId
        ];
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        $authors = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.author.legacyId');
        $facetTotal = $I->grabDataFromResponseByJsonPath('$.data.facet.total')[0];
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];

        $I->assertEquals(2, $facetTotal);
        $I->assertEquals(2, $totalCount);

        foreach ($activityTypes as $type) {
            $I->assertEquals('assigned', $type);
        }

        foreach ($authors as $id) {
            $I->assertEquals($authorId, $id);
        }
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see  GO1P-40144
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentByAssignerIdWithManager200(IntegrationTester $I)
    {
        $I->wantToTest("Manger sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&activityType=assigned&facet=true&assignerIds[]=authorID");

        $manager = $I->haveUser("manager_2");
        $learner1 = $I->haveUser("learner_assigned_filter_1");
        $courseId = $I->haveFixture("course_for_assignment_filter_1")->id;
        $I->amBearerAuthenticated($manager->jwt);
        $authorId = $manager->id;

        $dueDate  = strtotime("+20 days");
        $payload1 = ["due_date" => $dueDate, "status" => -2];
        $I->assignPlan($this->portalID, $courseId, $learner1->id, $payload1);

        $params = [
            "offset" => 0,
            "limit" => 20,
            "activityType" => "assigned",
            "facet" => true,
            "assignerIds[]" => $authorId
        ];
        $I->waitUntilSuccess(function () use ($I, $courseId, $authorId, $params) {
            $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$courseId}", $params);
            $I->seeResponseCodeIs(HttpCode::OK);
            $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
            $authors = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.author.legacyId');
            $facetTotal = $I->grabDataFromResponseByJsonPath('$.data.facet.total');
            $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount');
            $I->assertEquals(1, $facetTotal[0]);
            $I->assertEquals('assigned', $activityTypes[0]);
            $I->assertEquals($authorId, $authors[0]);
            $I->assertEquals(1, $totalCount[0]);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    protected function assertProperties(IntegrationTester $I): void
    {
        $activityTypes = $I->grabDataFromResponseByJsonPath('$.data.edges[*].node.activityType');
        $facetTotal = $I->grabDataFromResponseByJsonPath('$.data.facet.total')[0];
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
        $facetNotStarted = $I->grabDataFromResponseByJsonPath('$.data.facet."not-started"')[0];
        $facetInProgress = $I->grabDataFromResponseByJsonPath('$.data.facet."in-progress"')[0];
        $facetCompleted = $I->grabDataFromResponseByJsonPath('$.data.facet.completed')[0];

        foreach ($activityTypes as $type) {
            $I->assertEquals('assigned', $type);
        }
        $I->assertEquals(2, $facetTotal);
        $I->assertEquals(2, $facetNotStarted);
        $I->assertEquals(0, $facetInProgress);
        $I->assertEquals(0, $facetCompleted);
        $I->assertEquals(2, $totalCount);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see  GO1P-40145
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentByCompletedDateWithAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&activityType=assigned&facet=true&endedAt[from]=timestamp&endedAt[to]=timestamp");

        $admin = $I->haveUser("admin");
        $I->amBearerAuthenticated($admin->jwt);
        $courseID = $I->haveFixture("course_for_assignment_filter_3")->id;
        $I->haveFixture("enrolment_completed_filter_3");
        $endedAtFrom = strtotime("-2 day");
        $endedAtTo = strtotime("+2 day");

        // Having results
        $params = [
            "offset" => 0,
            "limit" => 20,
            "facet" => true,
            "endedAt[from]" => $endedAtFrom,
            "endedAt[to]" => $endedAtTo,
        ];
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$courseID}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $facetCompleted = $I->grabDataFromResponseByJsonPath('$.data.facet.completed')[0];
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
        $I->assertEquals(1, $facetCompleted);
        $I->assertEquals(1, $totalCount);

        // Having no results
        $params['endedAt[from]'] = strtotime('-30 days');
        $params['endedAt[to]'] = strtotime('-10 days');
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$courseID}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $edges = $I->grabDataFromResponseByJsonPath('$.data.edges')[0];
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
        $I->assertEmpty($edges);
        $I->assertEquals(0, $totalCount);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see  GO1P-40158
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentBySelfDirectedAndCompletedDateWithAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&activityType=assigned&facet=true&endedAt[from]=timestamp&endedAt[to]=timestamp&activityType=self-directed");

        $admin = $I->haveUser("admin");
        $I->amBearerAuthenticated($admin->jwt);
        $courseID = $I->haveFixture("course_for_assignment_filter_3")->id;
        $I->haveFixture("enrolment_completed_filter_3");
        $endedAtFrom = strtotime("-2 day");
        $endedAtTo = strtotime("+2 days");

        // Having results with "activityType" = "self-directed"
        $params = [
            "offset" => 0,
            "limit" => 20,
            "facet" => true,
            "activityType" => "self-directed",
            "endedAt[from]" => $endedAtFrom,
            "endedAt[to]" => $endedAtTo
        ];
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$courseID}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $facetNotStarted = $I->grabDataFromResponseByJsonPath('$.data.facet."not-started"')[0];
        $facetInProgress = $I->grabDataFromResponseByJsonPath('$.data.facet."in-progress"')[0];
        $facetCompleted = $I->grabDataFromResponseByJsonPath('$.data.facet.completed')[0];
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];

        $I->assertEquals(1, $totalCount);
        $I->assertEquals(0, $facetNotStarted);
        $I->assertEquals(0, $facetInProgress);
        $I->assertEquals(1, $facetCompleted);

        // Having no results with "activityType" = "self-directed"
        $params['endedAt[from]'] = strtotime("-20 days");
        $params['endedAt[to]'] = strtotime("-10 days");
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$courseID}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
        $I->assertEquals(0, $totalCount);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see  GO1P-40159
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentByAssignedAndCompletedDateWithAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&activityType=assigned&facet=true&endedAt[from]=timestamp&endedAt[to]=timestamp&activityType=assigned");

        $this->courseID2 = $I->haveFixture("course_for_assignment_filter_4")->id;
        $EnrolmentNotStartedId = $I->haveFixture('enrolment_completed_filter_6')->id;
        $EnrolmentInProgressId = $I->haveFixture('enrolment_completed_filter_7')->id;
        $EnrolmentCompletedId = $I->haveFixture('enrolment_completed_filter_8')->id;
        $I->amBearerAuthenticated($I->haveFixture('admin')->jwt);

        // Convert self-directed to assigned
        $dueDate = date('Y-m-d\TH:i:sO', strtotime('+30 days'));
        $endDate = date('Y-m-d\TH:i:sO', strtotime('+5 days'));
        $payload = ['dueDate' => $dueDate];
        $I->waitUntilSuccess(function () use ($I, $EnrolmentNotStartedId, $payload) {
            $I->sendPUT("/enrolment/enrolment/{$EnrolmentNotStartedId}", $payload);
            $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        }, $I->getHighLatency(), 1000);
        $I->waitUntilSuccess(function () use ($I, $EnrolmentInProgressId, $payload) {
            $I->sendPUT("/enrolment/enrolment/{$EnrolmentInProgressId}", $payload);
            $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        }, $I->getHighLatency(), 1000);
        $I->waitUntilSuccess(function () use ($I, $EnrolmentCompletedId, $dueDate, $endDate) {
            $I->sendPUT("/enrolment/enrolment/{$EnrolmentCompletedId}", ['dueDate' => $dueDate, 'endDate' => $endDate]);
            $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        }, $I->getHighLatency(), 1000);

        // Having results: Filter learning activities by assigned and completed date
        $endedAtFrom = strtotime("-5 day");
        $endedAtTo = strtotime("+10 days");
        $params = [
            "offset" => 0,
            "limit" => 20,
            "facet" => true,
            "activityType" => "assigned",
            "endedAt[from]" => $endedAtFrom,
            "endedAt[to]" => $endedAtTo
        ];
        $I->waitUntilSuccess(function () use ($I, $params) {
            $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID2}", $params);
            $I->seeResponseCodeIs(HttpCode::OK);
            $facetNotStarted = $I->grabDataFromResponseByJsonPath('$.data.facet."not-started"')[0];
            $facetInProgress = $I->grabDataFromResponseByJsonPath('$.data.facet."in-progress"')[0];
            $facetCompleted = $I->grabDataFromResponseByJsonPath('$.data.facet.completed')[0];
            $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
            $I->assertEquals(1, $totalCount);
            $I->assertEquals(0, $facetNotStarted);
            $I->assertEquals(0, $facetInProgress);
            $I->assertEquals(1, $facetCompleted);
        }, $I->getHighLatency(), 1000);

        // Having no results: Filter learning activities by assigned and completed date
        $params['endedAt[from]'] = strtotime("-20 days");
        $params['endedAt[to]'] = strtotime("-10 days");
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID2}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
        $I->assertEquals(0, $totalCount);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see  GO1P-40166
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentByStartedDateWithAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&facet=true&startedAt[from]=timestamp&startedAt[to]=timestamp");

        $I->amBearerAuthenticated($I->haveFixture('admin')->jwt);

        // Having results with Datetime range filter
        $endedAtFrom = strtotime("-2 day");
        $endedAtTo = strtotime("+2 days");
        $params = [
            "offset" => 0,
            "limit" => 20,
            "facet" => true,
            "startedAt[from]" => $endedAtFrom,
            "startedAt[to]" => $endedAtTo
        ];
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID2}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $facetNotStarted = $I->grabDataFromResponseByJsonPath('$.data.facet."not-started"')[0];
        $facetInProgress = $I->grabDataFromResponseByJsonPath('$.data.facet."in-progress"')[0];
        $facetCompleted = $I->grabDataFromResponseByJsonPath('$.data.facet.completed')[0];
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];

        $I->assertEquals(3, $totalCount);
        $I->assertEquals(1, $facetNotStarted);
        $I->assertEquals(1, $facetInProgress);
        $I->assertEquals(1, $facetCompleted);

        // Having no results with Datetime range filter
        $params['startedAt[from]'] = strtotime("+10 days");
        $params['startedAt[to]'] = strtotime("+30 days");
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID2}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
        $I->assertEquals(0, $totalCount);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see  GO1P-40168
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentByAssignedAndStartedDateWithAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&facet=true&startedAt[from]=timestamp&startedAt[to]=timestamp&activityType=assigned");

        $I->amBearerAuthenticated($I->haveFixture('admin')->jwt);

        // Having results with "activityType" = "assigned"
        $endedAtFrom = strtotime("-2 day");
        $endedAtTo = strtotime("+2 days");
        $params = [
            "offset" => 0,
            "limit" => 20,
            "facet" => true,
            "activityType" => "assigned",
            "startedAt[from]" => $endedAtFrom,
            "startedAt[to]" => $endedAtTo
        ];
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID2}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $facetNotStarted = $I->grabDataFromResponseByJsonPath('$.data.facet."not-started"')[0];
        $facetInProgress = $I->grabDataFromResponseByJsonPath('$.data.facet."in-progress"')[0];
        $facetCompleted = $I->grabDataFromResponseByJsonPath('$.data.facet.completed')[0];
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];

        $I->assertEquals(3, $totalCount);
        $I->assertEquals(1, $facetNotStarted);
        $I->assertEquals(1, $facetInProgress);
        $I->assertEquals(1, $facetCompleted);

        // Having no results with "activityType" = "assigned"
        $params['startedAt[from]'] = strtotime("+10 days");
        $params['startedAt[to]'] = strtotime("+30 days");
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$this->courseID2}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
        $I->assertEquals(0, $totalCount);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see  GO1P-40169
     * @link https://code.go1.com.au/microservices/enrolment/-/blob/master/resources/docs/content-learning.md
     */
    public function filterAssignmentBySelfDirectedAndStartedDateWithAdmin200(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&facet=true&startedAt[from]=timestamp&startedAt[to]=timestamp&activityType=self-directed");

        $I->amBearerAuthenticated($I->haveUser('admin')->jwt);
        $courseID = $I->haveFixture("course_for_assignment_filter_3")->id;
        $endedAtFrom = strtotime("-5 days");
        $endedAtTo = strtotime("+2 days");

        // Having results with "activityType" = "self-directed"
        $params = [
            "offset" => 0,
            "limit" => 20,
            "facet" => true,
            "activityType" => "self-directed",
            "startedAt[from]" => $endedAtFrom,
            "startedAt[to]" => $endedAtTo
        ];
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$courseID}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $facetNotStarted = $I->grabDataFromResponseByJsonPath('$.data.facet."not-started"')[0];
        $facetInProgress = $I->grabDataFromResponseByJsonPath('$.data.facet."in-progress"')[0];
        $facetCompleted  = $I->grabDataFromResponseByJsonPath('$.data.facet.completed')[0];
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];

        $I->assertEquals(3, $totalCount);
        $I->assertEquals(1, $facetNotStarted);
        $I->assertEquals(1, $facetInProgress);
        $I->assertEquals(1, $facetCompleted);

        // Having no results with "activityType" = "self-directed"
        $params['endedAt[from]'] = strtotime("-10 days");
        $params['endedAt[to]'] = strtotime("-5 days");
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$courseID}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
        $I->assertEquals(0, $totalCount);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see PSE-177
     */
    public function filterEnrollmentByDateRangeInADay(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&facet=true&startedAt[from]=timestamp&startedAt[to]=timestamp&activityType=self-directed/assigned request by date range, then 200 status is shown");

        $I->amBearerAuthenticated($I->haveUser('admin')->jwt);
        $courseID = $I->haveFixture("course_1")->id;
        $beginOfDay = strtotime("today");
        $endOfDay = strtotime("tomorrow", $beginOfDay) - 1;

        // Filter enrollment by date range with "activityType" = "self-directed"
        $params = [
            "offset" => 0,
            "limit" => 20,
            "facet" => true,
            "activityType" => "self-directed",
            "startedAt[from]" => $beginOfDay,
            "startedAt[to]" => $endOfDay,
        ];
        $I->waitUntilSuccess(function () use ($I, $courseID, $params) {
            $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$courseID}", $params);
            $I->seeResponseCodeIs(HttpCode::OK);
            $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];
            $I->assertGreaterOrEquals(1, $totalCount);
        }, $I->getHighLatency(), 1000);

        // Filter enrollment by date range with "activityType" = "assigned"
        $params['activityType'] = 'assigned';
        $EnrollmentId = $I->haveFixture('enrolment_completed_filter_8')->id;
        $I->sendPUT("/enrolment/enrolment/{$EnrollmentId}", ['dueDate' => $beginOfDay, 'endDate' => $endOfDay]);
        $I->sendGET("/enrolment/content-learning/{$this->portalID}/{$courseID}", $params);
        $I->seeResponseCodeIs(HttpCode::OK);
        $totalCount = $I->grabDataFromResponseByJsonPath('$.data.totalCount')[0];

        $I->assertGreaterOrEquals(1, $totalCount);
    }

    /**
     * @param IntegrationTester $I
     * @throws \Exception
     * @see MGL-1327
     */
    public function filterAssignmentsByGroupId(IntegrationTester $I)
    {
        $I->wantToTest("Admin sends a GET /enrolment/content-learning/portal_Id/course_Id?offset=0&limit=20&activityType=assigned, then 200 status is shown");

        $I->amBearerAuthenticated($I->haveUser('admin')->jwt);

        $courseId = $I->haveFixture('course_for_assign_via_group')->id;
        /** @var GroupMembership $groupMembership */
        $groupMembership = $I->haveFixture('group_membership_1');
        $I->assignContentToGroup($groupMembership->getGroupId(), [$courseId]);

        // assign other learner to course
        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $learner = $I->haveUser('learner_for_assigned_1');
        $I->assignPlan($groupMembership->getPortalId(), $courseId, $learner->id, ['source_type' => 'group', 'source_id' => $groupMembership->getGroupId(), 'due_date' => strtotime('now +5 days'), 'status' => -2]);

        $params = [
            'offset' => 0,
            'limit' => 20,
            'activityType' => 'assigned',
            'groupId' => $groupMembership->getGroupId(),
            'userIds[]' => $learner->id
        ];

        $I->waitUntilSuccess(function () use ($I, $groupMembership, $courseId, $learner, $params) {
            $I->sendGET("/enrolment/content-learning/{$groupMembership->getPortalId()}/{$courseId}", $params);
            $I->seeResponseCodeIs(HttpCode::OK);
            $res = json_decode($I->grabResponse(), true);
            $I->assertEquals(1, $res['data']['totalCount']);
            $I->assertCount(1, $res['data']['edges']);
            $assignedRecord = $res['data']['edges'][0];
            $I->assertEquals($learner->id, $assignedRecord['node']['user']['legacyId']);
            $I->assertEquals($learner->email, $assignedRecord['node']['user']['email']);
        }, $I->getHighLatency(), 2000);
    }
}
