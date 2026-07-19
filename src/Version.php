<?php

declare(strict_types=1);

namespace Spooled;

final class Version
{
    public const VERSION = '1.0.21';

    public const USER_AGENT = 'spooled-php/' . self::VERSION;

    private function __construct()
    {
    }
}
