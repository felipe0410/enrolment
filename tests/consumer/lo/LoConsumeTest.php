<?php

namespace go1\enrolment\tests\consumer\lo;

use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;

class LoConsumeTest extends LoConsumeTestCase
{
    public function testConsume()
    {
        $app = $this->getApp();

        $this->link($this->go1, EdgeTypes::HAS_LI, $this->moduleIdA, $this->liIdA2);

        $this->createModuleAEnrolments(
            EnrolmentStatuses::IN_PROGRESS,
            EnrolmentStatuses::IN_PROGRESS,
            EnrolmentStatuses::IN_PROGRESS
        );

        $this->handleRequest($app);

        $eModuleA = $this->getEnrolmentByLoId($this->moduleIdA);
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $eModuleA->status);

        $message = $this->queueMessages[Queue::DO_ENROLMENT][0];
        $this->assertEquals('li_link_change', $message['action']);
        $this->assertEquals($this->courseId, $message['id']);
    }
}
