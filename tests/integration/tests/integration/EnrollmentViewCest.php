<?php

use Codeception\Util\HttpCode;
use go1\integrationTest\Model\Enrolment;

class EnrollmentViewCest
{
    /**
     * @param IntegrationTester $I
     * @see GO1P-20214
     */
    public function viewEnrollmentDetails(IntegrationTester $I)
    {
        $I->wantToTest('Send GET /enrolment/enrolment_Id request with valid data, then 200 response code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_due_1');

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendGET('/enrolment/' . $enrolment->id);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();
        $I->seeResponseMatchesJsonType(['id' => 'integer:='.$enrolment->id]);
        $I->seeResponseContainsJson([
            'profile_id'        => $enrolment->user->id,
            'user_id'           => $enrolment->user->id,
            'taken_instance_id' => $enrolment->user->portal->id,
            'lo_id'             => $enrolment->lo->id,
            'status'            => $enrolment->status
        ]);
        //Api repo expect these fields
        $I->seeResponseMatchesJsonType([
            'result'            => 'integer',
            'pass'              => 'integer',
        ]);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-21355
     */
    public function viewEnrollmentDetailsWithMissingJwt(IntegrationTester $I)
    {
        $I->wantToTest('Send GET /enrolment/enrolment_Id request with missing jwt, then 403 response code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_due_1');

        $I->sendGET('/enrolment/' . $enrolment->id);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Missing or invalid JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see DEVX-594
     */
    public function viewSlimEnrollmentDetails(IntegrationTester $I)
    {
        $I->wantToTest('Send GET /enrolment/enrollments/enrolment_Id request with valid data, then 200 response code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_due_1');

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendGET('/enrolment/enrollments/' . $enrolment->id);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();
        $I->seeResponseMatchesJsonType(['id' => 'string:='.$enrolment->id]);
        $I->seeResponseContainsJson([
            'lo_id'             => $enrolment->lo->id,
            'status'            => $enrolment->status
        ]);
        //Api repo expect these fields
        $I->seeResponseMatchesJsonType([
            'enrollment_type'     => 'string',
            'assigner_account_id' => 'string',
            'assign_date'         => 'string',
            'user_account_id'     => 'string',
            'result'              => 'integer',
            'pass'                => 'boolean',
            'id'                  => 'string',
            'created_time'        => 'string',
            'updated_time'        => 'string',
            'due_date'            => 'string',
        ]);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-20214
     */
    public function viewEnrollmentDetailsWithInvalidEnrolmentId(IntegrationTester $I)
    {
        $I->wantToTest('Send GET /enrolment/enrolment_Id request with non existent enrolment_Id, then 404 response code is shown');

        $admin = $I->haveUser('admin');
        $I->amBearerAuthenticated($admin->jwt);
        $I->sendGET('/enrolment/' . PHP_INT_MAX);

        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['message' => 'Enrolment not found.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-20214
     */
    public function viewEnrolmentLoRemoteInvalidPermission(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrolment/lo/instance_Id/LO_type/LO_remote_Id?jwt=JWT with invalid permission, then 403 status code is returned');
        $portal = $I->havePortal('portal_1');
        $course = $I->haveFixture('course_1');
        $I->sendGET('/enrolment/lo/' . $portal->id . '/course/' . $course->remote_id);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Invalid or missing JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-20214
     */
    public function viewEnrolmentLoRemoteInvalidInput(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrolment/lo/instance_Id/LO_type/LO_remote_Id?jwt=JWT with invalid input, then 400 status code is returned');
        $portal = $I->havePortal('portal_1');
        $course = $I->haveFixture('course_1');
        $I->amBearerAuthenticated($portal->getAdminJwt());
        $I->sendGET('/enrolment/lo/' . $portal->id . '/course/' . $course->remote_id . '?courseId=1&portalId=1');
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['message' => 'Missing account.']);
    }

    /**
     * @param IntegrationTester $I
     * @see PRO-1776
     */
    public function getEnrolmentLoWithIncludeLTIRegistrationsParam(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolments/enrolment_Id?includeLTIRegistrations=1 request with a valid Id, then 200 response be shown');

        $enrolment = $I->haveFixture('enrolment_due_1');
        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());

        $I->sendGET('/enrolment/' . $enrolment->id . '?includeLTIRegistrations=1');

        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseMatchesJsonType([
            'id'       => 'integer'
        ]);
    }
}
