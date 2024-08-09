<?php

namespace go1\core\learning_record\attribute\tests;

use go1\core\learning_record\attribute\EnrolmentAttributeRepository;
use go1\core\learning_record\attribute\EnrolmentAttributes;
use go1\enrolment\EnrolmentRepository;
use go1\util\enrolment\EnrolmentHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentAttributeUpdateTest extends EnrolmentAttributeCreateTest
{
    /** @depends testCreate */
    public function testUpdate($params)
    {
        [$app, $enrolmentId] = $params;
        $req = Request::create("/enrolment/{$enrolmentId}/attributes?jwt={$this->jwt}", 'PUT');
        $req->request->replace([
            'provider' => 'GO1',
            'type'     => 'EVENT',
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];
        $attribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::PROVIDER);
        $this->assertEquals(1, $attribute->key);
        $this->assertEquals('GO1', $attribute->value);
        $attribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::TYPE);
        $this->assertEquals(2, $attribute->key);
        $this->assertEquals('EVENT', $attribute->value);
    }

    /** @depends testCreateDocuments */
    public function testUpdateDocuments($params)
    {
        [$app, $enrolmentId] = $params;
        $req = Request::create("/enrolment/{$enrolmentId}/attributes?jwt={$this->jwt}", 'PUT');
        $req->request->replace([
            'provider'  => 'GO1',
            'documents' => [
                $documents[] = [
                    'name' => 'document-external-record-update',
                    'size' => 30,
                    'type' => 'document',
                    'url'  => 'http://example.com',
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];

        $providerAttribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::PROVIDER);
        $this->assertEquals(1, $providerAttribute->key);
        $this->assertEquals('GO1', $providerAttribute->value);

        $documentAttribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::DOCUMENTS);
        $this->assertEquals(5, $documentAttribute->key);
        $this->assertEquals($documents, json_decode($documentAttribute->value, true));
    }

    /** @depends testCreateAwardRequired */
    public function testUpdateAwardRequired($params)
    {
        [$app, $enrolmentId] = $params;
        $req = Request::create("/enrolment/{$enrolmentId}/attributes?jwt={$this->jwt}", 'PUT');
        $req->request->replace([
            'award_required' => [
                $goals[] = [
                    'goal_id' => 789,
                    'value'   => 1.5,
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];

        $documentAttribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::AWARD_REQUIRED);
        $this->assertEquals(6, $documentAttribute->key);
        $this->assertEquals($goals, json_decode($documentAttribute->value, true));
    }

    /** @depends testCreateDocuments */
    public function testPersistDocuments($params)
    {
        [$app, $enrolmentId] = $params;
        $req = Request::create("/enrolment/{$enrolmentId}/attributes?jwt={$this->jwt}", 'PUT');
        $req->request->replace([
            'provider'  => 'GO1',
            'documents' => [],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];

        $providerAttribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::PROVIDER);
        $this->assertEquals(1, $providerAttribute->key);
        $this->assertEquals('GO1', $providerAttribute->value);

        $documentAttribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::DOCUMENTS);
        $this->assertEquals(5, $documentAttribute->key);
        $this->assertEquals([], json_decode($documentAttribute->value, true));
    }

    /** @depends testCreateAwardAchieved */
    public function testUpdateAwardAchieved($params)
    {
        [$app, $enrolmentId] = $params;
        $req = Request::create("/enrolment/{$enrolmentId}/attributes?jwt={$this->jwt}", 'PUT');
        $req->request->replace([
            'award_achieved' => [
                $goals[] = [
                    'goal_id' => 789,
                    'value'   => 1.5,
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];

        $documentAttribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::AWARD_ACHIEVED);
        $this->assertEquals(7, $documentAttribute->key);
        $this->assertEquals($goals, json_decode($documentAttribute->value, true));
    }

    /** @depends testCreate */
    public function testUpdateOptionalFields($params)
    {
        [$app, $enrolmentId] = $params;
        $req = Request::create("/enrolment/{$enrolmentId}/attributes?jwt={$this->jwt}", 'PUT');
        $req->request->replace([
            'url'         => '',
            'description' => '',
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];
        $attribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::URL);
        $this->assertEquals(3, $attribute->key);
        $this->assertEquals('', $attribute->value);
        $attribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::DESCRIPTION);
        $this->assertEquals(4, $attribute->key);
        $this->assertEquals('', $attribute->value);
    }

    public function testPublishMessage()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->achieveEnrolmentId}/attributes?jwt={$this->jwt}", 'PUT');
        $req->request->replace([
            'award_achieved' => [
                $goals[] = [
                    'goal_id' => 789,
                    'value'   => 1.5,
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $this->assertCount(1, $this->queueMessages);
        $message = $this->queueMessages['enrolment.update'][0];
        $this->assertEquals($this->achieveEnrolmentId, $message['id']);
        $this->assertEquals(75, $message['result']);
        $this->assertEquals(0, $message['pass']);
        $this->assertEquals(
            [
                'required' => [],
                'achieved' => [
                    (object) ['goal_id' => 789, 'value' => 1.5]],
            ],
            $message['award']
        );
    }

    /** @depends testCreateRequirements */
    public function testUpdateRequirements($params)
    {
        [$app, $enrolmentId] = $params;
        $req = Request::create("/enrolment/{$enrolmentId}/attributes?jwt={$this->jwt}", 'PUT');
        $req->request->replace([
            'award_achieved' => [
                $goals[] = [
                    'goal_id'      => 789,
                    'value'        => 1.5,
                    'requirements' => [
                        [
                            'goal_id' => 789,
                            'value'   => 15,
                        ],
                    ],
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];

        $attribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::AWARD_ACHIEVED);
        $this->assertEquals(7, $attribute->key);
        $requirements = json_decode($attribute->value, true)[0]['requirements'];
        $this->assertSame($requirements, $goals[0]['requirements']);
    }

    /** @depends testCreateRequirements */
    public function testInvalidUpdatedType($params)
    {
        [$app, $enrolmentId] = $params;
        $req = Request::create("/enrolment/{$enrolmentId}/attributes?jwt={$this->jwt}", 'PUT');
        $req->request->replace([
            'provider'  => 'GO1',
            'type'      => 'video'
        ]);
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('is not an element of the valid values', json_decode($res->getContent())->message);
    }

    /** @depends testCreateAwardAchieved */
    public function testUpdateArchived($params)
    {
        [$app, $enrolmentId] = $params;

        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];
        $db = $app['dbs']['go1'];
        $enrolment = EnrolmentHelper::loadSingle($db, $enrolmentId);
        $repository->deleteEnrolment($enrolment, 0);

        $req = Request::create("/enrolment/{$enrolmentId}/attributes?jwt={$this->jwt}", Request::METHOD_PUT);
        $req->request->replace([
            'award_achieved' => [
                $goals[] = [
                    'goal_id' => 789,
                    'value'   => 1.5,
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];

        $documentAttribute = $repository->loadBy($enrolmentId, EnrolmentAttributes::AWARD_ACHIEVED);
        $this->assertEquals(7, $documentAttribute->key);
        $this->assertEquals($goals, json_decode($documentAttribute->value, true));
    }
}
