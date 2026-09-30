<?php

declare(strict_types=1);

namespace OpenHandle;

use RuntimeException;
use Throwable;

/**
 * An error returned by the OpenHandle API or transport runtime.
 *
 * Branch on `code`, never on `message`. Like `PDOException`, the code is a
 * string, so `getCode()` returns it as well.
 */
final class OpenHandleError extends RuntimeException
{
    /**
     * @var string
     */
    public $code;

    /**
     * @var string
     */
    public $message;

    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        string $code,
        string $message,
        public readonly ?string $requestId = null,
        public readonly bool $retryable = false,
        public readonly ?float $retryAfter = null,
        public readonly ?int $status = null,
        public readonly ?array $details = null,
        ?Throwable $cause = null,
    ) {
        parent::__construct($message, 0, $cause);
        $this->code = $code;
        $this->message = $message;
    }

    public function __toString(): string
    {
        if ($this->requestId !== null && $this->requestId !== '') {
            return "{$this->code}: {$this->message} (request {$this->requestId})";
        }

        return "{$this->code}: {$this->message}";
    }
}
