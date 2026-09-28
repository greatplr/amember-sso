<?php

namespace Greatplr\AmemberSso\Api;

use RuntimeException;

/**
 * Thrown by AmemberApiClient when a request fails: the connection failed,
 * aMember answered with a non-2xx status (API key errors 10001-10004 come back
 * this way, as HTML or text), or the body wasn't JSON.
 */
class AmemberApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
