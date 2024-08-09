<?php

namespace Helper;

use Codeception\Module\REST;
use Codeception\TestInterface;
use go1\integrationTest\Fixtures\FixturesModule;

// here you can define custom actions
// all public methods declared in helper class will be available in $I

class Integration extends \Codeception\Module
{
    private $accountsAdminJwt;

    public function _before(TestInterface $test)
    {
        $restModule = $this->getREST();
        $restModule->haveHttpHeader('Content-Type', 'application/json');
    }

    private function getRest(): REST
    {
        return $this->getModule('REST');
    }

    public function getAccountAdminJwt()
    {
        if (!$this->accountsAdminJwt) {
            /** @var FixturesModule $fixturesModule */
            $fixturesModule = $this->getModule('\\' . FixturesModule::class);
            $email = $fixturesModule->config['accounts_admin_email'];
            $password = getenv('INTEGRATION_STAFF_PASSWORD');

            $rest = $this->getRest();
            $rest->sendPOST('/user/login', [
                'username' => $email,
                'password' => $password
            ]);
            $rest->seeResponseCodeIs(200);
            $this->accountsAdminJwt = $rest->grabDataFromResponseByJsonPath('$.jwt')[0];
        }
        return $this->accountsAdminJwt;
    }

    public function getConfigValue($key)
    {
        /** @var FixturesModule $fixturesModule */
        $fixturesModule = $this->getModule('\\' . FixturesModule::class);
        return $fixturesModule->config[$key];
    }
}
