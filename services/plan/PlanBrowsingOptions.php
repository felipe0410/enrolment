<?php

namespace go1\core\learning_record\plan;

use Assert\Assert;
use Doctrine\DBAL\Connection;
use go1\core\util\client\UserDomainHelper;
use go1\util\AccessChecker;
use go1\util\group\GroupHelper;
use Symfony\Component\HttpFoundation\Request;

final class PlanBrowsingOptions
{
    public const TYPE_LO    = 'lo';
    public const TYPE_AWARD = 'award';
    public const TYPE_ALL   = 'all';

    /** @var int */
    public $portalId;

    /** @var int */
    public $userId;

    /** @var int */
    public $groupId;

    /** @var int[]|null */
    public $entityId;

    /** @var int[]|null */
    public $id;

    /** @var bool */
    public $dueDate;

    /** @var string */
    public $type;

    /** @var string|null */
    public $sort;
    public $direction;

    public $offset;
    public $limit;

    public static function create(
        Request $req,
        Connection $go1,
        Connection $social,
        string $accountsName,
        AccessChecker $accessChecker,
        UserDomainHelper $userDomainHelper,
        $portal
    ) {
        $portalId = $portal ? $portal->id : 0;
        $userId     = $req->get('userId');
        $groupId    = $req->get('groupId');
        $entityId   = $req->get('entityId');
        $entityId   = is_string($entityId) ? array_unique(explode(',', $entityId)) : (array) $entityId;
        $id         = $req->get('id');
        $id         = is_string($id) ? array_unique(explode(',', $id)) : (array) $id;
        $dueDate    = $req->get('dueDate');
        $dueDate    = is_null($dueDate) ? $dueDate : (bool) $dueDate;
        $type       = $req->get('type', self::TYPE_ALL);
        $sort       = $req->get('sort');
        $direction  = $req->get('direction', 'ASC');
        $limit      = $req->get('limit', 20);
        $offset     = $req->get('offset', 0);

        $currentUser = $accessChecker->validUser($req);
        $isAdmin     = $portal ? $accessChecker->isPortalAdmin($req, $portal->title) : $accessChecker->isAccountsAdmin($req);
        if (!$isAdmin && $portal && $userId) {
            $student = $userDomainHelper->loadUser($userId, $portal->title);
            $actorPortalAccount = $accessChecker->validAccount($req, $portal->title);
            $isAdmin = ($actorPortalAccount && !empty($student->account))
                ? $userDomainHelper->isManager($portal->title, $actorPortalAccount->id, $student->account->legacyId, true)
                : false;
        }

        $claim = Assert::lazy();

        if ($portal) {
            $claim
                ->that($accessChecker->hasAccount($req, $portal->title), 'user')->true('User does not belong to the portal.');
        }
        if ($userId) {
            $claim
                ->that($isAdmin, 'userId')->eq(true, 'Only admin can use userId filter.');
        }
        if ($groupId) {
            $claim
                ->that($portalId, 'groupId')->notEq(0, 'Can not use `groupId` filter when browse in all portal.');
        }

        $claim
            ->that($userId, 'userId')->nullOr()->integerish()
            ->that($groupId, 'groupId')->nullOr()->integerish()
            ->that($entityId, 'entityId')->isArray()->all()->integerish()
            ->that($id, 'id')->isArray()->all()->integerish()
            ->that($dueDate, 'dueDate')->nullOr()->boolean()
            ->that($type, 'type')->inArray([self::TYPE_LO, self::TYPE_AWARD, self::TYPE_ALL])
            ->that($sort, 'sort')->nullOr()->string()->inArray(['id', 'created_date', 'due_date'])
            ->that($direction, 'direction')->string()->inArray(['ASC', 'DESC'])
            ->that($limit, 'limit')->nullOr()->integerish()->min(1)->max(100)
            ->that($offset, 'offset')->nullOr()->integerish()->min(0);

        if ($groupId) {
            $hasAccess = $isAdmin ? true : GroupHelper::canAccess($go1, $social, $currentUser->id, $groupId);
            $claim
                ->that(GroupHelper::load($social, $groupId), 'groupId')->isObject('Invalid group ID.')
                ->that($hasAccess, 'groupId')->eq(true, 'User does not belong to the group.');
        }

        $claim->verifyNow();

        $o             = new static();
        $o->portalId = $portalId;
        $o->userId     = $userId ?? $currentUser->id;
        $o->groupId    = $groupId;
        $o->entityId   = $entityId;
        $o->id         = $id;
        $o->dueDate    = $dueDate;
        $o->type       = (self::TYPE_ALL == $type) ? null : $type;
        $o->sort       = $sort;
        $o->direction  = $direction;
        $o->offset     = $offset;
        $o->limit      = $limit;

        return $o;
    }
}
