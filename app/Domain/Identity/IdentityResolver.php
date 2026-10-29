<?php

namespace App\Domain\Identity;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Applies one identity event to persons and person_distinct_ids, in one transaction, safely against any number of
 * resolvers running at the same time. Postgres only (advisory locks, FOR UPDATE, jsonb operators).
 *
 * The distinct id of the event is the TARGET (its person survives); the other id (`$anon_distinct_id`, `alias`) is
 * the SOURCE. By what is known about the two ids:
 *
 *   neither has a person   a person is created with both ids and is identified (every kind)
 *   one has a person       the other id is attached to it and the person is identified (every kind)
 *   the same person        nothing (a repeated event, or the reverse of an earlier one: A->B then B->A converges)
 *   two different persons  merge the source's person into the target's, unless
 *                            $identify           the source's person is already identified
 *                            $create_alias       both persons are already identified
 *                            $merge_dangerously  never refused
 *
 * A merge moves every distinct id of the source person to the target person (version + 1 each, so ClickHouse's
 * ReplacingMergeTree keeps the new assignment), merges the properties (the TARGET wins a key both have), keeps the
 * earlier created_at, marks the target identified and deletes the source person. Setting properties from `$set` and
 * `$set_once` is NOT done here.
 *
 * Concurrency, in this order inside the transaction:
 *   1. pg_advisory_xact_lock for each id of the event, keys in one global ascending order (IdentityLockKey), so two
 *      events that name the same id queue up and "A,B" against "B,A" cannot deadlock. This also closes the window in
 *      which two events both find "no person yet" for the same id: the unique (project_id, distinct_id) index would
 *      make one of them fail, the lock makes it wait instead and then see the other's person.
 *   2. The persons of the ids are locked FOR UPDATE in ascending id order. A merge elsewhere may move or delete a
 *      person that holds one of our ids as one of its OTHER ids without ever naming it, so the id lock alone is not
 *      enough; every writer of a person's distinct ids holds that person's row lock.
 *   3. The ids are looked up again. A different answer (a person vanished or an id moved while we waited for the row
 *      locks) rolls the transaction back and starts over (StaleIdentityState); the retry sees the committed state.
 * Because the advisory locks are all taken first and the row locks in ascending order, and a retry is a new
 * transaction that holds nothing, no cycle of waits can form. A wait is bounded by lock_timeout (the exception that
 * Postgres raises then is not caught: the consumer decides to retry or not).
 *
 * It must run outside a transaction of its own (the retry needs a transaction it can roll back by itself).
 */
final class IdentityResolver
{
    /** Attempts of one event when the persons it read keep changing under it (a contended merge). */
    private const int MAX_ATTEMPTS = 5;

    public function __construct(private readonly int $lockTimeoutMilliseconds = 10_000) {}

