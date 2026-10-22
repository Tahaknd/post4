-- Kafka'dan okuyan tablo (veri saklamaz).
-- Parse hataları kafka_handle_error_mode='stream' ile _error/_raw_message olarak events_errors'a gider.
CREATE TABLE IF NOT EXISTS kafka_events (
    uuid UUID,
    project_id UInt32,
    event String,
    distinct_id String,
    properties String,
    timestamp DateTime64(3, 'UTC')
) ENGINE = Kafka
SETTINGS
    kafka_broker_list = 'kafka:9092',
    kafka_topic_list = 'events',
    kafka_group_name = 'clickhouse_events',
    kafka_format = 'JSONEachRow',
    kafka_num_consumers = 1,
    kafka_max_block_size = 65536,
    kafka_handle_error_mode = 'stream',
    date_time_input_format = 'best_effort',
    input_format_null_as_default = 0;

-- Asıl event deposu (aynı uuid ikinci kez gelirse arka plan birleştirmesinde teke iner)
CREATE TABLE IF NOT EXISTS events (
    uuid UUID,
    project_id UInt32,
    event LowCardinality(String),
    distinct_id String,
    properties String CODEC(ZSTD(3)),
    timestamp DateTime64(3, 'UTC'),
    created_at DateTime64(3, 'UTC') DEFAULT now64(3),
    current_url String MATERIALIZED JSONExtractString(properties, '$current_url') CODEC(ZSTD(3)),
    browser LowCardinality(String) MATERIALIZED JSONExtractString(properties, '$browser'),
    country_code LowCardinality(String) MATERIALIZED JSONExtractString(properties, '$geoip_country_code')
) ENGINE = ReplacingMergeTree
PARTITION BY toYYYYMM(timestamp)
ORDER BY (project_id, toDate(timestamp), event, cityHash64(distinct_id), uuid);

-- Reddedilen / parse edilemeyen mesajlar (14 gün)
CREATE TABLE IF NOT EXISTS events_errors (
    received_at DateTime DEFAULT now(),
    topic LowCardinality(String),
    partition UInt16,
    offset UInt64,
    error String,
    raw String CODEC(ZSTD(3))
) ENGINE = MergeTree
ORDER BY (received_at, topic, partition, offset)
TTL received_at + INTERVAL 14 DAY;

-- Geçerli mesajlar events'e akar. Koruma koşulları events_errors_mv ile birebir aynı olmalı.
CREATE MATERIALIZED VIEW IF NOT EXISTS events_mv TO events AS
SELECT uuid, project_id, event, distinct_id, properties, timestamp
FROM kafka_events
WHERE length(_error) = 0
  AND uuid != toUUID('00000000-0000-0000-0000-000000000000')
  AND project_id > 0 AND event != '' AND distinct_id != ''
  AND timestamp >= toDateTime64('2000-01-01', 3, 'UTC')
  AND timestamp < now() + INTERVAL 366 DAY;

-- Geçersiz mesajlar events_errors'a akar.
CREATE MATERIALIZED VIEW IF NOT EXISTS events_errors_mv TO events_errors AS
SELECT
    _topic AS topic, _partition AS partition, _offset AS offset,
    if(length(_error) > 0, _error, 'rejected: invalid uuid/project_id/event/distinct_id/timestamp') AS error,
    if(length(_error) > 0, _raw_message,
       formatRowNoNewline('JSONEachRow', uuid, project_id, event, distinct_id, properties, timestamp)) AS raw
FROM kafka_events
WHERE NOT (length(_error) = 0
  AND uuid != toUUID('00000000-0000-0000-0000-000000000000')
  AND project_id > 0 AND event != '' AND distinct_id != ''
  AND timestamp >= toDateTime64('2000-01-01', 3, 'UTC')
  AND timestamp < now() + INTERVAL 366 DAY);
