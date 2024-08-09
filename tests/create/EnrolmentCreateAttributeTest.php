<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\core\learning_record\attribute\EnrolmentAttributeRepository;
use go1\core\learning_record\attribute\EnrolmentAttributes;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\policy\Realm;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentCreateAttributeTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    protected $loAccessDefaultValue = Realm::ACCESS;
    private $portalName           = 'az.mygo1.com';
    private $portalId;
    private $mail                 = 'student@go1.com.au';
    private $studentAccountId;
    private $studentUserId;
    private $studentProfileId     = 11;
    private $loId;
    private $jwt;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $go1 = $app['dbs']['go1'];

        // Create instance
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);
        $this->createPortalPrivateKey($go1, ['instance' => $this->portalName]);

        $this->loId = $this->createLO(
            $go1,
            [
                'type'        => LiTypes::MANUAL,
                'instance_id' => $this->portalId,
                'data'        => ['single_li' => true],
                'price'       => ['price' => 1.00],
            ]
        );

        $this->studentUserId = $this->createUser($go1, ['mail' => $this->mail, 'instance' => $app['accounts_name'], 'profile_id' => $this->studentProfileId]);
        $this->studentAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'uuid' => 'USER_UUID', 'mail' => $this->mail]);

        $this->jwt = UserHelper::ROOT_JWT;
    }

    public function test()
    {
        $app = $this->getApp();
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));

        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/{$this->mail}/in-progress?jwt={$this->jwt}", Request::METHOD_POST);
        $req->request->replace([
            'attributes' => [
                'provider' => 'FINSIA',
                'url'      => 'http://example.com',
                'date'     => '2019-10-29',
                'type'     => 'EVENT',
            ],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $enrolmentId = json_decode($res->getContent())->id ?? 0;

        $this->assertTrue($enrolmentId > 0);

        /** @var EnrolmentRepository $repo */
        $repo = $app[EnrolmentRepository::class];
        $enrolment = $repo->load($enrolmentId);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals(1, $enrolment->pass);
        $this->assertEquals("2019-10-29T00:00:00+0000", $enrolment->end_date);
        $this->assertEquals("2019-10-29T00:00:00+0000", $enrolment->start_date);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];
        $enrolmentRaw = $go1->executeQuery('SELECT * FROM gc_enrolment WHERE id = ?', [$enrolmentId])
            ->fetch(DB::OBJ);
        $this->assertEquals("2019-10-29 00:00:00", $enrolmentRaw->end_date);
        $this->assertEquals("2019-10-29 00:00:00", $enrolmentRaw->start_date);

        /** @var EnrolmentAttributeRepository $repoAttr */
        $repoAttr = $app[EnrolmentAttributeRepository::class];
        $attributes = $repoAttr->loadByEnrolmentId($enrolmentId);

        $this->assertEquals(EnrolmentAttributes::PROVIDER, $attributes[0]->key);
        $this->assertEquals('FINSIA', $attributes[0]->value);
        $this->assertEquals(EnrolmentAttributes::URL, $attributes[1]->key);
        $this->assertEquals('http://example.com', $attributes[1]->value);
    }

    public function testAttributesIsRequired()
    {
        $app = $this->getApp();

        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/{$this->mail}/in-progress?jwt={$this->jwt}", Request::METHOD_POST);
        $req->request->replace([]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Can not create enrolment.', json_decode($res->getContent())->message);
        $this->assertEquals(['Attributes is required.'], json_decode($res->getContent())->error->{$this->loId}->{400});
    }

    public function dataAttributes()
    {
        return [
            [[]],
            [(object) []],
            [''],
            ['string'],
            [[
                 'provider' => 'FINSIA',
                 'url'      => 'http://example.com',
             ]],
        ];
    }

    /** @dataProvider dataAttributes */
    public function testInvalidAttributes($attributes)
    {
        $app = $this->getApp();

        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/{$this->mail}/in-progress?jwt={$this->jwt}", Request::METHOD_POST);
        $req->request->replace(['attributes' => $attributes]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Can not create enrolment.', json_decode($res->getContent())->message);
    }

    /** @dataProvider dataAttributes */
    public function testInvalidType($attributes)
    {
        $app = $this->getApp();

        $req = Request::create("/{$this->portalName}/0/{$this->loId}/enrolment/{$this->mail}/in-progress?jwt={$this->jwt}", Request::METHOD_POST);
        $req->request->replace(['attributes' => $attributes]);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Can not create enrolment.', json_decode($res->getContent())->message);
    }
}
