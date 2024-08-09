<?php

namespace go1\core\learning_record\attribute;

use ReflectionClass;

class EnrolmentAttributeTypes
{
    public const BOOLEAN = "BOOLEAN";
    public const INTEGER = "INTEGER";
    public const STRING  = "STRING";
    public const ARRAY   = "ARRAY";

    public static function all()
    {
        $rSelf = new ReflectionClass(__CLASS__);

        $values = [];
        foreach ($rSelf->getConstants() as $const) {
            if (is_scalar($const)) {
                $values[] = $const;
            }
        }

        return $values;
    }
}
