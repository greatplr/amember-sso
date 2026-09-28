<?php

namespace Greatplr\AmemberSso\Support;

/**
 * Masks credentials in an aMember webhook payload before it is logged, stored,
 * queued or passed to event listeners.
 *
 * aMember adds `plain_password` to the `user` object whenever it knows the
 * password (signup, password set), and `setPassword` carries a `password`
 * object. No handler needs either.
 */
class WebhookPayload
{
    public const MASK = '[REDACTED]';

    /** Credential keys inside the user objects. */
    protected const USER_OBJECTS = ['user', 'oldUser'];
    protected const USER_SECRET_KEYS = ['plain_password', 'pass'];

    /** Objects that are masked in full. */
    protected const SECRET_OBJECTS = ['password'];

    public static function redact(array $payload): array
    {
        foreach (self::USER_OBJECTS as $object) {
            if (!is_array($payload[$object] ?? null)) {
                continue;
            }

            foreach (self::USER_SECRET_KEYS as $key) {
                if (array_key_exists($key, $payload[$object])) {
                    $payload[$object][$key] = self::MASK;
                }
            }
        }

        foreach (self::SECRET_OBJECTS as $object) {
            if (array_key_exists($object, $payload)) {
                $payload[$object] = self::mask($payload[$object]);
            }
        }

        return $payload;
    }

    protected static function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'mask'], $value);
        }

        return self::MASK;
    }
}
