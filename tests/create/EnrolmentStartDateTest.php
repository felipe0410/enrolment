<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\enrolment\Constants;
use go1\app\DomainService;
use go1\clients\LoClient;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime;
use go1\util\DB;
use go1\util\enrolment\EnrolmentHelper;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\queue\Queue;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\user\UserHelper;
use go1\util_dataset\generator\CoreDataGeneratorTrait;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentStartDateTest extends EnrolmentTestCase
{
    use CoreDataGeneratorTrait;
    use EnrolmentMockTrait;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $go1 = $app['dbs']['go1'];

        $this->generatePortalData($go1, $app['accounts_name']);
        $go1->executeQuery('DELETE FROM gc_enrolment WHERE 1'); // delete data auto generated by generator.

        $app->extend('go1.client.lo', function () use ($go1) {
            $client = $this
                ->getMockBuilder(LoClient::class)
                ->setMethods(['eventAvailableSeat'])
                ->disableOriginalConstructor()
                ->getMock();

            $client
                ->expects($this->any())
                ->method('eventAvailableSeat')
                ->willReturnCallback(function (int $eventId) {
                    return ($eventId == $this->eventUnderstandWebIn4HoursId) ? $this->eventUnderstandWebIn4HoursAvailableSeats : 0;
                });

            return $client;
        });

        $this->loAccessGrant($this->courseWebId, $this->userLearner1Id, $this->portalId, 2);
        $this->loAccessGrant($this->courseWebId, $this->userLearner2Id, $this->portalId, 2);
        $this->loAccessGrant($this->eventUnderstandWebIn4HoursLiId, $this->userLearner1Id, $this->portalId, 2);
        $this->loAccessGrant($this->eventUnderstandWebIn4HoursLiId, $this->userLearner2Id, $this->portalId, 2);
    }

    public function testCreate()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->courseWebId}/enrolment?jwt={$this->userLearner1JWT}", 'POST');
        $req->request->replace([
            'startDate' => $startDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
        ]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $enrolment = $this->queueMessages[Queue::ENROLMENT_CREATE][0];
        $this->assertEquals(DateTime::create($startDate)->format(DATE_ISO8601), $enrolment['start_date']);
    }

    public function testCreateNull()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->courseWebId}/enrolment?jwt={$this->userLearner1JWT}", 'POST');
        $req->request->replace([
            'startDate' => null,
        ]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $enrolment = $this->queueMessages[Queue::ENROLMENT_CREATE][0];
        $this->assertEqualsWithDelta(DateTime::create('now')->getTimestamp(), DateTime::create($enrolment['start_date'])->getTimestamp(), 5);
    }

    public function testCreateForStudent()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->courseWebId}/enrolment/{$this->userLearner1Mail}/in-progress?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'startDate' => $startDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(1, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $enrolment = $this->queueMessages[Queue::ENROLMENT_CREATE][0];
        $this->assertEquals(DateTime::create($startDate)->format(DATE_ISO8601), $enrolment['start_date']);
    }

    public function testCreateMultiple()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/enrolment?jwt={$this->userLearner2JWT}", 'POST');
        $req->request->replace([
            'items' => [
                ['loId' => $this->courseWebId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'startDate' => $startDate = (new \DateTime('+1 day'))->format(DATE_ISO8601)],
                ['loId' => $this->eventUnderstandWebIn4HoursLiId, 'status' => EnrolmentStatuses::IN_PROGRESS],
            ],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(2, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $this->assertEquals(DateTime::create($startDate)->format(DATE_ISO8601), $this->queueMessages[Queue::ENROLMENT_CREATE][0]['start_date']);
        $this->assertEqualsWithDelta(DateTime::create('now')->getTimestamp(), DateTime::create($this->queueMessages[Queue::ENROLMENT_CREATE][1]['start_date'])->getTimestamp(), 5);
    }

    public function testCreateMultipleNull()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/enrolment?jwt={$this->userLearner2JWT}", 'POST');
        $req->request->replace([
            'items' => [
                ['loId' => $this->courseWebId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'startDate' => $startDate = null],
                ['loId' => $this->eventUnderstandWebIn4HoursLiId, 'status' => EnrolmentStatuses::IN_PROGRESS, 'startDate' => $startDate = null],
            ],
        ]);

        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(2, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $this->assertEqualsWithDelta(DateTime::create('now')->getTimestamp(), DateTime::create($this->queueMessages[Queue::ENROLMENT_CREATE][0]['start_date'])->getTimestamp(), 5);
        $this->assertEqualsWithDelta(DateTime::create('now')->getTimestamp(), DateTime::create($this->queueMessages[Queue::ENROLMENT_CREATE][1]['start_date'])->getTimestamp(), 5);
    }

    public function testCreateMultipleEmpty()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/enrolment?jwt={$this->userLearner1JWT}", 'POST');
        $req->request->replace([
            'items' => [
                ['loId' => $this->courseWebId, 'status' => EnrolmentStatuses::IN_PROGRESS],
                ['loId' => $this->eventUnderstandWebIn4HoursLiId, 'status' => EnrolmentStatuses::IN_PROGRESS],
            ],
        ]);
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertCount(2, json_decode($res->getContent(), true));
        $this->assertCount(2, $this->queueMessages[Queue::ENROLMENT_CREATE]);
        $this->assertEqualsWithDelta(DateTime::create('now')->getTimestamp(), DateTime::create($this->queueMessages[Queue::ENROLMENT_CREATE][0]['start_date'])->getTimestamp(), 5);
        $this->assertEqualsWithDelta(DateTime::create('now')->getTimestamp(), DateTime::create($this->queueMessages[Queue::ENROLMENT_CREATE][1]['start_date'])->getTimestamp(), 5);
    }

    public function testFail()
    {
        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/0/{$this->courseWebId}/enrolment?jwt={$this->userLearner1JWT}", 'POST');
        $req->request->replace(['startDate' => 'foo']);
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('invalid or does not match format', $res->getContent());
    }

    public function testUpdateStartDateForStudent()
    {
        $app = $this->getApp();
        $this->createEnrolment($app['dbs']['go1'], ['lo_id' => $this->courseWebId, 'profile_id' => $this->userLearner1ProfileId]);
        $req = Request::create("/{$this->portalName}/0/{$this->courseWebId}/enrolment/{$this->userLearner1Mail}/in-progress?jwt=" . UserHelper::ROOT_JWT, 'POST');
        $req->request->replace([
            'startDate' => $startDate = (new \DateTime('+1 day'))->format(DATE_ISO8601),
        ]);

        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $enrolment = EnrolmentHelper::load($app['dbs']['go1'], json_decode($res->getContent())->id);
        $this->assertEquals(DateTime::create($startDate)->format(Constants::DATE_MYSQL), $enrolment->start_date);
    }
}