<?php

namespace go1\enrolment\tests\update;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\core\learning_record\attribute\EnrolmentAttributeRepository;
use go1\core\learning_record\attribute\EnrolmentAttributes;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LoTypes;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentUpdateAchievementTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use UserMockTrait;

    private $enrolmentId;
    private $required;
    private $achieved;
    private $userId;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        $app->handle(Request::create('/install?jwt=' . UserHelper::ROOT_JWT, 'POST'));

        $go1 = $app['dbs']['go1'];
        $portalId = $this->createPortal($go1, ['title' => 'qa.mygo1.com']);
        $loId = $this->createCourse($go1, ['instance_id' => $portalId, 'type' => LoTypes::ACHIEVEMENT]);
        $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name']]);
        $this->createUser($go1, ['instance' => 'qa.mygo1.com']);
        $this->enrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'lo_id' => $loId, 'taken_instance_id' => $portalId, 'status' => EnrolmentStatuses::IN_PROGRESS]);

        /** @var EnrolmentAttributeRepository $rAttribute */
        $rAttribute = $app[EnrolmentAttributeRepository::class];
        $rAttribute->create(EnrolmentAttributes::create(
            (object) [
            'enrolment_id' => $this->enrolmentId,
            'key'          => 6,
            'value'        => json_encode($this->required = ['goal_id' => 123, 'value' => 10])]
        ));
        $rAttribute->create(EnrolmentAttributes::create(
            (object) [
            'enrolment_id' => $this->enrolmentId,
            'key'          => 7,
            'value'        => json_encode($this->achieved = ['goal_id' => 123, 'value' => 9])]
        ));
    }

    public function test()
    {
        $app = $this->getApp();
        $req = Request::create(
            "/enrolment/{$this->enrolmentId}?jwt=" . UserHelper::ROOT_JWT,
            'PUT',
            [
                'status' => EnrolmentStatuses::COMPLETED,
                'result' => 99,
            ]
        );
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $this->assertEquals((object) $this->required, $this->queueMessages[Queue::ENROLMENT_UPDATE][0]['award']['required']);
        $this->assertEquals((object) $this->achieved, $this->queueMessages[Queue::ENROLMENT_UPDATE][0]['award']['achieved']);
    }
}
