<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;

use function call_user_func;
use function json_encode;

class MultipleEnrolmentTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;

    private $portalId;
    private $portalName = 'qa.mygo1.com';
    private $portalPublicKey;
    private $portalPrivateKey;
    private $userId;
    private $userSubId;
    private $userMail   = 'foo@bar.baz';
    private $profileId  = 55555;
    private $courseFree1Id;
    private $courseFree1Title;
    private $courseFree2Id;
    private $courseFree2Title;
    private $coursePay1Id;
    private $coursePay1Title;
    private $coursePay2Id;
    private $coursePay2Title;
    private $mockPost;

    protected function mockHttpPostRequest(DomainService $app, string $url, array $options)
    {
        if (null != $this->mockPost) {
            return call_user_func($this->mockPost, $url, $options) ?: parent::mockHttpPostRequest($app, $url, $options);
        }

        return parent::mockHttpPostRequest($app, $url, $options);
    }

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        /** @var Connection $db */
        $db = $app['dbs']['go1'];
        $hasAccount = 501;

        // Create portal
        $this->portalId = $this->createPortal($db, ['title' => $this->portalName, 'version' => 'v3.0.0', 'data' => json_encode(['configuration' => ['is_virtual' => 1]])]);
        $this->portalPublicKey = $this->createPortalPublicKey($db, ['instance' => $this->portalName]);
        $this->portalPrivateKey = $this->createPortalPrivateKey($db, ['instance' => $this->portalName]);

        // Create user
        $db->insert('gc_ro', [
            'type'      => $hasAccount,
            'source_id' => $this->userId = $this->createUser($db, ['instance' => $app['accounts_name'], 'mail' => $this->userMail, 'profile_id' => $this->profileId]),
            'target_id' => $this->userSubId = $this->createUser($db, ['instance' => $this->portalName, 'mail' => $this->userMail]),
            'weight'    => 0,
        ]);

        // Create courses
        $this->courseFree1Id = $this->createCourse($db, ['instance_id' => $this->portalId, 'title' => $this->courseFree1Title = uniqid('Free course 1'), 'remote_id' => 111]);
        $this->courseFree2Id = $this->createCourse($db, ['instance_id' => $this->portalId, 'title' => $this->courseFree2Title = uniqid('Free course 2'), 'remote_id' => 222]);
        $this->coursePay1Id = $this->createCourse($db, ['instance_id' => $this->portalId, 'title' => $this->coursePay1Title = uniqid('Paid course 1'), 'remote_id' => 333, 'price' => ['price' => 1.11, 'tax_included' => true]]);
        $this->coursePay2Id = $this->createCourse($db, ['instance_id' => $this->portalId, 'title' => $this->coursePay2Title = uniqid('Paid course 2'), 'remote_id' => 444, 'price' => ['price' => 2.22]]);

        $this->loAccessGrant($this->courseFree1Id, $this->userId, $this->portalId, 2);
        $this->loAccessGrant($this->courseFree2Id, $this->userId, $this->portalId, 2);
    }

    public function testMultipleFreeCourses()
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $jwt = $this->getPayload(['mail' => $this->userMail, 'profile_id' => $this->profileId, 'instance_name' => $this->portalName, 'user_id' => $this->userId]);
        $jwt = JWT::encode((array) $jwt, 'GO1_INTERNAL', 'HS256');
        $req = Request::create("/{$this->portalName}/enrolment?jwt={$jwt}", 'POST');
        $req->request->replace([
            'items' => [
                (object) ['loId' => $this->courseFree1Id, 'status' => EnrolmentStatuses::IN_PROGRESS],
                (object) ['loId' => $this->courseFree2Id, 'status' => EnrolmentStatuses::IN_PROGRESS],
            ],
        ]);

        $res = $app->handle($req);
        $results = json_decode($res->getContent(), true);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertTrue(is_numeric($results[$this->courseFree1Id][200]['id']));
        $this->assertTrue(is_numeric($results[$this->courseFree2Id][200]['id']));

        # Try to create enrolment on same learning objects, the result should be same
        $res = $app->handle($req);
        $results = json_decode($res->getContent(), true);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertTrue(is_numeric($results[$this->courseFree1Id][200]['id']));
        $this->assertTrue(is_numeric($results[$this->courseFree2Id][200]['id']));

        # Create enrolments for other user.
        $req = Request::create("/{$this->portalName}/enrolment/{$this->userMail}?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'items' => [
                (object) ['loId' => $this->courseFree1Id, 'status' => EnrolmentStatuses::IN_PROGRESS],
                (object) ['loId' => $this->courseFree2Id, 'status' => EnrolmentStatuses::IN_PROGRESS],
            ],
        ]);
        $res = $app->handle($req);
        $results = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        foreach ($results as $result) {
            $enrolment = EnrolmentHelper::load($go1, $result->{"200"}->id);
            $this->assertEquals($this->profileId, $enrolment->profile_id);
            $this->assertEquals($this->userId, $enrolment->user_id);
        }
    }

    public function testOneFreeAndOneCommercialCourse()
    {
        $app = $this->getApp();
        $jwt = $this->getPayload(['user_id' => $this->userId, 'mail' => $this->userMail, 'profile_id' => $this->profileId, 'instance_name' => $this->portalName]);
        $jwt = JWT::encode((array) $jwt, 'GO1_INTERNAL', 'HS256');

        $this->mockPost = function (string $url, array $options) use ($app) {
            if ($url == $app['payment_url'] . '/cart/process') {
                # $this->assertEquals("Bearer {$jwt}", $options['headers']['Authorization']);
                $this->assertEquals('stripe', $options['json']['paymentMethod']);
                $this->assertEquals(['connectionUuid' => $this->portalPublicKey, 'token' => 'USER_STRIPE_CARD_TOKEN', 'customer' => 'USER_STRIPE_CUSTOMER_ID'], $options['json']['paymentOptions']);
                $this->assertEquals(
                    $options['json']['cartOptions']['items'][0],
                    [
                        'instanceId'   => $this->portalId,
                        'productId'    => $this->courseFree1Id,
                        'type'         => 'lo',
                        'price'        => 0.0,
                        'tax'          => 0.0,
                        'tax_included' => false,
                        'currency'     => 'AUD',
                        'qty'          => 1,
                        'data'         => ['title' => $this->courseFree1Title],
                    ]
                );

                $this->assertEquals(
                    $options['json']['cartOptions']['items'][1],
                    [
                        'instanceId'   => $this->portalId,
                        'productId'    => $this->coursePay1Id,
                        'type'         => 'lo',
                        'price'        => 1.11,
                        'tax'          => 0.0,
                        'tax_included' => true,
                        'currency'     => 'AUD',
                        'qty'          => 1,
                        'data'         => ['title' => $this->coursePay1Title],
                    ]
                );

                return new Response(200, [], json_encode(['foo' => 'THE TRANSACTION ON #payment.', 'id' => 999]));
            }
        };

        # User create enrolments for himself
        $req = Request::create("/{$this->portalName}/enrolment?jwt={$jwt}", 'POST');
        $req->request->replace([
            'paymentMethod'  => 'stripe',
            'paymentOptions' => ['connectionUuid' => '…', 'token' => 'USER_STRIPE_CARD_TOKEN', 'customer' => 'USER_STRIPE_CUSTOMER_ID'],
            'items'          => [
                (object) ['loId' => $this->courseFree1Id, 'status' => EnrolmentStatuses::IN_PROGRESS],
                (object) ['loId' => $this->coursePay1Id, 'status' => EnrolmentStatuses::IN_PROGRESS],
            ],
        ]);

        $res = $app->handle($req);
        $results = json_decode($res->getContent(), true);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertTrue(is_numeric($results[$this->courseFree1Id][200]['id']));
        $this->assertTrue(is_numeric($results[$this->coursePay1Id][200]['id']));
    }

    public function testOneFreeAndOneCommercialCourseForOtherUser()
    {
        $app = $this->getApp();

        $this->mockPost = function (string $url, array $options) use ($app) {
            if ($url == $app['payment_url'] . '/cart/process') {
                $this->assertEquals('stripe', $options['json']['paymentMethod']);
                $this->assertEquals(['connectionUuid' => $this->portalPublicKey, 'token' => 'USER_STRIPE_CARD_TOKEN', 'customer' => 'USER_STRIPE_CUSTOMER_ID'], $options['json']['paymentOptions']);
                $this->assertEquals(
                    $options['json']['cartOptions']['items'][0],
                    [
                        'instanceId'   => $this->portalId,
                        'productId'    => $this->courseFree1Id,
                        'type'         => 'lo',
                        'price'        => 0.0,
                        'tax'          => 0.0,
                        'tax_included' => false,
                        'currency'     => 'AUD',
                        'qty'          => 1,
                        'data'         => ['title' => $this->courseFree1Title],
                    ]
                );

                $this->assertEquals(
                    $options['json']['cartOptions']['items'][1],
                    [
                        'instanceId'   => $this->portalId,
                        'productId'    => $this->coursePay1Id,
                        'type'         => 'lo',
                        'price'        => 1.11,
                        'tax'          => 0.0,
                        'tax_included' => true,
                        'currency'     => 'AUD',
                        'qty'          => 1,
                        'data'         => ['title' => $this->coursePay1Title],
                    ]
                );

                return new Response(200, [], json_encode(['foo' => 'THE TRANSACTION ON #payment.', 'payment_method' => 'credit', 'id' => 999]));
            }
        };

        # User create enrolments for other.
        $req = Request::create("/{$this->portalName}/enrolment/{$this->userMail}?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'paymentMethod'  => 'stripe',
            'paymentOptions' => ['connectionUuid' => '…', 'token' => 'USER_STRIPE_CARD_TOKEN', 'customer' => 'USER_STRIPE_CUSTOMER_ID'],
            'items'          => [
                (object) ['loId' => $this->courseFree1Id, 'status' => EnrolmentStatuses::IN_PROGRESS],
                (object) ['loId' => $this->coursePay1Id, 'status' => EnrolmentStatuses::IN_PROGRESS],
            ],
        ]);
        $res = $app->handle($req);
        $results = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        foreach ($results as $result) {
            $enrolment = EnrolmentHelper::load($app['dbs']['go1'], $result->{"200"}->id);
            $this->assertEquals($this->profileId, $enrolment->profile_id);
            $this->assertEquals($this->userId, $enrolment->user_id);
        }
    }
}
