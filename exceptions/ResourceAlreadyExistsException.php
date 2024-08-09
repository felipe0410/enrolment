<?php

namespace go1\enrolment\exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ResourceAlreadyExistsException extends ConflictHttpException
{
    private ?string $existingId;
    // Redefine the exception so message isn't optional
    public function __construct($message, ?string $existingId = null)
    {
        // some code

        // make sure everything is assigned properly
        parent::__construct($message);
        $this->existingId = $existingId;
    }

    public function getExistingId(): ?string
    {
        return $this->existingId;
    }
}
