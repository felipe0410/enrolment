<?php

namespace go1\enrolment\services\lo;

use go1\util\lo\LoSuggestedCompletionTypes;
use go1\util\plan\PlanTypes;

/**
 * @see LoSuggestedCompletionTypes
 */
class CompletionRule
{
    private int    $type;
    private string $value;
    private string $entityType = PlanTypes::ENTITY_LO;
    private int    $entityId;

    public function __construct(int $type, string $value)
    {
        $this->type = $type;
        $this->value = $value;
    }

    public function type(): int
    {
        return $this->type;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function withEntityType(string $entityType)
    {
        $this->entityType = $entityType;

        return $this;
    }

    public function withEntityId(int $entityId)
    {
        $this->entityId = $entityId;

        return $this;
    }
}
