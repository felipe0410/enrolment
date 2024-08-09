<?php

use Codeception\Util\HttpCode;

/**
 * Inherited Methods
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause()
 *
 * @SuppressWarnings(PHPMD)
 */
class IntegrationTester extends \Codeception\Actor
{
    use _generated\IntegrationTesterActions;
    use go1\integrationTest\Support\InternalApiTrait\WaitSuccessTimeout;

    public function assignPlan(int $portalId, int $loId, int $userId, array $payload)
    {
        // payload example: ["due_date" => strtotime('+5 days'), "status" => -2];
        $this->haveHttpHeader('Content-Type', 'application/json');
        $this->sendPOST("/enrolment/plan/{$portalId}/{$loId}/user/{$userId}", $payload);
        $this->seeResponseCodeIs(HttpCode::OK);

        return json_decode($this->grabResponse(), false);
    }

    /**
     * @return mixed
     * @see MGL-933
     */
    public function createRecurring(string $nextAssignedAt = null, string $nextDueDateAt = null)
    {
        $this->wantToTest('Send a POST enrolment/recurring request with valid payload, then 200 response is shown');
        $this->havePortal('portal_1');
        $admin = $this->haveUser('admin');

        $this->amBearerAuthenticated($admin->jwt);
        $this->sendPOST('/enrolment/recurring', [
            "frequency_type" => "month",
            "frequency_interval" => 2,
            "next_assigned_at" => $nextAssignedAt ?? (new DateTime('+2 months'))->format('Y-m-d\TH:i:sO'),
            "next_duedate_at" => $nextDueDateAt ?? (new DateTime('+3 months'))->format('Y-m-d\TH:i:sO'),
        ]);
        $this->seeResponseCodeIs(HttpCode::OK);
        $this->seeResponseJsonMatchesJsonPath('$.id');

        return json_decode($this->grabResponse(), false);
    }

    public function assignContentToGroup(int $groupId, array $loIds, int $dueDate = 0)
    {
        $params = [
            'group_id' => $groupId,
            'lo_ids' => $loIds,
            'lo_type' => 'lo'
        ];

        if (!empty($dueDate)) {
            $params['due_date'] = $dueDate;
        }

        $this->haveHttpHeader('Content-Type', 'application/json');
        $this->sendPOST('/go1-core-group/assignment', $params);
        $this->seeResponseCodeIs(HttpCode::OK);
    }
}
