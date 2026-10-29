<?php

namespace Tests\Support;

use App\Infrastructure\Kafka\Identity\RowWriter;
use App\Infrastructure\Kafka\Identity\WriteFailed;

final class RecordingRowWriter implements RowWriter
{
    /** @var array<string, list<array{key: ?string, payload: string}>> */
    public array $published = [];

    /** @var int The next publish() calls that fail with WriteFailed. */
    public int $failTimes = 0;

    public function __construct(private readonly Trace $trace) {}

    public function publish(string $topic, array $messages): void
    {
        $this->trace->add("publish:{$topic}:".count($messages));

        if ($this->failTimes > 0) {
            $this->failTimes--;

            throw new WriteFailed('scripted failure');
        }

        foreach ($messages as $message) {
            $this->published[$topic][] = $message;
        }
    }

    /** @return list<array<string, mixed>> */
    public function decoded(string $topic): array
    {
        return array_map(fn (array $m) => json_decode($m['payload'], true), $this->published[$topic] ?? []);
    }
}
