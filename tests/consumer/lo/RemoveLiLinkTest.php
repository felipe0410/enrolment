<?php

namespace go1\enrolment\tests\consumer\lo;

use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;

class RemoveLiLinkTest extends LoConsumeTestCase
{
    private function assertResult(string $expected)
    {
        $eModuleA = $this->getEnrolmentByLoId($this->moduleIdA);
        $this->assertEquals($expected, $eModuleA->status);
        $this->assertTrue(!empty($this->queueMessages[Queue::ENROLMENT_REVISION_CREATE]));
        $this->assertTrue(!empty($this->queueMessages[Queue::ENROLMENT_DELETE]));
    }

    /**
     * ModuleA has LI1A and LI1B
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = in-progress
     * LI1B enrolment status = in-progress
     */
    public function testCase1()
    {
        $app = $this->getApp();

        $this->link($this->go1, EdgeTypes::HAS_LI, $this->moduleIdA, $this->liIdA2);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS);

        $this->handleRequest($app);

        $this->assertResult(EnrolmentStatuses::IN_PROGRESS);
    }

    /**
     * ModuleA has LI1A and LI1B
     * ModuleA enrolment status = in-progress
     * LI1A enrolment status = in-progress
     * LI1B enrolment status = completed
     */
    public function testCase2()
    {
        $app = $this->getApp();

        $this->link($this->go1, EdgeTypes::HAS_LI, $this->moduleIdA, $this->liIdA2);

        $this->createModuleAEnrolments(EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED);

        $this->handleRequest($app);

        $this->assertResult(EnrolmentStatuses::COMPLETED);
    }

    /**
     * ModuleA has LI1A and LI1B
     * ModuleA enrolment status = completed
     * LI1A enrolment status = completed
     * LI1B enrolment status = completed
     */
    public function testCase3()
    {
        $app = $this->getApp();

        $this->link($this->go1, EdgeTypes::HAS_LI, $this->moduleIdA, $this->liIdA2);

        $this->createModuleAEnrolments(EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED);

        $this->handleRequest($app);

        $this->assertResult(EnrolmentStatuses::COMPLETED);
    }
}
