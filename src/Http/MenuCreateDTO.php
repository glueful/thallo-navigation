<?php

declare(strict_types=1);

namespace Thallo\Navigation\Http;

use Glueful\Validation\Rules\Length;
use Glueful\Validation\Rules\Regex;
use Glueful\Validation\Rules\Required;
use Glueful\Validation\Rules\Sanitize;
use Glueful\Validation\Rules\Type;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Validator;

/** Validates menu creation (slug + name). */
final class MenuCreateDTO
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
    ) {
    }

    /**
     * @param array<string,mixed> $body
     * @throws ValidationException
     */
    public static function fromRequest(array $body): self
    {
        $validator = new Validator([
            'slug' => [new Required(), new Sanitize(['trim']), new Regex('/^[a-z0-9-]{1,64}$/')],
            'name' => [new Required(), new Sanitize(['trim']), new Type('string'), new Length(1, 120)],
        ]);
        $errors = $validator->validate([
            'slug' => $body['slug'] ?? null,
            'name' => $body['name'] ?? null,
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $clean = $validator->filtered();
        return new self((string) $clean['slug'], (string) $clean['name']);
    }
}
