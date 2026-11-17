<?php

namespace OpenApiStudio\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class SpecException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(string $message, private int $status = 422, private array $errors = [])
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self('The spec is not valid.', 422, $errors);
    }

    public static function badName(string $name): self
    {
        return new self("\"{$name}\" is not a valid file name. Use letters, numbers, dots, dashes or underscores, ending in .yaml, .yml or .json.", 422);
    }

    public static function notFound(string $name): self
    {
        return new self("Spec \"{$name}\" was not found.", 404);
    }

    public static function alreadyExists(string $name): self
    {
        return new self("Spec \"{$name}\" already exists.", 409);
    }

    public static function conflict(string $name): self
    {
        return new self("\"{$name}\" was changed on disk after you opened it.", 409);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], $this->status);
    }
}
