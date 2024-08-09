<?php

use Codeception\Util\HttpCode;

class ReuseEnrollmentCest
{
    public function _before(IntegrationTester $I)
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29483
     */
    public function reuseEnrolmentInvalidInput(IntegrationTester $I)
    {
        $I->wantToTest('Send POST /enrolment/Portal_Name_Or_Id/reuse-enrolment&jwt=JWT request with invalid input, then 400 response code is shown');

        $portal = $I->havePortal('portal_1');
        $I->amBearerAuthenticated($I->haveUser('learner_1')->jwt);
        $I->sendPOST("/enrolment/{$portal->id}/reuse-enrolment", []);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains('The following 2 assertions failed');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29483
     */
    public function reuseEnrolmentPermissionDenied(IntegrationTester $I)
    {
        $I->wantToTest('Send POST /enrolment/Portal_Name_Or_Id/reuse-enrolment&jwt=JWT request with invalid jwt, then 403 response code is shown');

        $portal = $I->havePortal('portal_1');
        $I->sendPOST("/enrolment/{$portal->id}/reuse-enrolment", [
            'parentEnrolmentId' => 0,
            'reuseEnrolmentId'  => 0
        ]);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContains('Missing or invalid JWT.');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-29493
     */
    public function reuseEnrolmentValidInput(IntegrationTester $I)
    {
        $I->wantToTest('Send POST /enrolment/Portal_Name_Or_Id/reuse-enrolment&jwt=JWT with valid input, then 200 response code is shown');

        $course   = $I->haveFixture('course_allow_reuse_enrolment');
        $admin    = $I->haveFixture('admin');
        $learner  = $I->haveFixture('learner_1');
        $portal   = $I->haveFixture('portal_1');
        $moduleId = $I->haveFixture('module_allow_reuse_enrolment')->id;
        $I->amBearerAuthenticated($learner->jwt);

        $I->sendPOST("/enrolment/{$portal->id}/0/{$course->id}/enrolment/in-progress");
        $courseEnrolmentId = json_decode($I->grabResponse())->id;

        $I->sendPOST("/enrolment/{$portal->id}/{$course->id}/{$moduleId}/enrolment/in-progress?parentEnrolmentId={$courseEnrolmentId}");
        $moduleEnrolmentId = json_decode($I->grabResponse())->id;

        $I->amBearerAuthenticated($admin->jwt);
        $I->sendPUT("/enrolment/enrolment/{$courseEnrolmentId}", [
            'status' => 'completed',
            'result' => 100,
            'pass'   => 1
        ]);

        $I->sendPUT("/enrolment/enrolment/{$moduleEnrolmentId}", [
            'status' => 'completed',
            'result' => 100,
            'pass'   => 1
        ]);

        $I->amBearerAuthenticated($learner->jwt);
        $I->sendPOST("/enrolment/{$portal->id}/reuse-enrolment", [
            'parentEnrolmentId' => (int)$courseEnrolmentId,
            'reuseEnrolmentId'  => (int)$moduleEnrolmentId,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
    }
}
