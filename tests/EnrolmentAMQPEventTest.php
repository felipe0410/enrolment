<?php

namespace go1\enrolment\tests;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\clients\MqClient;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\Roles;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentAMQPEventTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalName  = 'az.mygo1.com';
    private $studentMail = 'student@mail.com';
    private $portalId;
    private $studentUserId;
    private $studentAccountId;
    private $profileId   = 999;
    private $jwt;
    private $loId;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);
        $this->loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->studentUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->studentMail, 'profile_id' => $this->profileId]);
        $this->studentAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->studentMail, 'uuid' => 'USER_UUID']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $this->studentAccountId);
        $this->jwt = $this->getJwt($this->studentMail, $app['accounts_name'], $this->portalName, [Roles::AUTHENTICATED], $this->studentAccountId, null, $this->profileId, $this->studentUserId);
    }

    public function testUpdate()
    {
        $app = $this->getApp();
        $app->extend('go1.client.mq', function (MqClient $mqClient) {
            $mqClient = $this
                ->getMockBuilder(MqClient::class)
                ->disableOriginalConstructor()
                ->setMethods(['batchAdd', 'close', 'isAvailable'])
                ->getMock();

            $mqClient
                ->expects($this->atLeastOnce())
                ->method('isAvailable')
                ->willReturn(true);

            $callCount = 0;
            $mqClient
                ->expects($this->exactly(2))
                ->method('batchAdd')
                ->will(
                    $this->returnCallback(function ($message, $routingKey) use (&$callCount) {
                        $this->assertEquals($this->profileId, $message['profile_id']);
                        if ($callCount === 0) {
                            $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $message['status']);
                            $this->assertEquals(Queue::ENROLMENT_REVISION_CREATE, $routingKey);
                        } elseif ($callCount === 1) {
                            $this->assertEquals('78.0', $message['result']);
                            $this->assertEquals('0.0', $message['original']['result']);
                            $this->assertEquals(Queue::ENROLMENT_UPDATE, $routingKey);
                        } else {
                            $this->fail('Called too many times');
                        }
                        $callCount++;
                        return true;
                    })
                );
            return $mqClient;
        });

        $db = $app['dbs']['go1'];
        $enrolmentId = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->profileId, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId]);

        $req = Request::create("/enrolment/{$enrolmentId}", 'PUT', ['result' => 78]);
        $req->attributes->set('jwt.payload', $this->getPayload(['profile_id' => 111, 'roles' => [Roles::ROOT]]));
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
    }

    public function testUpdateStatusSpreading()
    {
        $app = $this->getApp();
        $app->extend('go1.client.mq', function () {
            $mqClient = $this
                ->getMockBuilder(MqClient::class)
                ->disableOriginalConstructor()
                ->setMethods(['batchAdd', 'close', 'isAvailable'])
                ->getMock();

            $mqClient
                ->expects($this->atLeastOnce())
                ->method('isAvailable')
                ->willReturn(true);

            $mqClient
                ->expects($this->any())
                ->method('batchAdd')
                ->willReturnCallback(function ($body, string $routingKey, $context) {
                    is_array($body) ?
                        ($body = $body + ['context' => $context]) :
                        (is_object($body) && $body->context = $context);
                    $this->queueMessages[$routingKey][] = $body;
                });

            return $mqClient;
        });

        $db = $app['dbs']['go1'];

        $lpId = $this->createCourse($db, ['type' => 'learning_pathway', 'instance_id' => $this->portalId]);
        $moduleId = $this->createCourse($db, ['type' => 'module', 'instance_id' => $this->portalId]);
        $liVideoId = $this->createCourse($db, ['type' => 'video', 'instance_id' => $this->portalId]);
        $liResourceId = $this->createCourse($db, ['type' => 'iframe', 'instance_id' => $this->portalId]);

        // Linking
        $this->link($db, EdgeTypes::HAS_LP_ITEM, $lpId, $this->loId, 0);
        $this->link($db, EdgeTypes::HAS_MODULE, $this->loId, $moduleId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $moduleId, $liVideoId, 0);
        $this->link($db, EdgeTypes::HAS_LI, $moduleId, $liResourceId, 0);

        $lbEnrolmentId = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->profileId, 'lo_id' => $lpId, 'taken_instance_id' => $this->portalId]);
        $loEnrolmentId = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->profileId, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $lbEnrolmentId]);
        $moduleEnrolmentId = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->profileId, 'lo_id' => $moduleId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $loEnrolmentId]);
        $liVideoEnrolmentId = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->profileId, 'lo_id' => $liVideoId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $moduleEnrolmentId]);
        $liResourceEnrolmentId = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->profileId, 'lo_id' => $liResourceId, 'taken_instance_id' => $this->portalId, 'parent_enrolment_id' => $moduleEnrolmentId]);

        // Complete li video.
        $req = Request::create("/enrolment/{$liVideoEnrolmentId}", 'PUT', ['status' => 'completed']);
        $req->query->replace(['jwt' => $this->jwt]);
        $app->handle($req);
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_REVISION_CREATE]);
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_UPDATE]);

        // Complete li resource.
        $this->queueMessages = [];
        $req = Request::create("/enrolment/{$liResourceEnrolmentId}", 'PUT', ['status' => 'completed']);
        $req->query->replace(['jwt' => $this->jwt]);
        $app->handle($req);
        $this->assertCount(4, $this->queueMessages[Queue::ENROLMENT_REVISION_CREATE]);
        $this->assertCount(4, $this->queueMessages[Queue::ENROLMENT_UPDATE]);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, EnrolmentHelper::loadSingle($db, $moduleEnrolmentId)->status);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, EnrolmentHelper::loadSingle($db, $loEnrolmentId)->status);
    }

    public function testDelete()
    {
        $app = $this->getApp();
        $app->extend('go1.client.mq', function () {
            $mqClient = $this
                ->getMockBuilder(MqClient::class)
                ->disableOriginalConstructor()
                ->setMethods(['batchAdd', 'publish', 'isAvailable'])
                ->getMock();

            $mqClient
                ->expects($this->atLeastOnce())
                ->method('isAvailable')
                ->willReturn(true);

            $mqClient
                ->expects($this->once())
                ->method('batchAdd')
                ->with(
                    $this->callback(function ($message) {
                        $this->assertEquals($this->profileId, $message['profile_id']);
                        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $message['status']);

                        return true;
                    }),
                    Queue::ENROLMENT_REVISION_CREATE
                );
            $mqClient
                ->expects($this->once())
                ->method('publish')
                ->with(
                    $this->callback(function ($message) {
                        $this->assertEquals($this->profileId, $message->profile_id);
                        $this->assertEquals('in-progress', $message->status);

                        return true;
                    }),
                    Queue::ENROLMENT_DELETE
                );

            return $mqClient;
        });

        $db = $app['dbs']['go1'];
        $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->profileId, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId]);

        $req = Request::create("/{$this->loId}", 'DELETE');
        $req->attributes->set('jwt.payload', $this->getPayload(['user_id' => $this->studentUserId, 'roles' => ['Admin on #Accounts']]));
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testBrokerFailWhenUpdating()
    {
        $app = $this->getApp();
        $app->extend('go1.client.mq', function () {
            $mqClient = $this
                ->getMockBuilder(MqClient::class)
                ->disableOriginalConstructor()
                ->setMethods(['isAvailable'])
                ->getMock();

            $mqClient
                ->expects($this->atLeastOnce())
                ->method('isAvailable')
                ->willReturn(false);

            return $mqClient;
        });

        $db = $app['dbs']['go1'];
        $enrolmentId = $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->profileId, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId]);

        $req = Request::create("/enrolment/{$enrolmentId}", 'PUT', ['result' => 79]);
        $req->attributes->set('jwt.payload', $this->getPayload(['profile_id' => 111, 'roles' => [Roles::ROOT]]));
        $res = $app->handle($req);
        $this->assertEquals(500, $res->getStatusCode());
        $this->assertEquals('{"message":"Internal server error"}', $res->getContent());
    }

    public function testBrokerFailWhenDeleting()
    {
        $app = $this->getApp();
        $app->extend('go1.client.mq', function () {
            $mqClient = $this
                ->getMockBuilder(MqClient::class)
                ->disableOriginalConstructor()
                ->setMethods(['isAvailable'])
                ->getMock();

            $mqClient
                ->expects($this->atLeastOnce())
                ->method('isAvailable')
                ->willReturn(false);

            return $mqClient;
        });

        $db = $app['dbs']['go1'];
        $this->createEnrolment($db, ['user_id' => $this->studentUserId, 'profile_id' => $this->profileId, 'lo_id' => $this->loId, 'taken_instance_id' => $this->portalId]);

        $req = Request::create("/{$this->loId}", 'DELETE');
        $req->attributes->set('jwt.payload', $this->getPayload(['user_id' => $this->studentUserId, 'roles' => ['Admin on #Accounts']]));
        $res = $app->handle($req);
        $this->assertEquals(500, $res->getStatusCode());
        $this->assertEquals('{"message":"Failed to delete enrolment"}', $res->getContent());
    }
}
