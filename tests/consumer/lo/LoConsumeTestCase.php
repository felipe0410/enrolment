<?php

namespace go1\enrolment\tests\consumer\lo;

use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class LoConsumeTestCase extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    protected $portalId;
    protected $courseId;
    protected $moduleIdA;
    protected $moduleIdB;
    protected $liIdA1;
    protected $liIdA2;
    protected $liIdB1;
    protected $profileId = 10000;
    protected $userId;
    protected $courseEnrolmentId;
    protected $go1;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $this->go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($this->go1, ['title' => 'qa.mygo1.com']);

        $this->userId = $this->createUser($this->go1, [
            'instance'   => $app['accounts_name'],
            'uuid'       => 'USER_UUID',
            'mail'       => 'foo@bar.baz',
            'profile_id' => $this->profileId,
        ]);

        $this->courseId = $this->createCourse($this->go1, ['instance_id' => $this->portalId]);
        $this->courseEnrolmentId = $this->createEnrolment($this->go1, [
            'status'            => EnrolmentStatuses::IN_PROGRESS,
            'lo_id'             => $this->courseId,
            'taken_instance_id' => $this->portalId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
        ]);
        $this->moduleIdA = $this->createModule($this->go1, ['instance_id' => $this->portalId, 'title' => 'Module A']);
        $this->link($this->go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleIdA);
        $this->moduleIdB = $this->createModule($this->go1, ['instance_id' => $this->portalId, 'title' => 'Module B']);
        $this->link($this->go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleIdB);

        $this->liIdA1 = $this->createVideo($this->go1, ['instance_id' => $this->portalId, 'title' => 'Video A1']);
        $this->liIdA2 = $this->createVideo($this->go1, ['instance_id' => $this->portalId, 'title' => 'Video A2']);
        $this->liIdB1 = $this->createVideo($this->go1, ['instance_id' => $this->portalId, 'title' => 'Video B1']);
    }

    protected function handleRequest(DomainService $app)
    {
        $req = Request::create('/consume?jwt=' . UserHelper::ROOT_JWT, Request::METHOD_POST);
        $req->request->replace([
            'routingKey' => Queue::LO_UPDATE,
            'body'       => json_encode([
                'id'        => $this->courseId,
                'published' => 1,
                'type'      => 'course',
                'original'  => [
                    'id'        => $this->courseId,
                    'published' => 0,
                ],
            ]),
        ]);
        $res = $app->handle($req);

        $this->assertEquals(204, $res->getStatusCode());
    }

    protected function createModuleEnrolments($moduleId, $moduleStatus, $li1Id, $li1Status, $li2Id = 0, $li2Status = '')
    {
        $moduleEnrolmentId = $this->createEnrolment($this->go1, [
            'lo_id'               => $moduleId,
            'status'              => $moduleStatus,
            'parent_lo_id'        => $this->courseId,
            'parent_enrolment_id' => $this->courseEnrolmentId,
            'profile_id'          => $this->profileId,
            'taken_instance_id'   => $this->portalId,
            'user_id'             => $this->userId,
        ]);
        $options = [
            [
                'lo_id'               => $li1Id,
                'status'              => $li1Status,
                'parent_lo_id'        => $moduleId,
                'parent_enrolment_id' => $moduleEnrolmentId,
            ],
        ];
        if ($li2Status) {
            $options[] = [
                'lo_id'               => $li2Id,
                'status'              => $li2Status,
                'parent_lo_id'        => $moduleId,
                'parent_enrolment_id' => $moduleEnrolmentId,
            ];
        }

        foreach ($options as $option) {
            $this->createEnrolment($this->go1, $option + [
                    'profile_id'        => $this->profileId,
                    'taken_instance_id' => $this->portalId,
                    'user_id'           => $this->userId,
                ]);
        }
    }

    protected function createModuleAEnrolments($moduleAStatus, $LiAStatus, $LiBStatus = '')
    {
        $this->createModuleEnrolments($this->moduleIdA, $moduleAStatus, $this->liIdA1, $LiAStatus, $this->liIdA2, $LiBStatus);
    }

    protected function createModuleBEnrolments($moduleBStatus, $LiStatus)
    {
        $this->createModuleEnrolments($this->moduleIdB, $moduleBStatus, $this->liIdB1, $LiStatus);
    }

    protected function getEnrolmentByLoId(int $loId)
    {
        return EnrolmentHelper::findEnrolment($this->go1, $this->portalId, $this->userId, $loId);
    }
}
