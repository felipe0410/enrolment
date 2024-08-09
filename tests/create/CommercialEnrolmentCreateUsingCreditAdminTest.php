<?php

namespace go1\enrolment\tests\create;

use Doctrine\DBAL\Schema\Schema;
use go1\app\DomainService;
use go1\enrolment\tests\EnrolmentTestCase;
use go1\util\DB;
use go1\util\edge\EdgeTypes;
use go1\util\schema\EnrolmentTrackingSchema;
use go1\util\schema\mock\LoMockTrait;
use go1\util\schema\mock\PortalMockTrait;
use go1\util\schema\mock\UserMockTrait;
use go1\util\user\Roles;
use Symfony\Component\HttpFoundation\Request;

class CommercialEnrolmentCreateUsingCreditAdminTest extends EnrolmentTestCase
{
    use PortalMockTrait;
    use LoMockTrait;
    use UserMockTrait;

    private $portalName    = 'qa.mygo1.com';
    private $learningPathwayId;
    private $courseId;
    private $accountAdminJwt;
    private $portalAdminJwt;
    private $loAuthorJwt;
    private $managerJwt;
    private $mail          = 'admin@go1.com';
    private $userId;
    private $accountId;
    private $friendId;
    private $friendSubId;
    private $friendEmail   = 'friend@bar.baz';
    private $loAuthorUserId;
    private $loAuthorAccountId;
    private $loAuthorEmail = 'author@go1.com';
    private $managerUserId;
    private $managerAccountId;
    private $managerEmail  = 'manager@go1.com';

    protected function appInstall(DomainService $app)
    {
        parent::appInstall($app);
        DB::install($app['dbs']['enrolment'], [function (Schema $schema) {
            EnrolmentTrackingSchema::install($schema);
        }]);

        $go1 = $app['dbs']['go1'];
        $portalId = $this->createPortal($go1, ['title' => 'qa.mygo1.com']);
        $this->createPortalPublicKey($go1, ['instance' => $this->portalName]);

        $this->learningPathwayId = $this->createLearningPathway($go1, ['instance_id' => $portalId]);
        $this->courseId = $this->createCourse($go1, ['instance_id' => $portalId, 'price' => ['price' => 111.00, 'currency' => 'USD', 'tax' => 10.00, 'tax_included' => true]]);

        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $this->userId = $this->createUser($go1, ['mail' => $this->mail, 'instance' => $app['accounts_name'], 'profile_id' => 1111]),
            $this->accountId = $this->createUser($go1, ['mail' => $this->mail, 'instance' => $this->portalName, 'profile_id' => 1112])
        );

        $this->accountAdminJwt = $this->getJwt($this->mail, $app['accounts_name'], $this->portalName, [Roles::ROOT], 1112, $this->accountId, 1111, $this->userId);
        $this->portalAdminJwt = $this->getJwt($this->mail, $app['accounts_name'], $this->portalName, [Roles::ADMIN], 1112, $this->accountId, 1111, $this->userId);

        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $this->friendId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'profile_id' => 2111, 'mail' => $this->friendEmail]),
            $this->friendSubId = $this->createUser($go1, ['instance' => $this->portalName, 'profile_id' => 2112, 'mail' => $this->friendEmail])
        );

        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $this->loAuthorUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->loAuthorEmail, 'profile_id' => 1113]),
            $this->loAuthorAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->loAuthorEmail, 'profile_id' => 1114])
        );
        $this->link($go1, EdgeTypes::HAS_AUTHOR_EDGE, $this->courseId, $this->loAuthorUserId, 0);
        $this->loAuthorJwt = $this->getJwt($this->loAuthorEmail, $app['accounts_name'], $this->portalName, [Roles::AUTHENTICATED], 1114, $this->loAuthorAccountId, 1113, $this->loAuthorUserId);

        $this->link(
            $go1,
            EdgeTypes::HAS_ACCOUNT,
            $this->managerUserId = $this->createUser($go1, ['instance' => $app['accounts_name'], 'mail' => $this->managerEmail, 'profile_id' => 1115]),
            $this->managerAccountId = $this->createUser($go1, ['instance' => $this->portalName, 'mail' => $this->managerEmail, 'profile_id' => 1116])
        );
        $this->link($go1, EdgeTypes::HAS_MANAGER, $this->userId, $this->managerUserId, 0);
        $this->managerJwt = $this->getJwt($this->managerEmail, $app['accounts_name'], $this->portalName, [Roles::AUTHENTICATED], 1114, $this->managerAccountId, 1113, $this->loAuthorUserId);
    }

    public function dataJwt()
    {
        $this->getApp();

        return [
            [$this->accountAdminJwt, $this->userId],
            [$this->portalAdminJwt, $this->userId],
            # [$this->loAuthorJwt, $this->loAuthorUserId],
        ];
    }

    /**
     * @dataProvider dataJwt
     */
    public function testCreateWithoutToken($jwt)
    {
        $app = $this->getApp();
        $url = "/{$this->portalName}/{$this->learningPathwayId}/{$this->courseId}/enrolment/in-progress?jwt={$jwt}";

        // Without admin flag -> 400
        #$req = Request::create($url, 'POST');
        #$res = $app->handle($req);
        #$this->assertEquals(400, $res->getStatusCode());

        // With admin flag
        $req = Request::create($url . '&admin=1', 'POST', ['paymentMethod' => 'credit']);
        $res = $app->handle($req);
        $enrolment = json_decode($res->getContent());
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($enrolment->id));
    }

    public function testCreateForOtherUser()
    {
        $app = $this->getApp();

        $req = Request::create("/{$this->portalName}/{$this->learningPathwayId}/{$this->courseId}/enrolment/{$this->friendEmail}/in-progress?admin=1&jwt={$this->portalAdminJwt}", 'POST');
        $req->request->replace(['paymentMethod' => 'credit', 'paymentOptions' => ['userId' => $this->friendEmail]]);
        $res = $app->handle($req);
        $enrolment = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($enrolment->id));

        // No assigned relationship.
        $db = $app['dbs']['go1'];
        $this->assertFalse($db->fetchColumn('SELECT target_id FROM gc_ro WHERE type = ? AND source_id = ?', [EdgeTypes::HAS_ASSIGN, $enrolment->id]));
    }

    public function testCreateForOtherUserAuthor()
    {
        $app = $this->getApp();

        $req = Request::create("/{$this->portalName}/{$this->learningPathwayId}/{$this->courseId}/enrolment/{$this->friendEmail}/in-progress?admin=1&jwt={$this->loAuthorJwt}", 'POST');
        $req->request->replace(['paymentMethod' => 'credit', 'paymentOptions' => ['userId' => $this->friendEmail]]);
        $res = $app->handle($req);
        $enrolment = json_decode($res->getContent());

        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals(true, is_numeric($enrolment->id));

        // No assigned relationship.
        $db = $app['dbs']['go1'];
        $this->assertFalse($db->fetchColumn('SELECT target_id FROM gc_ro WHERE type = ? AND source_id = ?', [EdgeTypes::HAS_ASSIGN, $enrolment->id]));
    }
}
