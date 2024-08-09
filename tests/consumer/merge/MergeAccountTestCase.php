<?php

namespace go1\enrolment\tests\consumer\merge;

use go1\app\DomainService;
use go1\enrolment\controller\staff\MergeAccountController;
use go1\enrolment\domain\etc\EnrolmentMergeAccount;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class MergeAccountTestCase extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use PlanMockTrait;

    protected bool $mockUserDomainHelper = false;

    protected $portalId;
    protected $courseId;
    protected $moduleIdA;
    protected $moduleIdB;
    protected $liIdA1;
    protected $liIdB1;
    protected $liIdA2;
    protected $profileId1 = 10000;
    protected $profileId2 = 20000;
    protected $fooUserId;
    protected $barUserId;

    protected $go1;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $this->go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($this->go1, ['title' => 'qa.mygo1.com']);

        $this->fooUserId = $this->createUser($this->go1, [
            'instance'   => $app['accounts_name'],
            'uuid'       => 'USER_UUID',
            'mail'       => 'foo@bar.baz',
            'profile_id' => $this->profileId1,
        ]);

        $this->barUserId = $this->createUser($this->go1, [
            'instance'   => $app['accounts_name'],
            'uuid'       => 'USER_UUID1',
            'mail'       => 'bar@bar.baz',
            'profile_id' => $this->profileId2,
        ]);

        $this->courseId = $this->createCourse($this->go1, ['instance_id' => $this->portalId]);
        $this->moduleIdA = $this->createModule($this->go1, ['instance_id' => $this->portalId, 'title' => 'Module A']);
        $this->moduleIdB = $this->createModule($this->go1, ['instance_id' => $this->portalId, 'title' => 'Module B']);
        $this->link($this->go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleIdA);
        $this->link($this->go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleIdB);

        $this->liIdA1 = $this->createVideo($this->go1, ['instance_id' => $this->portalId, 'title' => 'Video A1']);
        $this->liIdA2 = $this->createVideo($this->go1, ['instance_id' => $this->portalId, 'title' => 'Video A2']);
        $this->liIdB1 = $this->createVideo($this->go1, ['instance_id' => $this->portalId, 'title' => 'Video B1']);
        $this->link($this->go1, EdgeTypes::HAS_LI, $this->moduleIdA, $this->liIdA1);
        $this->link($this->go1, EdgeTypes::HAS_LI, $this->moduleIdA, $this->liIdA2);
        $this->link($this->go1, EdgeTypes::HAS_LI, $this->moduleIdB, $this->liIdB1);
    }

    protected function requestMergeAccount(DomainService $app)
    {
        $body = [
            'action'    => MergeAccountController::TASK,
            'from'      => 'foo@bar.baz',
            'to'        => 'bar@bar.baz',
            'portal_id' => $this->portalId,
        ];
        $this->prepareRequest($app, $body);
    }

    protected function requestMergeCourseEnrolment(DomainService $app, \stdClass $enrolment)
    {
        $body = (array) $enrolment;
        $body['action'] = EnrolmentMergeAccount::MERGE_ACCOUNT_ACTION_ENROLMENT;
        $this->prepareRequest($app, $body);
    }

    protected function requestMergeRevision(DomainService $app)
    {
        $body = [
            'action'    => EnrolmentMergeAccount::MERGE_ACCOUNT_ACTION_ENROLMENT_REVISION,
            'from'      => 'foo@bar.baz',
            'to'        => 'bar@bar.baz',
            'portal_id' => $this->portalId,
        ];
        $this->prepareRequest($app, $body);
    }

    protected function prepareRequest(DomainService $app, array $body)
    {
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, Request::METHOD_POST);
        $req->request->replace([
            'routingKey' => EnrolmentMergeAccount::DO_ETC_MERGE_ACCOUNT,
            'body'       => $body,
        ]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
    }

    protected function prepareEnrolment(int $profileId)
    {
        $userId = $profileId == $this->profileId1 ? $this->fooUserId : $this->barUserId;
        $courseEnrolmentId = $this->createEnrolment($this->go1, [
            'status'            => EnrolmentStatuses::IN_PROGRESS,
            'lo_id'             => $this->courseId,
            'taken_instance_id' => $this->portalId,
            'profile_id'        => $profileId,
            'user_id'           => $userId,
        ]);

        $moduleOpt = [
            'status'              => EnrolmentStatuses::IN_PROGRESS,
            'parent_lo_id'        => $this->courseId,
            'parent_enrolment_id' => $courseEnrolmentId,
            'taken_instance_id'   => $this->portalId,
            'profile_id'          => $profileId,
            'user_id'             => $userId,
        ];
        $moduleAEnrolmentId = $this->createEnrolment($this->go1, $moduleOpt + ['lo_id' => $this->moduleIdA]);
        $moduleBEnrolmentId = $this->createEnrolment($this->go1, $moduleOpt + ['lo_id' => $this->moduleIdB]);

        $liOpt = [
            'status'              => EnrolmentStatuses::IN_PROGRESS,
            'parent_lo_id'        => $this->moduleIdA,
            'parent_enrolment_id' => $moduleAEnrolmentId,
            'taken_instance_id'   => $this->portalId,
            'profile_id'          => $profileId,
            'user_id'             => $userId,
        ];
        $this->createEnrolment($this->go1, $liOpt + ['lo_id' => $this->liIdA1]);
        $this->createEnrolment($this->go1, $liOpt + ['lo_id' => $this->liIdA2]);

        $liOpt['parent_lo_id'] = $this->moduleIdB;
        $liOpt['parent_enrolment_id'] = $moduleBEnrolmentId;
        $this->createEnrolment($this->go1, $liOpt + ['lo_id' => $this->liIdB1]);
    }

    protected function preparePlan(int $profileId)
    {
        $userId = $profileId == $this->profileId1 ? $this->fooUserId : $this->barUserId;
        $planData = [
            'user_id' => $userId,
            'instance_id' => $this->portalId,
            'entity_id' => $this->courseId,
            'due_date' => strtotime('+1 day'),
        ];
        $this->createPlan($this->go1, $planData);
    }

    protected function prepareEnrolmentRevision(int $profileId, int $userId)
    {
        $this->prepareEnrolment($profileId);

        $cEnrolment = $this->getEnrolmentByLoId($this->courseId, $userId);
        $this->go1->delete('gc_enrolment', ['id' => $cEnrolment->id]);
        $this->createRevisionEnrolment($this->go1, $cEnrolment->jsonSerialize());
    }

    protected function getEnrolmentByLoId(int $loId, int $userId)
    {
        return EnrolmentHelper::findEnrolment($this->go1, $this->portalId, $userId, $loId);
    }
}
