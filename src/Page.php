<?php

declare(strict_types=1);

namespace OpenHandle;

use Closure;

/**
 * One typed page returned by a list or search operation.
 *
 * @template-covariant TItem
 */
final class Page extends ResponseMetadata
{
    /**
     * The opaque cursor for the next page, or null after the final page.
     */
    public readonly ?string $nextCursor;

    public readonly bool $hasNextPage;

    /**
     * Whether the platform explicitly limited the returned list.
     */
    public readonly ?bool $isLimited;

    /**
     * @param array<string, mixed> $raw
     * @param list<TItem> $data
     * @param Closure(string): Page<TItem> $fetchNext
     */
    public function __construct(
        array $raw,
        string $requestId,
        Billing $billing,
        public readonly array $data,
        private readonly Closure $fetchNext,
    ) {
        parent::__construct($raw, $requestId, $billing);
        $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
        $cursors = is_array($meta['cursors'] ?? null) ? $meta['cursors'] : [];
        $cursor = $cursors['next'] ?? null;
        $this->nextCursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
        $this->hasNextPage = $this->nextCursor !== null;
        $this->isLimited = is_bool($meta['isLimited'] ?? null) ? $meta['isLimited'] : null;
    }

    /**
     * Fetch the next page, or return null without a request at the end.
     *
     * @return Page<TItem>|null
     */
    public function next(): ?Page
    {
        if ($this->nextCursor === null) {
            return null;
        }

        return ($this->fetchNext)($this->nextCursor);
    }
}
