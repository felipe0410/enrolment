<?php

namespace go1\enrolment\content_learning;

use stdClass;

class ContentLearningFilterOptions
{
    public stdClass        $portal;
    public int             $loId;
    public ?array          $sort;
    public ?string         $status         = null;
    public ?string         $activityType   = null;
    public ?array          $userIds        = null;
    public ?int            $offset         = 0;
    public ?int            $limit          = 20;
    public bool            $facet          = false;
    public ?bool           $overdue        = null;
    public ?stdClass       $managerAccount = null;
    public ?DateTimeFilter $startedAt      = null;
    public ?DateTimeFilter $endedAt        = null;
    public ?DateTimeFilter $assignedAt     = null;
    public ?DateTimeFilter $dueAt          = null;
    public ?array          $fields         = null;
    public int             $groupId        = 0;
    public ?int            $passed         = null;

    /**
     * @var int[]
     */
    public ?array $assignerIds = null;
    public ?int $accountStatus = null;
}
