<?php

namespace go1\enrolment\domain;

use PDO;

class PDOWrapper
{
    private string $dsn;
    private ?string $username;
    private ?string $password;
    private ?array  $pdoOptions;

    public function __construct(string $dsn, string $username = null, string $password = null, array $pdoOptions = [])
    {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->pdoOptions = $pdoOptions;
    }

    public function get(): PDO
    {
        return new PDO($this->dsn, $this->username, $this->password, $this->pdoOptions);
    }
}
