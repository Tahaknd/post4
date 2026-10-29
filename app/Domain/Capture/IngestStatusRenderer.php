<?php

namespace App\Domain\Capture;

/** Pure rendering of the `capture:status` report (see {@see IngestStatusReport::build()}) as text lines. */
final class IngestStatusRenderer
{
    /**
     * The human readable output as lines (an empty string is a blank line).
     *
     * @param  array<string, mixed>  $r
     * @return list<string>
     */
    public static function lines(array $r): array
    {
        $out = [];
        $e = $r['events'];
        $out[] = "Ingest status: {$r['status']}";
        foreach ($r['reasons'] as $reason) {
            $out[] = "  - {$reason}";
        }

        $out[] = '';
        $out[] = 'Events (posthog.events)';
        $out[] = '  total rows:          '.number_format($e['total']);
        $out[] = '  created last hour:   '.($e['last_hour'] === null ? 'unknown' : number_format($e['last_hour']));
        $out[] = '  newest created_at:   '.IngestStatusFormat::when($e['newest_created_at'], $e['newest_created_age_seconds']);

        $out[] = '';
        $out[] = 'Pipeline errors (posthog.events_errors, last hour)';
        $out[] = '  rows:                '.number_format($r['events_errors']['last_hour']);
        foreach ($r['events_errors']['top'] as $top) {
            $out[] = sprintf('  %5d x  %s  (newest %s)', $top['count'], $top['error'], $top['newest_received_at']);
        }

        $d = $r['dropped'];
        $out[] = '';
        $out[] = 'Dropped events (rejected by the normaliser, current + previous hour)';
        $out[] = '  total:              '.($d['known'] ? number_format($d['last_hour_total']) : 'unknown');
        foreach ((array) $d['by_project'] as $projectId => $count) {
            $out[] = sprintf('  project #%d:%s%s', $projectId, str_repeat(' ', max(1, 11 - strlen((string) $projectId))), number_format($count));
        }

        $c = $r['kafka_consumer'];
        $out[] = '';
        $out[] = 'Kafka consumer (system.kafka_consumers, posthog.kafka_events)';
        $out[] = '  consumers:           '.$c['consumers'];
        $out[] = '  newest exception:    '.($c['newest_exception'] === null
            ? 'none'
            : sprintf('%s (%d s ago): %s', $c['newest_exception']['time'], $c['newest_exception']['age_seconds'], $c['newest_exception']['message']));
        $out[] = '  last commit:         '.IngestStatusFormat::when($c['last_commit_time'], $c['last_commit_age_seconds'], 'never');
        $out[] = '  last poll:           '.IngestStatusFormat::when($c['last_poll_time'], $c['last_poll_age_seconds'], 'never');
        $out[] = '  offset sum:          '.number_format($c['offset_sum']);
        $out[] = '  vs previous run:     '.$r['previous_run']['summary'];

        $k = $r['kafka_lag'];
        $out[] = '';
        $out[] = 'Kafka lag (group clickhouse_events, topic events)';
        if (! $k['known']) {
            $out[] = '  total lag:           unknown';
        } else {
            $out[] = '  total lag:           '.number_format((int) $k['total']);
            foreach ((array) $k['partitions'] as $partition => $p) {
                $out[] = sprintf('  partition %d:         committed %s, high watermark %d, lag %d', $partition, $p['committed'] ?? 'none', $p['high'], $p['lag']);
            }
        }

        $ch = $r['clickhouse'];
        $out[] = '';
        $out[] = 'ClickHouse';
        $tmp = $ch['too_many_parts'];
        $out[] = '  TOO_MANY_PARTS:      '.($tmp === null
            ? 'never raised since the server started'
            : sprintf('%d time(s), last %s: %s', $tmp['count'], $tmp['last_error_time'], $tmp['last_error_message']));
        $out[] = '  disk:                '.($ch['disk'] === null
            ? 'unknown'
            : sprintf('%.1f GiB free of %.1f GiB (%.1f%%)', $ch['disk']['free_bytes'] / 1024 ** 3, $ch['disk']['total_bytes'] / 1024 ** 3, $ch['disk']['free_percent']));
        $out[] = sprintf('  active parts:        %s (max_parts_in_total %s)', $ch['active_parts'] ?? 'unknown', $ch['max_parts_in_total'] ?? 'unknown');
        $out[] = '  pending mutations:   '.$ch['pending_mutations'];

        if (isset($r['identity_consumer'])) {
            $i = $r['identity_consumer'];
            $out[] = '';
            $out[] = sprintf('Identity consumer (group %s, topic %s)', $i['group'], $i['topic']);
            if (! $i['lag']['known']) {
                $out[] = '  total lag:           unknown';
            } else {
                $out[] = '  total lag:           '.number_format((int) $i['lag']['total']);
                foreach ((array) $i['lag']['partitions'] as $partition => $p) {
                    $out[] = sprintf('  partition %d:         committed %s, high watermark %d, lag %d', $partition, $p['committed'] ?? 'none', $p['high'], $p['lag']);
                }
            }

            $out[] = '';
            $out[] = sprintf('Identity dead letters (topic %s)', $i['dlq']['topic']);
            $out[] = '  messages in topic:   '.($i['dlq']['known'] ? number_format((int) $i['dlq']['depth']) : 'unknown');
        }

        return $out;
    }
}
