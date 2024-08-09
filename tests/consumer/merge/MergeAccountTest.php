<?php

namespace go1\enrolment\tests\consumer\merge;

use go1\app\DomainService;
use go1\enrolment\domain\etc\EnrolmentMergeAccount;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class MergeAccountTest extends MergeAccountTestCase
{
    public function testController()
    {
        $app = $this->getApp();

        $req = Request::create("/staff/merge/{$this->portalId}/foo@bar.baz/bar@bar.baz?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_POST);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());

        $req = Request::create("/staff/merge/{$this->portalId}/foo@bar.baz/bar@bar.baz", Request::METHOD_POST);
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());

        $req = Request::create("/staff/merge/{$this->portalId}/tesT@bar.baz/test@bar.baz?jwt=" . UserHelper::ROOT_JWT, Request::METHOD_POST);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
    }

    public function testMoveEnrolment(DomainService $app = null)
    {
        $app = $app ?? $this->getApp();

        $this->prepareEnrolment($this->profileId1);
        $this->requestMergeAccount($app);

        $messages = $this->queueMessages[EnrolmentMergeAccount::DO_ETC_MERGE_ACCOUNT];
        $this->assertCount(7, $messages);

        $this->assertEquals(EnrolmentMergeAccount::MERGE_ACCOUNT_ACTION_ENROLMENT, $messages[0]->action);
        $this->assertEquals($this->fooUserId, $messages[0]->user_id);

        $this->assertEquals(EnrolmentMergeAccount::MERGE_ACCOUNT_ACTION_ENROLMENT_REVISION, $messages[6]['action']);
        $this->assertEquals('foo@bar.baz', $messages[6]['from']);
        $this->assertEquals('bar@bar.baz', $messages[6]['to']);
        $this->assertEquals($this->portalId, $messages[6]['portal_id']);
    }

    /**
     * Archive the destination user's enrolment and plans as they are not the latest and update the source enrolment and plan user_id to the destination user
     */
    private function testMoveEnrolmentWithToEnrolmentDeleted(DomainService $app = null)
    {
        $app = $app ?? $this->getApp();
        $this->requestMergeAccount($app);

        $messages = $this->queueMessages[EnrolmentMergeAccount::DO_ETC_MERGE_ACCOUNT];
        $this->assertCount(13, $messages);

        $this->assertEquals(EnrolmentMergeAccount::MERGE_ACCOUNT_ACTION_ENROLMENT, $messages[0]->action);
        $this->assertEquals($this->barUserId, $messages[0]->user_id);

        $this->assertEquals(EnrolmentMergeAccount::MERGE_ACCOUNT_ACTION_ENROLMENT_REVISION, $messages[12]['action']);
        $this->assertEquals('foo@bar.baz', $messages[12]['from']);
        $this->assertEquals('bar@bar.baz', $messages[12]['to']);
        $this->assertEquals($this->portalId, $messages[12]['portal_id']);
    }

    /**
     * Change enrolment profile_id from profile1 to profile2
     */
    public function testUpdateCourseEnrolment()
    {
        $app = $this->getApp();

        $this->testMoveEnrolment($app);
        $message = $this->queueMessages[EnrolmentMergeAccount::DO_ETC_MERGE_ACCOUNT][0];
        $this->requestMergeCourseEnrolment($app, $message);

        $enrolment = $this->getEnrolmentByLoId($this->courseId, $this->barUserId);
        $this->assertEquals($enrolment->profileId, $this->profileId2);
        $this->assertEquals($enrolment->userId, $this->barUserId);

        $enrolment = $this->getEnrolmentByLoId($this->courseId, $this->fooUserId);
        $this->assertEmpty($enrolment);
    }

    /**
     * Archive the enrolment
     */
    public function testArchiveCourseEnrolment()
    {
        $app = $this->getApp();

        $this->prepareEnrolment($this->profileId2);
        $this->preparePlan($this->profileId1);
        $this->testMoveEnrolment($app);
        $fromEnrolment = $this->getEnrolmentByLoId($this->courseId, $this->fooUserId);
        $plans = $this->go1
            ->executeQuery('SELECT * FROM gc_plan WHERE user_id = ? AND instance_id = ? and entity_id = ?', [$fromEnrolment->userId, $fromEnrolment->takenPortalId, $fromEnrolment->loId])
            ->fetchAll(\PDO::FETCH_OBJ);
        $planId = $plans[0]->id;
        $message = $this->queueMessages[EnrolmentMergeAccount::DO_ETC_MERGE_ACCOUNT][0];
        $this->requestMergeCourseEnrolment($app, $message);

        $enrolment = $this->getEnrolmentByLoId($this->courseId, $this->barUserId);
        $this->assertEquals($enrolment->profileId, $this->profileId2);

        $revisions = $this->go1
            ->executeQuery('SELECT * FROM gc_plan_revision WHERE plan_id = ?', [$planId])
            ->fetchAll(\PDO::FETCH_OBJ);

        foreach ($revisions as $revision) {
            $this->assertEquals($fromEnrolment->userId, $revision->user_id);
        }

        $plans = $this->go1
            ->executeQuery('SELECT * FROM gc_plan WHERE id = ?', [$planId])
            ->fetchAll(\PDO::FETCH_OBJ);
        $this->assertEmpty($plans);

        $revisions = $this->go1
            ->executeQuery('SELECT * FROM gc_enrolment_revision')
            ->fetchAll(\PDO::FETCH_OBJ);

        foreach ($revisions as $revision) {
            $this->assertEquals($this->profileId1, $revision->profile_id);
        }

        $enrolment = $this->getEnrolmentByLoId($this->courseId, $this->profileId1);
        $this->assertEmpty($enrolment);
    }

    /**
     * Archive the destination user's enrolment and plans as they are not the latest and update the source enrolment and plan user_id to the destination user
     */
    public function testMergeTwoAccountEnrolmentsAndPlans()
    {
        $app = $this->getApp();

        $this->prepareEnrolment($this->profileId2);
        sleep(1);
        $this->prepareEnrolment($this->profileId1);
        $this->preparePlan($this->profileId1);
        $this->preparePlan($this->profileId2);
        $this->testMoveEnrolmentWithToEnrolmentDeleted($app);
        $fromEnrolment = $this->getEnrolmentByLoId($this->courseId, $this->fooUserId);
        $toEnrolment = $this->getEnrolmentByLoId($this->courseId, $this->barUserId);
        $plans = $this->go1
            ->executeQuery('SELECT * FROM gc_plan WHERE user_id = ? AND instance_id = ? and entity_id = ?', [$fromEnrolment->userId, $fromEnrolment->takenPortalId, $fromEnrolment->loId])
            ->fetchAll(\PDO::FETCH_OBJ);
        $fromEnrolmentPlanId = $plans[0]->id;
        $plans = $this->go1
            ->executeQuery('SELECT * FROM gc_plan WHERE user_id = ? AND instance_id = ? and entity_id = ?', [$toEnrolment->userId, $toEnrolment->takenPortalId, $toEnrolment->loId])
            ->fetchAll(\PDO::FETCH_OBJ);
        $toEnrolmentPlanId = $plans[0]->id;
        $message = $this->queueMessages[EnrolmentMergeAccount::DO_ETC_MERGE_ACCOUNT][0];
        $this->requestMergeCourseEnrolment($app, $message);

        $enrolment = $this->getEnrolmentByLoId($this->courseId, $this->fooUserId);
        $this->assertEquals($enrolment->profileId, $this->profileId1);

        {
            // To enrolment and plan will be deleted as they are getting created later
            $revisions = $this->go1
                ->executeQuery('SELECT * FROM gc_plan_revision WHERE plan_id = ?', [$toEnrolmentPlanId])
                ->fetchAll(\PDO::FETCH_OBJ);

            foreach ($revisions as $revision) {
                $this->assertEquals($toEnrolment->userId, $revision->user_id);
            }

            $plans = $this->go1
                ->executeQuery('SELECT * FROM gc_plan WHERE id = ?', [$toEnrolmentPlanId])
                ->fetchAll(\PDO::FETCH_OBJ);
            $this->assertEmpty($plans);
        }

        {
            // This one not need to be deleted only user_id need to be updated
            $plans = $this->go1
                ->executeQuery('SELECT * FROM gc_plan WHERE id = ?', [$fromEnrolmentPlanId])
                ->fetchAll(\PDO::FETCH_OBJ);
            $this->assertNotEmpty($plans);
            $this->assertEquals($plans[0]->user_id, $fromEnrolment->userId);
        }

        $revisions = $this->go1
            ->executeQuery('SELECT * FROM gc_enrolment_revision')
            ->fetchAll(\PDO::FETCH_OBJ);

        foreach ($revisions as $revision) {
            $this->assertEquals($this->profileId2, $revision->profile_id);
        }

        $enrolment = $this->getEnrolmentByLoId($this->courseId, $this->barUserId);
        $this->assertEmpty($enrolment);
    }

    /**
     * Change enrolment revision profile_id from profile1 to profile2
     */
    public function testUpdateEnrolmentRevision()
    {
        $app = $this->getApp();

        $this->prepareEnrolmentRevision($this->profileId1, $this->fooUserId);
        $this->requestMergeRevision($app);

        $revisions = $this->go1
            ->executeQuery('SELECT * FROM gc_enrolment_revision')
            ->fetchAll(\PDO::FETCH_OBJ);

        foreach ($revisions as $revision) {
            $this->assertEquals($this->barUserId, $revision->user_id);
        }
    }
}
