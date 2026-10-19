const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

/** Loads the framework-free core with a fetch stub that answers with the given status and JSON. */
function core() {
    const source = fs.readFileSync(path.join(__dirname, '../../view/frontend/web/js/address-consent-core.js'), 'utf8');
    let module;
    vm.runInContext(source, vm.createContext({define: (names, factory) => { module = factory(); }, JSON, Promise, String, Object, Math}));
    return module;
}

function fetcher(status, body) {
    const calls = [];
    const fetch = (url, init) => {
        calls.push({url, init, body: JSON.parse(init.body)});
        return Promise.resolve({ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body)});
    };
    return {fetch, calls};
}

test('record posts the consent for one address to the REST route', async () => {
    const {fetch, calls} = fetcher(200, true);
    assert.equal(await core().record({url: '/rest/default/V1/ck-notification/address-consents', type: 'sms_offer',
        reference: 42, recipient: '+381601234567', wordingHash: 'abc', fetch}), true);
    assert.equal(calls[0].url, '/rest/default/V1/ck-notification/address-consents');
    assert.equal(calls[0].init.method, 'POST');
    assert.equal(calls[0].init.credentials, 'same-origin');
    assert.deepEqual(calls[0].body, {type: 'sms_offer', reference: '42', recipient: '+381601234567', wordingHash: 'abc',
        given: true});
});

test('unchecking sends given false', async () => {
    const {fetch, calls} = fetcher(200, true);
    await core().record({url: '/u', type: 't', reference: 'masked', recipient: '+1', wordingHash: '', given: false, fetch});
    assert.equal(calls[0].body.given, false);
});

test('a refusal or an error rejects, so the checkbox is not shown as given', async () => {
    await assert.rejects(core().record({url: '/u', type: 't', reference: 'r', recipient: '+1', wordingHash: 'h',
        fetch: fetcher(200, false).fetch}), /refused/);
    await assert.rejects(core().record({url: '/u', type: 't', reference: 'r', recipient: '+1', wordingHash: 'h',
        fetch: fetcher(400, {message: 'The consent text has changed.'}).fetch}), /400/);
});

test('formatNumber groups a number readably: calling code, a short first group, then threes, the last up to four', () => {
    const {formatNumber} = core();
    assert.equal(formatNumber('+12025550123'), '+1 202 555 0123');
    assert.equal(formatNumber('+381601234567'), '+381 60 123 4567');
    assert.equal(formatNumber('+12125550100'), '+1 212 555 0100');
    assert.equal(formatNumber('+4915123456789'), '+49 1512 345 6789');
    assert.equal(formatNumber('+447700900123'), '+44 770 090 0123');
    assert.equal(formatNumber('+38160123456'), '+381 60 123 456', 'a shorter national number');
    for (const value of ['', 'abc', '+0123456789', '060 123 4567']) {
        assert.equal(formatNumber(value), value, 'not E.164: unchanged');
    }
});

test('renderWording fills {{number}} (every one), or the no-number text; a wording without it stays as it is', () => {
    const {renderWording, NUMBER} = core();
    assert.equal(NUMBER, '{{number}}');
    assert.equal(renderWording('Yes, text me at {{number}}. Reply STOP to stop.', '+12025550123', 'this number'),
        'Yes, text me at +1 202 555 0123. Reply STOP to stop.');
    assert.equal(renderWording('{{number}} / {{number}}', '+12125550100', ''), '+1 212 555 0100 / +1 212 555 0100');
    assert.equal(renderWording('Yes, text me at {{number}}.', '', 'this number'), 'Yes, text me at this number.');
    assert.equal(renderWording('Yes, text me offers at this number.', '+12025550123', 'x'), 'Yes, text me offers at this number.');
});

test('the wording hash sent is the configured wording\'s, never the rendered text\'s', async () => {
    const {fetch, calls} = fetcher(200, true);
    await core().record({url: '/u', type: 't', reference: 'r', recipient: '+12025550123',
        wordingHash: 'hash-of-the-template', fetch});
    assert.equal(calls[0].body.wordingHash, 'hash-of-the-template', 'passed through unchanged');
});
