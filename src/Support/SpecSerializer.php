<?php

namespace OpenApiStudio\Support;

use DateTimeInterface;
use JsonException;
use OpenApiStudio\Exceptions\SpecException;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Converts between file contents and an in-memory spec.
 *
 * Maps are kept as stdClass (not PHP arrays) throughout, so an empty map
 * such as `schema: {}` survives a YAML -> JSON -> YAML round trip instead
 * of turning into a list.
 */
class SpecSerializer
{
    public static function formatFor(string $name): string
    {
        return str_ends_with(strtolower($name), '.json') ? 'json' : 'yaml';
    }

    public function parse(string $contents, string $format): mixed
    {
        if ($format === 'json') {
            try {
                return json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw SpecException::invalid(['Invalid JSON: '.$e->getMessage()]);
            }
        }

        try {
            $parsed = Yaml::parse($contents, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            throw SpecException::invalid(['Invalid YAML: '.$e->getMessage()]);
        }

        return $this->normalize($parsed);
    }

    public function dump(mixed $spec, string $format): string
    {
        if ($format === 'json') {
            return json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        }

        $flags = Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK;

        // Symfony 7.3+: write "- url: x" instead of "-" followed by an indented map.
        if (defined(Yaml::class.'::DUMP_COMPACT_NESTED_MAPPING')) {
            $flags |= Yaml::DUMP_COMPACT_NESTED_MAPPING;
        }

        return Yaml::dump($this->toDumpable($spec), 64, 2, $flags);
    }

    /**
     * The YAML dumper only writes compact "- key: value" list items for PHP
     * arrays, so turn maps into arrays. Maps that would be mistaken for lists
     * (empty, or keys 0..n) stay objects so they are still written as maps,
     * and so do maps with numeric keys such as response codes, which OpenAPI
     * requires to be quoted ('200':).
     */
    private function toDumpable(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $map = array_map(fn ($item) => $this->toDumpable($item), get_object_vars($value));

            $numericKeys = array_filter(array_keys($map), 'is_int') !== [];

            return ($map === [] || $numericKeys) ? (object) $map : $map;
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->toDumpable($item), $value);
        }

        return $value;
    }

    /**
     * Decode a JSON request body, keeping maps as objects.
     */
    public function decodeJson(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw SpecException::invalid(['Invalid JSON body: '.$e->getMessage()]);
        }
    }

    /**
     * YAML turns unquoted dates (e.g. `example: 2024-01-31`) into dates;
     * turn them back into the strings they were written as.
     */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s') === '00:00:00'
                ? $value->format('Y-m-d')
                : $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = $this->normalize($item);
            }

            return $value;
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalize($item), $value);
        }

        return $value;
    }
}
