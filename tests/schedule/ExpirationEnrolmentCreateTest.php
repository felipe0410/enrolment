<?php

namespace go1\enrolment\tests\schedule;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use Symfony\Component\HttpFoundation\Request;

class ExpirationEnrolmentCreateTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    protected $courseId;
    protected $moduleId;
    protected $jwt;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $go1 = $app['dbs']['go1'];

        $portalId = $this->createPortal($go1, ['title' => 'qa.mygo1.com']);
        $this->createPortalPublicKey($go1, ['instance' => 'qa.mygo1.com']);
        $userId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => 'foo@bar.baz', 'profile_id' => 555]);
        $accountId = $this->createUser($go1, ['instance' => 'qa.mygo1.com', 'mail' => 'foo@bar.baz']);
        $this->link($go1, EdgeTypes::HAS_ACCOUNT, $userId, $accountId);
        $this->jwt = $this->jwtForUser($go1, $userId, 'qa.mygo1.com');

        $this->courseId = $this->createCourse($go1, ['instance_id' => $portalId]);
        $this->moduleId = $this->createCourse($go1, ['instance_id' => $portalId]);
        $hasModuleEdgeId = $this->link($go1, EdgeTypes::HAS_MODULE, $this->courseId, $this->moduleId);
        $this->link($go1, EdgeTypes::HAS_ENROLMENT_EXPIRATION, $hasModuleEdgeId, $hasModuleEdgeId, 0, ['expiration' => '+ 2 years']);

        $this->loAccessGrant($this->courseId, $userId, $portalId, 2);
        $this->loAccessGrant($this->moduleId, $userId, $portalId, 2);
    }

    public function test()
    {
        /** @var Connection $db */
        $app = $this->getApp();
        $db = $app['dbs']['go1'];

        # Enrol to module
        $res = $app->handle(Request::create("/qa.mygo1.com/{$this->courseId}/{$this->moduleId}/enrolment/in-progress?jwt={$this->jwt}", 'POST'));
        $enrolmentId = json_decode($res->getContent())->id;
        $scheduleTime = $db
            ->executeQuery('SELECT target_id FROM gc_ro WHERE type = ? AND source_id = ?', [EdgeTypes::SCHEDULE_EXPIRE_ENROLMENT, $enrolmentId])
            ->fetchColumn();

        $nextTwoYears = strtotime('+ 2 years');
        $this->assertTrue($scheduleTime >= $nextTwoYears);

        return [$app, $enrolmentId];
    }
}
