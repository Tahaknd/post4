<?php

namespace App\Domain\Capture;

/** Best-effort count of the messages the dead letter topic (events_dlq) currently holds. */
interface DlqDepthProbe
{
    /**
     * Per partition: the oldest retained offset, the high watermark and the messages in between.
     *
     * @return array<int, array{low: int, high: int, depth: int}>
     *
     * @throws \Throwable when the brokers cannot be reached in time or answer with an error
     */
    public function depth(): array;
}
