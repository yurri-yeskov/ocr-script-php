<?php

/**
 * Manages query string variables and can aggregate them into a string
 */
class puzzle_Query extends puzzle_Collection
{
    const RFC3986 = 'RFC3986';
    const RFC1738 = 'RFC1738';

    /** @var bool URL encode fields and values */
    private $encoding = self::RFC3986;

    /** @var callable */
    private $aggregator;

    /**
     * Parse a query string into a puzzle_Query object
     *
     * $urlEncoding is used to control how the query string is parsed and how
     * it is ultimately serialized. The value can be set to one of the
     * following:
     *
     * - true: (default) Parse query strings using RFC 3986 while still
     *   converting "+" to " ".
     * - false: Disables URL decoding of the input string and URL encoding when
     *   the query string is serialized.
     * - 'RFC3986': Use RFC 3986 URL encoding/decoding
     * - 'RFC1738': Use RFC 1738 URL encoding/decoding
     *
     * @param string      $query       Query string to parse
     * @param bool|string $urlEncoding Controls how the input string is decoded
     *                                 and encoded.
     * @return self
     */
    public static function fromString($query, $urlEncoding = true)
    {
        static $qp;
        if (!$qp) {
            $qp = new puzzle_QueryParser();
        }

        $q = new self();

        if ($urlEncoding !== true) {
            $q->setEncodingType($urlEncoding);
        }

        $qp->parseInto($q, $query, $urlEncoding);

        return $q;
    }

    /**
     * Convert the query string parameters to a query string string
     *
     * @return string
     */
    public function __toString()
    {
        if (!$this->data) {
            return '';
        }

        // The default aggregator is statically cached
        static $defaultAggregator;

        if (!$this->aggregator) {
            if (!$defaultAggregator) {
                $defaultAggregator = self::phpAggregator();
            }
            $this->aggregator = $defaultAggregator;
        }

        $result = '';
        $aggregator = $this->aggregator;

        foreach (call_user_func($aggregator, $this->data) as $key => $values) {
            foreach ($values as $value) {
                if ($result) {
                    $result .= '&';
                }
                if ($this->encoding == self::RFC1738) {
                    $result .= urlencode($key);
                    if ($value !== null) {
                        $result .= '=' . urlencode($value);
                    }
                } elseif ($this->encoding == self::RFC3986) {
                    $result .= rawurlencode($key);
                    if ($value !== null) {
                        $result .= '=' . rawurlencode($value);
                    }
                } else {
                    $result .= $key;
                    if ($value !== null) {
                        $result .= '=' . $value;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Controls how multi-valued query string parameters are aggregated into a
     * string.
     *
     *     $query->setAggregator($query::duplicateAggregator());
     *
     * @param callable $aggregator Callable used to convert a deeply nested
     *     array of query string variables into a flattened array of key value
     *     pairs. The callable accepts an array of query data and returns a
     *     flattened array of key value pairs where each value is an array of
     *     strings.
     *
     * @return self
     */
    public function setAggregator($aggregator)
    {
        $this->aggregator = $aggregator;

        return $this;
    }

    /**
     * Specify how values are URL encoded
     *
     * @param string|bool $type One of 'RFC1738', 'RFC3986', or false to disable encoding
     *
     * @return self
     * @throws InvalidArgumentException
     */
    public function setEncodingType($type)
    {
        if ($type === false || $type === self::RFC1738 || $type === self::RFC3986) {
            $this->encoding = $type;
        } else {
            throw new InvalidArgumentException('Invalid URL encoding type');
        }

        return $this;
    }

    /**
     * Query string aggregator that does not aggregate nested query string
     * values and allows duplicates in the resulting array.
     *
     * Example: http://test.com?q=1&q=2
     *
     * @return callable
     */
    public static function duplicateAggregator()
    {
        return array('puzzle_Query', '__callback_duplicateAggregator');
    }

    public static function __callback_duplicateAggregator(array $data)
    {
        return self::walkQuery($data, '', array('puzzle_Query', '__callback_duplicateAggregator_1'));
    }

    public static function __callback_duplicateAggregator_1($key, $prefix)
    {
        return is_int($key) ? $prefix : "{$prefix}[{$key}]";
    }

    /**
     * Aggregates nested query string variables using the same technique as
     * ``http_build_query()``.
     *
     * @param bool $numericIndices Pass false to not include numeric indices
     *     when multi-values query string parameters are present.
     *
     * @return callable
     */
    public static function phpAggregator($numericIndices = true)
    {
        $aggregator = new __puzzle_phpAggregator($numericIndices);

        return array($aggregator, 'aggregate');
    }

    /**
     * Easily create query aggregation functions by providing a key prefix
     * function to this query string array walker.
     *
     * @param array    $query     Query string to walk
     * @param string   $keyPrefix Key prefix (start with '')
     * @param callable $prefixer  Function used to create a key prefix
     *
     * @return array
     */
    public static function walkQuery(array $query, $keyPrefix, $prefixer)
    {
        $result = array();
        foreach ($query as $key => $value) {
            if ($keyPrefix) {
                $key = call_user_func_array($prefixer, array($key, $keyPrefix));
            }
            if (is_array($value)) {
                $result += self::walkQuery($value, $key, $prefixer);
            } elseif (isset($result[$key])) {
                $result[$key][] = $value;
            } else {
                $result[$key] = array($value);
            }
        }

        return $result;
    }
}

class __puzzle_phpAggregator
{
    private $_useNumericIndices;

    public function __construct($useNumericIndices)
    {
        $this->_useNumericIndices = $useNumericIndices;
    }

    public function aggregate(array $data)
    {
        return puzzle_Query::walkQuery($data, '', array($this, '_walker'));
    }

    public function _walker($key, $prefix)
    {
        return !$this->_useNumericIndices && is_int($key)
            ? "{$prefix}[]"
            : "{$prefix}[{$key}]";
    }
}