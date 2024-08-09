<?php

namespace go1\enrolment\services;

/**
 * Provide context object so that methods can understand the flow it's being in.
 */
class Context
{
    private string $workflow;

    public static function nullContext(): Context
    {
        $ctx = new Context('');

        return $ctx;
    }

    public function __construct(string $workflow)
    {
        $this->workflow = $workflow;
    }

    public function onCreatingEnrolment(): bool
    {
        return $this->workflow === 'enrolment.create';
    }
}
