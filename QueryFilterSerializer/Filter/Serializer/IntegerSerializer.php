<?php
/**
 * User: victor
 * Date: 25.05.14
 * Time: 21:28
 */

namespace QueryFilterSerializer\Filter\Serializer;


use QueryFilterSerializer\Filter\ParsingException;
use QueryFilterSerializer\Helper\Formatter;

class IntegerSerializer extends AbstractSerializer
{
    const NAME = 'integer';

    const OPT_ALLOW_RANGES = 'ranges';
    const OPT_ALLOW_LIMITED = 'limited'; // limit the conditionals that are allowed for the filter

    const COND_DELIMITER = ';'; // delimiter that is used to split multiple values in encoded string
    const COND_EQUALS = 'eq'; // for portability we use textual representation
    const COND_NOT_EQUALS = 'neq';
    const COND_LESS_THAN_OR_EQUALS = 'lte';
    const COND_GREATER_THAN_OR_EQUALS = 'gte';
    const COND_LESS_THAN = 'lt';
    const COND_GREATER_THAN = 'gt';

    // TODO: add option to set delimiter
    protected $options = array(
        'ranges' => true,       // do we allow to set ranges: >, >=, = etc.
        'optimize' => true,
    );

    public function serialize(array $data)
    {

    }

    public function unserialize($data)
    {
        if (null === $data) {
            return array();
        }

        $values = array_filter(array_unique(explode(self::COND_DELIMITER, $data)), function($val) {
            return $val !== '';
        });

        $results = array();
        foreach ($values as $val) {
            $results[] = $this->parseValue($val);
        }

        $this->optimizeResults($results);

        return $results;
    }

    protected function optimizeResults(&$results)
    {
        if (!$this->getOption('optimize', true)) {
            return;
        }

        $groups = Formatter::groupArray(array('condition', 'value'), $results);

        $newConstraints = array();
        foreach ($groups as $op => $group) {
            switch ($op) {
                case self::COND_GREATER_THAN:
                case self::COND_GREATER_THAN_OR_EQUALS:
                    $groupVals = array_keys($group);
                    $max = max($groupVals);
                    if (null !== $max) { // has at least one value
                        foreach ($group[$max] as $constraint) {
                            $newConstraints[] = $constraint;
                        }
                    }
                    break;
                case self::COND_LESS_THAN:
                case self::COND_LESS_THAN_OR_EQUALS:
                    $groupVals = array_keys($group);
                    $min = min($groupVals);
                    if (null !== $min) {
                        foreach ($group[$min] as $constraint) {
                            $newConstraints[] = $constraint;
                        }
                    }
                    break;
                case self::COND_NOT_EQUALS:
                case self::COND_EQUALS:
                    $groupVals = array_keys($group);
                    $newConstraints[] = array('condition' => $op, 'value' => $groupVals);
                    break;
                default:
                    throw new ParseException('Undefined behavior for condition: ' . $op);
            }
        }

        $results = $newConstraints;
    }

    protected function parseValue($val)
    {
        if (is_numeric($val{0})) { // is number
            $res = array('condition' => self::COND_EQUALS, 'value' => $val);
        } else {
            if (isset($val{1}) && !is_numeric($val{1})) {
                $res['condition'] = substr($val, 0, 2);
                $res['value'] = substr($val, 2);
            } else {
                $res['condition'] = $val{0};
                $res['value'] = substr($val, 1);
            }

            $mappedConditions = $this->getConditionsAssoc(true);

            if (!isset($mappedConditions[$res['condition']])) {
                throw new ParsingException('Not found operand for integer type: ' . $res['condition']);
            }

            $res['condition'] = $mappedConditions[$res['condition']];
        }

        if (!is_numeric($res['value'])) {
            throw new ParsingException('Expected numeric value: ' . $res['value']);
        }


        return $res;
    }

