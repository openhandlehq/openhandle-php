<?php

declare(strict_types=1);

use OpenHandle\Models\TikTokPost;
use OpenHandle\OpenHandle;

/**
 * @return list<TikTokPost>
 */
function searchPosts(OpenHandle $openhandle): array
{
    $page = $openhandle->tiktok->search->posts->list(q: 'synthetic', freshness: '24h');

    return $page->data;
}
