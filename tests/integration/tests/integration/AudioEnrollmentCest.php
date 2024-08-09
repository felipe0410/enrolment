<?php

use Codeception\Util\HttpCode;
use go1\integrationTest\Model\Portal;
use go1\integrationTest\Model\User;

class AudioEnrollmentCest
{
    /** @var User */
    protected $admin;

    /**
     * @var Portal
     */
    private $portal;
    private $audioLi;

    /** @var User */
    private $learner;

    public function _before(IntegrationTester $I)
    {
        $this->portal  = $I->havePortal('portal_1');
        $this->audioLi = $I->haveFixture('li_standalone_audio');

        $this->learner = $I->haveFixture('learner_1');
        $this->admin   = $I->haveFixture('admin');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-32875
     */
    public function createEnrolmentForSingleAudioLi(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/instance/parent_Lo_Id/Lo_Id/enrolment/status with correct audio_LI_Id with admin Jwt, then 200 response is shown');

        $I->amBearerAuthenticated($this->admin->jwt);
        $I->sendPOST("/enrolment/{$this->portal->id}/0/{$this->audioLi->id}/enrolment/{$this->learner->email}/in-progress");
        $I->seeResponseCodeIs(HttpCode::OK);

        $I->seeResponseMatchesJsonType([
            'id' => 'integer'
        ]);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-33303
     */
    public function anonymousCanNotEnroll(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/instance/parent_lo_Id/lo_id/enrolment/status with incorrect audio_LI_Id request without JWT, then 403 status code is shown');

        $I->sendPOST("/enrolment/{$this->portal->id}/0/{$this->audioLi->id}/enrolment/{$this->learner->email}/in-progress");

        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => "Missing or invalid JWT."]);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-33302
     */
    public function createEnrolmentWithMembershipAndPolicyFailure(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/instance/parent_lo_Id/lo_Id/enrolment/status with incorrect audio_LI_Id request with learner Jwt, then 400 status code is shown');

        $I->amBearerAuthenticated($I->haveUser('learner_1')->jwt);
        $I->sendPOST("/enrolment/InvalidPortalId/0/{$this->audioLi->id}/enrolment/{$this->learner->email}/in-progress");

        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['message' => "Enrolment can not be associated with an invalid portal."]);
    }
}
