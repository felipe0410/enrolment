<?php

namespace go1\enrolment\tests\load;

use DateInterval;
use DateTime;
use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;

class FindParentEnrolmentTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalId;
    private $userId;
    private $accountId;
    private $profileId = 999;
    private $courseId;
    private $moduleAId;
    private $moduleBId;
    private $liA1Id;
    private $liA2Id;
    private $liB1Id;
    private $liB2Id;
    private $courseEnrolmentId;
    private $moduleAEnrolmentId;
    private $moduleBEnrolmentId;
    private $liA1EnrolmentId;
    private $liA2EnrolmentId;
    private $liB1EnrolmentId;
    private $liB2EnrolmentId;

    private $courseEnrolmentStartDate;
    private $moduleAEnrolmentStartDate;
    private $moduleBEnrolmentStartDate;
    private $liA1EnrolmentStartDate;
    private $liA2EnrolmentStartDate;
    private $liB1EnrolmentStartDate;
    private $liB2EnrolmentStartDate;

    private Connection $go1;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $this->go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($this->go1, ['title' => 'qa.mygo1.com']);
        $this->userId = $this->createUser($this->go1, ['instance' => $app['accounts_name'], 'mail' => 'qa@student.com', 'profile_id' => $this->profileId]);
        $this->accountId = $this->createUser($this->go1, ['instance' => 'qa.mygo1.com', 'mail' => 'qa@student.com']);
        $this->link($this->go1, EdgeTypes::HAS_ACCOUNT, $this->userId, $this->accountId);

        # Setup course structure
        # ---------------------
        $this->courseId = $this->createCourse($this->go1);
        $this->moduleAId = $this->createModule($this->go1);
        $this->moduleBId = $this->createModule($this->go1);
        $this->liA1Id = $this->createVideo($this->go1);
        $this->liA2Id = $this->createVideo($this->go1);
        $this->liB1Id = $this->createVideo($this->go1);
        $this->liB2Id = $this->createVideo($this->go1);
        $this->link($this->go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleAId);
        $this->link($this->go1, EdgeTypes::HAS_ELECTIVE_LO, $this->courseId, $this->moduleBId);
        $this->link($this->go1, EdgeTypes::HAS_LI, $this->moduleAId, $this->liA1Id);
        $this->link($this->go1, EdgeTypes::HAS_ELECTIVE_LI, $this->moduleAId, $this->liA2Id);
        $this->link($this->go1, EdgeTypes::HAS_LI, $this->moduleBId, $this->liB1Id);
        $this->link($this->go1, EdgeTypes::HAS_ELECTIVE_LI, $this->moduleBId, $this->liB2Id);

        $this->courseEnrolmentStartDate = (new DateTime())->add(new DateInterval('PT1S'))->format(DATE_ISO8601);
        $this->moduleAEnrolmentStartDate = (new DateTime())->add(new DateInterval('PT2S'))->format(DATE_ISO8601);
        $this->moduleBEnrolmentStartDate = (new DateTime())->add(new DateInterval('PT3S'))->format(DATE_ISO8601);

        $this->liA1EnrolmentStartDate = (new DateTime())->add(new DateInterval('PT4S'))->format(DATE_ISO8601);
        $this->liA2EnrolmentStartDate = (new DateTime())->add(new DateInterval('PT5S'))->format(DATE_ISO8601);
        $this->liB1EnrolmentStartDate = (new DateTime())->add(new DateInterval('PT6S'))->format(DATE_ISO8601);
        $this->liB2EnrolmentStartDate = (new DateTime())->add(new DateInterval('PT7S'))->format(DATE_ISO8601);
    }

    public function createEnrolmentData()
    {
        $this->courseEnrolmentId = $this->createEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->courseId, 'user_id' => $this->userId,
            'start_date'        => $this->courseEnrolmentStartDate, 'parent_lo_id' => null]);
        $this->moduleAEnrolmentId = $this->createEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->moduleAId, 'user_id' => $this->userId,
            'start_date'        => $this->moduleAEnrolmentStartDate, 'parent_lo_id' => $this->courseId, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->moduleBEnrolmentId = $this->createEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->moduleBId, 'user_id' => $this->userId,
            'start_date'        => $this->moduleBEnrolmentStartDate, 'parent_lo_id' => $this->courseId, 'parent_enrolment_id' => $this->courseEnrolmentId]);
        $this->liA1EnrolmentId = $this->createEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liA1Id, 'user_id' => $this->userId,
            'start_date'        => $this->liA1EnrolmentStartDate, 'parent_lo_id' => $this->moduleAId, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->liA2EnrolmentId = $this->createEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liA2Id, 'user_id' => $this->userId,
            'start_date'        => $this->liA2EnrolmentStartDate, 'parent_lo_id' => $this->moduleAId, 'parent_enrolment_id' => $this->moduleAEnrolmentId]);
        $this->liB1EnrolmentId = $this->createEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liB1Id, 'user_id' => $this->userId,
            'start_date'        => $this->liB1EnrolmentStartDate, 'parent_lo_id' => $this->moduleBId, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
        $this->liB2EnrolmentId = $this->createEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'lo_id' => $this->liB2Id, 'user_id' => $this->userId,
            'start_date'        => $this->liB2EnrolmentStartDate, 'parent_lo_id' => $this->moduleBId, 'parent_enrolment_id' => $this->moduleBEnrolmentId]);
    }

    public function createEnrolmentRevisionData()
    {
        $this->createRevisionEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'start_date' => $this->courseEnrolmentStartDate, 'enrolment_id' => $this->courseEnrolmentId,
            'lo_id'             => $this->courseId, 'parent_enrolment_id' => null, 'parent_lo_id' => null, 'user_id' => $this->userId]);
        $this->createRevisionEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'start_date' => $this->moduleAEnrolmentStartDate, 'enrolment_id' => $this->moduleAEnrolmentId,
            'lo_id'             => $this->moduleAId, 'parent_enrolment_id' => $this->courseEnrolmentId, 'parent_lo_id' => $this->courseId, 'user_id' => $this->userId]);
        $this->createRevisionEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'start_date' => $this->moduleBEnrolmentStartDate, 'enrolment_id' => $this->moduleBEnrolmentId,
            'lo_id'             => $this->moduleBId, 'parent_enrolment_id' => $this->courseEnrolmentId, 'parent_lo_id' => $this->courseId, 'user_id' => $this->userId]);
        $this->createRevisionEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'start_date' => $this->liA1EnrolmentStartDate, 'enrolment_id' => $this->liA1EnrolmentId,
            'lo_id'             => $this->liA1Id, 'parent_enrolment_id' => $this->moduleAEnrolmentId, 'parent_lo_id' => $this->moduleAId, 'user_id' => $this->userId]);
        $this->createRevisionEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'start_date' => $this->liA2EnrolmentStartDate, 'enrolment_id' => $this->liA2EnrolmentId,
            'lo_id'             => $this->liA2Id, 'parent_enrolment_id' => $this->moduleAEnrolmentId, 'parent_lo_id' => $this->moduleAId, 'user_id' => $this->userId]);
        $this->createRevisionEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'start_date' => $this->liB1EnrolmentStartDate, 'enrolment_id' => $this->liB1EnrolmentId,
            'lo_id'             => $this->liB1Id, 'parent_enrolment_id' => $this->moduleBEnrolmentId, 'parent_lo_id' => $this->moduleBId, 'user_id' => $this->userId]);
        $this->createRevisionEnrolment($this->go1, [
            'taken_instance_id' => $this->portalId, 'profile_id' => $this->profileId, 'start_date' => $this->liB2EnrolmentStartDate, 'enrolment_id' => $this->liB2EnrolmentId,
            'lo_id'             => $this->liB2Id, 'parent_enrolment_id' => $this->moduleBEnrolmentId, 'parent_lo_id' => $this->moduleBId, 'user_id' => $this->userId]);
    }

    public function testFindParentEnrolmentId()
    {
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $this->createEnrolmentData();
        $this->createEnrolmentRevisionData();

        $courseEnrolment = EnrolmentHelper::loadSingle($this->go1, $this->courseEnrolmentId);
        $parentEnrolmentId = $repository->findParentEnrolmentId($courseEnrolment);
        $this->assertEquals($parentEnrolmentId, null);
        $courseEnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->courseEnrolmentId);
        $this->assertEquals($parentEnrolmentId, $courseEnrolmentRevision->parent_enrolment_id);

        $moduleAEnrolment = EnrolmentHelper::loadSingle($this->go1, $this->moduleAEnrolmentId);
        $parentEnrolmentId = $repository->findParentEnrolmentId($moduleAEnrolment);
        $this->assertEquals($parentEnrolmentId, $this->courseEnrolmentId);
        $moduleAEnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->moduleAEnrolmentId);
        $this->assertEquals($parentEnrolmentId, $moduleAEnrolmentRevision->parent_enrolment_id);

        $moduleBEnrolment = EnrolmentHelper::loadSingle($this->go1, $this->moduleBEnrolmentId);
        $parentEnrolmentId = $repository->findParentEnrolmentId($moduleBEnrolment);
        $this->assertEquals($parentEnrolmentId, $this->courseEnrolmentId);
        $moduleBEnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->moduleBEnrolmentId);
        $this->assertEquals($parentEnrolmentId, $moduleBEnrolmentRevision->parent_enrolment_id);

        $liA1Enrolment = EnrolmentHelper::loadSingle($this->go1, $this->liA1EnrolmentId);
        $parentEnrolmentId = $repository->findParentEnrolmentId($liA1Enrolment);
        $this->assertEquals($parentEnrolmentId, $this->moduleAEnrolmentId);
        $liA1EnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->liA1EnrolmentId);
        $this->assertEquals($parentEnrolmentId, $liA1EnrolmentRevision->parent_enrolment_id);

        $liA2Enrolment = EnrolmentHelper::loadSingle($this->go1, $this->liA2EnrolmentId);
        $parentEnrolmentId = $repository->findParentEnrolmentId($liA2Enrolment);
        $this->assertEquals($parentEnrolmentId, $this->moduleAEnrolmentId);
        $liA2EnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->liA2EnrolmentId);
        $this->assertEquals($parentEnrolmentId, $liA2EnrolmentRevision->parent_enrolment_id);

        $liB1Enrolment = EnrolmentHelper::loadSingle($this->go1, $this->liB1EnrolmentId);
        $parentEnrolmentId = $repository->findParentEnrolmentId($liB1Enrolment);
        $this->assertEquals($parentEnrolmentId, $this->moduleBEnrolmentId);
        $liB1EnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->liB1EnrolmentId);
        $this->assertEquals($parentEnrolmentId, $liB1EnrolmentRevision->parent_enrolment_id);

        $liB2Enrolment = EnrolmentHelper::loadSingle($this->go1, $this->liB2EnrolmentId);
        $parentEnrolmentId = $repository->findParentEnrolmentId($liB2Enrolment);
        $this->assertEquals($parentEnrolmentId, $this->moduleBEnrolmentId);
        $liB2EnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->liB2EnrolmentId);
        $this->assertEquals($parentEnrolmentId, $liB2EnrolmentRevision->parent_enrolment_id);
    }

    public function testArchiveCourse()
    {
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $this->createEnrolmentData();

        $courseEnrolment = EnrolmentHelper::loadSingle($this->go1, $this->courseEnrolmentId);
        $moduleAEnrolment = EnrolmentHelper::loadSingle($this->go1, $this->moduleAEnrolmentId);
        $moduleBEnrolment = EnrolmentHelper::loadSingle($this->go1, $this->moduleBEnrolmentId);
        $liA1Enrolment = EnrolmentHelper::loadSingle($this->go1, $this->liA1EnrolmentId);
        $liA2Enrolment = EnrolmentHelper::loadSingle($this->go1, $this->liA2EnrolmentId);
        $liB1Enrolment = EnrolmentHelper::loadSingle($this->go1, $this->liB1EnrolmentId);
        $liB2Enrolment = EnrolmentHelper::loadSingle($this->go1, $this->liB2EnrolmentId);

        $repository->deleteEnrolment($courseEnrolment, 0);
        $parentEnrolmentId = $repository->findParentEnrolmentId($courseEnrolment);
        $this->assertEquals($parentEnrolmentId, null);
        $courseEnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->courseEnrolmentId);
        $this->assertEquals($parentEnrolmentId, $courseEnrolmentRevision->parent_enrolment_id);

        $parentEnrolmentId = $repository->findParentEnrolmentId($moduleAEnrolment);
        $this->assertEquals($parentEnrolmentId, $this->courseEnrolmentId);
        $moduleAEnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->moduleAEnrolmentId);
        $this->assertEquals($this->courseEnrolmentId, $moduleAEnrolmentRevision->parent_enrolment_id);

        $parentEnrolmentId = $repository->findParentEnrolmentId($moduleBEnrolment);
        $this->assertEquals($parentEnrolmentId, $this->courseEnrolmentId);
        $moduleBEnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->moduleBEnrolmentId);
        $this->assertEquals($this->courseEnrolmentId, $moduleBEnrolmentRevision->parent_enrolment_id);

        $parentEnrolmentId = $repository->findParentEnrolmentId($liA1Enrolment);
        $this->assertEquals($parentEnrolmentId, $this->moduleAEnrolmentId);
        $liA1EnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->liA1EnrolmentId);
        $this->assertEquals($this->moduleAEnrolmentId, $liA1EnrolmentRevision->parent_enrolment_id);

        $parentEnrolmentId = $repository->findParentEnrolmentId($liA2Enrolment);
        $this->assertEquals($parentEnrolmentId, $this->moduleAEnrolmentId);
        $liA2EnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->liA2EnrolmentId);
        $this->assertEquals($this->moduleAEnrolmentId, $liA2EnrolmentRevision->parent_enrolment_id);

        $parentEnrolmentId = $repository->findParentEnrolmentId($liB1Enrolment);
        $this->assertEquals($parentEnrolmentId, $this->moduleBEnrolmentId);
        $liB1EnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->liB1EnrolmentId);
        $this->assertEquals($this->moduleBEnrolmentId, $liB1EnrolmentRevision->parent_enrolment_id);

        $parentEnrolmentId = $repository->findParentEnrolmentId($liB2Enrolment);
        $this->assertEquals($parentEnrolmentId, $this->moduleBEnrolmentId);
        $liB2EnrolmentRevision = EnrolmentHelper::loadRevision($this->go1, $this->liB2EnrolmentId);
        $this->assertEquals($this->moduleBEnrolmentId, $liB2EnrolmentRevision->parent_enrolment_id);
    }
}
