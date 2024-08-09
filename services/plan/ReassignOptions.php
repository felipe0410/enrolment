<?php

namespace go1\core\learning_record\plan;

use Assert\Assert;
use Symfony\Component\HttpFoundation\Request;

final class ReassignOptions
{
    public ?int $planId;
    public ?int $loId;
    public ?int $userId;
    public ?int $portalId;
    public ?int $dueDate;
    public ?int $reassignDate;
    public ?int $assignerUserId;

    public const REASSIGNED = 'reassigned';
    public const AUTO_REASSIGNED = 'auto-reassigned';

    public static function create(Request $req)
    {
        $payload = $req->request->all();
        $planIds = $req->get('plan_ids');
        $loId = $req->get('lo_id');
        $userId = $req->get('user_id');
        $portalId = $req->get('portal_id');
        $dueDate = $req->get('due_date');
        $reassignDate = $req->get('reassign_date');
        $assignerUserId = $req->get('assigner_user_id');
        $fieldNames = array_keys($payload);

        $claim = Assert::lazy();
        $claim
            ->that($fieldNames, 'payload')->all()->inArray(
                ['plan_ids', 'lo_id', 'user_id', 'portal_id', 'due_date', 'reassign_date', 'notify', 'assigner_user_id'],
                fn ($arg) => "Unknown field '{$arg['value']}'"
            )
            ->that($req->request->has('plan_ids') || $req->request->has('lo_id'), 'payload', 'plan_ids or lo_id is required.')->true()
            ->that($req->request->has('plan_ids') && $req->request->has('lo_id'), 'payload', 'Only support either plan_ids or lo_id.')->false();

        if ($req->request->has('reassign_date')) {
            $claim
                ->that($reassignDate, 'reassign_date')
                ->numeric('Reassign date must be unix timestamp value.');
        }

        if ($req->request->has('plan_ids')) {
            $claim
                ->that($planIds, 'plan_ids', 'plan_ids is invalid.')->notNull()->all()->integerish()
                ->that($planIds, 'plan_ids', 'Only support a single plan for now.')->count(1);
            if ($dueDate) {
                $claim
                    ->that($dueDate, 'due_date')
                    ->numeric('Due date must be unix timestamp value.')
                    ->greaterThan(time(), 'Due date can not be in the past.');
            }
        }

        if ($req->request->has('lo_id')) {
            $claim
                ->that($loId, 'lo_id', 'lo_id is invalid.')
                    ->notNull()
                    ->integerish()
                ->that($portalId, 'portal_id', 'portal_id is invalid.')
                    ->notNull()
                    ->integerish()
                ->that($userId, 'user_id', 'user_id is invalid.')
                    ->notNull()
                    ->integerish()
                ->that($dueDate, 'due_date')
                    ->notNull('Missing due date.')
                    ->numeric('Due date must be unix timestamp value.')
                ->that($reassignDate, 'reassign_date')
                    ->notNull('Missing reassign date.')
                    ->lessOrEqualThan($dueDate, 'Reassign date can not be latter than Due date.');
        }

        $claim->verifyNow();

        $o = new static();
        $o->planId = $planIds[0] ?? null;
        $o->loId = $loId;
        $o->userId = $userId;
        $o->portalId = $portalId;
        $o->dueDate = $dueDate;
        $o->reassignDate = $reassignDate;
        $o->assignerUserId = $assignerUserId;

        return $o;
    }
}
