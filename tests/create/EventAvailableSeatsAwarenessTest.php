<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\clients\LoClient;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util_dataset\generator\CoreDataGeneratorTrait;
use Symfony\Component\HttpFoundation\Request;

class EventAvailableSeatsAwarenessTest extends EnrolmentTestCase
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
    }

    public function testOutOfSeatsEnrolment()
    {
        /** @var Connection $db */
        $this->eventUnderstandWebIn4HoursAvailableSeats = 2;
        $app = $this->getApp();
        $db = $app['dbs']['go1'];
        while (--$this->eventUnderstandWebIn4HoursAvailableSeats) {
            $profileId = rand(123, 456);
            $this->createEnrolment($db, ['lo_id' => $this->eventUnderstandWebIn4HoursLiId, 'profile_id' => $profileId]);
        }

        # Enrol by lo author
        $courseEnrolmentId = $this->createEnrolment($db, ['lo_id' => $this->courseWebId, 'user_id' => $this->userCourseAuthorId, 'profile_id' => $this->userCourseAuthorProfileId, 'taken_instance_id' => $this->portalId]);
        $req = "/{$this->portalName}/$this->courseWebId/{$this->eventUnderstandWebIn4HoursLiId}/enrolment/in-progress";
        $req = Request::create($req, 'POST');
        $req->query->replace(['jwt' => $this->userCourseAuthorJwt, 'parentEnrolmentId' => $courseEnrolmentId]);
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
    }

    public function testCreateMultipleEnrolments200()
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
    }

    public function testCreateMultipleEnrolments400()
    {
        $this->eventUnderstandWebIn4HoursAvailableSeats = 0;

        $app = $this->getApp();
        $req = Request::create("/{$this->portalName}/enrolment?jwt={$this->userLearner1JWT}", 'POST');
        $req->request->replace([
            'items' => [
                ['loId' => $this->courseWebId, 'status' => EnrolmentStatuses::IN_PROGRESS],
                ['loId' => $this->eventUnderstandWebIn4HoursLiId, 'status' => EnrolmentStatuses::IN_PROGRESS],
            ],
        ]);
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('No more available seat.', $res->getContent());
    }
}
