<?php
namespace GraphQL\Utils;

use GraphQL\Error\InvariantViolation;
use GraphQL\Language\AST\BooleanValue;
use GraphQL\Language\AST\EnumValue;
use GraphQL\Language\AST\FloatValue;
use GraphQL\Language\AST\IntValue;
use GraphQL\Language\AST\ListValue;
use GraphQL\Language\AST\Name;
use GraphQL\Language\AST\NullValue;
use GraphQL\Language\AST\ObjectField;
use GraphQL\Language\AST\ObjectValue;
use GraphQL\Language\AST\StringValue;
use GraphQL\Language\AST\Variable;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\IDType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\LeafType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Utils;

/**
 * Class AST
 * @package GraphQL\Utils
 */
class AST
{
    /**
     * Produces a GraphQL Value AST given a PHP value.
     *
     * Optionally, a GraphQL type may be provided, which will be used to
     * disambiguate between value primitives.
     *
     * | PHP Value     | GraphQL Value        |
     * | ------------- | -------------------- |
     * | Object        | Input Object         |
     * | Assoc Array   | Input Object         |
     * | Array         | List                 |
     * | Boolean       | Boolean              |
     * | String        | String / Enum Value  |
     * | Int           | Int                  |
     * | Float         | Int / Float          |
     * | Mixed         | Enum Value           |
     * | null          | NullValue            |
     *
     * @param $value
     * @param InputType $type
     * @return ObjectValue|ListValue|BooleanValue|IntValue|FloatValue|EnumValue|StringValue|NullValue
     */
    static function astFromValue($value, InputType $type)
    {
        if ($type instanceof NonNull) {
            $astValue = self::astFromValue($value, $type->getWrappedType());
            if ($astValue instanceof NullValue) {
                return null;
            }
            return $astValue;
        }

        if ($value === null) {
            return new NullValue([]);
        }

        // Convert PHP array to GraphQL list. If the GraphQLType is a list, but
        // the value is not an array, convert the value using the list's item type.
        if ($type instanceof ListOfType) {
            $itemType = $type->getWrappedType();
            if (is_array($value) || ($value instanceof \Traversable)) {
                $valuesASTs = [];
                foreach ($value as $item) {
                    $itemAST = self::astFromValue($item, $itemType);
                    if ($itemAST) {
                        $valuesASTs[] = $itemAST;
                    }
                }
                return new ListValue(['values' => $valuesASTs]);
            }
            return self::astFromValue($value, $itemType);
        }

        // Populate the fields of the input object by creating ASTs from each value
        // in the PHP object according to the fields in the input type.
        if ($type instanceof InputObjectType) {
            $isArray = is_array($value);
            $isArrayLike = $isArray || $value instanceof \ArrayAccess;
            if ($value === null || (!$isArrayLike && !is_object($value))) {
                return null;
            }
            $fields = $type->getFields();
            $fieldASTs = [];
            foreach ($fields as $fieldName => $field) {
                if ($isArrayLike) {
                    $fieldValue = isset($value[$fieldName]) ? $value[$fieldName] : null;
                } else {
                    $fieldValue = isset($value->{$fieldName}) ? $value->{$fieldName} : null;
                }

                // Have to check additionally if key exists, since we differentiate between
                // "no key" and "value is null":
                if (null !== $fieldValue) {
                    $fieldExists = true;
                } else if ($isArray) {
                    $fieldExists = array_key_exists($fieldName, $value);
                } else if ($isArrayLike) {
                    /** @var \ArrayAccess $value */
                    $fieldExists = $value->offsetExists($fieldName);
                } else {
                    $fieldExists = property_exists($value, $fieldName);
                }

                if ($fieldExists) {
                    $fieldNode = self::astFromValue($fieldValue, $field->getType());

                    if ($fieldNode) {
                        $fieldASTs[] = new ObjectField([
                            'name' => new Name(['value' => $fieldName]),
                            'value' => $fieldNode
                        ]);
                    }
                }
            }
            return new ObjectValue(['fields' => $fieldASTs]);
        }

        // Since value is an internally represented value, it must be serialized
        // to an externally represented value before converting into an AST.
        if ($type instanceof LeafType) {
            $serialized = $type->serialize($value);
        } else {
            throw new InvariantViolation("Must provide Input Type, cannot use: " . Utils::printSafe($type));
        }

        if (null === $serialized) {
            return null;
        }

        // Others serialize based on their corresponding PHP scalar types.
        if (is_bool($serialized)) {
            return new BooleanValue(['value' => $serialized]);
        }
        if (is_int($serialized)) {
            return new IntValue(['value' => $serialized]);
        }
        if (is_float($serialized)) {
            if ((int) $serialized == $serialized) {
                return new IntValue(['value' => $serialized]);
            }
            return new FloatValue(['value' => $serialized]);
        }
        if (is_string($serialized)) {
            // Enum types use Enum literals.
            if ($type instanceof EnumType) {
                return new EnumValue(['value' => $serialized]);
            }

            // ID types can use Int literals.
            $asInt = (int) $serialized;
            if ($type instanceof IDType && (string) $asInt === $serialized) {
                return new IntValue(['value' => $serialized]);
            }

            // Use json_encode, which uses the same string encoding as GraphQL,
            // then remove the quotes.
            return new StringValue([
                'value' => substr(json_encode($serialized), 1, -1)
            ]);
        }

        throw new InvariantViolation('Cannot convert value to AST: ' . Utils::printSafe($serialized));
    }

