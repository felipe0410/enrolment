<?php

namespace go1\enrolment\tests\integration\tests\integration;

use Codeception\Util\HttpCode;
use IntegrationTester;

class EnrolmentRecalculateCest
{
    /**
     * @param IntegrationTester $I
     * @see GO1P-29559
     */
    public function recalculateWithInvalidInput(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/enrolment/re-calculate/enrolment_Id?jwt=JWT with invalid input, then 400 response code is shown');

        $enrolment = $I->haveFixture('enrolment_due_1');

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendPOST("/enrolment/enrolment/re-calculate/{$enrolment->id}", [
            'membership' => '9999999999'
        ]);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains('The following 1 assertions failed:');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29557
     */
    public function recalculateWithInvalidJwt(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/enrolment/re-calculate/enrolment_Id?jwt=JWT with invalid jwt, then 403 response code is shown');

        $enrolment = $I->haveFixture('enrolment_due_1');
        $I->sendPOST("/enrolment/enrolment/re-calculate/{$enrolment->id}");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Invalid or missing JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29562
     */
    public function recalculateWithInvalidEnrolmentId(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/enrolment/re-calculate/enrolment_Id?jwt=JWT with invalid enrolment id, then 404 response code is shown');

        $enrolment = $I->haveFixture('enrolment_due_1');

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendPOST("/enrolment/enrolment/re-calculate/{$enrolment->id}99999");
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['message' => 'Enrolment not found.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29571
     */
    public function recalculateSuccesful(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/enrolment/re-calculate/enrolment_Id?jwt=JWT with valid info, then 204 status code is shown');

        $enrolment = $I->haveFixture('enrolment_due_1');

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendPOST("/enrolment/enrolment/re-calculate/{$enrolment->id}");
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
    }
}
