<?php

namespace go1\enrolment\exceptions;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ErrorWithErrorCode extends Exception
{
    private string $errorCode;
    private HttpException $httpException;

    public function __construct(string $errorCode, HttpException $previous = null)
    {
        $this->errorCode = $errorCode;
        $this->httpException = $previous;
        parent::__construct('', 0, $previous);
    }

    public function getHttpException(): HttpException
    {
        return $this->httpException;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
