<?php

use Codeception\Util\HttpCode;
use go1\integrationTest\Model\Enrolment;

class EnrollmentUpdateCest
{
    public function _before(IntegrationTester $I)
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29545
     */
    public function updateInvalidEnrollmentProperties(IntegrationTester $I)
    {
        $I->wantToTest('Send a PUT /enrolment/enrolment/enrolment_Id/properties?jwt=JWT request with invalid data, then 400 status code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_update_1');
        $I->consumeFixture('enrolment_module_5'); //Just make it unavailable

        $I->amBearerAuthenticated("Wrong JWT");

        $I->sendPUT("/enrolment/enrolment/{$enrolment->id}/properties");
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseEquals('{"message":"Invalid signature."}');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29547
     */
    public function updateEnrollmentPropertiesWithNoPermission(IntegrationTester $I)
    {
        $I->wantToTest('Send a PUT /enrolment/enrolment/enrolment_Id/properties?jwt=JWT request with no permission, then 403 status code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_update_2');
        $I->consumeFixture('enrolment_module_6'); //Just make it unavailable

        $I->amBearerAuthenticated($enrolment->user->jwt);

        $I->sendPUT("/enrolment/enrolment/{$enrolment->id}/properties");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseEquals('{"message":"Only accounts admin can update enrollment\u0027s data"}');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29551
     */
    public function updateEnrollmentPropertiesSuccess(IntegrationTester $I)
    {
        $I->wantToTest('Send a PUT /enrolment/enrolment/enrolment_Id/properties?jwt=JWT request with valid info, then 204 status code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_update_9');

        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $data = [
            'duration'           => 123,
            'custom_certificate' => 'https://www.webmerge.me/merge/165010/5i5cae?&test=1&_cache=6ana5tc19p'
        ];

        $I->sendPUT("/enrolment/enrolment/{$enrolment->id}/properties", $data);
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        $I->sendDELETE("/enrolment/enrolment/{$enrolment->id}?archiveChild=true");
        $I->seeResponseEquals('');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29555
     */
    public function updateEnrollmentWithInvalidInfo(IntegrationTester $I)
    {
        $I->wantToTest('Send a PUT /enrolment/enrolment/enrolment_Id?jwt=JWT request with invalid info, then 400 status code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_update_4');

        $I->amBearerAuthenticated("Wrong JWT");

        $I->sendPUT("/enrolment/enrolment/{$enrolment->id}");
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseEquals('{"message":"Invalid signature."}');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29552
     */
    public function updateEnrollmentWithWithNoPermission(IntegrationTester $I)
    {
        $I->wantToTest('Send a PUT /enrolment/enrolment/enrolment_Id?jwt=JWT request with invalid info, then 400 status code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_update_5');

        $I->sendPUT("/enrolment/enrolment/{$enrolment->id}");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseEquals('{"message":"Invalid or missing JWT."}');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29556
     */
    public function updateEnrollmentWithSuccess(IntegrationTester $I)
    {
        $I->wantToTest('Send a PUT /enrolment/enrolment/enrolment_Id?jwt=JWT request with valid info, then 204 status code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_update_6');

        $data = [
            "startDate"              => "2016-10-17T13:04:33+0700",
            "endDate"                => "2016-10-18T13:04:33+0700",
            "expectedCompletionDate" => "2016-10-18T13:04:33+0700",
            "dueDate"                => "2017-08-03T09:30:38+0000",
            "status"                 => 'completed',
            "result"                 => 45,
            "pass"                   => 0,
            "duration"               => 123,
            "note"                   => "manual mark complete",
            "data"                   => [
                "custom_certificate" => "https=>//www.webmerge.me/merge/165010/5i5cae?&test=1&_cache=6ana5tc19p"
            ]
        ];

        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $I->sendPUT("/enrolment/enrolment/{$enrolment->id}", $data);
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        $I->sendDELETE("/enrolment/enrolment/{$enrolment->id}?archiveChild=true");
        $I->seeResponseEquals('');
    }

    /**
     * @param IntegrationTester $I
     * @see DEVX-595
     */
    public function updateSlimEnrollmentWithSuccess(IntegrationTester $I)
    {
        $I->wantToTest('Send a PATCH /enrollments/enrolment_Id request with valid info, then 200 status code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_update_7');

        $data = [
            "startDate"              => "2016-10-17T13:04:33+07:00",
            "endDate"                => "2016-10-18T13:04:33+07:00",
            "dueDate"                => "2017-08-03T09:30:38+00:00",
            "result"                 => 45,
            "pass"                   => true
        ];
        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendPatch("/enrolment/enrollments/{$enrolment->id}", $data);
        $I->seeResponseCodeIs(HttpCode::OK);
        //Api repo expect these fields
        $I->seeResponseMatchesJsonType([
            'id'                 => 'string',
            'user_account_id'    => 'string',
            'lo_id'              => 'string',
            'created_time'       => 'string',
            'updated_time'       => 'string',
            'status'             => 'string',
            'result'             => 'integer',
            'pass'               => 'boolean',
            'start_date'         => 'string'
        ]);
        $I->sendDELETE("/enrolment/enrolment/{$enrolment->id}?archiveChild=true");
        $I->seeResponseEquals('');
    }

    /**
     * @param IntegrationTester $I
     * @see DEVX-595
     */
    public function updatePatchV3EnrollmentWithSuccess(IntegrationTester $I)
    {
        $I->wantToTest('Send a PATCH /enrollments/enrolment_Id request with valid info for assigned type, then 200 status code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_update_8');
        $admin     = $I->haveUser('admin_2');
        $assignerAccountId = $admin->accounts[0]->id;
        $data = [
            "start_date"              => "2016-10-17T13:04:33+07:00",
            "end_date"                => "2016-10-18T13:04:33+07:00",
            "due_date"                => "2017-08-03T09:30:38+00:00",
            "result"                 => 45,
            "pass"                   => true,
            "enrollment_type"        => 'assigned',
            "assign_date"            => '2016-10-17T12:04:33+07:00',
            "assigner_account_id"    => $assignerAccountId
        ];

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendPatch("/enrolment/enrollments/{$enrolment->id}", $data);
        $I->seeResponseCodeIs(HttpCode::OK);
        //Api repo expect these fields
        $I->seeResponseMatchesJsonType([
            'id'                 => 'string',
            'user_account_id'    => 'string',
            'lo_id'              => 'string',
            'created_time'       => 'string',
            'updated_time'       => 'string',
            'status'             => 'string',
            'result'             => 'integer',
            'pass'               => 'boolean',
            'start_date'         => 'string',
            'enrollment_type'    => 'string',
            'assign_date'        => 'string',
            'assigner_account_id' => 'string',
            'due_date'            => 'string'
        ]);
        $I->sendDELETE("/enrolment/enrolment/{$enrolment->id}?archiveChild=true");
        $I->seeResponseEquals('');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29552
     */
    public function updatePatchV3EnrollmentWithWithNoPermission(IntegrationTester $I)
    {
        $I->wantToTest('Send a PUT /enrolment/enrolment/enrolment_Id?jwt=JWT request with invalid info, then 400 status code is shown');

        $I->sendPatch("/enrolment/enrollments/1235");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseEquals('{"message":"Invalid or missing JWT.","error_code":"enrollment_invalid_jwt"}');
    }
}
