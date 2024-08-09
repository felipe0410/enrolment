<?php

namespace go1\core\learning_record\plan\util;

use Assert\Assert;
use Doctrine\DBAL\Connection;
use go1\util\DateTime;
use Symfony\Component\HttpFoundation\Request;
use stdClass;

final class PlanReference
{
    public const STATUS_ACTIVE     = 1;
    public const STATUS_DELETED    = 0;
    public const STATUS_REMOVED    = -1;

    public int      $id;
    public string   $sourceType;
    public int      $sourceId;
    public int      $planId;
    public int      $status;
    public string   $createdAt;
    public string   $updatedAt;

    public static function create(Request $req): ?self
    {
        $sourceType    = $req->get('source_type');
        $sourceId      = $req->get('source_id');

        if (!$sourceType || !$sourceId) {
            return null;
        }

        Assert::lazy()
            ->that($sourceType, 'source_type')->nullOr()->string()
            ->that($sourceId, 'source_id')->nullOr()->numeric()
            ->verifyNow();

        $o = new static();
        $o->sourceType = $sourceType;
        $o->sourceId = $sourceId;
        $o->status = static::STATUS_ACTIVE;

        return $o;
    }

    public static function createFromRecord(stdClass $record): ?self
    {
        $o = new static();
        $o->id = $record->id ?? 0;
        $o->planId = $record->plan_id ?? 0;
        $o->sourceType = $record->source_type ?? '';
        $o->sourceId = $record->source_id ?? 0;
        $o->status = $record->status ?? static::STATUS_ACTIVE;
        $o->createdAt = $record->created_at ?? '';
        $o->updatedAt = $record->updated_at ?? '';

        return $o;
    }

    public function setPlanId(int $planId): self
    {
        $this->planId = $planId;

        return $this;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function setUpdatedAt(string $updated): self
    {
        $this->updatedAt = $updated;

        return $this;
    }

    public function toCreateArray(): array
    {
        return [
            'plan_id'     => $this->planId,
            'source_type' => $this->sourceType,
            'source_id'   => $this->sourceId,
            'status'      => $this->status,
        ];
    }

    public function toUpdateArray(): array
    {
        return [
            'status'      => $this->status,
            'updated_at'  => DateTime::create($this->updatedAt)->format(DateTime::DEFAULT_HUMAN_FORMAT)
        ];
    }
}
