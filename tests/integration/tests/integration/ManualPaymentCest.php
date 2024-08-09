<?php

use Codeception\Util\HttpCode;

class ManualPaymentCest
{
    public function _before(IntegrationTester $I)
    {
        $I->amBearerAuthenticated($I->haveUser('admin')->jwt);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-23920
     */
    public function acceptManualEnrolmentWithInvalidEnrolmentId(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/manual-payment/accept/invalid_enrolment_Id request with invalid data, then 400 status code is shown');

        $I->sendPOST('/enrolment/enrolment/manual-payment/accept/1');
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains('This learning object is not configured for manual payment.');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-23926
     */
    public function rejectManualEnrolmentWithInvalidEnrolmentId(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/manual-payment/reject/invalid_enrolment_Id request with invalid data, then 400 status code is shown');

        $I->sendPOST('/enrolment/enrolment/manual-payment/reject/1');
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson([
            'error' => [
                'path'    => 'enrolment',
                'message' => 'Invalid enrolment.',
            ],
        ]);
    }
}
