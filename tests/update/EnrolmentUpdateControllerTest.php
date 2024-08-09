<?php

namespace go1\enrolment\tests\update;

use DateTime;
use Doctrine\DBAL\Connection;
use Firebase\JWT\JWT;
use go1\app\DomainService;
use go1\enrolment\content_learning\ErrorMessageCodes;
use go1\enrolment\controller\create\validator\EnrolmentCreateV3Validator;
use go1\enrolment\EnrolmentRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DateTime as DateTimeHelper;
use go1\util\edge\EdgeTypes;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\util\enrolment\EnrolmentStatuses;
use go1\util\lo\LiTypes;
use go1\util\queue\Queue;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\UserHelper;
use Prophecy\Argument;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EnrolmentUpdateControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;

    private $portalName = 'az.mygo1.com';
    private $altPortalName = 'az2.mygo1.com';
    private $portalId;
    private $altPortalId;
    private $adminJwt;
    private $altAdminJwt;
    private $remoteId   = 999;
    private $remoteId2  = 998;
    private $profileId  = 555;
    private $altProfileId  = 566;
    private $userId;
    private $altUserId;
    private $portalAccountId;
    private $adminAccountId;
    private $loId;
    private $loId2;
    private $enrolmentId;
    private $enrolmentId2;
    private $altEnrolmentId;
    private $enrolmentId3;

    protected function appInstall(DomainService $app)
    {
        /** @var Connection $go1 */
        parent::appInstall($app);

        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $this->altPortalId = $this->createPortal($go1, ['title' => $this->altPortalName]);
        $this->loId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId]);
        $this->loId2 = $this->createCourse($go1, ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId2]);
        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'profile_id' => $this->profileId]),
            $this->portalAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'user_id' => $this->userId])
        );
        $this->altUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'profile_id' => $this->altProfileId, 'mail' => 'admin2@foo.com']);
        $this->enrolmentId = $this->createEnrolment($go1, ['user_id' => $this->userId, 'lo_id' => $this->loId, 'profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'status' => EnrolmentStatuses::IN_PROGRESS]);
        $this->enrolmentId2 = $this->createEnrolment($go1, ['user_id' => $this->userId, 'lo_id' => $this->loId2, 'profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'status' => EnrolmentStatuses::IN_PROGRESS]);
        $this->altEnrolmentId = $this->createEnrolment($go1, ['user_id' => $this->altUserId, 'lo_id' => $this->loId, 'profile_id' => $this->altProfileId, 'taken_instance_id' => $this->altPortalId, 'status' => EnrolmentStatuses::IN_PROGRESS]);

        $adminUserId = $this->createUser($go1, ['profile_id' => $adminProfileId = 333, 'instance' => $app['accounts_name'], 'mail' => $adminMail = 'admin@foo.com']);
        $altAdminUserId = $this->createUser($go1, ['profile_id' => $altAdminProfileId = 344, 'instance' => $app['accounts_name'], 'mail' => $altAdminMail = 'admin3@foo.com']);
        $adminAccountId = $this->createUser($go1, ['profile_id' => $adminProfileId, 'instance' => $this->portalName, 'mail' => $adminMail, 'user_id' => $adminUserId]);
        $altAdminAccountId = $this->createUser($go1, ['profile_id' => $altAdminProfileId, 'instance' => $this->altPortalName, 'mail' => $altAdminMail]);
        $this->link($go1, EdgeTypes::HAS_ROLE, $adminAccountId, $this->createPortalAdminRole($go1, ['instance' => $this->portalName]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $adminUserId, $adminAccountId);
        $this->link($go1, EdgeTypes::HAS_ROLE, $altAdminAccountId, $this->createPortalAdminRole($go1, ['instance' => $this->altPortalName]));
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $altAdminUserId, $altAdminAccountId);
        $this->adminJwt = $this->jwtForUser($go1, $adminUserId, $this->portalName);
        $this->altAdminJwt = $this->jwtForUser($go1, $altAdminUserId, $this->altPortalName);
        $this->adminAccountId = $adminAccountId;
    }

    public function testInvalidJwt()
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->enrolmentId}", 'PUT');
        $req->attributes->set('jwt.payload', false);
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('Invalid or missing JWT.', json_decode($res->getContent())->message);
    }

    public function dataPut400()
    {
        return [
            ['bcd', EnrolmentStatuses::IN_PROGRESS, 99, 1, "The following 1 assertions failed:\n1) endDate: Date \"bcd\" is invalid or does not match format \"Y-m-d\TH:i:sO\".\n"],
            [(new DateTime())->format(DATE_ISO8601), 'progress', 99, 1, "The following 1 assertions failed:\n1) status: Value \"progress\" is not an element of the valid values: not-started, in-progress, pending, completed, expired\n"],
            [(new DateTime())->format(DATE_ISO8601), 'assigned', 99, 1, "The following 1 assertions failed:\n1) status: Value \"assigned\" is not an element of the valid values: not-started, in-progress, pending, completed, expired\n"],
            [(new DateTime())->format(DATE_ISO8601), EnrolmentStatuses::IN_PROGRESS, 'test numeric', 1, "The following 1 assertions failed:\n1) result: Value \"test numeric\" is not numeric.\n"],
        ];
    }

    /**
     * @dataProvider dataPut400
     */
    public function testPut400($endDate, $status, $result, $pass, $message)
    {
        $app = $this->getApp();
        $req = Request::create("/enrolment/{$this->enrolmentId}", 'PUT', [
            'endDate' => $endDate,
            'status'  => $status,
            'result'  => $result,
            'pass'    => $pass,
        ]);
        $req->attributes->set('jwt.payload', $this->getPayload(['profile_id' => $this->profileId, 'roles' => ['Admin on #Accounts']]));
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals($message, json_decode($res->getContent())->message);
    }

    public function testPatchSlimEnrollment200()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $req = Request::create(
            "/enrollments/{$this->enrolmentId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'status'    => EnrolmentStatuses::COMPLETED,
                'result'    => 28,
                'pass'      => 0,
                'end_date'   => DateTimeHelper::atom('+1 year', DATE_ATOM),
                'start_date' => DateTimeHelper::atom('+ 1 month', DATE_ATOM),
            ]
        );
        $res = $app->handle($req);
        $enrolment = $repository->load($this->enrolmentId);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals(28, $enrolment->result);
    }

    public function testPatchSlimEnrollment200WithISO8601Format()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $req = Request::create(
            "/enrollments/{$this->enrolmentId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'status'    => EnrolmentStatuses::COMPLETED,
                'result'    => 28,
                'pass'      => 0,
                'end_date'   => '2022-01-18T12:12:00Z',
                'start_date' => '2022-01-16T12:12:00z',
            ]
        );
        $res = $app->handle($req);
        $enrolment = $repository->load($this->enrolmentId);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals(28, $enrolment->result);
    }

    public function testPatchSlimEnrollment200WithEmptyPayload()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $enrolmentXId = $this->createEnrolment($go1, [
            'lo_id'             => $loId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::NOT_STARTED,
            'start_date'        => null,
        ]);
        $req = Request::create(
            "/enrollments/{$enrolmentXId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'status'    => EnrolmentStatuses::COMPLETED,
                'result'    => 28,
                'pass'      => 1,
                'start_date' => '2022-01-18T12:12:00+00:00',
                'end_date'  => '2022-01-18T12:12:00Z'
            ]
        );
        $app->handle($req);
        $req = Request::create(
            "/enrollments/{$enrolmentXId}?jwt={$this->adminJwt}",
            'PATCH',
            []
        );
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $responseData = json_decode($res->getContent());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $responseData->status);
        $this->assertEquals(28, $responseData->result);
        $this->assertEquals(true, $responseData->pass);
        $this->assertEquals($responseData->end_date, '2022-01-18T12:12:00+00:00');
        $this->assertEquals($responseData->status, EnrolmentStatuses::COMPLETED);
        $this->assertNotEmpty($responseData->start_date);
        $this->assertNotEmpty($responseData->lo_id);
        $this->assertNotEmpty($responseData->user_account_id);
        $this->assertNotEmpty($responseData->id);
        $this->assertTrue(!isset($responseData->due_date));
    }

    public function testPatchSlimEnrollment200WithCompleteStatusButNoEndDate()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $req = Request::create(
            "/enrollments/{$this->enrolmentId2}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'status'    => EnrolmentStatuses::COMPLETED,
            ]
        );
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertNotNull($req->attributes->get('BeamMiddleware'));
        $responseData = json_decode($res->getContent());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $responseData->status);
        $this->assertNotEmpty($responseData->end_date);
        $this->assertEquals($responseData->status, EnrolmentStatuses::COMPLETED);
        $this->assertNotEmpty($responseData->start_date);
        $this->assertNotEmpty($responseData->lo_id);
        $this->assertNotEmpty($responseData->user_account_id);
        $this->assertNotEmpty($responseData->id);
    }

    public function testPatchV3Enrollment400WithInvalidEnrollmentType()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $req = Request::create(
            "/enrollments/{$this->enrolmentId2}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type' => 'test1',
                'assign_date'     => 'test2'
            ]
        );
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
        $message = json_decode($res->getContent(), true);
        $this->assertEquals('Value "test1" is not an element of the valid values: self-directed, assigned', $message['additional_errors'][0]['message']);
        $this->assertEquals(1, count($message['additional_errors']));
    }

    public function testPatchV3Enrollment400WithInvalidDates()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $req = Request::create(
            "/enrollments/{$this->enrolmentId2}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type' => 'assigned',
                'assign_date'     => 'test2',
                'due_date'        => 'test3',
                'assigner_account_id' => 'terd'
            ]
        );
        $res = $app->handle($req);
        $message = json_decode($res->getContent(), true);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('Value "terd" is not numeric.', $message['additional_errors'][0]['message']);
        $this->assertEquals('Date "test2" is invalid or does not match format "Y-m-d\TH:i:sP".', $message['additional_errors'][1]['message']);
        $this->assertEquals('Date "test3" is invalid or does not match format "Y-m-d\TH:i:sP".', $message['additional_errors'][2]['message']);
    }

    public function testPatchV3Enrollment400WithNotProvidedAssignType()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $req = Request::create(
            "/enrollments/{$this->enrolmentId2}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'due_date'        => '2022-01-17T13:12:00+00:00'
            ]
        );
        $res = $app->handle($req);
        $message = json_decode($res->getContent(), true);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('enrollment_missing_enrollment_type', $message['error_code']);
    }

    public function testPatchV3Enrollment400WithInvalidAssignDate()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $req = Request::create(
            "/enrollments/{$this->enrolmentId2}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type' => 'assigned',
                'assign_date'     => '2022-01-18T13:12:00Z',
                'due_date'        => '2022-01-17T13:12:00+00:00'
            ]
        );
        $res = $app->handle($req);
        $message = json_decode($res->getContent(), true);
        $this->assertEquals(400, $res->getStatusCode());
        $this->assertEquals('enrollment_assign_time_later_than_due_date_not_allowed', $message['error_code']);
    }

    public function testPatchV3Enrollment200WithAssignedType()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $enrollmentType = EnrolmentOriginalTypes::ASSIGNED;
        $dueDate = '2022-01-17T13:12:00+00:00';

        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $enrolmentXId = $this->createEnrolment($go1, [
            'lo_id'             => $loId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::NOT_STARTED,
            'start_date'        => null,
        ]);

        $req = Request::create(
            "/enrollments/{$enrolmentXId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type'     => $enrollmentType,
                'assign_date'         => '2022-01-16T13:12:00Z',
                'due_date'            => $dueDate,
                'assigner_account_id' => $this->portalAccountId
            ]
        );

        $assigner = new \stdClass();
        $assigner->id = $this->userId;
        $enrolmentCreateV3Validator = $this->prophesize(EnrolmentCreateV3Validator::class);
        $enrolmentCreateV3Validator
            ->getAssigner($req, Argument::any(), $this->portalAccountId)
            ->willReturn($assigner);
        $enrolmentCreateV3Validator
            ->validateDate(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any())
            ->willReturn([1,2]);
        $enrolmentCreateV3Validator
            ->validateAssignerPermission(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any());
        $app->extend(EnrolmentCreateV3Validator::class, fn () => $enrolmentCreateV3Validator->reveal());

        $res = $app->handle($req);
        $response = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals('2022-01-16T13:12:00+00:00', $response->assign_date);
        $this->assertEquals($enrollmentType, $response->enrollment_type);
        $this->assertEquals($dueDate, $response->due_date);
        $this->assertEquals($this->portalAccountId, $response->assigner_account_id);
    }

    public function testPatchV3Enrollment200WithAssignedTypeNoAssigner()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $enrollmentType = EnrolmentOriginalTypes::ASSIGNED;
        $dueDate = '2022-01-17T13:12:00+00:00';
        $assignDate = '2022-01-16T13:12:00+00:00';

        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $this->enrolmentId3 = $this->createEnrolment($go1, [
            'lo_id'             => $loId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::NOT_STARTED,
            'start_date'        => null,
        ]);

        $req = Request::create(
            "/enrollments/{$this->enrolmentId3}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type'     => $enrollmentType,
                'assign_date'         => '2022-01-16T13:12:00Z',
                'due_date'            => $dueDate
            ]
        );

        $assigner = new \stdClass();
        $assigner->id = $this->userId;
        $enrolmentCreateV3Validator = $this->prophesize(EnrolmentCreateV3Validator::class);
        $enrolmentCreateV3Validator
            ->getAssigner(Argument::any(), Argument::any(), Argument::any())
            ->willReturn($assigner);
        $enrolmentCreateV3Validator
            ->validateDate(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any())
            ->willReturn([1,2]);
        $enrolmentCreateV3Validator
            ->validateAssignerPermission(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any());
        $app->extend(EnrolmentCreateV3Validator::class, fn () => $enrolmentCreateV3Validator->reveal());

        $res = $app->handle($req);
        $response = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals($assignDate, $response->assign_date);
        $this->assertEquals($enrollmentType, $response->enrollment_type);
        $this->assertEquals($dueDate, $response->due_date);
        $this->assertEquals($this->portalAccountId, $response->assigner_account_id);

        {
            // passing only enrolment type again and return same data

            $enrollmentType = EnrolmentOriginalTypes::ASSIGNED;
            $req = Request::create(
                "/enrollments/{$this->enrolmentId3}?jwt={$this->adminJwt}",
                'PATCH',
                [
                    'enrollment_type'     => $enrollmentType
                ]
            );

            $res = $app->handle($req);
            $response = json_decode($res->getContent());
            $this->assertEquals(200, $res->getStatusCode());
            $this->assertEquals($assignDate, $response->assign_date);
            $this->assertEquals($enrollmentType, $response->enrollment_type);
            $this->assertEquals($dueDate, $response->due_date);
            $this->assertEquals($this->portalAccountId, $response->assigner_account_id);
        }

        {
            // passing only enrolment type again and return same data

            $enrollmentType = EnrolmentOriginalTypes::SELF_DIRECTED;
            $req = Request::create(
                "/enrollments/{$this->enrolmentId3}?jwt={$this->adminJwt}",
                'PATCH',
                [
                    'enrollment_type'     => $enrollmentType
                ]
            );

            $res = $app->handle($req);
            $response = json_decode($res->getContent());
            $this->assertEquals(400, $res->getStatusCode());
            $this->assertEquals($response->error_code, 'enrollment_not_allowed_to_change_enrollment_type_to_self_directed');
        }

        {
            // passing wrong enrollment id

            $enrollmentType = EnrolmentOriginalTypes::SELF_DIRECTED;
            $req = Request::create(
                "/enrollments/123345?jwt={$this->adminJwt}",
                'PATCH',
                [
                    'enrollment_type'     => $enrollmentType
                ]
            );

            $res = $app->handle($req);
            $response = json_decode($res->getContent());
            $this->assertEquals(404, $res->getStatusCode());
            $this->assertEquals($response->error_code, 'enrollment_enrollment_not_found');
        }
    }

    public function testPatchV3Enrollment200WithAssignedTypeNoAssignerNoAssignDate()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $enrollmentType = 'assigned';
        $dueDate = '2022-01-17T13:12:00+00:00';

        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $enrolmentXId = $this->createEnrolment($go1, [
            'lo_id'             => $loId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::NOT_STARTED,
            'start_date'        => null,
        ]);

        $req = Request::create(
            "/enrollments/{$enrolmentXId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type'     => $enrollmentType,
                'due_date'            => $dueDate
            ]
        );

        $assigner = new \stdClass();
        $assigner->id = $this->userId;
        $enrolmentCreateV3Validator = $this->prophesize(EnrolmentCreateV3Validator::class);
        $enrolmentCreateV3Validator
            ->getAssigner($req, Argument::any(), Argument::any())
            ->willReturn($assigner);
        $enrolmentCreateV3Validator
            ->validateDate(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any())
            ->willReturn([1,2]);
        $enrolmentCreateV3Validator
            ->validateAssignerPermission(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any());
        $app->extend(EnrolmentCreateV3Validator::class, fn () => $enrolmentCreateV3Validator->reveal());

        $res = $app->handle($req);
        $response = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertNotEmpty($response->assign_date);
        $this->assertEquals($enrollmentType, $response->enrollment_type);
        $this->assertEquals($dueDate, $response->due_date);
        $this->assertEquals($this->portalAccountId, $response->assigner_account_id);
    }


    public function testPatchV3Enrollment200WithAssignedTypeAssignerIdDoesNotGetChanged()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $enrollmentType = 'assigned';
        $dueDate = '2022-01-17T13:12:00+00:00';
        $assignDate = '2022-01-15T13:12:00+00:00';

        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $enrolmentXId = $this->createEnrolment($go1, [
            'lo_id'             => $loId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::NOT_STARTED,
            'start_date'        => null,
        ]);

        $req = Request::create(
            "/enrollments/{$enrolmentXId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type'     => $enrollmentType,
                'due_date'            => $dueDate,
                'assign_date'         => $assignDate,
                'assigner_account_id' => $this->portalAccountId
            ]
        );

        $assigner = new \stdClass();
        $assigner->id = $this->userId;
        $enrolmentCreateV3Validator = $this->prophesize(EnrolmentCreateV3Validator::class);
        $enrolmentCreateV3Validator
            ->getAssigner($req, Argument::any(), Argument::any())
            ->willReturn($assigner);
        $enrolmentCreateV3Validator
            ->validateDate(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any())
            ->willReturn([1,2]);
        $enrolmentCreateV3Validator
            ->validateAssignerPermission(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any());
        $app->extend(EnrolmentCreateV3Validator::class, fn () => $enrolmentCreateV3Validator->reveal());

        $res = $app->handle($req);
        $response = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertNotEmpty($response->assign_date);
        $this->assertEquals($enrollmentType, $response->enrollment_type);
        $this->assertEquals($dueDate, $response->due_date);
        $this->assertEquals($this->portalAccountId, $response->assigner_account_id);

        $req = Request::create(
            "/enrollments/{$enrolmentXId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type'     => $enrollmentType,
                'due_date'            => $dueDate,
                'assign_date'         => $assignDate
            ]
        );
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals($dueDate, $response->due_date);
        $this->assertEquals($assignDate, $response->assign_date);
        // Assigner should not be changed to default adminAccountId.
        $this->assertNotEquals($this->adminAccountId, $response->assigner_account_id);
    }

    public function testPatchV3Enrollment200WithAssignedTypeOnly()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $enrolmentXId = $this->createEnrolment($go1, [
            'lo_id'             => $loId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::NOT_STARTED,
            'start_date'        => null,
        ]);

        $enrollmentType = 'assigned';

        $req = Request::create(
            "/enrollments/{$enrolmentXId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type'     => $enrollmentType
            ]
        );

        $assigner = new \stdClass();
        $assigner->id = '1';
        $enrolmentCreateV3Validator = $this->prophesize(EnrolmentCreateV3Validator::class);
        $enrolmentCreateV3Validator
            ->getAssigner(Argument::any(), Argument::any(), Argument::any())
            ->willReturn($assigner);
        $enrolmentCreateV3Validator
            ->validateDate(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any())
            ->willReturn([1,2]);
        $enrolmentCreateV3Validator
            ->validateAssignerPermission(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any());
        $app->extend(EnrolmentCreateV3Validator::class, fn () => $enrolmentCreateV3Validator->reveal());

        $res = $app->handle($req);
        $response = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals($enrollmentType, $response->enrollment_type);
        $this->assertNotEmpty($response->assign_date);
        $this->assertTrue(!isset($response->due_date));
        $this->assertEquals($this->portalAccountId, $response->assigner_account_id);
    }

    public function testPatchSlimEnrollment403()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $req = Request::create(
            "/enrollments/{$this->altEnrolmentId}?jwt={$this->altAdminJwt}",
            'PATCH',
            [
                'status'    => EnrolmentStatuses::COMPLETED,
                'result'    => 28,
                'pass'      => 0,
                'end_date'   => DateTimeHelper::atom('+1 year', DATE_ATOM),
                'start_date' => DateTimeHelper::atom('+ 1 month', DATE_ATOM),
            ]
        );
        $res = $app->handle($req);
        $repository->load($this->enrolmentId);
        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testPatchSlimEnrollment403NoAssignerFound()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $req = Request::create(
            "/enrollments/{$this->altEnrolmentId}?jwt={$this->altAdminJwt}",
            'PATCH',
            [
                'enrollment_type' => 'assigned',
                'assigner_account_id' => '8888888',
            ]
        );
        $res = $app->handle($req);
        $repository->load($this->enrolmentId);
        $this->assertEquals('USER_ACCOUNT_NOT_FOUND', json_decode($res->getContent())->error_code);
    }


    public function testPatchNotUpdateCreatedTime()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $enrolment = $repository->load($this->enrolmentId);
        sleep(1);
        $req = Request::create(
            "/enrollments/{$this->enrolmentId}?jwt={$this->adminJwt}",
            'PATCH',
            []
        );
        $res = $app->handle($req);
        $enrolment2 = $repository->load($this->enrolmentId);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals($enrolment->timestamp, $enrolment2->timestamp);
    }

    public function testValidateStatusChangePermissionFail()
    {
        $app = $this->getApp();
        $req = Request::create(
            "/enrollments/{$this->enrolmentId}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'status'    => EnrolmentStatuses::NOT_STARTED,
                'result'    => '28',
                'pass'      => '0',
                'endDate'   => DateTimeHelper::atom('+1 year', DATE_ISO8601),
                'startDate' => DateTimeHelper::atom('+ 1 month', DATE_ISO8601),
            ]
        );

        $res = $app->handle($req);
        $this->assertEquals('Permission denied. Enrollment status can not be updated to a previous status.', json_decode($res->getContent())->message);
    }

    public function testPut204()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        $time = (new DateTime())->format(DATE_ISO8601);
        $req = Request::create(
            "/enrolment/{$this->enrolmentId}?jwt={$this->adminJwt}",
            'PUT',
            [
                'status'    => EnrolmentStatuses::COMPLETED,
                'result'    => 28,
                'pass'      => 0,
                'endDate'   => $endDate = DateTimeHelper::atom('+1 year', DATE_ISO8601),
                'startDate' => $startDate = DateTimeHelper::atom('+ 1 month', DATE_ISO8601),
                'note'      => 'Manual completed!',
            ]
        );
        $res = $app->handle($req);
        $enrolment = $enrolment = $repository->load($this->enrolmentId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
        $this->assertEquals(28, $enrolment->result);
        $this->assertEquals($endDate, $enrolment->end_date);
        $this->assertTrue(DateTimeHelper::atom('now', DATE_ISO8601) >= $enrolment->start_date);
        $this->assertEquals(true, $enrolment->changed >= $time);
        $this->assertEquals(true, $enrolment->timestamp >= $this->timestamp);

        $revisions = $repository->loadRevisions($this->enrolmentId, 0, 1);
        $this->assertEquals($revisions[0]->note, 'Manual completed!');
        $this->assertEquals($revisions[0]->user_id, $enrolment->user_id);
    }

    public function dataPut204Admin()
    {
        return [
            [EnrolmentStatuses::NOT_STARTED],
            [EnrolmentStatuses::PENDING],
            [EnrolmentStatuses::COMPLETED],
        ];
    }

    /** @dataProvider dataPut204Admin */
    public function testPut204Admin($enrolmentStatus)
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create(
            "/enrolment/{$this->enrolmentId}?jwt={$this->adminJwt}",
            'PUT',
            [
                'status' => $enrolmentStatus,
                'note'   => 'Manual completed!',
            ]
        );
        $res = $app->handle($req);
        $enrolment = $enrolment = $repository->load($this->enrolmentId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($enrolmentStatus, $enrolment->status);
    }

    public function testPut204WithEnrolmentDuration()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];

        $req = Request::create(
            "/enrolment/{$this->enrolmentId}?jwt=" . $this->adminJwt,
            'PUT',
            [
                'status'   => 'completed',
                'result'   => 99,
                'pass'     => 1,
                'endDate'  => '2016-12-29T02:01:33+0700',
                'duration' => $durationTime = 33,
                'note'     => 'Manual completed with duration data!',
            ]
        );
        $res = $app->handle($req);
        $enrolment = $enrolment = $repository->load($this->enrolmentId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($this->enrolmentId, $enrolment->id);
        $this->assertEquals($durationTime, $enrolment->data->duration);
    }

    /**
     * @runInSeparateProcess
     */
    public function testPut204EnrolmentDateToInProgress()
    {
        /**
         * @var EnrolmentRepository $repository
         * @var Connection          $go1
         */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $userId = $this->createUser($go1, ['mail' => 'x@x.com', 'instance' => $app['accounts_name'], 'profile_id' => 123]),
            $this->createUser($go1, ['mail' => 'x@x.com', 'instance' => $this->portalName])
        );

        $enrolmentXId = $this->createEnrolment($go1, [
            'lo_id'             => $this->loId,
            'profile_id'        => 123,
            'user_id'           => $userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::NOT_STARTED,
            'start_date'        => null,
        ]);

        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $userId = $this->createUser($go1, ['mail' => 'y@y.com', 'instance' => $app['accounts_name'], 'profile_id' => 456]),
            $this->createUser($go1, ['mail' => 'y@y.com', 'instance' => $this->portalName])
        );

        $enrolmentYId = $this->createEnrolment($go1, [
            'lo_id'             => $this->loId,
            'profile_id'        => 456,
            'user_id'           => $userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::COMPLETED,
            'start_date'        => ($startDateY = DateTimeHelper::atom('-1 week', DATE_ISO8601)),
            'end_date'          => ($endDateY = DateTimeHelper::atom('-1 day', DATE_ISO8601)),
        ]);

        $req = Request::create("/enrolment/{$enrolmentXId}?jwt=" . UserHelper::ROOT_JWT, 'PUT', ['status' => EnrolmentStatuses::IN_PROGRESS]);
        $res = $app->handle($req);
        $repository = $app[EnrolmentRepository::class];
        $enrolmentX = $enrolmentX = $repository->load($enrolmentXId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $enrolmentX->status);
        $this->assertEquals(0, $enrolmentX->result);
        $this->assertEquals(0, $enrolmentX->pass);
        $this->assertTrue(DateTimeHelper::atom('now', DATE_ISO8601) >= $enrolmentX->start_date);
        $this->assertTrue(is_null($enrolmentX->end_date));

        $req = Request::create("/enrolment/{$enrolmentYId}?jwt=" . UserHelper::ROOT_JWT, 'PUT', ['status' => EnrolmentStatuses::IN_PROGRESS]);
        $res = $app->handle($req);
        $enrolmentY = $enrolmentX = $repository->load($enrolmentYId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::IN_PROGRESS, $enrolmentY->status);
        $this->assertEquals($startDateY, $enrolmentY->start_date);
        $this->assertEquals($endDateY, $enrolmentY->end_date);
    }

    /**
     * @runInSeparateProcess
     */
    public function testPut204EnrolmentDateToCompleted()
    {
        /**
         * @var EnrolmentRepository $repository
         * @var Connection          $go1
         */
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];

        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $userId = $this->createUser($go1, ['mail' => 'x@x.com', 'instance' => $app['accounts_name'], 'profile_id' => 123]),
            $this->createUser($go1, ['mail' => 'x@x.com', 'instance' => $this->portalName])
        );

        $enrolmentXId = $this->createEnrolment($go1, [
            'lo_id'             => $this->loId,
            'profile_id'        => 123,
            'user_id'           => $userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::NOT_STARTED,
        ]);

        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $userId = $this->createUser($go1, ['mail' => 'y@y.com', 'instance' => $app['accounts_name'], 'profile_id' => 456]),
            $this->createUser($go1, ['mail' => 'y@y.com', 'instance' => $this->portalName])
        );

        $enrolmentYId = $this->createEnrolment($go1, [
            'lo_id'             => $this->loId,
            'profile_id'        => 456,
            'user_id'           => $userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::COMPLETED,
            'start_date'        => ($startDateY = DateTimeHelper::atom('-1 week', DATE_ISO8601)),
        ]);

        $req = Request::create("/enrolment/{$enrolmentXId}?jwt=" . UserHelper::ROOT_JWT, 'PUT', [
            'status' => EnrolmentStatuses::COMPLETED,
            'result' => 88,
            'pass'   => 1,
        ]);
        $res = $app->handle($req);

        $repository = $app[EnrolmentRepository::class];
        $enrolmentX = $repository->load($enrolmentXId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolmentX->status);
        $this->assertEquals(88, $enrolmentX->result);
        $this->assertEquals(1, $enrolmentX->pass);
        $this->assertTrue(DateTimeHelper::atom('now', DATE_ISO8601) >= $enrolmentX->start_date);
        $this->assertTrue($enrolmentX->start_date <= $enrolmentX->end_date);

        $req = Request::create("/enrolment/{$enrolmentYId}?jwt=" . UserHelper::ROOT_JWT, 'PUT', [
            'status' => EnrolmentStatuses::COMPLETED,
            'result' => 28,
            'pass'   => 0,
        ]);
        $res = $app->handle($req);
        $enrolmentY = $enrolmentX = $repository->load($enrolmentYId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolmentY->status);
        $this->assertEquals(28, $enrolmentY->result);
        $this->assertEquals(0, $enrolmentY->pass);
        $this->assertEquals($startDateY, $enrolmentY->start_date);
        $this->assertTrue(DateTimeHelper::atom('now', DATE_ISO8601) >= $enrolmentY->end_date);
    }

    public function dataPut204EditingStatus()
    {
        $anyDate1 = DateTimeHelper::atom('-1 week', DATE_ISO8601);
        $anyDate2 = DateTimeHelper::atom('-1 day', DATE_ISO8601);

        return [
            [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::NOT_STARTED, null, 'now', null, null],
            [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::NOT_STARTED, $anyDate1, 'now', null, null],
            // Same test cases in testPut204EnrolmentDateToInProgress().
            [EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::IN_PROGRESS, null, 'now', null, null],
            [EnrolmentStatuses::COMPLETED, EnrolmentStatuses::IN_PROGRESS, $anyDate1, $anyDate1, $anyDate2, $anyDate2],
            // Same test cases in testPut204EnrolmentDateToCompleted().
            [EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::COMPLETED, null, 'now', null, 'any'],
            [EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED, $anyDate1, $anyDate1, null, 'now'],
        ];
    }

    /** @dataProvider dataPut204EditingStatus */
    public function testPut204EditingStatus($fromStatus, $toStatus, $startDate, $expectedStartDate, $endDate, $expectedEndDate)
    {
        /**
         * @var EnrolmentRepository $repository
         * @var Connection          $db
         */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $db = $app['dbs']['go1'];

        $loId = $this->createCourse($db, ['instance_id' => $this->portalId]);
        $enrolmentId = $this->createEnrolment($db, ['user_id' => $this->userId, 'lo_id' => $loId, 'profile_id' => $this->profileId, 'taken_instance_id' => $this->portalId, 'status' => $fromStatus, 'start_date' => $startDate, 'end_date' => $endDate]);

        $req = Request::create("/enrolment/{$enrolmentId}?jwt=" . UserHelper::ROOT_JWT, 'PUT', [
            'status' => $toStatus,
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());

        $enrolment = $repository->load($enrolmentId);
        $this->assertEquals($toStatus, $enrolment->status);
        if ($expectedStartDate === 'now') {
            $this->assertTrue(DateTimeHelper::atom('now', DATE_ISO8601) >= $enrolment->start_date);
        } else {
            $this->assertEquals($expectedStartDate, $enrolment->start_date);
        }
        if ($expectedEndDate === 'now') {
            $this->assertTrue(DateTimeHelper::atom('now', DATE_ISO8601) >= $enrolment->end_date);
        } elseif ($expectedEndDate === 'any') {
            $this->assertTrue($enrolment->start_date <= $enrolment->end_date);
        } else {
            $this->assertEquals($expectedEndDate, $enrolment->end_date);
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testPut400CustomCertificateWithInvalidEnrolment()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();

        $loId = $this->createCourse($app['dbs']['go1'], ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId + 1]);
        $enrolmentId = $this->createEnrolment($app['dbs']['go1'], [
            'lo_id'             => $loId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::IN_PROGRESS,
        ]);

        $req = Request::create(
            "/enrolment/{$enrolmentId}/properties?jwt=" . UserHelper::ROOT_JWT,
            'PUT',
            ['custom_certificate' => 'https://webmerge.me/certificates-bucket/custom.pdf']
        );
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('enrolment: Value "in-progress" does not equal expected value "completed"', json_decode($res->getContent())->message);
    }

    public function testPut400CustomCertificateWithInvalidLO()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();

        $loId = $this->createModule($app['dbs']['go1'], ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId + 1]);
        $enrolmentId = $this->createEnrolment($app['dbs']['go1'], [
            'lo_id'             => $loId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::COMPLETED,
        ]);

        $req = Request::create(
            "/enrolment/{$enrolmentId}/properties?jwt=" . UserHelper::ROOT_JWT,
            'PUT',
            ['custom_certificate' => 'https://webmerge.me/certificates-bucket/custom.pdf']
        );
        $res = $app->handle($req);

        $this->assertEquals(400, $res->getStatusCode());
        $this->assertStringContainsString('learningObject: Value "module" does not equal expected value "course"', json_decode($res->getContent())->message);
    }

    public function testPut204Properties()
    {
        $cert = 'https://webmerge.me/certificates-bucket/custom.pdf';
        $duration = 123;

        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $repository = $app[EnrolmentRepository::class];
        $enrolmentOriginalData = [
            'old_key' => 'old value',
            'history' => ['history #1', 'history #2'],
        ];

        $loId = $this->createCourse($app['dbs']['go1'], ['instance_id' => $this->portalId, 'remote_id' => $this->remoteId + 1]);
        $enrolmentId = $this->createEnrolment($app['dbs']['go1'], [
            'lo_id'             => $loId,
            'profile_id'        => $this->profileId,
            'user_id'           => $this->userId,
            'taken_instance_id' => $this->portalId,
            'status'            => EnrolmentStatuses::COMPLETED,
            'data'              => json_encode($enrolmentOriginalData),
        ]);

        $req = Request::create(
            "/enrolment/{$enrolmentId}/properties?jwt=" . UserHelper::ROOT_JWT,
            'PUT',
            [
                'custom_certificate' => $cert,
                'duration'           => $duration,
            ]
        );
        $res = $app->handle($req);
        $enrolment = $enrolment = $repository->load($enrolmentId);

        $message = $this->queueMessages[Queue::ENROLMENT_UPDATE][0];
        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($enrolmentId, $enrolment->id);
        $this->assertEquals($cert, $message['data']->custom_certificate);
        $this->assertEquals($duration, $message['data']->duration);
        $this->assertEquals($this->portalAccountId, $message['embedded']['account']['id']);
        $this->assertEquals($this->portalName, $message['embedded']['account']['instance']);
        $this->assertEquals('john.doe@qa.local', $message['embedded']['account']['mail']);
        $this->assertEquals('A T', $message['embedded']['account']['name']);

        $this->assertEquals($cert, $enrolment->data->custom_certificate);
        $this->assertEquals($duration, $enrolment->data->duration);

        $newEnrolmentData = json_decode(json_encode($enrolment->data), true);
        $this->assertEquals($enrolmentOriginalData['old_key'], $newEnrolmentData['old_key']);
        $this->assertEquals($enrolmentOriginalData['history'][0], $newEnrolmentData['history'][0]);
        $this->assertEquals($enrolmentOriginalData['history'][1], $newEnrolmentData['history'][1]);

        $this->assertEquals(3, count($enrolment->data->history));
        $this->assertEquals('updated', $enrolment->data->history[2]->action);
        $this->assertEquals($duration, $enrolment->data->history[2]->duration);
        $this->assertEquals(null, $enrolment->data->history[2]->original_duration);
        $this->assertEquals($cert, $enrolment->data->history[2]->custom_certificate);
        $this->assertEquals(null, $enrolment->data->history[2]->original_custom_certificate);
    }

    /**
     * @runInSeparateProcess
     */
    public function testEventInstructorCanUpdateEnrolment()
    {
        $app = $this->getApp();
        $go1DB = $app['dbs']['go1'];

        $this->link(
            $go1DB,
            EdgeTypes::HAS_ACCOUNT,
            $instructrUser = $this->createUser(
                $go1DB,
                ['instance' => $app['accounts_name'], 'mail' => $mail = 'instructor@go1.com']
            ),
            $instructr = $this->createUser($go1DB, ['mail' => $mail, 'instance' => $this->portalName])
        );

        $learnerProfile = 1007;
        $this->link(
            $go1DB,
            EdgeTypes::HAS_ACCOUNT,
            $learnerUserId = $this->createUser(
                $go1DB,
                ['instance' => $app['accounts_name'], 'mail' => $mail = 'learnx@go1.com', 'profile_id' => $learnerProfile]
            ),
            $leaner = $this->createUser($go1DB, ['mail' => $mail, 'instance' => $this->portalName])
        );

        $event = $this->createLO($go1DB, ['type' => LiTypes::EVENT, 'instance_id' => $this->portalId]);
        $enrolmentId = $this->createEnrolment(
            $go1DB,
            [
                'lo_id'             => $event,
                'profile_id'        => $learnerProfile,
                'user_id'           => $learnerUserId,
                'taken_instance_id' => $this->portalId,
                'status'            => EnrolmentStatuses::IN_PROGRESS,
            ]
        );

        $instructrJwt = $this->jwtForUser($go1DB, $instructrUser, $this->portalName);

        $req = Request::create("/enrolment/{$enrolmentId}?jwt={$instructrJwt}", 'PUT');
        $key = 'secret';
        $internalData = JWT::encode(['is_instructor' => true], $key, 'HS256');
        $req->headers->set('JWT-Private-Key', $key);
        $req->request->replace([
            'status'        => EnrolmentStatuses::COMPLETED,
            'pass'          => 1,
            'startDate'     => DateTimeHelper::atom('now', DATE_ISO8601),
            'endDate'       => DateTimeHelper::atom('+1 hour', DATE_ISO8601),
            'internal_data' => $internalData,
        ]);
        $res = $app->handle($req);
        $this->assertEquals(204, $res->getStatusCode());
    }

    public function dataPut204EnrolmentDate()
    {
        return [
            // New status
            [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::NOT_STARTED, '-1 week', '-1 day'],
            [EnrolmentStatuses::COMPLETED, EnrolmentStatuses::NOT_STARTED, '-1 week', '-1 day'],
            [EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::IN_PROGRESS, '-1 week', '-1 day'],
            [EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::COMPLETED, '-1 week', '-1 day'],
            [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::COMPLETED, '-1 week', '-1 day'],
            [EnrolmentStatuses::COMPLETED, EnrolmentStatuses::IN_PROGRESS, '-1 week', '-1 day'],
            // Same status
            [EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::NOT_STARTED, '-1 week', '-1 day'],
            [EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::NOT_STARTED, null, '-1 day'],
            [EnrolmentStatuses::NOT_STARTED, EnrolmentStatuses::NOT_STARTED, '-1 week', null],
            [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS, '-1 week', '-1 day'],
            [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS, null, '-1 day'],
            [EnrolmentStatuses::IN_PROGRESS, EnrolmentStatuses::IN_PROGRESS, '-1 week', null],
            [EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED, '-1 week', '-1 day'],
            [EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED, null, '-1 day'],
            [EnrolmentStatuses::COMPLETED, EnrolmentStatuses::COMPLETED, '-1 week', null],
        ];
    }

    /** @dataProvider dataPut204EnrolmentDate */
    public function testPut204Go1StaffEnrolmentDate($enrolmentStatus, $newStatus, $newStartDate, $newEndDate)
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'id' => 1001, 'remote_id' => 1001]);
        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $learnerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'qa.learner@example.com', 'profile_id' => $learnerProfileId = 123]),
            $learnerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'qa.learner@example.com'])
        );

        $enrolmentCreatingData = [
            'profile_id'        => $learnerProfileId,
            'user_id'           => $learnerUserId,
            'lo_id'             => $loId,
            'taken_instance_id' => $this->portalId,
            'status'            => $enrolmentStatus,
        ];

        $enrolmentStartDate = $enrolmentEndDate = $newEnrolmentStartDate = $newEnrolmentEndDate = null;
        if (EnrolmentStatuses::IN_PROGRESS == $enrolmentStatus) {
            $enrolmentCreatingData['start_date'] = $enrolmentStartDate = DateTimeHelper::atom('-2 weeks', DATE_ISO8601);
        } elseif (EnrolmentStatuses::COMPLETED == $enrolmentStatus) {
            $enrolmentCreatingData['start_date'] = $enrolmentStartDate = DateTimeHelper::atom('-2 weeks', DATE_ISO8601);
            $enrolmentCreatingData['end_date'] = $enrolmentEndDate = DateTimeHelper::atom('-2 days', DATE_ISO8601);
        }
        $enrolmentId = $this->createEnrolment($go1, $enrolmentCreatingData);

        // Request to updating above existing enrolment with provided data
        $enrolmentUpdatingData = [
            'status'    => $newStatus,
            'note'      => 'Updated by Go1 Staff!',
        ];
        $newStartDate && ($enrolmentUpdatingData['startDate'] = $newEnrolmentStartDate = DateTimeHelper::atom($newStartDate, DATE_ISO8601));
        $newEndDate && ($enrolmentUpdatingData['endDate'] = $newEnrolmentEndDate = DateTimeHelper::atom($newEndDate, DATE_ISO8601));

        $req = Request::create(
            "/enrolment/{$enrolmentId}?jwt=" . UserHelper::ROOT_JWT,
            'PUT',
            $enrolmentUpdatingData
        );
        $res = $app->handle($req);
        $enrolment = $repository->load($enrolmentId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($newStatus, $enrolment->status);

        $now = DateTimeHelper::atom('now', DATE_ISO8601);
        $isNewStatus = ($enrolmentStatus != $newStatus);
        if ($isNewStatus) {
            if (EnrolmentStatuses::NOT_STARTED == $newStatus) {
                $this->assertNotNull($enrolment->start_date);
                $this->assertNull($enrolment->end_date);
            } elseif (EnrolmentStatuses::IN_PROGRESS == $newStatus) {
                $this->assertEquals(($newEnrolmentStartDate ?: ($enrolmentStartDate ?? $now)), $enrolment->start_date);
                $this->assertEquals(($newEnrolmentEndDate ?: ($enrolmentEndDate ?? null)), $enrolment->end_date);
            } elseif (EnrolmentStatuses::COMPLETED == $newStatus) {
                $this->assertEquals(($newEnrolmentStartDate ?: ($enrolmentStartDate ?? $now)), $enrolment->start_date);
                $this->assertEquals($newEnrolmentEndDate ?: $now, $enrolment->end_date);
            }
        } else {
            if (EnrolmentStatuses::NOT_STARTED == $newStatus) {
                $this->assertNull($enrolment->start_date);
                $this->assertNull($enrolment->end_date);
            } else {
                $this->assertEquals(($newEnrolmentStartDate ?: $enrolmentStartDate), $enrolment->start_date);
                if (EnrolmentStatuses::COMPLETED == $newStatus) {
                    $this->assertEquals($newEnrolmentEndDate ?: $now, $enrolment->end_date);
                } else {
                    $this->assertEquals($enrolmentEndDate, $enrolment->end_date);
                }
            }
        }
    }

    /** @dataProvider dataPut204EnrolmentDate */
    public function testPut204PortalAdminEnrolmentDate($enrolmentStatus, $newStatus, $newStartDate, $newEndDate)
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'id' => 1002, 'remote_id' => 1002]);
        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $learnerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'qa.learner@example.com', 'profile_id' => $learnerProfileId = 123]),
            $learnerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'qa.learner@example.com', 'profile_id' => $learnerProfileId + 100])
        );

        $enrolmentCreatingData = [
            'profile_id'        => $learnerProfileId,
            'user_id'           => $learnerUserId,
            'lo_id'             => $loId,
            'taken_instance_id' => $this->portalId,
            'status'            => $enrolmentStatus,
        ];

        $enrolmentStartDate = $enrolmentEndDate = $newEnrolmentStartDate = $newEnrolmentEndDate = null;
        if (EnrolmentStatuses::NOT_STARTED == $enrolmentStatus) {
            $enrolmentCreatingData['start_date'] = null;
            $enrolmentCreatingData['end_date'] = null;
        } elseif (EnrolmentStatuses::IN_PROGRESS == $enrolmentStatus) {
            $enrolmentCreatingData['start_date'] = $enrolmentStartDate = DateTimeHelper::atom('-2 weeks', DATE_ISO8601);
        } elseif (EnrolmentStatuses::COMPLETED == $enrolmentStatus) {
            $enrolmentCreatingData['start_date'] = $enrolmentStartDate = DateTimeHelper::atom('-2 weeks', DATE_ISO8601);
            $enrolmentCreatingData['end_date'] = $enrolmentEndDate = DateTimeHelper::atom('-2 days', DATE_ISO8601);
        }
        $enrolmentId = $this->createEnrolment($go1, $enrolmentCreatingData);

        // Request to updating above existing enrolment with provided data
        $enrolmentUpdatingData = [
            'status'    => $newStatus,
            'note'      => 'Updated by Go1 Staff!',
        ];
        $newStartDate && ($enrolmentUpdatingData['startDate'] = $newEnrolmentStartDate = DateTimeHelper::atom($newStartDate, DATE_ISO8601));
        $newEndDate && ($enrolmentUpdatingData['endDate'] = $newEnrolmentEndDate = DateTimeHelper::atom($newEndDate, DATE_ISO8601));

        $req = Request::create(
            "/enrolment/{$enrolmentId}?jwt=" . $this->adminJwt,
            'PUT',
            $enrolmentUpdatingData
        );
        $res = $app->handle($req);
        $enrolment = $repository->load($enrolmentId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals($newStatus, $enrolment->status);

        $now = DateTimeHelper::atom('now', DATE_ISO8601);
        $isNewStatus = ($enrolmentStatus != $newStatus);
        if ($isNewStatus) {
            if (EnrolmentStatuses::NOT_STARTED == $newStatus) {
                $this->assertNotNull($enrolment->start_date);
                $this->assertNull($enrolment->end_date);
            } elseif (EnrolmentStatuses::IN_PROGRESS == $newStatus) {
                $this->assertEquals(($enrolmentStartDate ?? ($newEnrolmentStartDate ?: $now)), $enrolment->start_date);
                $this->assertEquals(($enrolmentEndDate ?? ($newEnrolmentEndDate ?: null)), $enrolment->end_date);
            } elseif (EnrolmentStatuses::COMPLETED == $newStatus) {
                $this->assertEquals(($enrolmentStartDate ?? ($newEnrolmentStartDate ?: $now)), $enrolment->start_date);
                $this->assertEquals($newEnrolmentEndDate ?: $now, $enrolment->end_date);
            }
        } else {
            if (EnrolmentStatuses::NOT_STARTED == $newStatus) {
                $this->assertNull($enrolment->start_date);
                $this->assertNull($enrolment->end_date);
            } else {
                $this->assertEquals($enrolmentStartDate, $enrolment->start_date);
                if (EnrolmentStatuses::COMPLETED == $newStatus) {
                    $this->assertEquals($newEnrolmentEndDate ?: $now, $enrolment->end_date);
                } else {
                    $this->assertEquals($enrolmentEndDate, $enrolment->end_date);
                }
            }
        }
    }

    public function dataPut204CompleteEnrolmentWithLearnerJwt()
    {
        return [
            [EnrolmentStatuses::IN_PROGRESS],
            [EnrolmentStatuses::NOT_STARTED],
        ];
    }

    /** @dataProvider dataPut204CompleteEnrolmentWithLearnerJwt */
    public function testPut204CompleteEnrolmentWithLearnerJwt($currentEnrolmentStatus)
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        // LO must be configured properly to enable manual learner completions
        $loData = json_encode((object)[
            'can_mark_as_complete' => true
        ]);

        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'id' => 1002, 'remote_id' => 1002, 'data' => $loData]);
        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $learnerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'qa.learner@example.com', 'profile_id' => $learnerProfileId = 123]),
            $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'qa.learner@example.com', 'profile_id' => $learnerProfileId + 100])
        );

        $enrolmentCreatingData = [
            'profile_id'        => $learnerProfileId,
            'user_id'           => $learnerUserId,
            'lo_id'             => $loId,
            'taken_instance_id' => $this->portalId,
            'status'            => $currentEnrolmentStatus,
        ];

        $enrolmentCreatingData['start_date'] = DateTimeHelper::atom('-2 weeks', DATE_ISO8601);
        $enrolmentId = $this->createEnrolment($go1, $enrolmentCreatingData);

        $req = Request::create(
            "/enrolment/{$enrolmentId}?jwt={$this->jwtForUser($go1, $learnerUserId, $this->portalName)}",
            'PUT',
            [
                'status'    => EnrolmentStatuses::COMPLETED,
                'result'    => 100,
                'pass'      => 1,
                'endDate'   => DateTimeHelper::atom('+1 year', DATE_ISO8601),
                'startDate' => DateTimeHelper::atom('+ 1 month', DATE_ISO8601),
                'note'      => 'Manually completed by learner',
            ]
        );
        $res = $app->handle($req);
        $enrolment = $repository->load($enrolmentId);

        $this->assertEquals(204, $res->getStatusCode());
        $this->assertEquals(EnrolmentStatuses::COMPLETED, $enrolment->status);
    }

    public function dataPut403CompleteEnrolmentWithLearnerJwt(): array
    {
        return [
            [EnrolmentStatuses::IN_PROGRESS, false],
            [EnrolmentStatuses::NOT_STARTED, false],
            [EnrolmentStatuses::IN_PROGRESS, true, EnrolmentStatuses::NOT_STARTED],
            [EnrolmentStatuses::EXPIRED],
            [EnrolmentStatuses::PENDING],
            [EnrolmentStatuses::COMPLETED],
        ];
    }

    /** @dataProvider dataPut403CompleteEnrolmentWithLearnerJwt */
    public function testPut403CompleteEnrolmentWithLearnerJwt($currentEnrolmentStatus, $shouldConfigureForManualCompletion = true, $updateEnrollmentStatus = EnrolmentStatuses::COMPLETED)
    {
        $app = $this->getApp();
        $go1 = $app['dbs']['go1'];
        /** @var EnrolmentRepository $repository */
        $repository = $app[EnrolmentRepository::class];

        $loData = json_encode((object)[
            'can_mark_as_complete' => $shouldConfigureForManualCompletion
        ]);

        $loId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'id' => 1002, 'remote_id' => 1002, 'data' => $loData]);
        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $learnerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'qa.learner@example.com', 'profile_id' => $learnerProfileId = 123]),
            $this->createUser($go1, ['instance' => $this->portalName, 'mail' => 'qa.learner@example.com', 'profile_id' => $learnerProfileId + 100])
        );

        $enrolmentCreatingData = [
            'profile_id'        => $learnerProfileId,
            'user_id'           => $learnerUserId,
            'lo_id'             => $loId,
            'taken_instance_id' => $this->portalId,
            'status'            => $currentEnrolmentStatus,
        ];

        $enrolmentCreatingData['start_date'] = DateTimeHelper::atom('-2 weeks', DATE_ISO8601);
        $enrolmentId = $this->createEnrolment($go1, $enrolmentCreatingData);

        $req = Request::create(
            "/enrolment/{$enrolmentId}?jwt={$this->jwtForUser($go1, $learnerUserId, $this->portalName)}",
            'PUT',
            [
                'status'    => $updateEnrollmentStatus ?: EnrolmentStatuses::COMPLETED,
                'result'    => 100,
                'pass'      => 1,
                'endDate'   => DateTimeHelper::atom('+1 year', DATE_ISO8601),
                'startDate' => DateTimeHelper::atom('+ 1 month', DATE_ISO8601),
                'note'      => 'Manually completed by learner',
            ]
        );
        $res = $app->handle($req);
        $enrolment = $repository->load($enrolmentId);

        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals($currentEnrolmentStatus, $enrolment->status);
    }

    public function testPatchV3Enrollment400WithInvalidAssigner()
    {
        /** @var EnrolmentRepository $repository */
        $app = $this->getApp();
        $assigner = new \stdClass();
        $assigner->id = 23;
        $enrolmentCreateV3Validator = $this->prophesize(EnrolmentCreateV3Validator::class);
        $enrolmentCreateV3Validator
            ->getAssigner(Argument::any(), Argument::any(), Argument::any())
            ->willReturn($assigner);
        $enrolmentCreateV3Validator
            ->validateAssignerPermission(Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any(), Argument::any())
            ->willThrow(new AccessDeniedHttpException());
        $app->extend(EnrolmentCreateV3Validator::class, fn () => $enrolmentCreateV3Validator->reveal());

        $req = Request::create(
            "/enrollments/{$this->enrolmentId2}?jwt={$this->adminJwt}",
            'PATCH',
            [
                'enrollment_type' => 'assigned',
                'assigner_account_id' => $this->portalAccountId
            ]
        );
        $res = $app->handle($req);
        $message = json_decode($res->getContent(), true);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('enrollment_operation_not_permitted', $message['error_code']);
    }
}
