<?php

namespace go1\core\learning_record\attribute;

use InvalidArgumentException;
use JsonSerializable;
use ReflectionClass;
use stdClass;

class EnrolmentAttributes implements JsonSerializable
{
    public const PROVIDER       = 1;
    public const TYPE           = 2;
    public const URL            = 3;
    public const DESCRIPTION    = 4;
    public const DOCUMENTS      = 5;
    public const AWARD_REQUIRED = 6;
    public const AWARD_ACHIEVED = 7;
    public const UTM_SOURCE     = 8;
    public const UTM_CONTENT    = 9;

    public const S_PROVIDER       = 'provider';
    public const S_TYPE           = 'type';
    public const S_URL            = 'url';
    public const S_DESCRIPTION    = 'description';
    public const S_DOCUMENTS      = 'documents';
    public const S_AWARD_REQUIRED = 'award_required';
    public const S_AWARD_ACHIEVED = 'award_achieved';
    public const S_UTM_SOURCE     = 'utm_source';
    public const S_UTM_CONTENT    = 'utm_content';

    public $id;
    public $enrolmentId;
    public $key;
    public $value;
    public $created;

    public static function create(stdClass $input): EnrolmentAttributes
    {
        $attribute = new EnrolmentAttributes();
        $attribute->id = isset($input->id) ? (int) $input->id : null;
        $attribute->enrolmentId = $input->enrolment_id ?? 0;
        $attribute->key = $input->key;
        $attribute->value = $input->value;
        $attribute->created = $input->created ?? time();

        return $attribute;
    }

    public function jsonSerialize(): array
    {
        return [
            'id'           => $this->id,
            'enrolment_id' => $this->enrolmentId,
            'key'          => $this->key,
            'value'        => $this->value,
            'created'      => $this->created,
        ];
    }

    public static function machineName(int $attribute): ?string
    {
        $map = [
            self::PROVIDER       => self::S_PROVIDER,
            self::TYPE           => self::S_TYPE,
            self::URL            => self::S_URL,
            self::DESCRIPTION    => self::S_DESCRIPTION,
            self::DOCUMENTS      => self::S_DOCUMENTS,
            self::AWARD_REQUIRED => self::S_AWARD_REQUIRED,
            self::AWARD_ACHIEVED => self::S_AWARD_ACHIEVED,
            self::UTM_SOURCE     => self::S_UTM_SOURCE,
            self::UTM_CONTENT    => self::S_UTM_CONTENT,
        ];

        return $map[$attribute] ?? null;
    }

    public static function toNumeric(string $attribute): ?int
    {
        switch ($attribute) {
            case self::S_PROVIDER:
                return self::PROVIDER;
            case self::S_TYPE:
                return self::TYPE;
            case self::S_URL:
                return self::URL;
            case self::S_DESCRIPTION:
                return self::DESCRIPTION;
            case self::S_DOCUMENTS:
                return self::DOCUMENTS;
            case self::S_AWARD_REQUIRED:
                return self::AWARD_REQUIRED;
            case self::S_AWARD_ACHIEVED:
                return self::AWARD_ACHIEVED;
            case self::S_UTM_SOURCE:
                return self::UTM_SOURCE;
            case self::S_UTM_CONTENT:
                return self::UTM_CONTENT;
            default:
                throw new InvalidArgumentException('Unknown attribute: ' . $attribute);
        }
    }

    public static function all(): array
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
