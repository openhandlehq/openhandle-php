<?php

declare(strict_types=1);

namespace OpenHandle;

use JsonException;
use OpenHandle\Internal\Wire;
use OpenHandle\Models\WebhookEvent;
use TypeError;
use UnexpectedValueException;

/**
 * Verifies webhooks that OpenHandle pushes to your endpoint. It needs no API
 * key and performs no I/O.
 */
final class Webhook
{
    private const SIGNATURE_HEADER = 'x-openhandle-signature';
    private const TIMESTAMP_HEADER = 'x-openhandle-timestamp';

    /**
     * Verify a pushed webhook and return the typed event.
     *
     * Pass the raw request body exactly as received. Never decode and encode
     * it first, because the signature covers the original bytes. Header names
     * match case-insensitively, and a header may hold a string or a list of
     * strings, as PSR-7 `getHeaders()` returns them. `$now` exists for tests.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @throws WebhookVerificationError
     */
    public static function verify(
        string $rawBody,
        array $headers,
        string $secret,
        int $toleranceSeconds = 300,
        ?int $now = null,
    ): WebhookEvent {
        $values = [];
        foreach ($headers as $name => $value) {
            $values[strtolower((string) $name)] ??= is_array($value) ? ($value[0] ?? null) : $value;
        }

        $signature = $values[self::SIGNATURE_HEADER] ?? null;
        if ($signature === null || $signature === '') {
            throw new WebhookVerificationError(WebhookVerificationError::MISSING_SIGNATURE, 'The X-Openhandle-Signature header is missing.');
        }

        $timestamp = $values[self::TIMESTAMP_HEADER] ?? null;
        if ($timestamp === null || $timestamp === '') {
            throw new WebhookVerificationError(WebhookVerificationError::MISSING_TIMESTAMP, 'The X-Openhandle-Timestamp header is missing.');
        }

        if (preg_match('/\A[0-9]+\z/', $timestamp) !== 1) {
            throw new WebhookVerificationError(WebhookVerificationError::INVALID_TIMESTAMP, 'The X-Openhandle-Timestamp header is not Unix seconds.');
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $toleranceSeconds) {
            throw new WebhookVerificationError(
                WebhookVerificationError::TIMESTAMP_OUT_OF_TOLERANCE,
                "The webhook timestamp is more than {$toleranceSeconds} seconds from the current time.",
            );
        }

        $expected = 'sha256=' . hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
        if (!hash_equals($expected, $signature)) {
            throw new WebhookVerificationError(WebhookVerificationError::INVALID_SIGNATURE, 'The webhook signature does not match.');
        }

        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new WebhookVerificationError(WebhookVerificationError::INVALID_PAYLOAD, 'The webhook body is not valid JSON.', $cause);
        }

        if (!is_array($event) || array_is_list($event)) {
            throw new WebhookVerificationError(WebhookVerificationError::INVALID_PAYLOAD, 'The webhook body is not a JSON object.');
        }
        $named = true;
        foreach (['id', 'type', 'environment', 'createdAt'] as $field) {
            $named = $named && is_string($event[$field] ?? null);
        }
        $data = $event['data'] ?? null;
        if (!$named || !is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new WebhookVerificationError(WebhookVerificationError::INVALID_PAYLOAD, 'The webhook body is not a webhook event.');
        }

        try {
            return Wire::object($event, 'event', WebhookEvent::class);
        } catch (UnexpectedValueException|TypeError $cause) {
            throw new WebhookVerificationError(
                WebhookVerificationError::INVALID_PAYLOAD,
                'The webhook body is not a valid webhook event: ' . $cause->getMessage(),
                $cause,
            );
        }
    }
}