    public function apply(IdentityEvent $event): IdentityResult
    {
        $invalid = $event->invalidDistinctId();

        if ($invalid !== null) {
            return new IdentityResult(
                IdentityOutcome::Rejected,
                $event->projectId,
                problem: DistinctIdRules::problem($invalid),
                detail: $invalid,
            );
        }

        if ($event->distinctId === $event->otherDistinctId) {
            return new IdentityResult(IdentityOutcome::Noop, $event->projectId, reason: IdentityNoopReason::SameDistinctId);
        }

        if (DB::transactionLevel() > 0) {
            throw new LogicException('IdentityResolver::apply() must run outside a transaction: it retries by rolling back its own.');
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->resolve($event));
            } catch (StaleIdentityState $stale) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $stale;
                }
            }
        }
    }

    private function resolve(IdentityEvent $event): IdentityResult
    {
        $project = $event->projectId;
        $ids = $event->distinctIds();

        DB::select('select set_config(?, ?, true)', ['lock_timeout', $this->lockTimeoutMilliseconds.'ms']);

        foreach (IdentityLockKey::sorted($project, $ids) as [$namespace, $hash]) {
            DB::select('select pg_advisory_xact_lock(?, ?)', [$namespace, $hash]);
        }

        $before = $this->personIdsOf($project, $ids);
        $persons = $this->lockPersons($project, array_values(array_unique($before)));

        if (count($persons) !== count(array_unique($before)) || $this->personIdsOf($project, $ids) !== $before) {
            throw new StaleIdentityState('A person changed while its row was being locked.');
        }

        $targetId = $before[$event->distinctId] ?? null;
        $sourceId = $before[$event->otherDistinctId] ?? null;
        $now = CarbonImmutable::now();

        if ($targetId === null && $sourceId === null) {
            return $this->create($event, $now);
        }

        if ($targetId === null || $sourceId === null) {
            $personId = $targetId ?? $sourceId;
            $newId = $targetId === null ? $event->distinctId : $event->otherDistinctId;

            return $this->attach($event, $persons[$personId], $newId, $now);
        }

        if ($targetId === $sourceId) {
            return $this->noop($event, $persons[$targetId], IdentityNoopReason::SamePerson);
        }

        $refusal = $this->mergeRefusal($event->kind, $persons[$targetId]->is_identified, $persons[$sourceId]->is_identified);

        if ($refusal !== null) {
            return $this->noop($event, $persons[$targetId], $refusal);
        }

        return $this->merge($event, $persons[$targetId], $persons[$sourceId], $now);
    }

    private function mergeRefusal(IdentityKind $kind, bool $targetIdentified, bool $sourceIdentified): ?IdentityNoopReason
    {
        return match (true) {
            $kind === IdentityKind::Identify && $sourceIdentified => IdentityNoopReason::SourceAlreadyIdentified,
            $kind === IdentityKind::CreateAlias && $sourceIdentified && $targetIdentified => IdentityNoopReason::BothAlreadyIdentified,
            default => null,
        };
    }

    private function create(IdentityEvent $event, CarbonImmutable $now): IdentityResult
    {
        $uuid = (string) Str::uuid7();
        $personId = (int) DB::table('persons')->insertGetId([
            'uuid' => $uuid,
            'project_id' => $event->projectId,
            'is_identified' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $changes = [];
        foreach ($event->distinctIds() as $distinctId) {
            $changes[] = $this->insertDistinctId($event->projectId, $personId, $uuid, $distinctId, $now);
        }

        return new IdentityResult(IdentityOutcome::Created, $event->projectId, $personId, $uuid, $this->byDistinctId($changes));
    }

    private function attach(IdentityEvent $event, object $person, string $distinctId, CarbonImmutable $now): IdentityResult
    {
        $change = $this->insertDistinctId($event->projectId, (int) $person->id, $person->uuid, $distinctId, $now);

        DB::table('persons')->where('id', $person->id)->where('is_identified', false)
            ->update(['is_identified' => true, 'updated_at' => $now]);

        return new IdentityResult(IdentityOutcome::Attached, $event->projectId, (int) $person->id, $person->uuid, [$change]);
    }

    private function merge(IdentityEvent $event, object $target, object $source, CarbonImmutable $now): IdentityResult
    {
        $moved = DB::select(
            'update person_distinct_ids set person_id = ?, version = version + 1, updated_at = ?
             where project_id = ? and person_id = ? returning distinct_id, version',
            [$target->id, $now, $event->projectId, $source->id],
        );

        // jsonb || : the right operand wins a key both have, so the target's value is kept.
        DB::update(
            'update persons set properties = source.properties || persons.properties,
                    created_at = least(persons.created_at, source.created_at), is_identified = true, updated_at = ?
             from persons source where persons.id = ? and source.id = ?',
            [$now, $target->id, $source->id],
        );

        DB::table('persons')->where('id', $source->id)->delete();

        $changes = array_map(
            fn (object $row) => new DistinctIdChange($event->projectId, $row->distinct_id, (int) $target->id, $target->uuid, (int) $row->version),
            $moved,
        );

        return new IdentityResult(
            IdentityOutcome::Merged,
            $event->projectId,
            (int) $target->id,
            $target->uuid,
            $this->byDistinctId($changes),
            (int) $source->id,
            $source->uuid,
        );
    }

    private function noop(IdentityEvent $event, object $person, IdentityNoopReason $reason): IdentityResult
    {
        return new IdentityResult(IdentityOutcome::Noop, $event->projectId, (int) $person->id, $person->uuid, reason: $reason);
    }

    private function insertDistinctId(int $projectId, int $personId, string $personUuid, string $distinctId, CarbonImmutable $now): DistinctIdChange
    {
        DB::table('person_distinct_ids')->insert([
            'project_id' => $projectId,
            'person_id' => $personId,
            'distinct_id' => $distinctId,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new DistinctIdChange($projectId, $distinctId, $personId, $personUuid, 0);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int> distinct id => person id, for the ids that have a person
     */
    private function personIdsOf(int $projectId, array $ids): array
    {
        $map = [];

        foreach (DB::table('person_distinct_ids')->where('project_id', $projectId)->whereIn('distinct_id', $ids)->get(['distinct_id', 'person_id']) as $row) {
            $map[$row->distinct_id] = (int) $row->person_id;
        }

        ksort($map);

        return $map;
    }

    /**
     * Locks the persons FOR UPDATE in ascending id order (the one order every writer uses) and returns the rows
     * that still exist, by id. A person deleted by a transaction that committed while we waited is not returned.
     *
     * @param  list<int>  $personIds
     * @return array<int, object>
     */
    private function lockPersons(int $projectId, array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $rows = DB::table('persons')->where('project_id', $projectId)->whereIn('id', $personIds)
            ->orderBy('id')->lockForUpdate()->get(['id', 'uuid', 'is_identified']);

        $byId = [];
        foreach ($rows as $row) {
            $row->is_identified = (bool) $row->is_identified;
            $byId[(int) $row->id] = $row;
        }

        return $byId;
    }

    /**
     * @param  list<DistinctIdChange>  $changes
     * @return list<DistinctIdChange>
     */
    private function byDistinctId(array $changes): array
    {
        usort($changes, fn (DistinctIdChange $a, DistinctIdChange $b) => strcmp($a->distinctId, $b->distinctId));

        return $changes;
    }
}
