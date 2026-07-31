import { execSync } from 'node:child_process'
import { writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { randomUUID } from 'node:crypto'
import path from 'node:path'
import axios from 'axios'

const KEYCLOAK_URL = 'http://localhost:8090'
const API_URL = 'http://localhost:8080'
const REALM = 'betting-game'
const CLIENT_ID = 'betting-game-frontend'

const FIXTURE_PATH = path.join(path.dirname(fileURLToPath(import.meta.url)), '.fixture.json')

/**
 * Repo root, two levels up from frontend/tests/e2e - `docker-compose.yml`
 * lives there, and the db bootstrap below needs to run from that directory.
 */
const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')

async function token(username, password) {
  const response = await axios.post(
    `${KEYCLOAK_URL}/realms/${REALM}/protocol/openid-connect/token`,
    new URLSearchParams({
      client_id: CLIENT_ID,
      grant_type: 'password',
      username,
      password
    }),
    { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }
  )

  return response.data.access_token
}

/**
 * Participant creation has no HTTP endpoint (self-registration is E1-01, not
 * implemented) - QUICKSTART.md's own walkthrough bootstraps the demo users'
 * participant rows the same way, directly against the database. Idempotent
 * (`ON DUPLICATE KEY UPDATE`) so re-running the suite against a stack that
 * already has these rows does not fail.
 *
 * Tolerates a missing `docker-compose`: the suite also runs from inside a
 * container (there is no local Node on every dev machine), and there the
 * compose CLI is not on PATH. The rows only have to exist - who created them
 * does not matter - so a failure here is a warning, and the member-add below
 * is what actually fails loudly if they are missing.
 */
function ensureParticipants() {
  const sql = `
    INSERT INTO user (user_id, username, password_hash, email) VALUES
      (1, 'admin', 'x', 'admin@example.com'),
      (2, 'testuser', 'x', 'test@example.com')
    ON DUPLICATE KEY UPDATE username = VALUES(username);

    INSERT INTO participant (participant_id, user_id, display_name, is_active) VALUES
      (1, 1, 'Admin', 1),
      (2, 2, 'Test User', 1)
    ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), is_active = 1;
  `

  try {
    execSync('docker-compose exec -T db mariadb -uroot -psecret betting_game', {
      cwd: REPO_ROOT,
      input: sql,
      stdio: ['pipe', 'pipe', 'pipe']
    })
  } catch (error) {
    console.warn(
      '[e2e] Could not bootstrap participants via docker-compose '
      + `(${error.message.split('\n')[0]}).\n`
      + '[e2e] Assuming participants 1 and 2 already exist. If the seeding below '
      + 'fails, run this once from the repo root:\n'
      + '[e2e]   docker-compose exec -T db mariadb -uroot -psecret betting_game '
      + '< database/seed-demo-participants.sql'
    )
  }
}

function command(adminToken) {
  const client = axios.create({
    baseURL: API_URL,
    headers: { Authorization: `Bearer ${adminToken}`, 'Content-Type': 'application/json' }
  })

  return async (method, path, data) => {
    const response = await client.request({
      method,
      url: path,
      data,
      headers: { 'Idempotency-Key': randomUUID() }
    })

    return response.data
  }
}

/**
 * The first calendar year no existing tipp year occupies.
 *
 * Tipp years may not overlap (`TippYear::assertNoOverlap`), so a fixed range
 * would let the suite seed exactly once and answer 409 on every later run.
 * Taking the year after the latest known `endDate` keeps each run in its own
 * range without having to delete anything that came before.
 */
async function nextFreeYear(adminToken) {
  const { data } = await axios.get(`${API_URL}/admin/tipp-years`, {
    headers: { Authorization: `Bearer ${adminToken}` }
  })

  const latest = (data.tippYears ?? [])
    .map(year => new Date(year.endDate).getFullYear())
    .reduce((max, year) => Math.max(max, year), 0)

  return Math.max(latest + 1, 2026)
}

/**
 * Seeds one full tipp year (period, two members, bet rows, a submitted
 * ticket, a recorded and evaluated draw) through the real command handlers -
 * the same walkthrough QUICKSTART.md documents via curl, run here so the E2E
 * specs have real, known data to assert against instead of an empty stack.
 *
 * The year is left `closed` at the end (not `running`): B-18 allows only one
 * running tipp year at a time, and leaving this one running would make a
 * second suite run fail with 409 before it even starts.
 */
async function seedTippYear(api, calendarYear) {
  const suffix = Date.now()

  const year = await api('POST', '/admin/tipp-years', {
    name: `E2E ${calendarYear}`,
    startDate: `${calendarYear}-01-01`,
    endDate: `${calendarYear}-12-31`,
    ticketCostPerRow: 1.2
  })
  const tippYearId = year.resourceId

  await api('PUT', `/admin/tipp-years/${tippYearId}/status`, { status: 'running' })

  const period = await api('POST', `/admin/tipp-years/${tippYearId}/bet-periods`, {
    name: `Period ${calendarYear}`,
    startDate: `${calendarYear}-01-01`,
    endDate: `${calendarYear}-12-31`
  })
  const betPeriodId = period.resourceId

  await api('POST', `/admin/tipp-years/${tippYearId}/members`, { participantId: 1 })
  await api('POST', `/admin/tipp-years/${tippYearId}/members`, { participantId: 2 })

  await api('PUT', '/admin/participants/1/bet-row', { betPeriodId, numbers: [3, 12, 19, 27, 33, 45] })
  await api('PUT', '/admin/participants/2/bet-row', { betPeriodId, numbers: [7, 8, 9, 10, 11, 12] })

  const ticket = await api('POST', `/admin/tipp-years/${tippYearId}/tickets`, {
    periodStart: `${calendarYear}-01-01`,
    periodEnd: `${calendarYear}-01-31`,
    drawCount: 1,
    superzahl: 7,
    lotteryReference: `E2E-${suffix}`
  })

  const draw = await api('POST', '/admin/draws', {
    tippYearId,
    drawDate: `${calendarYear}-01-07`,
    numbers: [7, 8, 9, 10, 11, 44],
    superzahl: 7
  })
  const drawId = draw.resourceId

  await api('PUT', `/admin/draws/${drawId}/winnings`, { totalAmount: 50 })

  // One fee per participant. They are handed out separately so no two specs
  // write to the same row: the admin spec books participant 1's fee, while
  // the participant spec reads participant 2's and still finds it open.
  const feesResponse = await axios.get(`${API_URL}/admin/fees`, {
    params: { tippYearId },
    headers: { Authorization: `Bearer ${await token('admin', 'admin123')}` }
  })
  const feeOf = participantId =>
    feesResponse.data.fees.find(fee => fee.participantId === participantId)?.feeId ?? null

  await api('PUT', `/admin/tipp-years/${tippYearId}/status`, { status: 'closed' })

  return {
    tippYearId,
    betPeriodId,
    ticketId: ticket.resourceId,
    drawId,
    adminFeeId: feeOf(1),
    participantFeeId: feeOf(2)
  }
}

export default async function globalSetup() {
  ensureParticipants()

  const adminToken = await token('admin', 'admin123')
  const api = command(adminToken)

  const fixture = await seedTippYear(api, await nextFreeYear(adminToken))

  writeFileSync(FIXTURE_PATH, JSON.stringify(fixture, null, 2))
}
