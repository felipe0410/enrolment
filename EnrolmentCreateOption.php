<?php

namespace go1\enrolment;

use stdClass;
use go1\util\model\Enrolment;
use go1\util\enrolment\EnrolmentOriginalTypes;

class EnrolmentCreateOption
{
    /**
     * @deprecated
     */
    public $profileId;

    public $userId;
    public $learningObject;
    public $portalId;
    public $status;
    public $parentLearningObjectId = 0;
    public $parentEnrolmentId = 0;
    public $reEnrol = false;
    public $data = [];
    public $reCalculate = false;
    public $dueDate = false;
    public $startDate = false;
    public $notify = true;
    public $pass = 0;
    public $endDate = false;
    public $result = 0;
    public $enrolmentType;
    public $assignDate = false;

    /**
     * @var stdClass
     */
    public $parentEnrolment;
    public $transaction;
    public $assigner;
    public $actorUserId;
    public $attributes;

    public static function create()
    {
        return new EnrolmentCreateOption();
    }

    public function createEnrolment(): Enrolment
    {
        $enrolment = Enrolment::create();
        $enrolment->takenPortalId = $this->portalId;
        $enrolment->profileId = $this->profileId;
        $enrolment->userId = $this->userId;
        $enrolment->loId = $this->learningObject->id;
        $enrolment->parentLoId = $this->parentLearningObjectId;
        $enrolment->parentEnrolmentId = $this->parentEnrolmentId;
        $enrolment->startDate = $this->startDate;
        $enrolment->endDate = $this->endDate;
        $enrolment->status = $this->status;
        $enrolment->data = $this->data;
        $enrolment->pass = $this->pass;
        $enrolment->result = $this->result;

        return $enrolment;
    }
}
