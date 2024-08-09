<?php

namespace go1\enrolment\tests\consumer\lo;

use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;

class UpdateLiLinksTest extends LoConsumeTestCase
{
    private function createLiLink(array $links)
    {
        foreach ($links as $parent => $los) {
            foreach ($los as $key => $lo) {
                $this->link($this->go1, EdgeTypes::HAS_LI, $parent, $lo, $key);
            }
        }
    }

    private function assertResult(array $expected)
    {
        $eLiA1 = $this->getEnrolmentByLoId($this->liIdA1);
        $this->assertEquals($this->moduleIdB, $eLiA1->parentLoId);

        $eModuleA = $this->getEnrolmentByLoId($this->moduleIdA);
        $this->assertEquals($expected['moduleA_enrolment_status'], $eModuleA->status);

        $eModuleB = $this->getEnrolmentByLoId($this->moduleIdB);
        $this->assertEquals($expected['moduleB_enrolment_status'], $eModuleB->status);
        $this->assertEquals($eModuleB->id, $eLiA1->parentEnrolmentId);
    }

    /**
     * ModuleA has LI1A and LI1B
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = in-progress
     * LI1B enrolment status = in-progress
     * ModuleB has nothing and no enrolment
     */
    public function testCase1()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdA => [$this->liIdA2],
            $this->moduleIdB => [$this->liIdA1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);
        $this->handleRequest($app);
        $this->assertResult([
            'moduleA_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
            'moduleB_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
        ]);
    }

    /**
     * ModuleA has LI1A and LI1B
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = not-started
     * LI1B enrolment status = in-progress
     * ModuleB has nothing and no enrolment
     */
    public function testCase2()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdA => [$this->liIdA2],
            $this->moduleIdB => [$this->liIdA1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::IN_PROGRESS);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
            'moduleB_enrolment_status' => EnrolmentStatuses::NOT_STARTED,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A and LI1B
     * ModuleA enrolment status = completed
     * LI1A enrolment status = completed
     * LI1B enrolment status = completed
     * ModuleB has nothing and no enrolment
     */
    public function testCase3()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdA => [$this->liIdA2],
            $this->moduleIdB => [$this->liIdA1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::COMPLETED,
            'moduleB_enrolment_status' => EnrolmentStatuses::COMPLETED,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A and LI1B
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = completed
     * LI1B enrolment status = in-progress
     * ModuleB has nothing and no enrolment
     */
    public function testCase4()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdA => [$this->liIdA2],
            $this->moduleIdB => [$this->liIdA1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED, EnrolmentStatuses::IN_PROGRESS);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
            'moduleB_enrolment_status' => EnrolmentStatuses::COMPLETED,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = in-progress
     * ModuleB has nothing and no enrolment
     */
    public function testCase5()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdB => [$this->liIdA1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
            'moduleB_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A
     * ModuleA enrolment status = not-started
     * LI1A enrolment status = not-started
     * ModuleB has nothing and no enrolment
     */
    public function testCase6()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdB => [$this->liIdA1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::NOT_STARTED);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::NOT_STARTED,
            'moduleB_enrolment_status' => EnrolmentStatuses::NOT_STARTED,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A
     * ModuleA enrolment status = completed
     * LI1A enrolment status = completed
     * ModuleB has nothing and no enrolment
     */
    public function testCase7()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdB => [$this->liIdA1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::COMPLETED,
            'moduleB_enrolment_status' => EnrolmentStatuses::COMPLETED,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A, LI1B
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = in-progress
     * LI1B enrolment status = in-progress
     * ModuleB has LI2A
     * ModuleB enrolment status = in-progress
     * LI2A enrolment status = in-progress
     */
    public function testCase8()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdA => [$this->liIdA2],
            $this->moduleIdB => [$this->liIdB1, $this->liIdA1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);
        $this->createModuleBEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
            'moduleB_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A, LI1B
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = in-progress
     * LI1B enrolment status = completed
     * ModuleB has LI2A
     * ModuleB enrolment status = in-progress
     * LI2A enrolment status = in-progress
     */
    public function testCase9()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdA => [$this->liIdA2],
            $this->moduleIdB => [$this->liIdB1, $this->liIdA1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED);
        $this->createModuleBEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::COMPLETED,
            'moduleB_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A, LI1B
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = in-progress
     * LI1B enrolment status = in-progress
     * ModuleB has LI2A
     * ModuleB enrolment status = completed
     * LI2A enrolment status = completed
     */
    public function testCase10()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdA => [$this->liIdA2],
            $this->moduleIdB => [$this->liIdB1, $this->liIdA1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);
        $this->createModuleBEnrolments(EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
            'moduleB_enrolment_status' => EnrolmentStatuses::COMPLETED,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = in-progress
     * ModuleB has LI2A
     * ModuleB enrolment status = in-progress
     * LI2A enrolment status = in-progress
     */
    public function testCase11()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdB => [$this->liIdA1, $this->liIdB1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);
        $this->createModuleBEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
            'moduleB_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A
     * ModuleA enrolment status = completed
     * LI1A enrolment status = completed
     * ModuleB has LI2A
     * ModuleB enrolment status = in-progress
     * LI2A enrolment status = in-progress
     */
    public function testCase12()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdB => [$this->liIdA1, $this->liIdB1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED);
        $this->createModuleBEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::COMPLETED,
            'moduleB_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = in-progress
     * ModuleB has LI2A
     * ModuleB enrolment status = completed
     * LI2A enrolment status = completed
     */
    public function testCase13()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdB => [$this->liIdA1, $this->liIdB1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);
        $this->createModuleBEnrolments(EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
            'moduleB_enrolment_status' => EnrolmentStatuses::COMPLETED,
        ];
        $this->assertResult($expected);
    }

    /**
     * ModuleA has LI1A and LI1B
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = completed
     * LI1B enrolment status = in-progress
     * ModuleB has LI2A and no enrollments
     */
    public function testCase14()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdA => [$this->liIdA2],
            $this->moduleIdB => [$this->liIdA1, $this->liIdB1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED, EnrolmentStatuses::IN_PROGRESS);

        $this->handleRequest($app);

        $expected = [
            'moduleA_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
            'moduleB_enrolment_status' => EnrolmentStatuses::IN_PROGRESS,
        ];
        $this->assertResult($expected);
    }

    public function testSingleLI()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdA => [$this->liIdA1, $this->liIdA2],
            $this->moduleIdB => [$this->liIdB1],
        ]);

        $this->createEnrolment($this->go1, [
            'lo_id'               => $this->liIdA1,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
            'parent_lo_id'        => 0,
            'parent_enrolment_id' => 0,
            'profile_id'          => $this->profileId,
            'user_id'             => $this->userId,
            'taken_instance_id'   => $this->portalId,
        ]);

        $this->handleRequest($app);

        $eLiA1 = $this->getEnrolmentByLoId($this->liIdA1);
        $this->assertEquals(0, $eLiA1->parentLoId);
        $this->assertEquals(0, $eLiA1->parentEnrolmentId);
        $this->assertEquals($this->liIdA1, $eLiA1->loId);
    }

    /**
     * ModuleA has liIdA2
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = in-progress
     * ModuleB has LI1A, liIdB1
     */
    public function testDuplicatedEnrolments()
    {
        $app = $this->getApp();

        $this->createLiLink([
            $this->moduleIdA => [$this->liIdA2],
            $this->moduleIdB => [$this->liIdA1, $this->liIdB1],
        ]);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);
        $this->createModuleBEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);
        $eA1InMA = $this->getEnrolmentByLoId($this->liIdA1);
        $eB = $this->getEnrolmentByLoId($this->moduleIdB);
        $this->createEnrolment($this->go1, [
            'lo_id'               => $this->liIdA1,
            'status'              => EnrolmentStatuses::IN_PROGRESS,
            'parent_lo_id'        => $this->moduleIdB,
            'parent_enrolment_id' => $eB->id,
            'profile_id'          => $this->profileId,
            'user_id'             => $this->userId,
            'taken_instance_id'   => $this->portalId,
        ]);

        $this->handleRequest($app);

        $eA1 = EnrolmentHelper::loadByLoAndUserId($this->go1, $this->liIdA1, $this->userId, $this->moduleIdA);
        $this->assertEmpty($eA1);

        $rLIA1 = EnrolmentHelper::loadRevision($this->go1, $eA1InMA->id);
        $this->assertEquals($this->moduleIdA, $rLIA1->parent_lo_id);

        $eA1InMB = EnrolmentHelper::findEnrolment($this->go1, $this->portalId, $this->userId, $this->liIdA1, $eB->id);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $eA1InMB->status);
    }
}
