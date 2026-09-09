<?php

declare(strict_types=1);

namespace Spooled\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spooled\Types\Queue;

#[CoversClass(Queue::class)]
final class QueueTest extends TestCase
{
    #[Test]
    public function from_array_reads_queue_name_not_name(): void
    {
        $got = Queue::fromArray([
            'queueName' => 'emails',
            'enabled' => true,
            'paused' => false,
            'maxRetries' => 5,
            'defaultTimeout' => 300,
        ]);

        $this->assertSame('emails', $got->name);
        $this->assertFalse($got->paused);
        $this->assertSame(300, $got->timeout);
        $this->assertSame(5, $got->maxRetries);
    }

    #[Test]
    public function from_array_derives_paused_from_enabled_when_list_omits_paused(): void
    {
        $got = Queue::fromArray([
            'queueName' => 'emails',
            'enabled' => false,
            'maxRetries' => 3,
            'defaultTimeout' => 60,
        ]);

        $this->assertTrue($got->paused);
        $this->assertSame(60, $got->timeout);
    }
}
