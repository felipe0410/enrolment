<?php

namespace go1\enrolment\domain;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager;
use PDO;

class ConnectionWrapper
{
    private array $params;
    private ?Configuration $config;
    private ?EventManager $eventManager;
    private ?Connection $connection = null;

    /**
     * @see \Doctrine\DBAL\DriverManager::getConnection
     *
     * Initializes a new instance of the Connection class.
     *
     * @param mixed[] $params The connection parameters.
     * @param Configuration|null $config The configuration, optional.
     * @param EventManager|null $eventManager The event manager, optional.
     *
     * @throws DBALException
     */
    public function __construct(
        array $params,
        ?Configuration $config = null,
        ?EventManager $eventManager = null,
        Connection $connection = null
    ) {
        $this->params = $params;
        $this->config = $config;
        $this->eventManager = $eventManager;
        $this->connection = $connection;
    }

    /**
     * @throws DBALException
     */
    public function get(): Connection
    {
        if (is_null($this->connection)) {
            $this->params['pdo'] = isset($this->params['pdo']) ? $this->params['pdo']->get() : null;
            $this->params['driverOptions'][PDO::ATTR_TIMEOUT] = 10;
            $this->params['driverOptions'][PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
            $this->connection = DriverManager::getConnection(
                $this->params,
                $this->config,
                $this->eventManager
            );
        }

        return $this->connection;
    }
}
