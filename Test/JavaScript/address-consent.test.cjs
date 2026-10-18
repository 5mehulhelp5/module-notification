const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

/** Loads the framework-free core with a fetch stub that answers with the given status and JSON. */
function core() {
    const source = fs.readFileSync(path.join(__dirname, '../../view/frontend/web/js/address-consent-core.js'), 'utf8');
    let module;
    vm.runInContext(source, vm.createContext({define: (names, factory) => { module = factory(); }, JSON, Promise, String}));
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
