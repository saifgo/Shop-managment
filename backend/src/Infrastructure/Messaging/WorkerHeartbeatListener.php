<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Touches a heartbeat file while messenger:consume is looping, so the worker
 * container's healthcheck can tell a live worker from a hung one.
 */
final class WorkerHeartbeatListener
{
    public const FILE = '/tmp/messenger-worker.heartbeat';
    private const MIN_INTERVAL_SECONDS = 10;

    private int $lastBeat = 0;

    #[AsEventListener(event: WorkerStartedEvent::class)]
    #[AsEventListener(event: WorkerRunningEvent::class)]
    public function beat(): void
    {
        $now = time();
        if ($now - $this->lastBeat < self::MIN_INTERVAL_SECONDS) {
            return;
        }

        @touch(self::FILE, $now);
        $this->lastBeat = $now;
    }
}
