<?php

declare(strict_types=1);

use OpenHandle\Models\InstagramPost;
use OpenHandle\Models\TikTokProfile;
use OpenHandle\OpenHandle;

/**
 * @return array{TikTokProfile, InstagramPost}
 */
function getResources(OpenHandle $openhandle): array
{
    $profile = $openhandle->tiktok->profile(id: '7300000000000000001')->get();
    $post = $openhandle->instagram->post(url: 'https://www.instagram.com/p/Db04otPRpRH/')->get();

    return [$profile->data, $post->data];
}
