<?php

use Codeception\Util\HttpCode;
use go1\integrationTest\Model\Enrolment;

class EnrollmentDeleteCest
{
    private $deletedEnrolmentId;

    /**
     * @param IntegrationTester $I
     * @see GO1P-24101
     */
    public function deleteEnrollmentSuccessWithChildren(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enrolment/Id request with children param true, then 204 status code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_deletion_1');
        $I->consumeFixture('enrolment_module'); //Just make it unavailable

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());

        $I->sendDELETE("/enrolment/enrolment/{$enrolment->id}?archiveChild=true");
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        $I->seeResponseEquals('');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-24093
     */
    public function deleteEnrollmentSuccess(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enrolment/Id request with valid data, then 204 status code is shown');
        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_deletion_2');

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendDELETE("/enrolment/enrolment/".$enrolment->id);
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        $this->deletedEnrolmentId = $enrolment->id;
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-24097
     */
    public function deleteEnrollmentForbidden(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enrolment/Id request with invalid JWT, then 403 status code is shown');
        $I->sendDELETE("/enrolment/enrolment/1");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Missing or invalid JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-24102
     */
    public function deleteEnrollmentWithChildrenNoJwtForbidden(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enrolment/Id request with invalid JWT, then 403 status code is shown');
        $I->sendDELETE("/enrolment/enrolment/1?archiveChild=true");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Missing or invalid JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @depends deleteEnrollmentSuccess
     * @see GO1P-24100
     */
    public function deleteEnrollmentNotFound(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enrolment/Id request without valid Id, then 404 status code is shown');

        $portal = $I->havePortal('portal_1');
        // try to delete it again
        $I->amBearerAuthenticated($portal->getAdminJwt());
        $I->sendDELETE("/enrolment/enrolment/".$this->deletedEnrolmentId);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseEquals('{"message":"Enrolment not found."}');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-24094
     */
    public function deleteEnrollmentWithoutLO(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/LO_Id request with LO Id that does not exist, then 404 response is shown');
        $portal = $I->havePortal('portal_1');
        $I->amBearerAuthenticated($portal->getAdminJwt());
        // delete enrolment with archived LO
        $I->sendDELETE("/enrolment/1");
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseEquals('{"message":"Enrolment not found."}');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-24095
     */
    public function deleteEnrollmentWithInvalidJWT(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enrolment/enrolment_Id?archiveChild=archive_children request with an invalid token, then 403 response is shown');

        /** @var Enrolment $enrolment */
        $enrolment   = $I->haveFixture('enrolment_due_1');
        $otherPortal = $I->havePortal('portal_alt');

        // login as a different user
        $I->amBearerAuthenticated($otherPortal->getAdminJwt());
        $I->sendDELETE("/enrolment/enrolment/{$enrolment->id}?archiveChild=1");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseEquals('{"message":"Only portal admin or manager can archive enrolment."}');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29398
     */
    public function deleteEnrollmentWithEnrolmentIdNotFound(IntegrationTester $I)
    {
        $I->wantToTest('Send DELETE enrolment/enrolment/Enrolment_Id?archiveChild=archive_Children with invalid info, then 404 status code is returned');

        $portal = $I->havePortal('portal_1');
        $I->amBearerAuthenticated($portal->getAdminJwt());

        // login as a different user
        $nonExistingId = PHP_INT_MAX;
        $I->sendDELETE("/enrolment/enrolment/$nonExistingId?archiveChild=1");
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseEquals('{"message":"Enrolment not found."}');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29392
     */
    public function deleteEnrollmentByLoId(IntegrationTester $I)
    {
        $I->amGoingTo("Admin can successfully delete an enrolment by Lo Id");
        $I->wantToTest("Send a DELETE enrolment/LO_Id request with valid info, then 200 response is shown'");

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_deletion_3');
        $I->consumeFixture('enrolment_module_3'); //Just make it unavailable

        $I->amBearerAuthenticated($enrolment->user->jwt);

        // delete enrolment with LOs
        $I->sendDELETE("/enrolment/{$enrolment->lo->id}");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseEquals('{}');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29395
     */
    public function deleteEnrollmentByLoIdWithWrongUser(IntegrationTester $I)
    {
        $I->amGoingTo("An Invalid user can not delete enrolment by Lo Id");
        $I->wantToTest("Send a DELETE enrolment/LO_Id request with invalid user, then 404 response is shown'");

        /** @var Enrolment $enrolment */
        $enrolment = $I->consumeFixture('enrolment_for_deletion_4');
        $I->consumeFixture('enrolment_module_4'); //Just make it unavailable

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());

        // delete enrolment with LOs
        $I->sendDELETE("/enrolment/{$enrolment->lo->id}");
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseEquals('{"message":"Enrolment not found."}');
    }
}
