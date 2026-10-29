<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Producer;
use RdKafka\TopicPartition;
use Symfony\Component\Process\Process;

/**
 * Helpers of the opt-in end-to-end test of the identity consumer (group "kafka-identity", scripts/test-identity.sh):
 * they talk to the REAL Kafka and Postgres of the compose stack, from inside the app container.
 *
 * The phpunit environment pins the database, the cache and the Kafka brokers to dead addresses on purpose, so nothing
 * here goes through the app's own config: Kafka is reached at IDENTITY_E2E_KAFKA (kafka:9092), Postgres through a
 * second connection ('live') built from the container's DB_HOST/DB_USERNAME/DB_PASSWORD, and the consumer runs as a
 * child process whose environment is spelled out in consumerEnvironment().
 */
final class KafkaEndToEnd
{
    public static function brokers(): string
    {
        return (string) (getenv('IDENTITY_E2E_KAFKA') ?: 'kafka:9092');
    }

    /** Fails (never skips) unless the runner script set the variables of the scratch project. */
    public static function require(string $name): string
    {
        $value = getenv($name);

        if (! is_string($value) || $value === '') {
            Assert::fail("The kafka-identity group needs the stack and a scratch project: run scripts/test-identity.sh ({$name} is not set).");
        }

        return $value;
    }

    public static function liveConnection(): Connection
    {
        $connection = DB::connectUsing('live', [
            'driver' => 'pgsql',
            'host' => getenv('DB_HOST') ?: 'postgres',
            'port' => getenv('DB_PORT') ?: '5432',
            'database' => 'posthog',
            'username' => getenv('DB_USERNAME') ?: 'posthog',
            'password' => getenv('DB_PASSWORD') ?: 'posthog',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ], true);

        Assert::assertSame('posthog', $connection->scalar('select current_database()'), 'the live connection must be the compose Postgres');

        return $connection;
    }

    /** @return array<int, int> partition => high watermark of the topic right now */
    public static function highWatermarks(string $topic): array
    {
        $consumer = self::consumer('identity-e2e-watermarks-'.bin2hex(random_bytes(4)));
        $metadata = $consumer->getMetadata(false, $consumer->newTopic($topic), 10_000);
        $result = [];

        foreach ($metadata->getTopics() as $t) {
            foreach ($t->getPartitions() as $partition) {
                $low = 0;
                $high = 0;
                $consumer->queryWatermarkOffsets($topic, $partition->getId(), $low, $high, 10_000);
                $result[$partition->getId()] = (int) $high;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * Makes a group start at these offsets (as if it had read everything before them). The group must have no
     * members. Committing needs no subscription.
     *
     * @param  array<int, int>  $offsets  partition => next offset to read
     */
    public static function commitOffsets(string $group, string $topic, array $offsets): void
    {
        $consumer = self::consumer($group);
        $consumer->commit(array_map(fn (int $partition, int $offset) => new TopicPartition($topic, $partition, $offset), array_keys($offsets), $offsets));
    }

    /**
     * Produces one raw message and returns where it landed.
     *
     * @return array{partition: int, offset: int}
     */
    public static function produce(string $topic, string $payload, string $key): array
    {
        $landed = null;
        $conf = new Conf;
        $conf->set('metadata.broker.list', self::brokers());
        $conf->set('enable.idempotence', 'true');
        $conf->setDrMsgCb(function ($kafka, $message) use (&$landed): void {
            $landed = ['error' => $message->err, 'partition' => $message->partition, 'offset' => $message->offset];
        });
        $producer = new Producer($conf);
        $producer->newTopic($topic)->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);
        Assert::assertSame(RD_KAFKA_RESP_ERR_NO_ERROR, $producer->flush(15_000), 'the message was not delivered');
        $producer->poll(0);
        Assert::assertSame(RD_KAFKA_RESP_ERR_NO_ERROR, $landed['error'] ?? null);

        return ['partition' => $landed['partition'], 'offset' => $landed['offset']];
    }

    /**
     * Every message of the topic from these offsets up to the end as it is now, decoded as JSON.
     *
     * @param  array<int, int>  $from  partition => first offset to read
     * @return list<array<string, mixed>>
     */
    public static function readJson(string $topic, array $from): array
    {
        $end = self::highWatermarks($topic);
        $consumer = self::consumer('identity-e2e-reader-'.bin2hex(random_bytes(4)));
        $consumer->assign(array_map(fn (int $partition, int $offset) => new TopicPartition($topic, $partition, $offset), array_keys($from), $from));

        $open = array_filter($end, fn (int $high, int $partition) => $high > ($from[$partition] ?? 0), ARRAY_FILTER_USE_BOTH);
        $messages = [];
        $deadline = microtime(true) + 30;

        while ($open !== [] && microtime(true) < $deadline) {
            $message = $consumer->consume(1000);

            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                continue;
            }

            $messages[] = json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR);

            if ($message->offset + 1 >= $end[$message->partition]) {
                unset($open[$message->partition]);
            }
        }

        Assert::assertSame([], $open, "could not read {$topic} to its end");
        $consumer->close();

        return $messages;
    }

    /**
     * Runs `identity:consume` in a child process against the live stack until the topic has been idle for a few
     * seconds, and returns its exit code, its stdout and the counters it printed.
     *
     * @return array{exit: int, stdout: string, stderr: string, counts: array<string, int>}
     */
    public static function runConsumer(string $group): array
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'identity:consume', "--group={$group}", '--stop-after-idle=5', '--max-runtime=240', '--max-memory-mb=100000'],
            base_path(),
            self::consumerEnvironment(),
            timeout: 300,
        );
        $process->run();

        $counts = [];
        if (preg_match('/identity:consume stopped \(\w+\): (\{.*\})/', $process->getOutput(), $m) === 1) {
            $counts = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
        }

        return ['exit' => $process->getExitCode(), 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput(), 'counts' => $counts];
    }

    /** What the child needs that phpunit.xml pinned to something else. */
    private static function consumerEnvironment(): array
    {
        return [
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'posthog',
            'DB_URL' => '',
            'KAFKA_BROKERS' => self::brokers(),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'stderr',
        ];
    }

    private static function consumer(string $group): KafkaConsumer
    {
        $conf = new Conf;
        $conf->set('metadata.broker.list', self::brokers());
        $conf->set('group.id', $group);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('log_level', '3');
        $conf->setLogCb(static function (): void {});

        return new KafkaConsumer($conf);
    }
}
