<?php

use Codeception\Util\HttpCode;
use go1\integrationTest\Model\Enrolment;
use go1\integrationTest\Model\Policy;

class EnrollmentRevisionCest
{
    private $enrolmentRevisionId;

    public function _before(IntegrationTester $I)
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    public function createEnrollmentRevision(IntegrationTester $I)
    {
        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed');

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendPUT('/enrolment/enrolment/' . $enrolment->id, ['note' => 'hello']);
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        $I->seeResponseEquals('');
    }

    /**
     * @param IntegrationTester $I
     * @depends createEnrollmentRevision
     */
    public function getEnrollmentRevisionId(IntegrationTester $I)
    {
        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed');

        // Intermittent Replication delay
        sleep(1);

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendGET("/enrolment/lo/{$enrolment->lo->id}/history/{$enrolment->user->id}");
        $I->seeResponseCodeIs(HttpCode::OK);
        $this->enrolmentRevisionId = $I->grabDataFromResponseByJsonPath('$[0].id')[0];
    }

    /**
     * @param IntegrationTester $I
     * @depends createEnrollmentRevision
     * @see GO1P-29402
     */
    public function getEnrollmentRevisionIdInvalidLoId(IntegrationTester $I)
    {
        $I->wantToTest('Send GET enrolment/lo/LO_ID/history/user_Id?jwt=JWT with invalid LoId, then 404 status code is returned');
        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed');

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendGET("/enrolment/lo/11111111/history/{$enrolment->user->id}");
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseEquals('{"message":"Learning object not found."}');
    }

    /**
     * @param IntegrationTester $I
     * @depends createEnrollmentRevision
     * @see GO1P-29403
     */
    public function getEnrollmentRevisionIdInvalidPermission(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment/lo/history/enrolment_Id request with invalid permission, then 403 response code is shown');
        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed');

        $I->sendGET("/enrolment/lo/11111111/history/{$enrolment->user->id}");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseEquals('{"message":"Missing or invalid JWT."}');
    }

    /**
     * @param IntegrationTester $I
     * @depends getEnrollmentRevisionId
     * @see GO1P-20222
     */
    public function getEnrollmentRevisionWithValidData(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment/revision/enrolment_revision_Id request with valid data, then 200 response code is shown');

        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed');
        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());

