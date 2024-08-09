<?php

namespace go1\enrolment\controller\create;

use go1\util\enrolment\EnrolmentStatuses;
use Symfony\Component\HttpFoundation\Request;
use go1\util\DateTime as DateTimeHelper;

class CreateMiddleware
{
    public function __invoke(Request $req): void
    {
        $req->attributes->set('isSlimEnrolment', true);

        $reEnrol = filter_var($req->query->get('re-enroll', false), FILTER_VALIDATE_BOOLEAN);
        $req->attributes->set('reEnrol', $reEnrol);

        if ($startDates = $req->request->get('start_date')) {
            $req->attributes->set('startDate', DateTimeHelper::atom($startDates, DATE_ISO8601));
        }
        if ($endDate = $req->request->get('end_date')) {
            $req->attributes->set('endDate', $endDate);
        }
        if ($dueDates = $req->request->get('due_date')) {
            $req->attributes->set('dueDate', DateTimeHelper::atom($dueDates, DATE_ISO8601));
        }
        if ($loId = $req->request->get('lo_id')) {
            $req->attributes->set('loId', $loId);
        }
        if ($parentEnrolmentId = $req->request->get('parent_enrollment_id', 0)) {
            $req->attributes->set('parentEnrolmentId', $parentEnrolmentId);
        }
    }
}
