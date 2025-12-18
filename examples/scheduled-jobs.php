<?php

declare(strict_types=1);

/**
 * Scheduled jobs example for the Spooled PHP SDK.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Spooled\SpooledClient;
use Spooled\Config\ClientOptions;

// Create client
$client = new SpooledClient(new ClientOptions(
    apiKey: getenv('API_KEY') ?: 'your-api-key',
));

// Create a schedule
$schedule = $client->schedules->create([
    'name' => 'daily-report',
    'queue' => 'reports',
    'schedule' => '0 9 * * *',  // Every day at 9 AM
    'payload' => [
        'type' => 'daily',
        'format' => 'pdf',
    ],
    'timezone' => 'America/New_York',
    'maxRetries' => 3,
]);

echo "Created schedule: {$schedule->id}\n";
echo "Name: {$schedule->name}\n";
echo "Cron: {$schedule->schedule}\n";
echo "Next run: {$schedule->nextRunAt}\n";

// List all schedules
$list = $client->schedules->list();
echo "\nAll schedules:\n";
foreach ($list->schedules as $s) {
    $status = $s->paused ? '(paused)' : '(active)';
    echo "  - {$s->name} {$status}: {$s->schedule}\n";
}

// Trigger the schedule immediately
echo "\nTriggering schedule manually...\n";
$job = $client->schedules->trigger($schedule->id);
echo "Created job: {$job->id}\n";

// Get execution history
$history = $client->schedules->history($schedule->id);
echo "\nExecution history:\n";
foreach ($history as $entry) {
    echo "  - {$entry->executedAt}: {$entry->status}\n";
}

// Pause the schedule
$paused = $client->schedules->pause($schedule->id);
echo "\nSchedule paused: " . ($paused->paused ? 'yes' : 'no') . "\n";

// Resume the schedule
$resumed = $client->schedules->resume($schedule->id);
echo "Schedule resumed: " . ($resumed->paused ? 'still paused' : 'active') . "\n";

// Clean up - delete the schedule
$client->schedules->delete($schedule->id);
echo "\nSchedule deleted.\n";

