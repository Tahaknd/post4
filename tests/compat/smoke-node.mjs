// Exercises what the official posthog-node SDK really sends, against our capture endpoint.
// Env: PH_API_KEY, PH_HOST, PH_PREFIX (every custom event name starts with it).
// Prints one tab-separated line per event the SDK flushed (EVENT, name, uuid, iso timestamp, epoch ms, distinct id)
// so tests/compat/run.sh can verify exactly that set in ClickHouse.
import { randomUUID } from 'node:crypto'
import { PostHog } from 'posthog-node'

const { PH_API_KEY, PH_HOST, PH_PREFIX } = process.env
if (!PH_API_KEY || !PH_HOST || !PH_PREFIX) {
  console.error('PH_API_KEY, PH_HOST and PH_PREFIX are required')
  process.exit(2)
}

const epoch = Math.floor(Date.now() / 1000)
const distinctId = `compat-user-${epoch}`
const flushed = []
const errors = []

function track(client) {
  client.on('flush', (messages) => {
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

// --- client A: flushAt 1, every call is its own request ---------------------------------------
const a = new PostHog(PH_API_KEY, { host: PH_HOST, flushAt: 1, flushInterval: 0 })
track(a)

a.capture({
  distinctId,
  event: `${PH_PREFIX}capture`,
  properties: { source: 'posthog-node', $current_url: 'http://example.test/compat', answer: 42 },
})

// Custom timestamp (Date, millisecond precision) and a caller-chosen uuid.
const customTimestamp = new Date(Date.now() - 2 * 3600 * 1000)
const customUuid = randomUUID()
a.capture({
  distinctId,
  event: `${PH_PREFIX}custom_timestamp_uuid`,
  properties: { source: 'posthog-node' },
  timestamp: customTimestamp,
  uuid: customUuid,
})

// identify sends $identify with the traits under $set; alias sends $create_alias.
a.identify({ distinctId, properties: { email: 'compat@example.test', plan: 'free' } })
a.alias({ distinctId, alias: `compat-alias-${epoch}` })

await a.shutdown()

// --- client B: flushAt 5, 12 events leave in batches of 5, 5 and 2 -------------------------------
const b = new PostHog(PH_API_KEY, { host: PH_HOST, flushAt: 5, flushInterval: 0 })
track(b)
for (let i = 1; i <= 12; i++) {
  b.capture({ distinctId, event: `${PH_PREFIX}batch_${i}`, properties: { i } })
}
await b.shutdown()

// --- report ------------------------------------------------------------------------------------
for (const e of flushed) {
  console.log(['EVENT', e.event, e.uuid, e.timestamp, e.ms, e.distinctId].join('\t'))
}
console.log(['CUSTOM', `${PH_PREFIX}custom_timestamp_uuid`, customUuid, customTimestamp.toISOString(), customTimestamp.getTime()].join('\t'))
console.log(['ERRORS', errors.length].join('\t'))
console.error(`sent ${flushed.length} events for ${distinctId} (${errors.length} SDK errors)`)
for (const msg of errors) console.error(`sdk error: ${msg}`)
process.exit(errors.length === 0 ? 0 : 1)
