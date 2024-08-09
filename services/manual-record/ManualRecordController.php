<?php

namespace go1\core\learning_record\manual_record;

use Assert\Assert;
use Assert\LazyAssertionException;
use go1\core\util\client\UserDomainHelper;
use go1\enrolment\domain\ConnectionWrapper;
use go1\enrolment\services\PortalService;
use go1\util\AccessChecker;
use go1\util\DB;
use go1\util\enrolment\ManualRecord;
use go1\util\enrolment\ManualRecordRepository;
use go1\util\Error;
use go1\util\lo\LoChecker;
use go1\util\lo\LoHelper;
use go1\util\Text;
use HTMLPurifier;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ManualRecordController
{
    private ConnectionWrapper $go1;
    private ManualRecordRepository $repository;
    private HTMLPurifier $html;
    private AccessChecker $access;
    private LoChecker $lo;
    private UserDomainHelper $userDomainHelper;
    private PortalService $portalService;

    public function __construct(
        ConnectionWrapper $go1,
        ManualRecordRepository $repository,
        HTMLPurifier $html,
        AccessChecker $accessChecker,
        LoChecker $lo,
        UserDomainHelper $userDomainHelper,
        PortalService $portalService
    ) {
        $this->go1 = $go1;
        $this->repository = $repository;
        $this->html = $html;
        $this->access = $accessChecker;
        $this->lo = $lo;
        $this->userDomainHelper = $userDomainHelper;
        $this->portalService = $portalService;
    }

    public function post(string $instance, string $entityType, $entityId, Request $req)
    {
        if (!$user = $this->access->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$instanceId = ($this->portalService->loadBasicByTitle($instance)->id ?? null)) {
            return Error::simpleErrorJsonResponse('Portal not found.', 406);
        }

        if ('lo' === $entityType) {
            $lo = LoHelper::load($this->go1->get(), $entityId);
            if (!$lo) {
                return Error::simpleErrorJsonResponse('Entity not found.', 406);
            }

            if ($instanceId != $lo->instance_id && $lo->marketplace != 1) {
                return Error::simpleErrorJsonResponse('Invalid portal.', 406);
            }
        }

        if (!$this->access->hasAccount($req, $instance)) {
            if (!$this->access->isPortalAdmin($req, $instance)) {
                return Error::simpleErrorJsonResponse('Account not found.', 403);
            }
        }

        $data = $req->get('data', []);
        Text::purify($this->html, $data);

        try {
            Assert::lazy()
                  ->that($entityType, 'entityType')->inArray(['lo', 'external'])
                  ->verifyNow();

            $previousRecord = $this->repository->loadByEntity($instanceId, $entityType, $entityId, $user->id);
            if ($previousRecord) {
                return $previousRecord->verified
                    ? new JsonResponse($previousRecord)
                    : Error::jr(sprintf('Manual record #%d was created, waiting for verification.', $previousRecord->id));
            }

            $record = ManualRecord::create((object) [
                'instance_id' => $instanceId,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'user_id'     => $user->id,
                'data'        => $data,
            ]);

            $this->repository->create($record);
            return new JsonResponse($record, 201);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }
    }

    private function access(int $id, Request $req, bool $requireAdmin = true)
    {
        if (!$user = $this->access->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (!$record = $this->repository->load($id)) {
            return Error::jr404('Manual record not found.');
        }

        if (!$portal = $this->portalService->loadBasicById($record->instanceId)) {
            return Error::jr406('Portal not found.');
        }

        $isAdmin = $this->access->isPortalAdmin($req, $portal->title);
        if ($requireAdmin && !$isAdmin) {
            if (!$this->lo->isAuthor($this->go1->get(), $record->entityId, $user->id)) {
                return Error::jr403('Only course author can verify the manual record.');
            }
        }

        if (!$isAdmin) {
            if ($user->id != $record->userId) {
                return Error::jr403('Access denied.');
            }
        }

        if (!in_array($record->entityType, ['lo', 'external'])) {
            return Error::jr406('Unsupported manual record type.');
        }

        return $record;
    }

    public function put(int $id, Request $req)
    {
        $record = $this->access($id, $req, false);
        if ($record instanceof Response) {
            return $record;
        }

        $entityType = $req->get('entity_type');
        $entityId = $req->get('entity_id');
        $data = $req->get('data');
        Text::purify($this->html, $data);
        $record->entityType = $entityType ?? $record->entityType;
        $record->entityId = $entityId ?? $record->entityId;
        $record->data = $data;
        $this->repository->update($record);

        return new JsonResponse(null, 200);
    }

    public function putVerify(int $id, $value, Request $req)
    {
        if (!$user = $this->access->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        $record = $this->access($id, $req);
        if ($record instanceof Response) {
            return $record;
        }

        $verified = (bool) $value;
        if ($verified != $record->verified) {
            $record->verified = $verified;
            $this->repository->update($record, $user->id);
        }

        return new JsonResponse(null, 200);
    }

    public function delete(int $id, Request $req)
    {
        $record = $this->access($id, $req, false);
        if ($record instanceof Response) {
            return $record;
        }

        $this->repository->delete($id);

        return new JsonResponse(null, 204);
    }

    public function get($instance, $loId, Request $req)
    {
        if (!$user = $this->access->validUser($req)) {
            return Error::createMissingOrInvalidJWT();
        }

        if (is_numeric($instance)) {
            $portal = $this->portalService->loadBasicById((int) $instance);
        } else {
            $portal = $this->portalService->loadBasicByTitle($instance);
        }
        if (!$portal) {
            return Error::simpleErrorJsonResponse('Portal not found', 404);
        }

        $record = $this->repository->loadByEntity($portal->id, 'lo', $loId, $user->id);

        return $record
            ? new JsonResponse($record, 200)
            : new JsonResponse(null, 404);
    }

    /**
     * @return stdClass[]
     */
    private function loadManualRecords(int $offset, int $limit, int $userId, int $instanceId, ?string $entityType, ?int $entityId, ?bool $verified): array
    {
        $q = $this->repository->db()
            ->createQueryBuilder()
            ->from('enrolment_manual', 'm')
            ->select('m.*')
            ->andWhere('m.instance_id = :instance_id')
            ->andWhere('m.user_id = :user_id')
            ->setParameter(':user_id', $userId)
            ->setParameter(':instance_id', $instanceId)
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($entityType) {
            $q
                ->andWhere('m.entity_type = :entity_type')
                ->setParameter(':entity_type', $entityType);
        }

        if ($entityId) {
            $q
                ->andWhere('m.entity_id= :entity_id')
                ->setParameter(':entity_id', $entityId);
        }

        if (!is_null($verified)) {
            $q
                ->andWhere('m.verified = :verified')
                ->setParameter(':verified', $verified);
        }

        $q = $q->execute();
        return $q->fetchAll(DB::OBJ);
    }

    public function browse($instance, $userId, $limit, $offset, Request $req)
    {
        $entityType = $req->get('entityType');
        $entityId = $req->get('entityId');
        $verified = $req->get('verified');
        $verified = ('all' === $verified) ? null : $verified;
        $verified = ('true' === $verified) ? true : $verified;
        $verified = ('false' === $verified) ? false : $verified;

        try {
            if (!$user = $this->access->validUser($req)) {
                return Error::createMissingOrInvalidJWT();
            }

            if ('me' === $userId) {
                $userId = $user->id;
            }

            if (!$instanceId = ($this->portalService->loadBasicByTitle($instance)->id ?? null)) {
                return Error::simpleErrorJsonResponse('Portal not found.', 406);
            }

            Assert::lazy()
                ->that($userId, 'userId')->numeric()->min(1)
                ->that($verified, 'verified')->nullOr()->boolean()
                ->that($entityType, 'entityType')->nullOr()->string()
                ->that($entityId, 'entityId')->nullOr()->numeric()->min(1)
                ->verifyNow();

            if ($userId != $user->id) {
                if (!$this->access->isPortalAdmin($req, $instance)) {
                    if (!$student = $this->userDomainHelper->loadUser($userId, $instance)) {
                        return Error::simpleErrorJsonResponse('User not found.', 406);
                    }

                    if (!$this->access->isPortalManager($req, $instance)) {
                        $actorPortalAccount = $this->access->validAccount($req, $instance);
                        $isManager = ($actorPortalAccount && !empty($student->account))
                            ? $this->userDomainHelper->isManager($instance, $actorPortalAccount->id, $student->account->legacyId)
                            : false;

                        if (!$isManager) {
                            return Error::simpleErrorJsonResponse('Only portal admin can browse other manual records.', 403);
                        }
                    }
                }
            }

            $rows = $this->loadManualRecords($offset, $limit, $userId, $instanceId, $entityType, $entityId, $verified);

            $records = [];
            $loIds = [];
            foreach ($rows as $row) {
                $records[] = ManualRecord::create($row);

                if ('lo' === $row->entity_type) {
                    $loIds[] = $row->entity_id;
                }
            }

            if ($loIds) {
                $los = LoHelper::loadMultiple($this->go1->get(), $loIds);

                $losById = [];
                foreach ($los as $lo) {
                    $losById[$lo->id] = $lo;
                }
                foreach ($records as &$record) {
                    $loId = $record->entityId;
                    if ($record->entityType === 'lo' && isset($losById[$loId])) {
                        $record->entity = $losById[$loId];
                    }
                }
            }

            return new JsonResponse($records);
        } catch (LazyAssertionException $e) {
            return Error::createLazyAssertionJsonResponse($e);
        }
    }
}
