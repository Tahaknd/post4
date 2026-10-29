<?php

namespace App\Infrastructure\Kafka\Identity;

/**
 * Where the identity consumer reads from: one topic, as a member of one consumer group, with manual commits.
 * The real one is RdKafkaMessageSource; the tests use a scripted fake.
 */
interface MessageSource
{
    /**
     * Registers the rebalance listeners and joins the group. Both run INSIDE a later poll(), on the consumer's own
     * thread: $onAssigned before the first message of the new partitions is returned, $onRevoked before they are taken
     * away (the last chance to commit them).
     *
     * @param  \Closure(list<int>): void  $onAssigned
     * @param  \Closure(list<int>): void  $onRevoked
     */
    public function listen(\Closure $onAssigned, \Closure $onRevoked): void;

    /** The next message, or null when the timeout passed (or the broker only reported something to log). */
    public function poll(int $timeoutMs): ?ConsumedMessage;

    /**
     * Commits synchronously. The offset of a partition is that of the NEXT message to read (last handled + 1).
     *
     * @param  array<int, int>  $nextOffsets  partition => offset
     *
     * @throws CommitFailed
     */
    public function commit(array $nextOffsets): void;

    /** Leaves the group. Never commits anything. */
    public function close(): void;
}
