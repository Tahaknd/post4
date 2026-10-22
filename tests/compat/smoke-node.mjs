// Exercises what the official posthog-node SDK really sends, against our capture endpoint.
// Env: PH_API_KEY, PH_HOST, PH_PREFIX (every custom event name starts with it).
// Prints tab-separated lines on stdout so tests/compat/run.sh can verify exactly that set in ClickHouse:
//   SDK    <package> <version>
//   EVENT  <name> <uuid> <iso timestamp> <epoch ms> <distinct id>   (one per event the SDK flushed)
//   FLUSH  <client> <batch size>                                    (one per `flush` the SDK emitted)
//   CUSTOM <name> <uuid> <iso timestamp> <epoch ms>                 (the caller-chosen uuid/timestamp event)
//   ERRORS <count>
// The report is always printed, even if the SDK threw. Exit code: 0 when the SDK reported no errors, 1
// otherwise, 2 for missing configuration. Writes nothing to disk.
import { randomUUID } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { PostHog } from 'posthog-node'

const { PH_API_KEY, PH_HOST, PH_PREFIX } = process.env
if (!PH_API_KEY || !PH_HOST || !PH_PREFIX) {
  console.error('PH_API_KEY, PH_HOST and PH_PREFIX are required')
  process.exit(2)
}

const epoch = Math.floor(Date.now() / 1000)
const distinctId = `compat-user-${epoch}`
const flushed = []
const batches = []
const errors = []
let custom = null

function track(name, client) {
  client.on('flush', (messages) => {
    batches.push({ client: name, size: messages.length })
    for (const m of messages) {
      flushed.push({
        event: m.event,
        uuid: m.uuid,
        timestamp: new Date(m.timestamp).toISOString(),
        ms: new Date(m.timestamp).getTime(),
        distinctId: m.distinct_id ?? m.distinctId,
      })
    }
  })
  client.on('error', (err) => errors.push(String(err?.message ?? err)))
}

function sdkVersion() {
  try {
    const url = new URL('./node_modules/posthog-node/package.json', import.meta.url)
    return JSON.parse(readFileSync(url, 'utf8')).version
  } catch {
    return 'unknown'
  }
}

function report() {
  console.log(['SDK', 'posthog-node', sdkVersion()].join('\t'))
  for (const e of flushed) {
    console.log(['EVENT', e.event, e.uuid, e.timestamp, e.ms, e.distinctId].join('\t'))
  }
  for (const b of batches) {
    console.log(['FLUSH', b.client, b.size].join('\t'))
  }
  if (custom) {
    console.log(['CUSTOM', custom.event, custom.uuid, custom.timestamp.toISOString(), custom.timestamp.getTime()].join('\t'))
  }
  console.log(['ERRORS', errors.length].join('\t'))
  console.error(`sent ${flushed.length} events for ${distinctId} (${errors.length} SDK errors)`)
  for (const msg of errors) console.error(`sdk error: ${msg}`)
}

async function stop(name, client) {
  try {
    await client.shutdown()
  } catch (err) {
    errors.push(`${name}.shutdown: ${String(err?.message ?? err)}`)
  }
}

try {
  // --- client A: flushAt 1, every call is its own request -----------------------------------------
  const a = new PostHog(PH_API_KEY, { host: PH_HOST, flushAt: 1, flushInterval: 0 })
  track('A', a)
  try {
    a.capture({
      distinctId,
      event: `${PH_PREFIX}capture`,
      properties: { source: 'posthog-node', $current_url: 'http://example.test/compat', answer: 42 },
    })

    // Custom timestamp (Date, millisecond precision) and a caller-chosen uuid.
    custom = {
      event: `${PH_PREFIX}custom_timestamp_uuid`,
      uuid: randomUUID(),
      timestamp: new Date(Date.now() - 2 * 3600 * 1000),
    }
    a.capture({
      distinctId,
      event: custom.event,
      properties: { source: 'posthog-node' },
      timestamp: custom.timestamp,
      uuid: custom.uuid,
    })

    // identify sends $identify with the traits under $set; alias sends $create_alias.
    a.identify({ distinctId, properties: { email: 'compat@example.test', plan: 'free' } })
    a.alias({ distinctId, alias: `compat-alias-${epoch}` })
  } finally {
    await stop('a', a)
  }

  // --- client B: flushAt 5, 12 events leave in batches of 5, 5 and 2 -----------------------------------
  const b = new PostHog(PH_API_KEY, { host: PH_HOST, flushAt: 5, flushInterval: 0 })
  track('B', b)
  try {
    for (let i = 1; i <= 12; i++) {
      b.capture({ distinctId, event: `${PH_PREFIX}batch_${i}`, properties: { i } })
    }
  } finally {
    await stop('b', b)
  }
} catch (err) {
  errors.push(`script crashed: ${err?.stack ?? err}`)
} finally {
  report()
}

process.exit(errors.length === 0 ? 0 : 1)
