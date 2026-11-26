// node --test tests/js/scanner.test.mjs — run by tests/Feature/ScannerSessionTest.php.
// SPEC-event-panel AC-PANEL-9: what the door scanner makes of each answer.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { outcomeOf } from '../../resources/js/scanner.js'

test('a 401 means the session ended, with or without the Nexo ID body', () => {
    assert.equal(outcomeOf(401, null), 'signedOut')
    assert.equal(outcomeOf(401, { reason: 'signed_out' }), 'signedOut')
})

test('a 419 (the CSRF token died with the session) means the session ended too', () => {
    assert.equal(outcomeOf(419, null), 'signedOut')
})

test('a redirect not followed (today\'s "sign in first") means the session ended', () => {
    assert.equal(outcomeOf(0, null, 'opaqueredirect'), 'signedOut')
})

test('a 429 is still "too many attempts", and any other failure still "could not validate"', () => {
    assert.equal(outcomeOf(429, null), 'throttled')
    for (const status of [403, 404, 422, 500, 502, 503]) {
        assert.equal(outcomeOf(status, null), 'failed', `status ${status}`)
    }
})

test('a 200 keeps the result the endpoint gave', () => {
    for (const result of ['ok', 'already', 'revoked', 'event_inactive', 'unknown']) {
        assert.equal(outcomeOf(200, { result, name: 'Ana' }), result)
    }
    assert.equal(outcomeOf(200, {}), 'unknown')
})
