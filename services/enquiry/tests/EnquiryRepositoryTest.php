<?php

namespace go1\core\learning_record\enquiry\tests;

use Doctrine\DBAL\Connection;
use go1\app\DomainService;
use go1\core\learning_record\enquiry\EnquiryRepository;
use go1\core\learning_record\enquiry\EnquiryServiceProvider;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\edge\EdgeTypes;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;

class EnquiryRepositoryTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $portalId;
    private $loId;
    private $userId;
    private $enquiryId;

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);

        /** @var Connection $go1 */
        $go1 = $app['dbs']['go1'];
        $this->portalId = $this->createPortal($go1, ['title' => 'az.mygo1.com']);
        $this->enquiryId = $go1->insert('gc_ro', [
            'type'      => EdgeTypes::HAS_ENQUIRY,
            'source_id' => $this->loId = $this->createCourse($go1, ['instance_id' => $this->portalId, 'data' => '{"allow_enrolment":"enquiry"}']),
            'target_id' => $this->userId = $this->createUser($go1, ['instance' => $app['accounts_name']]),
            'weight'    => 0,
            'data'      => json_encode([
                'course'     => 'Example course',
                'first'      => 'A',
                'last'       => 'T',
                'mail'       => 'thehongtt@gmail.com',
                'phone'      => '0123456789',
                'created'    => time(),
                'updated'    => null,
                'updated_by' => null,
                'body'       => 'I want to enroll to this course',
                'status'     => EnquiryServiceProvider::ENQUIRY_PENDING,
            ]),
        ]);
    }

    public function testFindEnquiry()
    {
        $app = $this->getApp();
        $rows = $app[EnquiryRepository::class]->findEnquiry($this->loId, $this->userId, true);

        $this->assertCount(1, $rows);
        $this->assertEquals($this->loId, $rows[0]->sourceId);
        $this->assertEquals($this->userId, $rows[0]->targetId);
        $this->assertEquals(EdgeTypes::HAS_ENQUIRY, $rows[0]->type);
    }
}
