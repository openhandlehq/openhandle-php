<?php

declare(strict_types=1);

namespace OpenHandle;

use InvalidArgumentException;

/**
 * A locally invalid resource reference, thrown before any request.
 */
class OpenHandleReferenceError extends InvalidArgumentException {}
