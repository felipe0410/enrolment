<?php

namespace go1\enrolment\tests\delete;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\enrolment\EnrolmentRevisionRepository;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\schema\mock\EnrolmentMockTrait;
use go1\util\user\UserHelper;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentDeleteTest extends EnrolmentTestCase
{
    use EnrolmentMockTrait;

    private Connection $go1;
    private string     $jwt = UserHelper::ROOT_JWT;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        $this->go1 = $app['dbs']['go1'];
        $this->createEnrolmentRevisionData();
    }

    private function createEnrolmentRevisionData(): void
    {
        $this->createRevisionEnrolment($this->go1, ['id' => 2, 'enrolment_id' => 23]);
        $this->createRevisionEnrolment($this->go1, ['id' => 4, 'enrolment_id' => 24]);
        $this->createRevisionEnrolment($this->go1, ['id' => 6, 'enrolment_id' => 50]);
        $this->createRevisionEnrolment($this->go1, ['id' => 8, 'enrolment_id' => 50]);
    }

    public function testDeleteRevision403(): void
    {
        $app = $this->getApp();
        $req = Request::create("/staff/revision/2", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testDeleteRevision404(): void
    {
        $app = $this->getApp();
        $req = Request::create("/staff/revision/404?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testDeleteRevisions403(): void
    {
        $app = $this->getApp();
        $req = Request::create("/staff/enrolment-revisions/50", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(403, $res->getStatusCode());
    }

    public function testDeleteRevisions404(): void
    {
        $app = $this->getApp();
        $req = Request::create("/staff/enrolment-revisions/404?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testEnrolmentRevisionDelete(): void
    {
        $app = $this->getApp();
        $repository = $app[EnrolmentRevisionRepository::class];
        $this->assertNotNull($repository->load(2));

        $req = Request::create("/staff/revision/2?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());

        $this->assertNull($repository->load(2));
        $this->assertNotNull($repository->load(4));
        $this->assertNotNull($repository->load(6));
        $this->assertNotNull($repository->load(8));
    }

    public function testEnrolmentRevisionsDelete(): void
    {
        $app = $this->getApp();
        $repository = $app[EnrolmentRevisionRepository::class];
        $this->assertNotNull($repository->load(2));

        $req = Request::create("/staff/enrolment-revisions/50?jwt=$this->jwt", 'DELETE');
        $res = $app->handle($req);

        $this->assertEquals(200, $res->getStatusCode());

        $this->assertNull($repository->load(6));
        $this->assertNull($repository->load(8));
        $this->assertNotNull($repository->load(4));
        $this->assertNotNull($repository->load(2));
    }
}
