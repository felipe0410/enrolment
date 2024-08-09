<?php

namespace go1\enrolment\services;

use go1\clients\PortalClient;
use go1\enrolment\domain\ConnectionWrapper;
use go1\util\lo\LoHelper;
use go1\util\portal\PortalHelper;
use stdClass;

class PortalService
{
    private ConnectionWrapper $writeDb;
    private PortalClient $portalClient;

    public function __construct(ConnectionWrapper $writeDb, PortalClient $portalClient)
    {
        $this->writeDb = $writeDb;
        $this->portalClient = $portalClient;
    }

    public function load($portalIdOrName): ?stdClass
    {
        return PortalHelper::load($this->writeDb->get(), $portalIdOrName) ?: null;
    }

    public function loadBasicById(int $portalId): ?stdClass
    {
        $portal = $this->portalClient->loadBasic($portalId);
        return $portal ? (object) ['id' => $portal->getId(), 'title' => $portal->getTitle()] : null;
    }

    public function loadBasicByTitle(string $portalTitle): ?stdClass
    {
        $portal = $this->portalClient->loadBasic($portalTitle);
        return $portal ? (object) ['id' => $portal->getId(), 'title' => $portal->getTitle()] : null;
    }

    public function loadByLoId(int $loId): ?stdClass
    {
        $lo = LoHelper::load($this->writeDb->get(), $loId);
        return $lo ? $this->load($lo->instance_id) : null;
    }

    public function loadBasicByLoId(int $loId): ?stdClass
    {
        $lo = LoHelper::load($this->writeDb->get(), $loId);
        return $lo ? $this->loadBasicById($lo->instance_id) : null;
    }
}
