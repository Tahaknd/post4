-- Kafka'dan okuyan tablo (veri saklamaz)
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
    kafka_skip_broken_messages = 100;

-- Asıl event deposu
CREATE TABLE IF NOT EXISTS events (
    uuid UUID,
    project_id UInt32,
    event String,
    distinct_id String,
    properties String,
    timestamp DateTime64(3, 'UTC'),
    created_at DateTime64(3, 'UTC') DEFAULT now64(3),
    current_url String MATERIALIZED JSONExtractString(properties, '$current_url'),
    browser String MATERIALIZED JSONExtractString(properties, '$browser'),
    country_code String MATERIALIZED JSONExtractString(properties, '$geoip_country_code')
) ENGINE = MergeTree
PARTITION BY toYYYYMM(timestamp)
ORDER BY (project_id, toDate(timestamp), event, cityHash64(distinct_id), uuid);

-- Kafka tablosundan events'e akıtan materialized view
CREATE MATERIALIZED VIEW IF NOT EXISTS events_mv TO events AS
SELECT uuid, project_id, event, distinct_id, properties, timestamp
FROM kafka_events;
