<?php

use Codeception\Util\HttpCode;

class EnrollmentAttributesCest
{
    public function _before(IntegrationTester $I)
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    protected function createEnrollmentAttributes(IntegrationTester $I)
    {
        $enrolment = $I->haveFixture('enrolment_completed');
        $postData  = [
            'provider'       => 'GO1',
            'type'           => 'EVENT',
            'url'            => "https://www.go1.com",
            'description'    => "Testing Achievement",
            'documents'      => [
                0 => [
                    'name' => 'document-external-record',
                    'size' => 30,
                    'type' => 'document',
                    'url'  => 'https://example.com'
                ]
            ],
            'award_required' => [
                0 => [
                    'goal_id' => 1,
                    'value'   => '1.5',
                ],
                1 => [
                    'goal_id' => 2,
                    'value'   => '3'
                ],
            ]
        ];
        $I->sendPOST("/enrolment/enrolment/{$enrolment->id}/attributes", $postData);
    }

    public function updateEnrollmentAttributes(IntegrationTester $I)
    {
        $enrolment = $I->haveFixture('enrolment_completed');
        $postData  = [
            'provider'    => 'GO1',
            'type'        => 'EVENT',
            'url'         => "https://www.go1.com",
            'description' => "Testing Achievement",
            'documents'   => [
                0 => [
                    'name' => 'document-external-record',
                    'size' => 30,
                    'type' => 'document',
                    'url'  => 'https://example.com'
                ],
                1 => [
                    'name' => 'document-external-record-final',
                    'size' => 300,
                    'type' => 'event',
                    'url'  => 'https://example.com/event'
                ],
            ]
        ];
        $I->sendPUT("/enrolment/enrolment/{$enrolment->id}/attributes", $postData);
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#post
     * @see GO1P-30719
     */
    public function enrolAttributesSuccessfully(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a POST /enrolment/enrolment_Id/attributes request with valid parameters, then 200 status code is shown');

        $I->haveFixture('enrolment_completed');
        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $this->createEnrollmentAttributes($I);

        $I->seeResponseCodeIs(HttpCode::CREATED);
        $I->grabDataFromResponseByJsonPath('$.id');
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#post
     * @see GO1P-30725
     */
    public function enrolAttributeWithoutAuthority(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a POST /enrolment/enrolment_Id/attributes request without authority role, then 403 status code is shown');

        $learner = $I->haveFixture('learner_1');
        $I->amBearerAuthenticated($learner->jwt);
        $this->createEnrollmentAttributes($I, $learner);

        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Only portal admin or manager can post enrolment attribute.']);
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#post
     * @see GO1P-30724
     */
    public function enrolAttributeWithInvalidJwt(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send POST /enrolment/enrolment_Id/attributes with invalid JWT, then 403 status is shown');

        $learner = $I->haveFixture('learner_2');
        $I->amBearerAuthenticated($learner->jwt);
        $this->createEnrollmentAttributes($I, $learner);

        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Only portal admin or manager can post enrolment attribute.']);
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#post
     * @see GO1P-30721
     */
    public function enrolToAttributeWithInvalidData(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a POST /enrolment/enrolment_Id/attributes request with invalid parameters, then 400 status code is shown');

        $enrolment = $I->haveFixture('enrolment_completed');
        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $postData = [
            'providers' => 'GO1'
        ];
        $I->sendPOST("/enrolment/enrolment/{$enrolment->id}/attributes", $postData);

        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['message' => 'Unknown attribute: providers']);
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#put
     * @see GO1P-30721
     */
    public function createEnrolToAttributeWithInvalidType(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a POST /enrolment/enrolment_Id/attributes request with invalid type, then 400 status code is shown');

        $enrolment = $I->haveFixture('enrolment_completed');
        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $postData = [
            'provider' => 'GO1',
            'type' => 'GO1'
        ];
        $I->sendPOST("/enrolment/enrolment/{$enrolment->id}/attributes", $postData);

        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains('is not an element of the valid values');
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#post
     * @see GO1P-30720
     */
    public function enrolToNonExistingEnrolment(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a POST /enrolment/enrolment_Id/attributes request with non existing enrolment, then 404 status code is shown');

        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $postData = [
            'provider'    => 'GO1',
            'type'        => 'event',
            'url'         => "https://www.go1.com",
            'description' => "Testing Achievement",
            'documents'   => [
                'name' => 'document-external-record',
                'size' => 30,
                'type' => 'document',
                'url'  => 'https://example.com'
            ]
        ];
        $I->sendPOST("/enrolment/enrolment/876923043/attributes", $postData);

        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['message' => 'Enrolment not found.']);
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#put
     * @see GO1P-30726
     */
    protected function updateEnrolAttributesSuccessfully(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a PUT /enrolment/enrolment_Id/attributes request with valid parameters, then 204 status code is shown');

        $I->haveFixture('enrolment_completed');
        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $this->updateEnrollmentAttributes($I);

        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        $I->seeResponseEquals('');
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#put
     * @see GO1P-30732
     */
    public function updateEnrolAttributeWithAuthorityRoles(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a PUT /enrolment/enrolment_Id/attributes request without authority roles, then 403 status code is shown');

        $learner = $I->haveFixture('learner_1');
        $I->amBearerAuthenticated($learner->jwt);
        $this->updateEnrollmentAttributes($I, $learner);

        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Only portal admin or manager can post enrolment attribute.']);
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#put
     * @see GO1P-30731
     */
    public function updateEnrolAttributeWithInvalidJWT(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a PUT /enrolment/enrolment_Id/attributes request with invalid JWT, then 403 status code is shown');

        $learner = $I->haveFixture('learner_2');
        $I->amBearerAuthenticated($learner->jwt);
        $this->updateEnrollmentAttributes($I, $learner);

        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->seeResponseContainsJson(['message' => 'Only portal admin or manager can post enrolment attribute.']);
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#put
     * @see GO1P-30727
     */
    public function updateEnrolAttributeWithNonExistingEnrolment(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a PUT /enrolment/enrolment_Id/attributes request with non existing enrolment_Id, then 404 status code is shown');

        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $postData = [
            'provider'    => 'GO1',
            'type'        => 'event',
            'url'         => "https://www.go1.com",
            'description' => "Testing Achievement",
            'documents'   => [
                'name' => 'document-external-record',
                'size' => 30,
                'type' => 'document',
                'url'  => 'https://example.com'
            ]
        ];
        $I->sendPUT("/enrolment/enrolment/8769113043/attributes", $postData);

        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['message' => 'Enrolment not found.']);
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#put
     * @see GO1P-30728
     */
    public function updateEnrolToAttributeWithInvalidData(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a PUT /enrolment/enrolment_Id/attributes request with invalid parameters, then 400 status code is shown');

        $enrolment = $I->haveFixture('enrolment_completed');
        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $postData = [
            'providers' => 'GO1'
        ];
        $I->sendPUT("/enrolment/enrolment/{$enrolment->id}/attributes", $postData);

        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['message' => 'Unknown attribute: providers']);
    }

    /**
     * @param IntegrationTester $I
     * @link https://code.go1.com.au/microservices/enrolment/blob/master/services/attribute/docs/enrolment-attributes.md#put
     * @see GO1P-30728
     */
    public function updateEnrolToAttributeWithInvalidType(IntegrationTester $I)
    {
        $I->wantToTest('As authority when I send a PUT /enrolment/enrolment_Id/attributes request with invalid type, then 400 status code is shown');

        $enrolment = $I->haveFixture('enrolment_completed');
        $I->amBearerAuthenticated($I->getAccountAdminJwt());
        $postData = [
            'provider' => 'GO1',
            'type'     => 'GO1'
        ];
        $I->sendPUT("/enrolment/enrolment/{$enrolment->id}/attributes", $postData);

        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContains('is not an element of the valid values');
    }
}
