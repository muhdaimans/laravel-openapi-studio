<?php

namespace OpenApiStudio\Support;

use stdClass;

/**
 * Structural checks that keep the editor from writing a file other tools
 * can't read. It is not a full OpenAPI schema validator.
 */
class SpecValidator
{
    public const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @return list<string> human-readable problems; empty when valid
     */
    public function validate(mixed $spec): array
    {
        if (! $spec instanceof stdClass) {
            return ['The document must be a map with "openapi", "info" and "paths" keys.'];
        }

        $errors = [];

        $openapi = $spec->openapi ?? null;
        if (! is_string($openapi) || ! preg_match('/^3\.\d+(\.\d+)?$/', $openapi)) {
            $errors[] = isset($spec->swagger)
                ? 'Swagger 2.0 files are not supported. Convert the file to OpenAPI 3 first.'
                : 'The "openapi" field must be a 3.x version, for example "3.0.3".';
        }

        $info = $spec->info ?? null;
        if (! $info instanceof stdClass) {
            $errors[] = 'The "info" section is missing.';
        } else {
            if (! $this->filled($info->title ?? null)) {
                $errors[] = 'The API title (info.title) is required.';
            }
            if (! $this->filled($info->version ?? null)) {
                $errors[] = 'The API version (info.version) is required.';
            }
        }

        if (isset($spec->paths)) {
            if (! $spec->paths instanceof stdClass && $spec->paths !== []) {
                $errors[] = 'The "paths" section must be a map of paths.';
            } elseif ($spec->paths instanceof stdClass) {
                foreach (get_object_vars($spec->paths) as $path => $item) {
                    if (! str_starts_with((string) $path, '/')) {
                        $errors[] = "Path \"{$path}\" must start with \"/\".";
                    }
                    if (! $item instanceof stdClass) {
                        $errors[] = "Path \"{$path}\" must be a map of operations.";

                        continue;
                    }
                    foreach (self::METHODS as $method) {
                        if (isset($item->{$method}) && ! $item->{$method} instanceof stdClass) {
                            $errors[] = strtoupper($method)." {$path} must be a map.";
                        }
                    }
                }
            }
        }

        $schemas = $spec->components->schemas ?? null;
        if ($schemas !== null && ! $schemas instanceof stdClass && $schemas !== []) {
            $errors[] = 'components.schemas must be a map of schema names.';
        }

        return $errors;
    }

    private function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
