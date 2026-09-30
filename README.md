# OpenHandle PHP SDK

The official PHP client for the OpenHandle API.

## Installation

```bash
composer require openhandle/sdk
```

The package supports PHP 8.2 and newer. It uses Guzzle for HTTP and ships
typed models and PHPStan generics for every response.

## Usage

Create a Test key in the [Openhandle dashboard](https://app.openhandle.dev),
store it as `OPENHANDLE_TEST_KEY`, and create one reusable client:

```php
use OpenHandle\OpenHandle;

$openhandle = new OpenHandle(apiKey: (string) getenv('OPENHANDLE_TEST_KEY'));
$profile = $openhandle->instagram->profile('northstar_forge_test');

$response = $profile->get();
$posts = $profile->posts->list(freshness: '24h');

echo $response->data->handle, ' ', count($posts->data), PHP_EOL;
```

The key selects the environment. `oh_test_` keys return deterministic synthetic
data with a `$0.000` actual charge; `oh_live_` keys use real public identifiers
and normal billing. Never expose an API key in client-side code.

See the [API reference](https://openhandle.dev/docs/api-reference) for every
operation.

## Resource selection

The SDK follows one predictable grammar:

```text
$openhandle-><platform>-><resource>(reference)-><subresource>-><operation>(options)
```

Only terminal operations such as `get`, `list`, `search`, and `fetch` perform
network requests. Selecting a resource is synchronous and reusable:

```php
$post = $openhandle->instagram->post('Db04otPRpRH');

$response = $post->get();
$comments = $post->comments->list();
```

A profile selector accepts a username shorthand or an explicit reference:

```php
$openhandle->instagram->profile('openai');
$openhandle->instagram->profile('https://www.instagram.com/openai/');
$openhandle->instagram->profile(username: '12356');
$openhandle->instagram->profile(id: '25025320');
$openhandle->instagram->profile(url: 'https://www.instagram.com/openai/');
```

A raw string is never treated as a platform ID. `profile('12356')` selects the
username `12356`; `profile(id: '12356')` selects platform ID `12356`. Numeric
reference values are rejected because platform IDs are opaque strings.

Call `$openhandle->fetch($url)` when you do not know which resource a supported
social URL represents. TikTok short links such as `tiktok.com/t/…` and
`vm.tiktok.com/…` are rejected by selectors and only work through `fetch`,
which expands them server-side.

Pass operation options as named arguments. Their positions are not part of the
public API and can change when the API adds an option.

## Pagination

A list or search operation returns one typed page:

```php
$page = $openhandle->instagram->profile('northstar_forge_test')->posts->list();

var_dump($page->data, $page->hasNextPage, $page->nextCursor);
$nextPage = $page->next();
```

`next()` returns `null` after the final page without making a request.
`items()` iterates lazily across pages, one request per page:

```php
foreach ($openhandle->instagram->profile('northstar_forge_test')->posts->items() as $post) {
    echo $post->id, PHP_EOL;
}
```

## Responses

Every response preserves the public envelope. `data` is a typed model from the
`OpenHandle\Models` namespace, and metadata is available on the response:

```php
$response = $openhandle->instagram->profile('northstar_forge_test')->get();

$response->platform;      // "instagram"
$response->resource;      // "profile"
$response->capturedAt;    // DateTimeImmutable
$response->source;        // "live" or "cache"
$response->requestId;     // stable request identifier for logs and support
$response->billing->cost; // authoritative charge as a decimal string
$response->raw;           // the decoded body, exactly as returned
```

A missing metric is `null`. It is never `0`.

Type your own functions with the generic response and page types:

```php
use OpenHandle\Models\InstagramProfile;
use OpenHandle\OpenHandle;
use OpenHandle\Response;

/**
 * @return Response<InstagramProfile>
 */
function profile(OpenHandle $openhandle): Response
{
    return $openhandle->instagram->profile('northstar_forge_test')->get();
}
```

## Errors and retries

The SDK throws `OpenHandleError` with the documented fields. Branch on `code`,
never on `message`:

```php
use OpenHandle\OpenHandleError;

try {
    $response = $openhandle->instagram->profile('private_account')->get();
} catch (OpenHandleError $error) {
    echo $error->code, ' ', $error->requestId, ' ', var_export($error->retryable, true), PHP_EOL;
}
```

Retryable failures are retried automatically with capped exponential backoff
and `Retry-After` support. Configure the client, or override per request:

```php
$openhandle = new OpenHandle(apiKey: '...', timeout: 10.0, maxRetries: 1);
$openhandle->twitter->profile('openai')->get(timeout: 5.0, maxRetries: 0);
```

Pass your own Guzzle client as `httpClient` to add proxies or middleware.

Locally invalid references throw `OpenHandleReferenceError` before any request
is made. `ReferenceMismatchError` reports a social URL that belongs to a
different platform or resource than the selector.

## License

[MIT](./LICENSE)
