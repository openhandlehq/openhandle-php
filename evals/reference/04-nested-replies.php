<?php

declare(strict_types=1);

use OpenHandle\Models\InstagramComment;
use OpenHandle\OpenHandle;

/**
 * @return list<InstagramComment>
 */
function listReplies(OpenHandle $openhandle): array
{
    $page = $openhandle->instagram->post('910000000000000001')->comment('18120112390529134')->replies->list();

    return $page->data;
}