    /**
     * Get list of all available conditions and their aliases
     * @param bool $flip - flip keys and values
     * @return array
     */
    protected function getConditionsAssoc($flip = false)
    {
        $assoc = array(
            self::COND_EQUALS => '=',
            self::COND_LESS_THAN_OR_EQUALS => '<=',
            self::COND_GREATER_THAN_OR_EQUALS => '>=',
            self::COND_NOT_EQUALS => '!',
            self::COND_LESS_THAN => '<',
            self::COND_GREATER_THAN => '>',
        );

        return $flip ? array_flip($assoc) : $assoc;
    }

    /**
     * Create piece of SQL with placeholder and values
     * @param $filter
     * @param $tableAlias
     * @return array first element is string, the second - list of values
     * @throws ParsingException
     */
    public function buildSqlParts($filter, $tableAlias = 't')
    {
        $sql = [];
        $fieldPhBase = $tableAlias . '_' . $filter['field'];
        // TODO: return assoc arrays of strings with values instead of using builder ?
        $num = 1;
        foreach ($filter['constraints'] as $constraint) {
            $fieldPh = $num > 1 ? $fieldPhBase . $num : $fieldPhBase;
            $num++;
            switch ($constraint['condition']) {
                case IntegerSerializer::COND_GREATER_THAN:
                    $value = is_array($constraint['value']) ? max($constraint['value']) : $constraint['value'];
                    $sql[] = [
                        'sql' => $tableAlias . '.' . $filter['field'] . ' > :' . $fieldPh,
                        'parameter' => [$fieldPh => $value]
                    ];
                    break;
                case IntegerSerializer::COND_GREATER_THAN_OR_EQUALS:
                    $value = is_array($constraint['value']) ? max($constraint['value']) : $constraint['value'];
                    $sql[] = [
                        'sql' => $tableAlias . '.' . $filter['field'] . ' >= :' . $fieldPh,
                        'parameter' => [$fieldPh => $value]
                    ];
                    break;
                case IntegerSerializer::COND_LESS_THAN:
                    $value = is_array($constraint['value']) ? min($constraint['value']) : $constraint['value'];
                    $sql[] = [
                        'sql' => $tableAlias . '.' . $filter['field'] . ' < :' . $fieldPh,
                        'parameter' => [$fieldPh => $value]
                    ];
                    break;
                case IntegerSerializer::COND_LESS_THAN_OR_EQUALS:
                    $value = is_array($constraint['value']) ? min($constraint['value']) : $constraint['value'];
                    $sql[] = [
                        'sql' => $tableAlias . '.' . $filter['field'] . ' <= :' . $fieldPh,
                        'parameter' => [$fieldPh => $value]
                    ];
                    break;
                case IntegerSerializer::COND_NOT_EQUALS:
                    if (is_array($constraint['value']) && count($constraint['value']) > 1) {
                        $sql[] = [
                            'sql' => $tableAlias . '.' . $filter['field'] . ' NOT IN(:' . $fieldPh . ')',
                            'parameter' => [$fieldPh => $constraint['value']]
                        ];
                    } else {
                        $sql[] = [
                            'sql' => $tableAlias . '.' . $filter['field'] . ' != :' . $fieldPh,
                            'parameter' => [$fieldPh => reset($constraint['value'])]
                        ];
                    }
                    break;
                case IntegerSerializer::COND_EQUALS:
                    if (is_array($constraint['value']) && count($constraint['value']) > 1) {
                        $sql[] = [
                            'sql' => $tableAlias . '.' . $filter['field'] . ' IN(:' . $fieldPh . ')',
                            'parameter' => [$fieldPh => $constraint['value']]
                        ];
                    } else {
                        $sql[] = [
                            'sql' => $tableAlias . '.' . $filter['field'] . ' = :' . $fieldPh,
                            'parameter' => [$fieldPh => reset($constraint['value'])]
                        ];
                    }
                    break;
                default:
                    throw new ParsingException('Undefined behavior for condition: ' . $constraint['condition']);
            }
        }

        return $sql;
    }


}