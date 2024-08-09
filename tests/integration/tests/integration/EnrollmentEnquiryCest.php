<?php

namespace go1\enrolment\tests\integration\tests\integration;

use Codeception\Util\HttpCode;
use IntegrationTester;
use go1\integrationTest\Model\LearningObject;

class EnrollmentEnquiryCest
{
    /**
     * @param IntegrationTester $I
     * @see GO1P-22733
     */
    public function deleteEnquiryWithoutJwt(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enquiry/lo_Id/student/email request without JWT, then 403 status code is shown');
        $learner = $I->haveUser('learner_1');

        $I->sendDELETE('/enrolment/enquiry/1/student/' . $learner->email);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Missing or invalid JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-22793
     */
    public function deleteEnquiryWithInvalidLo(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enquiry/lo_Id/student/email request with invalid LO, then 400 status code is shown');
        $learner = $I->haveUser('learner_1');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());
        $I->sendDELETE('/enrolment/enquiry/1/student/' . $learner->email);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseEquals('{"message":"Invalid learning object."}');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-22734
     */
    public function postAdminEnquiryWithoutJWT(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/admin/enquiry/lo_Id/email request without JWT, then 403 status code is shown');
        $learner = $I->haveUser('learner_1');
        $I->sendPOST('/enrolment/admin/enquiry/1/' . $learner->email, ["status" => 1, "instance" => 1]);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Missing or invalid JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-22732
     */
    public function postEnquiryWithInvalidLo(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/enquiry/lo_Id/email request with invalid LO, then 400 status code is shown');

        $learner = $I->haveUser('learner_1');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());
        $I->sendPOST('/enrolment/enquiry/1/' . $learner->email, $data = [
            'enquireFirstName' => 'Test First Name',
            'enquireLastName'  => 'Test Last Name',
            'enquirePhone'     => '0400 000 000',
            'enquireMessage'   => 'Internal testing, please ignore'
        ]);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains('Invalid enquiry mail.');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-23777
     */
    public function postEnquiryWithoutJwt(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/enquiry/lo_Id/email request without JWT, then 403 status code is shown');

        $learner = $I->haveUser('learner_1');
        $I->sendPOST('/enrolment/enquiry/1/' . $learner->email, $data = [
            'enquireFirstName' => 'First Name',
            'enquireLastName'  => 'Last Name',
            'enquirePhone'     => '0400 000 500',
            'enquireMessage'   => 'Internal testing, please ignore'
        ]);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Missing or invalid JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-23778
     */
    public function postEnquiryWithNoData(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/enquiry/lo_Id/email request with no data, then 400 status code is shown');

        $learner = $I->haveUser('learner_1');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());
        $I->sendPOST('/enrolment/enquiry/1/' . $learner->email, $data = []);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains("The following 3 assertions failed");
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-23779
     */
    public function postEnquiryWithMissingParameters(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/enquiry/lo_Id/email request with missing parameters, then 400 status code is shown');

        $learner = $I->haveUser('learner_1');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());
        $I->sendPOST('/enrolment/enquiry/1/' . $learner->email, $data = [
            'enquireFirstName' => '',
            'enquireLastName'  => '',
            'enquirePhone'     => '',
            'enquireMessage'   => ''
        ]);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['message' => 'Invalid enquiry mail.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-22776
     */
    public function postEnquiryValid(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/enquiry/lo_Id/email request with valid LO, then 200 status code is shown');
        $learner = $I->haveUser('learner_without_enrolments');

        /** @var LearningObject $course */
        $course = $I->haveFixture('course_enquiry');
        $I->amBearerAuthenticated($learner->jwt);

        $I->sendPOST("/enrolment/enquiry/{$course->id}/{$learner->email}", $data = [
            'enquireFirstName' => 'Test First Name',
            'enquireLastName'  => 'Test Last Name',
            'enquirePhone'     => '0400 000 000',
            'enquireMessage'   => 'Internal testing, please ignore'
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseJsonMatchesJsonPath('$.id');
    }

    /**
     * @param IntegrationTester $I
     * @depends postEnquiryValid
     * @see GO1P-24119
     */
    public function getEnquiry(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment/enquiry/lo_Id/email request with valid LO, then 200 status code is shown');

        /** @var LearningObject $course */
        $course = $I->haveFixture('course_enquiry');

        $learner = $I->haveUser('learner_without_enrolments');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());

        // do the get that we are testing
        $I->waitUntilSuccess(function () use ($I, $course, $learner) {
            $I->sendGET("/enrolment/enquiry/{$course->id}/{$learner->email}");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseMatchesJsonType([
                'source_id' => "integer:=".$course->id
            ]);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @depends postEnquiryValid
     * @see GO1P-24129
     */
    public function deleteEnquiryWithInvalidEnquiry(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enquiry/LO_Id/email?jwt=JWT with an invalid enquiry, then 400 response code is shown');

        /** @var LearningObject $course */
        $course = $I->haveFixture('course_enquiry');

        $learner = $I->haveUser('learner_without_enrolments');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());

        // do the get that we are testing
        $I->sendGET("/enrolment/enquiry/{$course->id}/{$learner->email}");
        $I->seeResponseCodeIs(HttpCode::OK);

        $I->sendDELETE("/enrolment/enquiry/abc");
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }

    /**
     * @param IntegrationTester $I
     * @depends postEnquiryValid
     * @see GO1P-24134
     */
    public function deleteEnquiryWithInvalidIdType(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enquiry/LO_Id/email?jwt=JWT with an invalid Id type, then 400 response code is shown');

        $learner = $I->haveUser('learner_without_enrolments');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());

        $I->sendDELETE("/enrolment/enquiry/invalid_type");
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }

    /**
     * @param IntegrationTester $I
     * @depends postEnquiryValid
     * @see GO1P-24139
     */
    public function deleteEnquiryWithInvalidLOID(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enquiry/LO_Id/email?jwt=JWT with a invalid/Incorrect LO Id, then 403 response code is shown');

        $learner = $I->haveUser('learner_without_enrolments');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());

        $I->sendDELETE("/enrolment/enquiry/1");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseEquals('{"message":"Only portal\u0027s admin or student\u0027s manager can delete enquiry request"}');
    }

    /**
     * @param IntegrationTester $I
     * @depends postEnquiryValid
     * @see GO1P-24140
     */
    public function deleteEnquiryWithInvalidPortal(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment/enquiry/LO_Id/email?jwt=JWT with portal that does not exist, then 400 response is shown');

        /** @var LearningObject $course */
        $course = $I->haveFixture('course_enquiry');

        $learner = $I->haveUser('learner_without_enrolments');
        $I->amBearerAuthenticated('Invalid_Jwt');

        $I->sendDELETE("/enrolment/enquiry/{$course->id}/{$learner->email}");
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseEquals('{"message":"Invalid signature."}');
    }

    /**
     * @param IntegrationTester $I
     * @depends postEnquiryValid
     * @see GO1P-24127
     */
    public function deleteEnquiryWithLearnerUser(IntegrationTester $I)
    {
        $I->wantToTest('Send a DELETE /enrolment//enquiry/LO_Id/email?jwt=JWT with a user who is not a portal manager or student manager, then 403 response is shown');

        /** @var LearningObject $course */
        $course = $I->haveFixture('course_enquiry');

        $learner = $I->haveUser('learner_without_enrolments');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());
        $I->sendDELETE("/enrolment/enquiry/{$course->id}");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseEquals('{"message":"Only portal\u0027s admin or student\u0027s manager can delete enquiry request"}');
    }

    /**
     * @see GO1P-24120
     * @param IntegrationTester $I
     */
    public function getEnquiryWithInvalidLO(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment/enquiry/lo_Id/email request with an invalid lO, then 404 status code is shown');

        $learner = $I->haveUser('learner_without_enrolments');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());

        $I->sendGET("/enrolment/enquiry/1/".$learner->email);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseEquals('[]');
    }

    /**
     * @see GO1P-22775
     * @param IntegrationTester $I
     */
    public function getEnquiryWithInvalidEmail(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment/enquiry/lo_Id/email request with an invalid lo, then 404 status code is shown');

        /** @var LearningObject $course */
        $course = $I->haveFixture('course_enquiry');

        $learner = $I->haveUser('learner_without_enrolments');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());

        $I->sendGET("/enrolment/enquiry/{$course->id}/notexisting@example.com");
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['message' => 'Invalid enquiry mail.']);
    }

    /**
     * @see GO1P-24121
     * @param IntegrationTester $I
     */
    public function getEnquiryWithNoValidEmailAndLo(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET enrollment/enquiry/LO_ID/email without a valid email and LO Id, then 405 response be shown');

        $course = $I->haveFixture('course_enquiry');
        $learner = $I->haveUser('learner_without_enrolments');
        $I->amBearerAuthenticated($learner->portal->getAdminJwt());

        $I->sendGET('/enrolment/enquiry/' . $course->id);
        $I->seeResponseCodeIs(HttpCode::METHOD_NOT_ALLOWED);
    }
}
