<?php

namespace go1\enrolment\tests\update;

use DateTime;
use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\Constants;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime as DateTimeHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentUpdateStreamTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use UserMockTrait;

    private $portalName = 'qa.mygo1.com';
    private $portalId;
    private $enrolmentId;
    private $userId;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name']]);
        $this->createUser($go1, ['instance' => $this->portalName]);

        $this->enrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'lo_id' => $loId, 'taken_instance_id' => $this->portalId, 'status' => EnrolmentStatuses::IN_PROGRESS]);
    }

    public function test()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $endDate = new DateTime("+1 year");
        $startDate = new DateTime("+1 month");
        $req = Request::create(
            "/enrolment/{$this->enrolmentId}?jwt=" . UserHelper::ROOT_JWT,
            'PUT',
            [
                'status'    => EnrolmentStatuses::COMPLETED,
                'result'    => 99,
                'endDate'   => $endDate->format(DATE_ISO8601),
                'startDate' => $startDate->format(DATE_ISO8601),
            ]
        );
        $res = $app->handle($req);
        $repository = $app[EnrolmentRepository::class];
        $enrolment = $repository->load($this->enrolmentId);
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(1, $id = $enrolment->id);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];
        $stream = $go1->fetchAll('SELECT * FROM enrolment_stream WHERE portal_id = ? AND action = ?', [$this->portalId, 'update'])[0];
        $payload = json_decode($stream['payload'], true);
        $payload = array_map(function ($payload) {
            return ($payload['op'] === 'test') ? null : $payload;
        }, $payload);
        $payload = array_values(array_filter($payload));
        $this->assertEquals($this->portalId, $stream['portal_id']);
        $this->assertEquals($id, $stream['enrolment_id']);
        $this->assertEquals(1, $stream['actor_id']);
        $this->assertEquals('update', $stream['action']);
        $this->assertEquals($startDate->format(Constants::DATE_MYSQL), $payload[0]['value']);
        $this->assertEquals('replace', $payload[0]['op']);
        $this->assertEquals('/start_date', $payload[0]['path']);
        $this->assertEquals($endDate->format(Constants::DATE_MYSQL), $payload[1]['value']);
        $this->assertEquals('replace', $payload[1]['op']);
        $this->assertEquals('/end_date', $payload[1]['path']);
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $payload[2]['value']);
        $this->assertEquals('replace', $payload[2]['op']);
        $this->assertEquals('/status', $payload[2]['path']);
        $this->assertEquals(99, $payload[3]['value']);
        $this->assertEquals('replace', $payload[3]['op']);
        $this->assertEquals('/result', $payload[3]['path']);
    }
}
