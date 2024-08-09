<?php

namespace go1\enrolment\tests\integration\tests\integration;

use IntegrationTester;
use Codeception\Util\HttpCode;
use go1\integrationTest\Model\Enrolment;

class CourseEnrollmentCest
{
    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/resources/docs/enrolment-create.md
     * @see GO1P-25027
     */
    public function enrolFreeCourseSuccessful(IntegrationTester $I)
    {
        $I->amGoingTo('User can successfully enroll into a free course');
        $I->wantToTest('Send a POST /enrolment/portal/parent_LO_Id/course_Id/enrolment request with valid data, then 200 status code is shown');

        $portal       = $I->havePortal('portal_1');
        $course       = $I->haveFixture('course_1');
        $parent_lo_id = 0;

        $I->amBearerAuthenticated($I->haveUser('learner_1')->jwt);
        $I->sendPOST("/enrolment/{$portal->id}/{$parent_lo_id}/{$course->id}/enrolment");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseMatchesJsonType(['id' => 'integer']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-20216
     */
    public function getCourseEnrollmentWithValidData(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment/lo/lo_Id request with valid data, then 200 response code is shown.');

        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_in_progress_1');

        $I->amBearerAuthenticated($enrolment->user->jwt);
        $I->sendGET('/enrolment/lo/'.$enrolment->lo->id);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'id'                  => $enrolment->id,
            'parent_lo_id'        => '0',
            'lo_id'               => $enrolment->lo->id,
            'taken_instance_id'   => $enrolment->user->portal->id,
            'status'              => $enrolment->status,
            'result'              => '0',
            'pass'                => '0',
            'parent_enrolment_id' => '0',
            'user_id' => $enrolment->user->id
        ]);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-20218
     */
    public function getEnrollmentWithInvalidData(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment/lo/lo_Id request with non existent LO Id, then 404 response code is shown.');

        $I->amBearerAuthenticated($I->haveUser('learner_1')->jwt);
        $I->sendGET('/enrolment/lo/1');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['message' => 'Learning object not found']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-20770
     */
    public function viewEnrollmentWithoutJwt(IntegrationTester $I)
    {
        $I->wantToTest('Send GET /enrolment/lo/lo_Id request with invalid JWT, then 403 response code is shown');
        $I->sendGET('/enrolment/lo/1');
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Invalid or missing JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-22724
     */
    public function deleteInvalidLoForEnrolment(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/lo_Id request with invalid LO Id, then 404 response code is shown.');

        $I->amBearerAuthenticated($I->haveUser('learner_1')->jwt);
        $I->sendDELETE('/enrolment/test12349');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }

    /**
     * @see GO1P-24091
     * @param IntegrationTester $I
     */
    public function enrolFailure(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/instance request with invalid instance name/Id, then 400 response is shown.');

        $I->amBearerAuthenticated($I->haveUser('learner_1')->jwt);
        $I->sendPOST('/enrolment/thisportaldoesnotexistjaj/1/2/enrolment/STATUS');
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['message' => 'Enrolment can not be associated with an invalid portal.']);
    }

    /**
     * @param IntegrationTester $I
     *@see GO1P-28639
     */
    public function enrolFreeLinkLiUpdate(IntegrationTester $I)
    {
        $I->amGoingTo('Student can successfully update enrolment status of a LO type Link that is assigned to this student');
        $I->wantToTest('Send a PUT /enrolment/enrolment/enrolment_Id request with valid JWT, then 200 response is shown');

        $user = $I->haveFixture('learner_1');
        $lo   = $I->haveFixture('li_standalone_link_2');

        $I->amBearerAuthenticated($user->jwt);
        $I->sendPOST("/enrolment/{$user->portal->id}/0/{$lo->id}/enrolment");
        $id = json_decode($I->grabResponse())->id;

        $I->sendPUT("/enrolment/enrolment/$id", ['status' => 'completed']);
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        $I->seeResponseEquals('');
    }
}
