<?php

namespace App\Infrastructure\Kafka\Identity;

use App\Domain\Identity\DistinctIdChange;

/**
 * The message the identity consumer writes to the `person_distinct_ids` topic: one JSON object per distinct id that
 * was inserted, or moved to another person (a merge), or re-sent after a restart. This is the contract of the
 * ClickHouse side (Milestone 4.3), which reads the topic with the Kafka engine and format JSONEachRow:
 *
 *   {"project_id":12,"distinct_id":"alice","person_id":"0194c1e2-...","version":1,"is_deleted":0,
 *    "created_at":"2026-10-29 12:00:00.123"}
 *
 *   project_id   UInt32            the project
 *   distinct_id  String            the id exactly as captured
 *   person_id    UUID              the uuid of the person that holds the id AFTER the event (persons.uuid, not the
 *                                  Postgres bigint id: ClickHouse never sees that one)
 *   version      UInt64            0 when the id was first linked, +1 every time it moved to another person: the
 *                                  ReplacingMergeTree(version) key, so the highest version wins whatever order the rows
 *                                  arrive in, and the same row twice (a replay) changes nothing
 *   is_deleted   UInt8             always 0 for now: a merge MOVES ids (a higher version), it deletes none
 *   created_at   DateTime64(3,UTC) the `timestamp` of the identity event that caused the row, formatted
 *                                  `Y-m-d H:i:s.v` like the events topic (the time the consumer ran when the event
 *                                  has none); the same event always yields the same value, so a replayed row is
 *                                  identical to the first one
 *
 * The message key is `<project_id>:<distinct_id>`, like on the events topic: every row of one distinct id lands in
 * one partition, in order.
 */
final class PersonDistinctIdRow
{
    /** @return array{project_id: int, distinct_id: string, person_id: string, version: int, is_deleted: int, created_at: string} */
    public static function fromChange(DistinctIdChange $change, string $createdAt): array
    {
        return [
            'project_id' => $change->projectId,
            'distinct_id' => $change->distinctId,
            'person_id' => $change->personUuid,
            'version' => $change->version,
            'is_deleted' => 0,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{key: string, payload: string}
     */
    public static function message(array $row): array
    {
        return [
            'key' => $row['project_id'].':'.$row['distinct_id'],
            'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }
}
