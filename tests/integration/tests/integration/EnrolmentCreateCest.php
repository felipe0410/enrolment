<?php

namespace go1\enrolment\tests\integration\tests\integration;

use Codeception\Util\HttpCode;
use IntegrationTester;
use go1\integrationTest\Model\Enrolment;
use go1\integrationTest\Model\Portal;
use go1\integrationTest\Model\User;

class EnrolmentCreateCest
{
    /** @var User */
    protected $admin;
    /** @var string */
    protected $hostEntityType = 'lo';
    /**
     * @var Portal
     */
    private $portal;
    private $course;
    private $module;
    private $courseEnrolment;
    /** @var User */
    private $learner;

    public function _before(IntegrationTester $I)
    {
        $this->portal = $I->havePortal('portal_1');
        $this->course = $I->haveFixture('course_1');
        $this->module = $I->haveFixture('module_enrol_1');

        /** @var Enrolment $enrolment */
        $this->courseEnrolment = $I->haveFixture('enrolment_due_1');
        $this->learner         = $I->haveFixture('learner_1');
        $this->admin           = $I->haveFixture('admin');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-24765
     */
    public function createEnrolmentWithMembershipAndPolicyFailure(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST enrolment/instance/parentLo_Id/Lo_Id/enrolment/status?membership_Id=membership_Id&policy_Id=PolicyId&parentEnrolmentId=parentEnrolmentId request with invalid data, then 400 response code is shown');

        $I->amBearerAuthenticated($I->haveUser('learner_1')->jwt);
        $I->sendPOST("/enrolment/InvalidPortalId/{$this->courseEnrolment->lo->id}/{$this->module->id}/enrolment/in-progress?membershipId={$this->portal->id}&policyId=InvalidPolicyId&parentEnrolmentId={$this->courseEnrolment->id}");
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['message' => 'Enrolment can not be associated with an invalid portal.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-24766
     */
    public function createEnrolmentWithMembershipAndPolicyForbidden(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST enrolment/instance/parentLo_Id/Lo_Id/enrolment/status?membershipId=membership_Id&policy_Id=Policy_Id&parentEnrolmentId=parentEnrolmentId request with missing jwt, then 403 response code is shown');

        $I->sendPOST("/enrolment/{$this->portal->id}/{$this->courseEnrolment->lo->id}/{$this->module->id}/enrolment/in-progress?membershipId={$this->portal->id}&policyId=InvalidPolicyId&parentEnrolmentId={$this->courseEnrolment->id}");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Missing or invalid JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-28865
     */
    public function createMultipleEnrolmentSuccess(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST enrolment/instance/enrolment request with valid data, then 200 response code is shown');

        $data = [
            "coupon"         => 'test',
            "paymentMethod"  => "stripe",
            "paymentOptions" => [
                "token" => "test_token"
            ],
            "items" => [
                ["loId" => 111, "parentLoId" => 11, "status" => "in-progress", "dueDate" => "2017-08-03T09:30:38+0000"],
                ["loId" => 222, "parentLoId" => 11, "status" => "in-progress"],
                ["loId" => 333, "parentLoId" => 11, "status" => "in-progress", "startDate" => "2018-04-16T15:16:17+0000"]
            ]
        ];

        $I->amBearerAuthenticated($this->admin->jwt, $data);
        $I->sendPOST("/enrolment/{$this->portal->id}/enrolment");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseEquals('[]');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-28866
     */
    public function createMultipleEnrolmentFailure(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST enrolment/instance/enrolment request with invalid data, then 400 response code is shown');

        $data = [
            "items" => [
                ["loId" => 111, "parentLoId" => 11, "status" => "in-progress", "dueDate" => "2017-08-03T09:30:38+0000"],
                ["loId" => 222, "parentLoId" => 11, "status" => "in-progress"],
                ["loId" => 333, "parentLoId" => 11, "status" => "in-progress", "startDate" => "2018-04-16T15:16:17+0000"]
            ]
        ];

        $invalidPortalId = 99999999;

        $I->amBearerAuthenticated($this->admin->jwt);
        $I->sendPOST("/enrolment/{$invalidPortalId}/enrolment", $data);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['message' => 'Enrolment can not be associated with an invalid portal.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-28868
     */
    public function createMultipleEnrolmentForbidden(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST enrolment/instance/enrolment request with missing jwt, then 403 response code is shown');

        $data = [
            "items" => [
                ["loId" => 111, "parentLoId" => 11, "status" => "in-progress", "dueDate" => "2017-08-03T09:30:38+0000"],
                ["loId" => 222, "parentLoId" => 11, "status" => "in-progress"],
                ["loId" => 333, "parentLoId" => 11, "status" => "in-progress", "startDate" => "2018-04-16T15:16:17+0000"]
            ]
        ];
        $I->sendPOST("/enrolment/{$this->portal->id}/enrolment", $data);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Missing or invalid JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-28869
     */
    public function createMultipleEnrolmentViaCreditGatewaySuccess(IntegrationTester $I)
    {
        $I->wantToTest('Send   /enrolment/instance/enrolment with valid info via credit gateway request with valid data, then 200 response code is shown');

        $data = [
            "coupon"         => 'test',
            "paymentMethod"  => "credit",
            "paymentOptions" => [
                "tokens" => [
                    "TOKEN_1" => ["productType" => "lo", "productId" => 111],
                    "TOKEN_2" => ["productType" => "lo", "productId" => 222]
                ]

            ],
            "items"          => [
                ["loId" => 111, "parentLoId" => 11, "status" => "in-progress", "dueDate" => "2017-08-03T09:30:38+0000"],
                ["loId" => 222, "parentLoId" => 11, "status" => "in-progress"]
            ]
        ];
        $I->amBearerAuthenticated($this->admin->jwt, $data);
        $I->sendPOST("/enrolment/{$this->portal->id}/enrolment");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseEquals('[]');
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-28870
     */
    public function createChildLoEnrolmentWithStudentEmailSuccess(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST /enrolment/instance/Parent_LO_Id/LO_Id/enrolment/student_email_status request with valid data, then 200 response code is shown');

        $I->amBearerAuthenticated($this->admin->jwt);
        $I->sendPOST("/enrolment/{$this->portal->id}/0/{$this->course->id}/enrolment/{$this->learner->email}/in-progress");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseMatchesJsonType([
            'id'       => 'integer'
        ]);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-28871
     */
    public function createMultiChildLoEnrolmentWithEmailSuccess(IntegrationTester $I)
    {
        $I->wantToTest('Send a POST enrolment/instance/enrolment/student_email/ with valid info, then 200 response code is shown');

        $data = [
            "coupon"         => 'test',
            "paymentMethod"  => "credit",
            "paymentOptions" => [
                "tokens" => [
                    "TOKEN_1" => ["productType" => "lo", "productId" => 111],
                    "TOKEN_2" => ["productType" => "lo", "productId" => 222]
                ]

            ],
            "items"          => [
                ["loId" => 111, "parentLoId" => 11, "status" => "in-progress", "dueDate" => "2017-08-03T09:30:38+0000"],
                ["loId" => 222, "parentLoId" => 11, "status" => "in-progress"]
            ]
        ];
        $I->amBearerAuthenticated($this->admin->jwt, $data);
        $I->sendPOST("/enrolment/{$this->portal->id}/enrolment/{$this->learner->email}");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseEquals('[]');
    }
}
