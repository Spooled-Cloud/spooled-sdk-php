<?php

declare(strict_types=1);

namespace Spooled\Resources;

use Spooled\Http\HttpClient;

/**
 * Base class for API resources.
 */
abstract class BaseResource
{
    public function __construct(
        protected readonly HttpClient $httpClient,
    ) {
    }
}
