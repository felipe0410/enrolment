<?php

use Codeception\Util\HttpCode;
use go1\integrationTest\Model\Enrolment;

class DocumentControllerCest
{
    private $awardTitle = 'Award Via API';
    private $tagAward = ['awardCodeceptionTag'];
    private $image = 'https://res.cloudinary.com/go1vn/image/upload/v1534812906/keo2bgwr0rrgup13svwc.jpg';
    private $pdfFile = "https://s3.ap-southeast-2.amazonaws.com/s3.dev.go1.service/tientag.mygo1.com/apiom/48740/1559796075/1559796075-success-1559617299.pdf";
    private $trackOngoingLearningAward;
    private $learner;
    private $learningRecord;
    private $status = -2;
    private $enrolment;
    private $awardEnrolment;

    /**
     * @param IntegrationTester $I
     */
    public function getDocumentNoAdmin(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment-index/enrolment/enrolment_Id request, then 403 status code is shown');

        /** @var Enrolment $enrolment */
        $this->enrolment = $I->haveFixture('enrolment_due_1');
        $I->sendGET('/enrolment-index/enrolment/'.base64_encode($this->enrolment->id));
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseEquals('{"message":"Internal resource"}');
    }

    /**
     * @param IntegrationTester $I
     */
    public function getDocumentEnrolmentOk(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment-index/enrolment/enrolment_id?jwt=JWT request, then 200 status code is shown');

        /** @var Enrolment $enrolment */
        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $I->sendGET('/enrolment-index/enrolment/'.base64_encode($this->enrolment->id));
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['identifier' => "{$this->enrolment->id}"]);
        $I->seeResponseContainsJson(['mail' => "{$this->enrolment->user->email}"]);
    }

    /**
     * @param IntegrationTester $I
     */
    public function getDocumentAwardOk(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment-index/enrolment/award-id?jwt=JWT request, then 200 status code is shown');

        /** @var Enrolment $enrolment */
        $award2 = $I->haveFixture("award_2");
        $learner2 = $I->haveUser("learner_for_award_enrolment_1");
        $I->amBearerAuthenticated($learner2->jwt);
        $I->sendPOST("/award/enroll/{$award2->id}", ["due_date" => "+2 day"]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $this->awardEnrolment = json_decode($I->grabResponse());

        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $I->sendGET('/enrolment-index/enrolment/'.base64_encode('award:'.$this->awardEnrolment->id));
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['identifier' => "award:{$this->awardEnrolment->id}"]);
        $I->seeResponseContainsJson(['lo_id' => "{$award2->id}"]);
    }

    protected function createTrackOngoingAward(IntegrationTester $I)
    {
        /** @var LearningObject $course */
        $course = $I->haveFixture('course_1');
        $admin  = $I->haveUser("admin");

        $I->sendPOST("/award?jwt={$admin->jwt}", [
            'type' => 'award',
            'title' => $this->awardTitle,
            'tags' => $this->tagAward,
            'quantity' => 0,
            'instance_id' => $course->portal->id,
            'data' => [
                'moderate_external_learning_records' => true,
                'image' => $this->image,
                'label' => '',
                'external_learning_enabled' => true
            ],
            'items' => [
                [
                    'type' => 'lo',
                    'entity_id' => $course->id,
                    'quantity' => 5,
                    'mandatory' => 1,
                    'weight' => 0
                ],
            ],
        ]);

        $I->seeResponseCodeIs(HttpCode::OK);
        $this->trackOngoingLearningAward = json_decode($I->grabResponse());
    }

    protected function buildPayload()
    {
        $datetime = new DateTime();
        $body = [
            "title" => "Award manual item",
            "quantity" => 10,
            "completion_date" => $datetime->format(DATE_ISO8601),
            "certificate" => [
                "name" => "success-1559617299.pdf",
                "size" => "32856",
                "type" => "application/pdf",
                "url" => $this->pdfFile
            ],
            "categories" => [],
            "type" => "online"
        ];

        return $body;
    }

    protected function enrollAward(IntegrationTester $I)
    {
        $this->learner = $I->haveUser("learner_for_award_enrolment_2");
        $I->amBearerAuthenticated($this->learner->jwt);
        $I->sendPOST("/award/enroll/{$this->trackOngoingLearningAward->id}", ["due_date" => "+2 day"]);
        $I->seeResponseCodeIs(HttpCode::OK);
    }

    /**
     * @param IntegrationTester $I
     */
    public function getDocumentAwardManualOk(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment-index/enrolment/award-manual-record-id?jwt=JWT request, then 200 status code is shown');

        /** @var Enrolment $enrolment */
        $this->createTrackOngoingAward($I);
        $this->enrollAward($I);
        $I->sendPOST("/award/{$this->trackOngoingLearningAward->id}/item/manual", $this->buildPayload());
        $I->seeResponseCodeIs(HttpCode::OK);
        $this->learningRecord = json_decode($I->grabResponse());

        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $I->sendGET('/enrolment-index/enrolment/'.base64_encode('award:manual-record:'.$this->learningRecord->id));
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['identifier' => "award:manual-record:{$this->learningRecord->id}"]);
        $I->seeResponseContainsJson(['url' => "{$this->pdfFile}"]);
    }

    protected function createEnrollmentAssign(IntegrationTester $I): int
    {
        $admin = $I->haveUser("admin");
        $I->amBearerAuthenticated($admin->jwt);
        $payload = ["due_date" => strtotime("now"), "status" => $this->status];

        $manager = $I->haveUser("manager_1");
        $loId = $I->haveFixture("course_for_assign_plan");
        $learner  = $I->haveUser("learner_11");

        return $I->assignPlan($admin->portal->id, $loId->id, $learner->id, $payload)->id;
    }

    /**
     * @param IntegrationTester $I
     */
    public function getDocumentPlanAssignedOk(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment-index/enrolment/plan-assigned-id?jwt=JWT request, then 200 status code is shown');

        /** @var Enrolment $enrolment */
        $assignID = $this->createEnrollmentAssign($I);

        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $I->sendGET('/enrolment-index/enrolment/'.base64_encode('plan-assigned:'.$assignID));
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['identifier' => "plan-assigned:{$assignID}"]);
        $I->seeResponseContainsJson(['status' => $this->status]);
    }

    /**
     * @param IntegrationTester $I
     */
    public function getDocumentManualRecordOk(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment-index/enrolment/manual-record-id?jwt=JWT request, then 200 status code is shown');

        /** @var Enrolment $enrolment */
        $portal = $I->havePortal('portal_1');
        $enrolment = $I->haveFixture('enrolment_for_manualrecord');
        $user = $I->haveUser('learner_1');
        $course = $I->haveFixture('marketplace_course_1');
        $portalName = $portal->domain;
        $body = [
            "data" => [
                'description' => 'I studied this at home. Please verify, thanks!'
            ]
        ];

        $I->amBearerAuthenticated($portal->getAdminJwt());
        $I->sendPOST("/enrolment/manual-record/{$portalName}/lo/{$course->id}", $body);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $response = json_decode($I->grabResponse());
        $recordId = $response->id;

        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $I->sendGET('/enrolment-index/enrolment/'.base64_encode('manual-record:'.$recordId));
        $response = json_decode($I->grabResponse());
        $I->seeResponseCodeIs(HttpCode::OK);

        $I->seeResponseContainsJson(['identifier' => "manual-record:{$recordId}"]);
        $I->seeResponseContainsJson(['lo_id' => $course->id]);
    }

    /**
     * @param IntegrationTester $I
    * @depends getDocumentEnrolmentOk
    * @depends getDocumentAwardOk
     */
    public function getDocumentBulk(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment-index/enrolment?ids=id1,id2,id3,...&jwt=JWT request, then 200 status code is shown');

        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $I->sendGET('/enrolment-index/enrolment?ids='.base64_encode($this->enrolment->id).','.base64_encode('award:'.$this->awardEnrolment->id).','.base64_encode('invalidid'));
        $I->seeResponseCodeIs(HttpCode::OK);
        $ret = json_decode($I->grabResponse());

        $I->assertEquals(2, sizeof($ret->data));
        $I->assertEquals(1, sizeof($ret->errors));
        $I->seeResponseContainsJson(['identifier' => "{$this->enrolment->id}"]);
        $I->seeResponseContainsJson(['identifier' => "award:{$this->awardEnrolment->id}"]);
    }
}
