<?php

namespace go1\enrolment\tests\load;

use DI\Container;
use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\domain_users\clients\iam\lib\Model\UserDto;
use go1\domain_users\clients\user_management\lib\Api\SearchApi;
use go1\domain_users\clients\user_management\lib\Configuration;
use go1\domain_users\clients\user_management\lib\Model\AccountUserDto;
use go1\domain_users\clients\user_management\lib\Model\ElasticSearchResponseDto;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\rest\RestService;
use go1\util\edge\EdgeTypes;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PlanMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Prophecy\Argument;
use Symfony\Component\HttpFoundation\Request;

class NonEnrolledControllerTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use UserMockTrait;
    use LoMockTrait;
    use EnrolmentMockTrait;
    use PlanMockTrait;

    private $portalName = 'qa.go1.co';
    private $portalId;
    private $studentUserId;
    private $studentAccountId;
    private $studentJwt;

    private $adminUserId;
    private $adminAccountId;
    private $adminJwt;
    private $loId;
    private $accountUserDto;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];

        $this->portalId = $this->createPortal($go1, ['title' => $this->portalName]);
        $adminRoleId = $this->createRole($go1, ['instance' => $this->portalName, 'name' => 'administrator']);

        $this->studentUserId = $this->createUser($go1, ['mail' => 'student@example.com', 'instance' => $app['accounts_name']]);
        $this->studentAccountId = $this->createUser($go1, ['mail' => 'student@example.com', 'instance' => $this->portalName]);
        $this->adminUserId = $this->createUser($go1, ['mail' => 'admin@example.com', 'instance' => $app['accounts_name']]);
        $this->adminAccountId = $this->createUser($go1, ['mail' => 'admin@example.com','instance' => $this->portalName]);

        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->studentUserId, $this->studentAccountId);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $this->adminUserId, $this->adminAccountId);
        $this->link($go1, EdgeTypes::HAS_ROLE, $this->adminAccountId, $adminRoleId);


        $this->loId = $this->createCourse($go1, ['instance_id' => $this->portalId]);
        $loId2 = $this->createCourse($go1, ['instance_id' => $this->portalId]);

        $this->createEnrolment($go1, ['user_id' => $this->studentUserId, 'lo_id' => $this->loId, 'profile_id' => 1, 'taken_instance_id' => $this->portalId]);
        $this->createEnrolment($go1, ['user_id' => $this->studentUserId, 'lo_id' => $loId2, 'profile_id' => 2, 'taken_instance_id' => $this->portalId]);
        $this->createEnrolment($go1, ['user_id' => $this->adminUserId, 'lo_id' => $loId2, 'profile_id' => 3, 'taken_instance_id' => $this->portalId]);

        $this->studentJwt = $this->jwtForUser($go1, $this->studentUserId, $this->portalName);
        $this->adminJwt = $this->jwtForUser($go1, $this->adminUserId, $this->portalName);

        $accountUserDto = new AccountUserDto([
            '_gc_user_account_id' => 2,
            'account_guid'        => 'acc_222',
            'portal_id'           => 1,
            'roles'               => [],
            'status'              => true,
            'locale'              => 'au',
            'created_time'        => '2022-09-21T06:00:46.035Z',
            'updated_time'        => '2022-09-21T06:00:46.035Z',
            'logged_in_time'      => '2022-09-21T06:00:46.035Z',
            'custom_fields'       => (object)[],
            'user'                => new UserDto([
                '_gc_user_id'  => 1,
                'family_name'  => 'John',
                'given_name'   => 'Doe',
                'username'     => '',
                'email'        => 'john.doe@example.com',
                'picture'      => '//me.png',
                'status'       => true,
                'created_time' => '2022-09-21T06:00:46.035Z',
                'updated_time' => '2022-09-21T06:00:46.035Z',
                'user_guid'    => '2022-09-21T06:00:46.035Z',
                'region'       => ''
            ])
        ]);

        $app->extend(SearchApi::class, function () use ($accountUserDto) {
            $searchApi = $this->prophesize(SearchApi::class);
            $searchApi->getConfig()->willReturn(Configuration::getDefaultConfiguration());
            $searchApi->searchAccounts(Argument::any())->will(function () use ($accountUserDto) {
                return new ElasticSearchResponseDto([
                    'total' => 1,
                    'data' => [
                        $accountUserDto
                    ]
                ]);
            });
            return $searchApi->reveal();
        });


    }

    public function testGetNotEnrolledUsers()
    {
        $app = $this->getApp();
        $req = Request::create("/learning-objects/{$this->loId}/non-enrolled?jwt=$this->adminJwt&sort[0][field]=given_name&sort[0][direction]=asc&sort[1][field]=name&sort[2][field]=given_name&sort[3][field]=family_name");
        $res = $app->handle($req);
        $this->assertEquals(200, $res->getStatusCode());
        $learning = json_decode($res->getContent(), true);
        $this->assertEquals(["id" => 2,"user_id" => 1,"mail" => "john.doe@example.com","first_name" => "Doe","last_name" => "John","full_name" => "Doe John","avatar" => "//me.png"], $learning['data'][0]);
        $this->assertEquals(["request_id" => null,"code" => "success", "filter_info" => ["offset" => 0,"limit" => 20,"next_offset" => 1,"total" => 1]], $learning['meta']);
    }

    public function testOnlyAdminCanAccess()
    {
        $app = $this->getApp();
        $req = Request::create("/learning-objects/{$this->loId}/non-enrolled?jwt=$this->studentJwt");
        $res = $app->handle($req);
        $this->assertEquals(403, $res->getStatusCode());
        $this->assertEquals('enrollment_lo_access_denied', json_decode($res->getContent())->error_code);
    }

    public function testSortWithWrongDirection()
    {
        $app = $this->getApp();
        $req = Request::create("/learning-objects/{$this->loId}/non-enrolled?jwt=$this->adminJwt&sort[0][field]=first_name&sort[0][direction]=ASC");
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }

    public function testSortWithWrongFieldName()
    {
        $app = $this->getApp();
        $req = Request::create("/learning-objects/{$this->loId}/non-enrolled?jwt=$this->adminJwt&sort[0][field]=mail&sort[0][direction]=asc");
        $res = $app->handle($req);
        $this->assertEquals(400, $res->getStatusCode());
    }
}
