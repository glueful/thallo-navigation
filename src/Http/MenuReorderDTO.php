<?php

declare(strict_types=1);

namespace Thallo\Navigation\Http;

use Glueful\Validation\ValidationException;

/** Validates the reorder payload SHAPE: a non-empty list of non-empty slug strings. */
final class MenuReorderDTO
{
    /** @param list<string> $slugs */
    public function __construct(public readonly array $slugs)
    {
    }

    /**
     * @param array<string,mixed> $body
     * @throws ValidationException on a malformed payload (not an array, empty, non-string).
     */
    public static function fromRequest(array $body): self
    {
        $raw = $body['slugs'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new ValidationException(['slugs' => ['slugs must be a non-empty array.']]);
        }
        $slugs = [];
        foreach (array_values($raw) as $i => $s) {
            if (!is_string($s) || trim($s) === '') {
                throw new ValidationException(["slugs.$i" => ['Each slug must be a non-empty string.']]);
            }
            $slugs[] = trim($s);
        }
        return new self($slugs);
    }
}
