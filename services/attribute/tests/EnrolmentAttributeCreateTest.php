<?php

namespace go1\core\learning_record\attribute\tests;

use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\core\learning_record\attribute\EnrolmentAttributeController;
use go1\core\learning_record\attribute\EnrolmentAttributeRepository;
use go1\core\learning_record\attribute\EnrolmentAttributes;
use go1\core\learning_record\attribute\utils\client\DimensionsClient;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\Roles;
use go1\util\user\UserHelper;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentAttributeCreateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;
    use EnrolmentMockTrait;

    protected $jwt       = UserHelper::ROOT_JWT;
    private $managerJwt;
    private $noneManagerJwt;
    private $mail      = 'student@mygo1.com';
    private $profileId = 999;
    private $courseEnrolmentId;
    protected $achieveEnrolmentId;
    private $moduleEnrolmentId;
    private $li1EnrolmentId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));

        $go1 = $app['dbs']['go1'];
        $portalId = $this->createPortal($go1, ['title' => $portalName = 'qa.mygo1.com']);
        $courseId = $this->createCourse($go1, ['instance_id' => $portalId]);
        $achieveId = $this->createCourse($go1, ['instance_id' => $portalId, 'type' => 'achievement']);
        $moduleId = $this->createModule($go1, ['instance_id' => $portalId]);
        $li1Id = $this->createVideo($go1, ['instance_id' => $portalId]);

        $this->link($go1, EdgeTypes::HAS_MODULE, $courseId, $moduleId);
        $this->link($go1, EdgeTypes::HAS_LI, $moduleId, $li1Id);

        $studentAccountId = $this->createUser($go1, ['instance' => $portalName, 'mail' => $this->mail, 'profile_id' => $this->profileId]);
        $userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->mail, 'profile_id' => $this->profileId]);
        $base = ['profile_id' => $this->profileId, 'taken_instance_id' => $portalId, 'user_id' => $userId];
        $this->courseEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $courseId, 'status' => EnrolmentStatuses::IN_PROGRESS]);
        $this->achieveEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $achieveId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'result' => 75]);
        $this->moduleEnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $moduleId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->li1EnrolmentId = $this->createEnrolment($go1, $base + ['lo_id' => $li1Id, 'status' => EnrolmentStatuses::COMPLETED, 'parent_enrolment_id' => $this->moduleEnrolmentId]);

        $managerId = $this->createUser($go1, ['mail' => $managerMail = 'manager@mail.com', 'instance' => $app['accounts_name']]);
        $managerPortalAccountId = $this->createUser($go1, ['mail' => $managerMail = 'manager@mail.com', 'instance' => $portalName]);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $managerId, $managerPortalAccountId);
        $this->link($go1, EdgeTypes::HAS_MANAGER, $studentAccountId, $managerId);
        $this->managerJwt = $this->jwtForUser($go1, $managerId, $portalName);
        $this->noneManagerJwt = JWT::encode((array) $this->getPayload(['mail' => 'none-manager@mail.com', 'roles' => [Roles::MANAGER, 'instance' => $portalName]]), 'PRIVATE_KEY', 'HS256');
    }

    public function test403()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->courseEnrolmentId}/attributes", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Missing or invalid JWT.', json_decode($res->getContent())->message);
    }

    public function test404()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/404/attributes?jwt=$this->jwt", 'POST');
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
        $this->assertEquals('Enrolment not found.', json_decode($res->getContent())->message);
    }

    public function testCreate()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->li1EnrolmentId}/attributes?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'provider'    => 'FINSIA',
            'type'        => 'VIDEO',
            'url'         => 'http://example.com',
            'description' => 'Description',
        ]);
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $ids = array_column(json_decode($res->getContent()), 'id');

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];
        $attributes = $repository->loadMultiple($ids);
        $this->assertEquals($ids[0], $attributes[0]->id);
        $this->assertEquals($this->li1EnrolmentId, $attributes[0]->enrolmentId);
        $this->assertEquals(1, $attributes[0]->key);
        $this->assertEquals('FINSIA', $attributes[0]->value);

        return [$app, $this->li1EnrolmentId];
    }

    public function testManager()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->li1EnrolmentId}/attributes?jwt=$this->noneManagerJwt", 'POST');
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());

        $req = Request::create("/enrolment/{$this->li1EnrolmentId}/attributes?jwt=$this->managerJwt", 'POST');
        $req->request->replace([
            'provider' => 'FINSIA',
        ]);
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
    }

    public function attributeData()
    {
        return [
            [[], 'Value "<ARRAY>" is blank, but was expected to contain a value'],
            [['1' => 'provider'], 'Unknown attribute: 1'],
            [['url' => 'url'], 'Value "url" was expected to be a valid URL starting with http or https'],
            [['provider' => 'type', '1' => 'type'], 'Unknown attribute: 1'],
            [['provider' => (object) []], 'Unsupported attribute value type'],
            [['documents' => '{}'], 'Documents needs to be an array'],
            [['documents' => []], 'A document must have at least one attached to attribute'],
            [['award_required' => '{}'], 'Award required needs to be an array'],
            [['award_required' => []], 'Award required must have at least one attached to attribute'],
            [['award_achieved' => '{}'], 'Award achieved needs to be an array'],
            [['award_achieved' => []], 'Award achieved must have at least one attached to attribute'],
            [['award_achieved' => [
                [
                    'goal_id'      => 123,
                    'value'        => 1.5,
                    'requirements' => 1,
                ],
            ]], 'Requirements need to be an array'],
            [['award_achieved' => [
                [
                    'goal_id'      => 123,
                    'value'        => 1.5,
                    'requirements' => [],
                ],
            ]], 'Requirements must have at least one'],
        ];
    }

    /** @dataProvider attributeData */
    public function testInvalidData($attributes, $expectedMsg)
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->li1EnrolmentId}/attributes?jwt=$this->jwt", 'POST');
        $req->request->replace($attributes);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString($expectedMsg, json_decode($res->getContent())->message);
    }

    public function testCreateDocuments()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->li1EnrolmentId}/attributes?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'provider'  => 'FINSIA',
            'documents' => [
                $documents[] = [
                    'name' => 'document-external-record',
                    'size' => 30,
                    'type' => 'document',
                    'url'  => 'http://example.com',
                ],
                $documents[] = [
                    'name' => 'document-external-record',
                    'size' => 30,
                    'type' => 'document',
                    'url'  => 'http://example.com',
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $ids = array_column(json_decode($res->getContent()), 'id');
        $this->assertCount(2, $ids);

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];
        $enrolAttribute = $repository->loadMultiple($ids);

        $providerAttribute = $enrolAttribute[0];
        $this->assertEquals($this->li1EnrolmentId, $providerAttribute->enrolmentId);
        $this->assertEquals(1, $providerAttribute->key);
        $this->assertEquals('FINSIA', $providerAttribute->value);

        $documentAttribute = $enrolAttribute[1];
        $this->assertEquals($this->li1EnrolmentId, $documentAttribute->enrolmentId);
        $this->assertEquals(5, $documentAttribute->key);
        $this->assertEquals($documents, json_decode($documentAttribute->value, true));

        return [$app, $this->li1EnrolmentId];
    }

    public function testCreateAwardRequired()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->courseEnrolmentId}/attributes?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'award_required' => [
                $goals[] = [
                    'goal_id' => 123,
                    'value'   => 1.5,
                ],
                $goals[] = [
                    'goal_id' => 456,
                    'value'   => 3.0,
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $ids = array_column(json_decode($res->getContent()), 'id');
        $this->assertCount(1, $ids);

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];
        $enrolAttribute = $repository->loadMultiple($ids);

        $goalAttribute = $enrolAttribute[0];
        $this->assertEquals($this->courseEnrolmentId, $goalAttribute->enrolmentId);
        $this->assertEquals(6, $goalAttribute->key);
        $this->assertEquals($goals, json_decode($goalAttribute->value, true));

        return [$app, $this->courseEnrolmentId];
    }

    public function testCreateUTMs()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->courseEnrolmentId}/attributes?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'utm_source' => 'some_external_source',
            'utm_content' => 'some_external_content',
        ]);
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $body = $res->getContent();
        $ids = array_column(json_decode($res->getContent()), 'id');
        $this->assertCount(2, $ids);
        $this->assertEquals(json_encode([
            [
                "id"          => 1,
                "key"         => "utm_source",
                "value"       => "some_external_source",
            ],
            [
                "id"          => 2,
                "key"         => "utm_content",
                "value"       => "some_external_content",
            ],
        ]), $body);

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];
        [$utmSource, $utmContent] = $repository->loadMultiple($ids);

        $this->assertEquals($this->courseEnrolmentId, $utmSource->enrolmentId);
        $this->assertEquals(8, $utmSource->key);
        $this->assertEquals($utmSource->value, "some_external_source");

        $this->assertEquals($this->courseEnrolmentId, $utmContent->enrolmentId);
        $this->assertEquals(9, $utmContent->key);
        $this->assertEquals($utmContent->value, "some_external_content");

        return [$app, $this->courseEnrolmentId];
    }


    public function testCreateByPut()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->courseEnrolmentId}/attributes?jwt={$this->jwt}", Request::METHOD_PUT);
        $req->request->replace([
            'award_required' => [
                $goals[] = [
                    'goal_id' => 123,
                    'value'   => 1.5,
                ],
                $goals[] = [
                    'goal_id' => 456,
                    'value'   => 3.0,
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];
        $awardRequired = $repository->loadBy($this->courseEnrolmentId, EnrolmentAttributes::AWARD_REQUIRED);

        $this->assertEquals($this->courseEnrolmentId, $awardRequired->enrolmentId);
        $this->assertEquals(EnrolmentAttributes::AWARD_REQUIRED, $awardRequired->key);
        $this->assertEquals($goals, json_decode($awardRequired->value, true));
    }

    public function dataFormat()
    {
        return [
            ['[]', []],
            ['GO1', 'GO1'],
            [
                '[{"goal_id":123,"value":10},{"goal_id":456,"value":20}]',
                [(object) ['goal_id' => 123, 'value' => 10], (object) ['goal_id' => 456, 'value' => 20]],
            ],
        ];
    }

    /**
     * @dataProvider dataFormat
     */
    public function testFormat($value, $expected)
    {
        $attribute = EnrolmentAttributes::create((object) [
            'id'           => 1,
            'enrolment_id' => 1,
            'key'          => 6,
            'value'        => $value,
        ]);
        $obj = $this
            ->getMockBuilder(EnrolmentAttributeController::class)
            ->disableOriginalConstructor()
            ->getMock();
        $method = (new ReflectionClass(EnrolmentAttributeController::class))->getMethod('format');
        $method->setAccessible(true);
        $format = $method->invokeArgs($obj, [$attribute]);
        $this->assertEquals($expected, $format['value']);
    }

    public function testCreateAwardAchieved()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->courseEnrolmentId}/attributes?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'award_achieved' => [
                $goals[] = [
                    'goal_id' => 123,
                    'value'   => 1.5,
                ],
                $goals[] = [
                    'goal_id' => 456,
                    'value'   => 3.0,
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $ids = array_column(json_decode($res->getContent()), 'id');
        $this->assertCount(1, $ids);

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];
        $enrolAttribute = $repository->loadMultiple($ids);

        $goalAttribute = $enrolAttribute[0];
        $this->assertEquals($this->courseEnrolmentId, $goalAttribute->enrolmentId);
        $this->assertEquals(7, $goalAttribute->key);
        $this->assertEquals($goals, json_decode($goalAttribute->value, true));

        return [$app, $this->courseEnrolmentId];
    }

    public function testPublishMessage()
    {
        $app = $this->getApp();

        $req = Request::create("/enrolment/{$this->li1EnrolmentId}/attributes?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'provider'    => 'FINSIA',
            'type'        => 'VIDEO',
            'url'         => 'http://example.com',
            'description' => 'Description',
        ]);
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $this->assertCount(0, $this->queueMessages);

        $req = Request::create("/enrolment/{$this->achieveEnrolmentId}/attributes?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'award_achieved' => [
                [
                    'goal_id' => 123,
                    'value'   => 1.5,
                ],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());

        $this->assertCount(1, $this->queueMessages);
        $message = $this->queueMessages['enrolment.update'][0];
        $this->assertEquals($this->achieveEnrolmentId, $message['id']);
        $this->assertEquals(75.0, $message['result']);
        $this->assertEquals(0, $message['pass']);
        $this->assertEquals(
            [
                'required' => [],
                'achieved' => [
                    (object) ['goal_id' => 123, 'value' => 1.5]],
            ],
            $message['award']
        );

        return [$app, $this->li1EnrolmentId];
    }

    public function testCreateRequirements()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->courseEnrolmentId}/attributes?jwt={$this->jwt}", 'POST');
        $req->request->replace([
            'award_achieved' => [
                $goals[] = [
                    'goal_id'      => 123,
                    'value'        => 1.5,
                    'requirements' => [
                        [
                            'goal_id' => 123,
                            'value'   => 15,
                        ],
                    ],
                ]
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(201, $res->getStatusCode());
        $id = array_column(json_decode($res->getContent()), 'id')[0];

        /** @var EnrolmentAttributeRepository $repository */
        $repository = $app[EnrolmentAttributeRepository::class];
        $goalAttribute = $repository->load($id);
        $this->assertEquals($this->courseEnrolmentId, $goalAttribute->enrolmentId);
        $requirements = json_decode($goalAttribute->value, true)[0]['requirements'];
        $this->assertEquals($requirements, $goals[0]['requirements']);

        return [$app, $this->courseEnrolmentId];
    }

    public function secondAttributeData()
    {
        return [
            [['provider' => 'type', 'type' => ''], 'No type'],
            [['provider' => 'type', 'type' => 'ABC'], 'Unavailable type'],
        ];
    }

    /** @dataProvider secondAttributeData */
    public function testTypeValidation400($attributes)
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->li1EnrolmentId}/attributes?jwt=$this->jwt", 'POST');
        $req->request->replace($attributes);
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }

    public function testCannotGetDimensions()
    {
        $app = $this->getApp();
        $app->extend(DimensionsClient::class, function () {
            $dimensionsClient = $this
                ->getMockBuilder(DimensionsClient::class)
                ->disableOriginalConstructor()
                ->setMethods(['getDimensions'])
                ->getMock();

            $dimensionsClient
                ->expects($this->any())
                ->method('getDimensions')
                ->willReturn([]);

            return $dimensionsClient;
        });
        $req = Request::create("/enrolment/{$this->li1EnrolmentId}/attributes?jwt=$this->jwt", 'POST');
        $req->request->replace([
            'provider'    => 'FINSIA',
            'type'        => 'VIDEO',
            'url'         => 'http://example.com',
            'description' => 'Description',
        ]);
        $res = $app->handle($req);
        $this->assertEquals('Can not get dimensions list', json_decode($res->getContent())->message);
    }

    public function testInvalidAttributeType()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->li1EnrolmentId}/attributes?jwt=$this->jwt", 'POST');
        $req->request->replace([
            'provider'    => 'FINSIA',
            'type'        => 'video',
            'url'         => 'http://example.com',
            'description' => 'Description',
        ]);
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('is not an element of the valid values', json_decode($res->getContent())->message);
    }
}
