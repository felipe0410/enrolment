<?php

namespace go1\core\learning_record\attribute;

use Assert\Assert;
use Assert\LazyAssertionException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\ParameterBag;

class AttributeCreateOptions
{
    /**
     * @param ParameterBag $params
     * @param int          $enrolmentId
     * @param array        $externalLearningTypeAllow
     * @return EnrolmentAttributes[]
     * @throws LazyAssertionException
     */
    public static function create(ParameterBag $params, int $enrolmentId, array $externalLearningTypeAllow): array
    {
        $params = $params->all();
        $attributes = self::validate($params, $externalLearningTypeAllow);

        return array_map(
            function ($attribute) use ($enrolmentId) {
                $attribute->enrolmentId = $enrolmentId;

                return $attribute;
            },
            $attributes
        );
    }

    /**
     * @param ParameterBag|mixed $params
     * @param array $externalLearningTypeAllow
     * @return EnrolmentAttributes[]
     * @throws LazyAssertionException
     */
    public static function validate($params, $externalLearningTypeAllow): array
    {
        $claim = Assert::lazy();
        $claim
            ->that($params, 'attributes')->notBlank()->isArray()->notEmpty()
            ->verifyNow();

        foreach ($params as $key => $value) {
            if ($key == 'date') {
                $claim->that($value, 'date')->date('Y-m-d');
                continue;
            }

            $claim
                ->that(EnrolmentAttributes::toNumeric($key), 'attribute');

            if (EnrolmentAttributes::S_URL == $key) {
                $claim
                    ->that($value, 'url')->url();
            }

            if (EnrolmentAttributes::S_TYPE == $key) {
                $claim
                    ->that($value, 'type')->string()->inArray($externalLearningTypeAllow);
            }

            $supportArrTypes = [
                EnrolmentAttributes::S_DOCUMENTS,
                EnrolmentAttributes::S_AWARD_REQUIRED,
                EnrolmentAttributes::S_AWARD_ACHIEVED,
            ];
            $type = in_array($key, $supportArrTypes)
                ? EnrolmentAttributeTypes::ARRAY : strtoupper(gettype($value));
            switch ($type) {
                case EnrolmentAttributeTypes::BOOLEAN:
                    if (is_numeric($value)) {
                        $claim
                            ->that($value, $key)->numeric()->inArray([0, 1]);
                        break;
                    }
                    $claim
                        ->that($value, $key)->boolean();
                    break;
                case EnrolmentAttributeTypes::INTEGER:
                    $claim
                        ->that($value, $key)->numeric();
                    break;
                case EnrolmentAttributeTypes::STRING:
                    $claim
                        ->that($value, $key)->string();
                    break;
                case EnrolmentAttributeTypes::ARRAY:
                    if ($key == EnrolmentAttributes::S_DOCUMENTS) {
                        $claim
                            ->that($value, $key)
                            ->isArray('Documents needs to be an array')
                            ->notEmpty('A document must have at least one attached to attribute')
                            ->satisfy(
                                function ($value) use ($claim) {
                                    foreach ($value as $document) {
                                        $claim
                                            ->that($document, 'document')
                                            ->isArray('Document needs to be an array');
                                        $claim
                                            ->that($document['name'] ?? '', 'name')->string()
                                            ->that($document['size'] ?? '', 'size')->integer()
                                            ->that($document['type'] ?? '', 'type')->string()
                                            ->that($document['url'] ?? '', 'url')->string();
                                    }
                                }
                            );
                    } elseif (in_array($key, [EnrolmentAttributes::S_AWARD_REQUIRED, EnrolmentAttributes::S_AWARD_ACHIEVED])) {
                        $text = ($key == EnrolmentAttributes::S_AWARD_REQUIRED) ? 'required' : 'achieved';
                        $claim
                            ->that($value, $key)
                            ->isArray("Award $text needs to be an array")
                            ->notEmpty("Award $text must have at least one attached to attribute")
                            ->satisfy(
                                function ($value) use ($claim) {
                                    foreach ($value as $goal) {
                                        $claim
                                            ->that($goal, 'goal')
                                            ->isArray('Goal needs to be an array');
                                        $claim
                                            ->that($goal['goal_id'] ?? '', 'goal_id')->integer()
                                            ->that($goal['value'] ?? '', 'value')->numeric();

                                        if (!isset($goal['requirements'])) {
                                            continue;
                                        }

                                        $claim
                                            ->that($requirements = $goal['requirements'], 'requirements')
                                            ->isArray('Requirements need to be an array')
                                            ->notEmpty('Requirements must have at least one')
                                            ->satisfy(
                                                function ($requirements) use ($claim) {
                                                    foreach ($requirements as $_) {
                                                        $claim
                                                            ->that($_['goal_id'] ?? '', 'goal_id')->integer()
                                                            ->that($_['value'] ?? '', 'value')->numeric();
                                                    }
                                                }
                                            );
                                    }
                                }
                            );
                    }
                    $value = json_encode($value);
                    break;
                default:
                    throw new InvalidArgumentException('Unsupported attribute value type');
            }

            $attributes[] = EnrolmentAttributes::create((object) [
                'key'   => EnrolmentAttributes::toNumeric($key),
                'value' => $value,
            ]);
        }
        $claim->verifyNow();

        return $attributes ?? [];
    }
}
