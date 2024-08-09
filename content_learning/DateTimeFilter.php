<?php

namespace go1\enrolment\content_learning;

use DateTime;
use DateTimeZone;

class DateTimeFilter
{
    public ?DateTime $from = null;
    public ?DateTime $to   = null;

    public static function create(array $input): self
    {
        $_ = new self();
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;

        if (!is_null($from)) {
            $_->from = new DateTime();
            $_->from->setTimezone(new DateTimeZone('UTC'));
            $_->from->setTimestamp((is_numeric($from) ? $from : strtotime($from)));
        }

        if (!is_null($to)) {
            $_->to = new DateTime();
            $_->to->setTimezone(new DateTimeZone('UTC'));
            $_->to->setTimestamp((is_numeric($to) ? $to : strtotime($to)));
        }

        return $_;
    }
}
