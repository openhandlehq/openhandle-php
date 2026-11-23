<?php

declare(strict_types=1);

namespace OpenHandle;

use RuntimeException;
use Throwable;

/**
 * A pushed webhook that failed local verification.
 *
 * Branch on `code`, never on `message`. Like `OpenHandleError`, the code is a
 * string, so `getCode()` returns it as well.
 */
final class WebhookVerificationError extends RuntimeException
{
    public const MISSING_SIGNATURE = 'missing_signature';
    public const MISSING_TIMESTAMP = 'missing_timestamp';
    public const INVALID_TIMESTAMP = 'invalid_timestamp';
    public const TIMESTAMP_OUT_OF_TOLERANCE = 'timestamp_out_of_tolerance';
    public const INVALID_SIGNATURE = 'invalid_signature';
    public const INVALID_PAYLOAD = 'invalid_payload';

    /**
     * @var self::MISSING_SIGNATURE|self::MISSING_TIMESTAMP|self::INVALID_TIMESTAMP|self::TIMESTAMP_OUT_OF_TOLERANCE|self::INVALID_SIGNATURE|self::INVALID_PAYLOAD
     */
    public $code;

    /**
     * @var string
     */
    public $message;

    /**
     * @param self::MISSING_SIGNATURE|self::MISSING_TIMESTAMP|self::INVALID_TIMESTAMP|self::TIMESTAMP_OUT_OF_TOLERANCE|self::INVALID_SIGNATURE|self::INVALID_PAYLOAD $code
     */
    public function __construct(string $code, string $message, ?Throwable $cause = null)
    {
        parent::__construct($message, 0, $cause);
        $this->code = $code;
        $this->message = $message;
    }

    public function __toString(): string
    {
        return "{$this->code}: {$this->message}";
    }
}
