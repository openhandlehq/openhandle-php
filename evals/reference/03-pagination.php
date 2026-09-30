<?php

declare(strict_types=1);

use OpenHandle\Models\InstagramPost;
use OpenHandle\OpenHandle;
use OpenHandle\Page;

/**
 * @return list<string>
 */
function collectPostIds(OpenHandle $openhandle): array
{
    return postIdsFrom($openhandle->instagram->profile('northstar_forge_test')->posts->list());
}

/**
 * @param Page<InstagramPost> $page
 *
 * @return list<string>
 */
function postIdsFrom(Page $page): array
{
    $postIds = [];
    for ($current = $page; $current !== null; $current = $current->next()) {
        foreach ($current->data as $post) {
            $postIds[] = $post->id;
        }
    }

    return $postIds;
}
