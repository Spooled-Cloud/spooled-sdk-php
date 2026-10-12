<?php

declare(strict_types=1);

namespace Spooled\Types;

/**
 * Body formats an outgoing webhook is delivered as (`format` on
 * `webhooks->create()` / `update()`; plain strings work the same).
 */
enum WebhookFormat: string
{
    /** The signed JSON envelope (`id`, `event`, `created_at`, `data`). The default. */
    case JSON = 'json';
    /** `{"text": ...}` for a Slack incoming webhook URL. */
    case SLACK = 'slack';
    /** `{"content": ...}` for a Discord webhook URL. */
    case DISCORD = 'discord';
    /** A MessageCard for a Microsoft Teams incoming webhook URL. */
    case TEAMS = 'teams';
}