    /**
     * Produces a PHP value given a GraphQL Value AST.
     *
     * A GraphQL type must be provided, which will be used to interpret different
     * GraphQL Value literals.
     *
     * | GraphQL Value        | PHP Value     |
     * | -------------------- | ------------- |
     * | Input Object         | Assoc Array   |
     * | List                 | Array         |
     * | Boolean              | Boolean       |
     * | String               | String        |
     * | Int / Float          | Int / Float   |
     * | Enum Value           | Mixed         |
     * | Null Value           | null          |
     *
     * @param $valueAST
     * @param InputType $type
     * @param null $variables
     * @return array|null|\stdClass
     * @throws \Exception
     */
    public static function valueFromAST($valueAST, InputType $type, $variables = null)
    {
        if ($type instanceof NonNull) {
            // Note: we're not checking that the result of valueFromAST is non-null.
            // We're assuming that this query has been validated and the value used
            // here is of the correct type.
            return self::valueFromAST($valueAST, $type->getWrappedType(), $variables);
        }

        if (!$valueAST) {
            // When there is no AST, then there is also no value.
            // Importantly, this is different from returning the GraphQL null value.
            return ;
        }

        if ($valueAST instanceof NullValue) {
            // This is explicitly returning the value null.
            return NullValue::getNullValue();
        }

        if ($valueAST instanceof Variable) {
            $variableName = $valueAST->name->value;

            if (!$variables || !isset($variables[$variableName])) {
                // No valid return value.
                return ;
            }
            // Note: we're not doing any checking that this variable is correct. We're
            // assuming that this query has been validated and the variable usage here
            // is of the correct type.
            return $variables[$variableName];
        }

        if ($type instanceof ListOfType) {
            $itemType = $type->getWrappedType();
            $items = $valueAST instanceof ListValue ? $valueAST->values : [$valueAST];
            $result = [];
            foreach ($items as $itemAST) {
                $value = self::valueFromAST($itemAST, $itemType, $variables);
                if ($value === NullValue::getNullValue()) {
                    $value = null;
                }
                $result[] = $value;
            }
            return $result;
        }

        if ($type instanceof InputObjectType) {
            $fields = $type->getFields();
            if (!$valueAST instanceof ObjectValue) {
                // No valid return value.
                return ;
            }
            $fieldASTs = Utils::keyMap($valueAST->fields, function($field) {return $field->name->value;});
            $values = [];
            foreach ($fields as $field) {
                $fieldAST = isset($fieldASTs[$field->name]) ? $fieldASTs[$field->name] : null;
                $fieldValue = self::valueFromAST($fieldAST ? $fieldAST->value : null, $field->getType(), $variables);

                // If field is not in AST and defaultValue was never set for this field - do not include it in result
                if (null === $fieldValue && null === $field->defaultValue && !$field->defaultValueExists()) {
                    continue;
                }

                // Set Explicit null value or default value:
                if (NullValue::getNullValue() === $fieldValue) {
                    $fieldValue = null;
                } else if (null === $fieldValue) {
                    $fieldValue = $field->defaultValue;
                }

                $values[$field->name] =  $fieldValue;
            }
            return $values;
        }

        if ($type instanceof LeafType) {
            return $type->parseLiteral($valueAST);
        }

        throw new InvariantViolation('Must be input type');
    }
}
