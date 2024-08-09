<?php

namespace go1\enrolment\controller\create\validator;

use stdClass;
use Assert\Assert;
use go1\util\Error;
use go1\util\AccessChecker;
use go1\util\enrolment\EnrolmentOriginalTypes;
use go1\enrolment\EnrolmentCreateOption;
use Symfony\Component\HttpFoundation\Request;

class EnrolmentTrackingValidator
{
    private AccessChecker $accessChecker;

    public function __construct(
        AccessChecker $accessChecker
    ) {
        $this->accessChecker = $accessChecker;
    }

    public function validateParams(EnrolmentCreateOption $option): array
    {
        $enrolmentType = $option->enrolmentType;
        $actorId = $option->actorUserId;

        Assert::lazy()
            ->that($enrolmentType, 'enrolment_type')->nullOr()->numeric()->between(
                EnrolmentOriginalTypes::I_SELF_DIRECTED,
                EnrolmentOriginalTypes::I_ASSIGNED
            )
            ->that($actorId, 'jwt_actor_id')->numeric()
            ->verifyNow();

        return [$enrolmentType, $actorId];
    }
}