        $I->sendGET("/enrolment/revision/{$this->enrolmentRevisionId}?tree=1");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['id' => "$this->enrolmentRevisionId", 'enrolment_id' => $enrolment->id, 'status' => 'completed']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-20232
     */
    public function getEnrollmentRevisionWithoutJWT(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment/revision/enrolment_revision_Id request with valid enrolment_revision_Id & missing jwt, then 403 response code is shown.');

        $I->sendGET('/enrolment/revision/1?tree=1');
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Missing or invalid JWT.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-20230
     */
    public function getEnrollmentRevisionWithInvalidEnrollmentId(IntegrationTester $I)
    {
        $I->wantToTest('Send a GET /enrolment/revision/enrolment_revision_Id request with invalid enrolment_revision_Id, then 404 response code is shown.');

        /** @var Enrolment $enrolment */
        $portal = $I->havePortal('portal_1');

        $I->amBearerAuthenticated($portal->getAdminJwt());

        $I->sendGET('/enrolment/revision/1?tree=1');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['message' => 'Enrolment not found.']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-30929
     */
    public function adminGetHistoryEnrolmentLearner200(IntegrationTester $I)
    {
        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed');

        $I->amBearerAuthenticated($enrolment->user->portal->getAdminJwt());
        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 1,
                'status' => 'completed'
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 0,
                'status' => 'completed'
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->wantToTest("Admin send a GET /enrolment/lo/lo_Id/history/learner_id request, then 200 status code is shown");
        $I->waitUntilSuccess(function () use ($I, $enrolment) {
            $I->sendGET("/enrolment/lo/{$enrolment->lo->id}/history/{$enrolment->user->id}");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseContainsJson([
                [
                    'status' => 'completed',
                    'pass' => 1,
                    'enrolment_id' => $enrolment->id
                ],
                [
                    'status' => 'completed',
                    'pass' => 0,
                    'enrolment_id' => $enrolment->id
                ],
            ]);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-30930
     */
    public function managerGetHistoryEnrolmentLearner200(IntegrationTester $I)
    {
        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed_3');
        $learner = $I->haveUser('learner_with_manager');
        $manager = $I->haveUser('manager_1');
        $admin = $I->haveUser('admin');
        $I->amBearerAuthenticated($admin->jwt);

        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 1
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 0
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->wantToTest("Manager send a GET /enrolment/lo/lo_Id/history/learner_Id request with manager Jwt, then 200 status code is shown");
        $I->amBearerAuthenticated($manager->jwt);
        $I->waitUntilSuccess(function () use ($I, $enrolment, $learner) {
            $I->sendGET("/enrolment/lo/{$enrolment->lo->id}/history/{$learner->id}");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseContainsJson([
                [
                    'status' => 'completed',
                    'pass' => 1,
                    'enrolment_id' => $enrolment->id
                ],
                [
                    'status' => 'completed',
                    'pass' => 0,
                    'enrolment_id' => $enrolment->id
                ],
            ]);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-31170
     */
    public function managerGetHistoryEnrolmentSharedCourseLearner200(IntegrationTester $I)
    {
        $I->wantToTest('Manager send a GET /enrolment/lo/shared_lo_Id/history/their_learner_Id request with shared lo_Id, then 200 status code is shown');

        /** @var Policy $policy */
        $policy = $I->haveFixture('policy_portal');

        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed_3');

        $learner = $I->haveUser('learner_with_manager');
        $manager = $I->haveUser('manager_1');
        $admin   = $I->haveUser('admin');
        $I->amBearerAuthenticated($admin->jwt);
        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 1
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 0
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->amBearerAuthenticated($manager->jwt);
        $I->sendGET("/enrolment/lo/{$policy->hostEntity->id}/history/{$learner->id}");
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            [
                'status'       => 'completed',
                'pass'         => 1,
                'enrolment_id' => $enrolment->id
            ],
            [
                'status'       => 'completed',
                'pass'         => 0,
                'enrolment_id' => $enrolment->id
            ],
        ]);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-31171
     */
    public function managerGetHistoryEnrolmentSharedCourseLearner403(IntegrationTester $I)
    {
        $I->wantToTest('Manager send a GET /enrolment/lo/shared_lo_Id/history/not_their_user_Id request with not their user_Id , then 403 status code is shown');

        /** @var Policy $policy */
        $policy = $I->haveFixture('policy_portal');

        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed_3');

        $learner = $I->haveUser('learner_1');
        $manager = $I->haveUser('manager_2');
        $admin = $I->haveUser('admin');
        $I->amBearerAuthenticated($admin->jwt);
        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 1
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 0
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->amBearerAuthenticated($manager->jwt);
        $I->sendGET("/enrolment/lo/{$policy->hostEntity->id}/history/{$learner->id}");
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Permission denied']);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-31169
     */
    public function adminGetHistoryEnrolmentSharedCourseLearner200(IntegrationTester $I)
    {
        $I->wantToTest('Admin send a GET /enrolment/lo/shared_lo_Id/history/learner_Id request with shared lo_Id, then 200 status code is shown');

        /** @var Policy $policy */
        $policy = $I->haveFixture('policy_portal');

        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed');

        $learner = $enrolment->user;
        $admin = $I->haveUser('admin');
        $I->amBearerAuthenticated($admin->portal->getAdminJwt());
        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 1
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 0
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->amBearerAuthenticated($admin->portal->getAdminJwt());

        $I->waitUntilSuccess(function () use ($I, $policy, $enrolment, $learner) {
            $I->sendGET("/enrolment/lo/{$policy->hostEntity->id}/history/{$learner->id}");
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->seeResponseContainsJson([
                [
                    'status' => 'completed',
                    'pass' => 1,
                    'enrolment_id' => $enrolment->id
                ],
                [
                    'status' => 'completed',
                    'pass' => 0,
                    'enrolment_id' => $enrolment->id
                ],
            ]);
        }, $I->getHighLatency(), 1000);
    }

    /**
     * @param IntegrationTester $I
     * @see GO1P-31168
     */
    public function managerGetHistoryEnrolmentNotLearner403(IntegrationTester $I)
    {
        /** @var Enrolment $enrolment */
        $enrolment = $I->haveFixture('enrolment_completed_3');
        $learner = $I->haveUser('learner_2');
        $manager = $I->haveUser('manager_1');
        $admin = $I->haveUser('admin');
        $I->amBearerAuthenticated($admin->jwt);
        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 1
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->sendPUT(
            '/enrolment/enrolment/' . $enrolment->id,
            [
                'pass' => 0
            ]
        );
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);

        $I->wantToTest("Manager send a GET /enrolment/lo/lo_Id/history/not their user_Id request with not their user_Id , then 403 status code is shown");
        $I->amBearerAuthenticated($manager->jwt);
        $I->waitUntilSuccess(function () use ($I, $enrolment, $learner) {
            $I->sendGET("/enrolment/lo/{$enrolment->lo->id}/history/{$learner->id}");
            $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
            $I->seeResponseContainsJson(["message" => "Permission denied"]);
        }, $I->getHighLatency(), 1000);
    }
}
