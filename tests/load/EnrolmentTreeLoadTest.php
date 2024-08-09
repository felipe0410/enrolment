<?php

namespace go1\enrolment\tests\load;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\lo\LiTypes;
use go1\util\lo\LoTypes;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

class EnrolmentTreeLoadTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $userId;
    private $profileId = 999;
    private $courseId;
    private $moduleAId;
    private $moduleBId;
    private $liA1Id;
    private $liA2Id;
    private $liB1Id;
    private $liB2Id;
    private $videoId;
    private $ltiId;
    private $courseEnrolmentId;
    private $moduleAEnrolmentId;
    private $moduleBEnrolmentId;
    private $videoEnrolmentIdInModuleB;
    private $videoEnrolmentId;
    private $ltiRegistrations;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => 'qa.mygo1.com']);
        $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'qa@student.com', 'profile_id' => $this->profileId]);

        # Setup course structure
        # ---------------------
        $this->courseId = $this->createCourse($go1);
        $this->moduleAId = $this->createModule($go1);
        $this->moduleBId = $this->createModule($go1);
        $this->liA1Id = $this->createVideo($go1);
        $this->liA2Id = $this->createVideo($go1);
        $this->liB1Id = $this->createVideo($go1);
        $this->liB2Id = $this->createVideo($go1);
        $this->videoId = $this->createVideo($go1, ['data' => ['single_li' => true]]);
        $this->ltiB1Id = $this->createLO($go1, ['type' => LiTypes::LTI,'title' => 'LTI 1.3 course B1', 'single_li' => true]);
        $this->ltiB2Id = $this->createLO($go1, ['type' => LiTypes::LTI,'title' => 'LTI 1.3 course B2', 'single_li' => true]);

        $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleAId);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LO, $this->courseId, $this->moduleBId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleAId, $this->liA1Id);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LI, $this->moduleAId, $this->liA2Id);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleBId, $this->liB1Id);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleBId, $this->videoId);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleBId, $this->ltiB1Id);
        $this->link($go1, EdgeTypes::HAS_LI, $this->moduleBId, $this->ltiB2Id);
        $this->link($go1, EdgeTypes::HAS_ELECTIVE_LI, $this->moduleBId, $this->liB2Id);

        $this->courseEnrolmentId = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->courseId]);
        $this->moduleAEnrolmentId = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->moduleAId, 'parent_enrolment_id' => $this->courseEnrolmentId]);

        $this->moduleBEnrolmentId = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->moduleBId, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->videoEnrolmentIdInModuleB = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->videoId, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $this->videoEnrolmentId = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->videoId, 'parent_enrolment_id' => 0]);
        $this->ltiB1EnrolmentIdInModuleB = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->ltiB1Id, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $this->ltiB1EnrolmentId = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->ltiB1Id, 'parent_enrolment_id' => 0]);
        $this->ltiB2EnrolmentIdInModuleB = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->ltiB2Id, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $this->ltiB2EnrolmentId = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->ltiB2Id, 'parent_enrolment_id' => 0]);

        $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liA1Id, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liA2Id, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liB1Id, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liB2Id, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);

        $this->link($go1, EdgeTypes::HAS_ORIGINAL_ENROLMENT, $this->videoEnrolmentIdInModuleB, $this->videoEnrolmentId);
        $this->link($go1, EdgeTypes::HAS_ORIGINAL_ENROLMENT, $this->ltiB1EnrolmentIdInModuleB, $this->ltiB1EnrolmentId);
        $this->link($go1, EdgeTypes::HAS_ORIGINAL_ENROLMENT, $this->ltiB1EnrolmentIdInModuleB, $this->ltiB2EnrolmentId);

        $this->ltiB1Registrations = ['registrationCompletion' => 'UNKNOWN', 'registrationCompletionAmount' => 1];
        $this->ltiB2Registrations = ['registrationCompletion' => 'UNKNOWN', 'registrationCompletionAmount' => 0];
    }

    public function testCase1()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $app->extend('client', function () use ($app) {
            $httpClient = $this
                ->getMockBuilder(Client::class)
                ->disableOriginalConstructor()
                ->setMethods(['get'])
                ->getMock();

            $httpClient
                ->expects($this->exactly(2))
                ->method('get')
                ->willReturnCallback(function (string $url, array $options) use ($app) {
                    if (strpos($url, "progress/$this->ltiB1EnrolmentIdInModuleB") !== false) {
                        return new Response(200, [], json_encode($this->ltiB1Registrations));
                    } elseif (strpos($url, "progress/$this->ltiB2EnrolmentIdInModuleB") !== false) {
                        return new Response(200, [], json_encode($this->ltiB2Registrations));
                    }
                    return  new Response(404, [], null);
                });

            return $httpClient;
        });

        $go1 = $app['dbs']['go1'];
        $repository = $app[EnrolmentRepository::class];
        $enrolment = EnrolmentHelper::load($go1, $this->courseEnrolmentId);
        $tree = $repository->loadEnrolmentTree($enrolment, 1);
        $this->assertObjectHasAttribute('due_date', $tree);
        $this->assertEquals(LoTypes::COURSE, $tree->lo_type);
        $this->assertEquals(false, isset($tree->registrations));
        $this->assertEquals($this->moduleAId, $tree->items[0]->lo_id);
        $this->assertObjectHasAttribute('due_date', $tree->items[0]);
        $this->assertEquals(LoTypes::MODULE, $tree->items[0]->lo_type);
        $this->assertEquals(false, isset($tree->items[0]->registrations));
        $this->assertCount(2, $tree->items[0]->items);
        $this->assertEquals(LiTypes::VIDEO, $tree->items[0]->items[0]->lo_type);
        $this->assertEquals(false, isset($tree->items[0]->items[0]->registrations));
        $this->assertEquals($this->moduleBId, $tree->items[1]->lo_id);
        $this->assertEquals(false, isset($tree->items[1]->registrations));
        $this->assertObjectHasAttribute('due_date', $tree->items[1]);
        $this->assertCount(5, $tree->items[1]->items);
        $this->assertEquals($this->videoEnrolmentId, $tree->items[1]->items[0]->original_enrolment_id);
        $this->assertEquals(LiTypes::VIDEO, $tree->items[1]->items[0]->lo_type);
        $this->assertEquals($this->videoId, $tree->items[1]->items[0]->lo_id);
        $this->assertEquals(false, isset($tree->items[1]->items[0]->registrations));
        $this->assertEquals(LiTypes::LTI, $tree->items[1]->items[1]->lo_type);
        $this->assertEquals($this->ltiB1Id, $tree->items[1]->items[1]->lo_id);
        $this->assertEquals((object) $this->ltiB1Registrations, $tree->items[1]->items[1]->registrations);
        $this->assertEquals(LiTypes::LTI, $tree->items[1]->items[2]->lo_type);
        $this->assertEquals($this->ltiB2Id, $tree->items[1]->items[2]->lo_id);
        $this->assertEquals((object) $this->ltiB2Registrations, $tree->items[1]->items[2]->registrations);
    }

    public function testCase2()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        //setup course, modules and lis
        $courseId = $this->createCourse($go1);

        $moduleAId = $this->createModule($go1);
        $videoId = $this->createVideo($go1, ['data' => ['single_li' => true]]);
        $ltiAId = $this->createLO($go1, ['type' => LiTypes::LTI,'title' => 'LTI course A', 'single_li' => true]);

        $moduleBId = $this->createModule($go1);
        $textId = $this->createLO($go1, ['type' => LiTypes::TEXT,'title' => 'Text course', 'single_li' => true]);
        $ltiBId = $this->createLO($go1, ['type' => LiTypes::LTI,'title' => 'LTI course B', 'single_li' => true]);
        $ltiCId = $this->createLO($go1, ['type' => LiTypes::LTI,'title' => 'LTI course C', 'single_li' => true]);

        $this->link($go1, EdgeTypes::HAS_MODULE, $courseId, $moduleAId);
        $this->link($go1, EdgeTypes::HAS_MODULE, $courseId, $moduleBId);
        $this->link($go1, EdgeTypes::HAS_LI, $moduleAId, $videoId);
        $this->link($go1, EdgeTypes::HAS_LI, $moduleAId, $ltiAId);
        $this->link($go1, EdgeTypes::HAS_LI, $moduleBId, $textId);
        $this->link($go1, EdgeTypes::HAS_LI, $moduleBId, $ltiBId);
        $this->link($go1, EdgeTypes::HAS_LI, $moduleBId, $ltiCId);

        //setup enrollments
        $courseEnrolmentId = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $courseId]);

        $moduleAEnrolmentId = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $moduleAId, 'parent_enrolment_id' => $courseEnrolmentId]);
        $videoEnrolmentIdInModuleA = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $videoId, 'parent_enrolment_id' => $moduleAEnrolmentId]);
        $this->ltiAEnrolmentIdInModuleA = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $ltiAId, 'parent_enrolment_id' => $moduleAEnrolmentId]);

        $moduleBEnrolmentId = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $moduleBId, 'parent_enrolment_id' => $courseEnrolmentId]);
        $textEnrolmentIdInModuleB = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $textId, 'parent_enrolment_id' => $moduleBEnrolmentId]);
        $this->ltiBEnrolmentIdInModuleB = $this->createEnrolment($go1, ['taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $ltiBId, 'parent_enrolment_id' => $moduleBEnrolmentId]);

        //setup LTI registrations mock data
        $this->ltiARegistrations = ['registrationCompletion' => 'UNKNOWN', 'registrationCompletionAmount' => 1];
        $this->ltiBRegistrations = ['registrationCompletion' => 'UNKNOWN', 'registrationCompletionAmount' => 0];

        $app->extend('client', function () use ($app) {
            $httpClient = $this
                ->getMockBuilder(Client::class)
                ->disableOriginalConstructor()
                ->setMethods(['get'])
                ->getMock();

            $httpClient
                ->expects($this->exactly(2))
                ->method('get')
                ->willReturnCallback(function (string $url, array $options) use ($app) {
                    if (strpos($url, "progress/$this->ltiAEnrolmentIdInModuleA") !== false) {
                        return new Response(200, [], json_encode($this->ltiARegistrations));
                    } elseif (strpos($url, "progress/$this->ltiBEnrolmentIdInModuleB") !== false) {
                        return new Response(200, [], json_encode($this->ltiBRegistrations));
                    }
                    return  new Response(404, [], null);
                });

            return $httpClient;
        });

        $repository = $app[EnrolmentRepository::class];
        $enrolment = EnrolmentHelper::load($go1, $courseEnrolmentId);
        $tree = $repository->loadEnrolmentTree($enrolment, 1);
        $this->assertEquals(LoTypes::COURSE, $tree->lo_type);
        $this->assertEquals(false, isset($tree->registrations));
        $this->assertCount(2, $tree->items);

        $this->assertEquals($moduleAId, $tree->items[0]->lo_id);
        $this->assertEquals(LoTypes::MODULE, $tree->items[0]->lo_type);
        $this->assertEquals(false, isset($tree->items[0]->registrations));
        $this->assertCount(2, $tree->items[0]->items);
        $this->assertEquals($videoId, $tree->items[0]->items[0]->lo_id);
        $this->assertEquals(LiTypes::VIDEO, $tree->items[0]->items[0]->lo_type);
        $this->assertEquals(false, isset($tree->items[0]->items[0]->registrations));
        $this->assertEquals($ltiAId, $tree->items[0]->items[1]->lo_id);
        $this->assertEquals(LiTypes::LTI, $tree->items[0]->items[1]->lo_type);
        $this->assertEquals((object) $this->ltiARegistrations, $tree->items[0]->items[1]->registrations);

        $this->assertEquals($moduleBId, $tree->items[1]->lo_id);
        $this->assertEquals(LoTypes::MODULE, $tree->items[1]->lo_type);
        $this->assertEquals(false, isset($tree->items[1]->registrations));
        $this->assertCount(2, $tree->items[1]->items);
        $this->assertEquals($textId, $tree->items[1]->items[0]->lo_id);
        $this->assertEquals(LiTypes::TEXT, $tree->items[1]->items[0]->lo_type);
        $this->assertEquals(false, isset($tree->items[1]->items[0]->registrations));
        $this->assertEquals($ltiBId, $tree->items[1]->items[1]->lo_id);
        $this->assertEquals(LiTypes::LTI, $tree->items[1]->items[1]->lo_type);
        $this->assertEquals((object) $this->ltiBRegistrations, $tree->items[1]->items[1]->registrations);
    }
}
