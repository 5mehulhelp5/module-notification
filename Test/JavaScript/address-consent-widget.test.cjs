const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

/**
 * The Luma checkbox (address-consent.js) with a minimal jQuery stand-in and a scripted core: each core.record() call
 * takes the next answer (true: recorded, false: failed).
 */
function widget(answers, options = {}) {
    const state = {checked: false, phone: '+381601234567', events: [], posts: [], label: 'rendered by the template'};
    const element = (name) => ({
        name,
        handlers: {},
        on(events, selector, handler) {
            const fn = typeof selector === 'function' ? selector : handler;
            events.split(' ').forEach((event) => { (this.handlers[event.split('.')[0]] ||= []).push(fn); });
            return this;
        },
        off() { return this; },
        trigger(event, args) {
            state.events.push([event, ...(args || [])]);
            (this.handlers[event] || []).forEach((fn) => fn({type: event}, ...(args || [])));
            return this;
        },
        prop(name, value) {
            if (value === undefined) {
                return state.checked;
            }
            state.checked = value;
            return this;
        },
        val() { return state.phone; }
    });
    const box = element('box');
    const label = {text(value) { state.label = value; return label; }, first() { return label; }};
    const root = Object.assign(element('root'), {find: (selector) => (selector.includes('label') ? label : box)});
    const doc = element('document');
    const phone = element('phone');
    const document = {};
    let proto;
    const $ = (target) => (target === document ? doc : phone);
    $.widget = (name, definition) => { proto = definition; $.commercekings = {ckAddressConsent: definition}; };
    let real;
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../view/frontend/web/js/address-consent-core.js'), 'utf8'),
        vm.createContext({define: (names, factory) => { real = factory(); }, JSON, Promise, String, Object, Math}));
    const core = {
        ...real,
        record: (options) => {
            state.posts.push({recipient: options.recipient, given: options.given});
            return answers.shift() ? Promise.resolve(true) : Promise.reject(new Error('Address consent not recorded (503)'));
        }
    };
    const source = fs.readFileSync(path.join(__dirname, '../../view/frontend/web/js/address-consent.js'), 'utf8');
    vm.runInContext(source, vm.createContext({
        define: (names, factory) => factory($, core),
        window: {checkoutConfig: {quoteData: {entity_id: 42}}},
        document, Promise, String
    }));
    const instance = Object.create(proto);
    Object.assign(instance, {element: root, uuid: 1,
        options: {...proto.options, type: 'cart_reminder_sms', phone: '#phone', urls: {guest: '/g', mine: '/m'}, ...options}});
    instance._create();
    /** what the shopper does: tick or untick the box, or change the number (the phone field's change event) */
    const user = {
        async check(checked) {
            state.checked = checked;
            box.trigger('change');
            await instance.queue;
        },
        async number(value) {
            state.phone = value;
            phone.trigger('change');
            doc.handlers.change.forEach((fn) => fn({type: 'change'}));
            await instance.queue;
        }
    };
    return {state, instance, user, events: () => state.events.map((event) => event[0]).filter((name) => name !== 'change')};
}

test('checking records the consent for the number', async () => {
    const {state, user, events} = widget([true]);
    await user.check(true);
    assert.deepEqual(state.posts, [{recipient: '+381601234567', given: true}]);
    assert.deepEqual(events(), ['ckAddressConsent:recorded']);
    assert.equal(state.checked, true);
});

test('a consent that was not recorded leaves the box unchecked', async () => {
    const {state, user, events} = widget([false]);
    await user.check(true);
    assert.equal(state.checked, false);
    assert.deepEqual(events(), ['ckAddressConsent:failed']);
});

test('a failed withdrawal checks the box again - the server still holds the consent - and unchecking retries', async () => {
    const {state, instance, user, events} = widget([true, false, true]);
    await user.check(true);
    await user.check(false);
    assert.equal(state.checked, true, 'not shown as withdrawn');
    assert.equal(instance.recordedFor, '+381601234567');
    assert.deepEqual(events(), ['ckAddressConsent:recorded', 'ckAddressConsent:failed']);
    await user.check(false);
    assert.deepEqual(state.posts.slice(1), [{recipient: '+381601234567', given: false},
        {recipient: '+381601234567', given: false}]);
    assert.equal(state.checked, false);
    assert.equal(instance.recordedFor, null);
});

test('a new number while checked: the old consent is withdrawn and the box unchecked - nothing recorded for it',
    async () => {
        const {state, instance, user, events} = widget([true, true, true]);
        await user.check(true);
        await user.number('+381609999999');
        assert.deepEqual(state.posts, [{recipient: '+381601234567', given: true},
            {recipient: '+381601234567', given: false}]);
        assert.equal(state.checked, false, 'the shopper checks again for the new number');
        assert.equal(instance.recordedFor, null);
        assert.deepEqual(events(), ['ckAddressConsent:recorded']);
        await user.check(true);
        assert.deepEqual(state.posts.slice(2), [{recipient: '+381609999999', given: true}],
            'recorded on the new check');
        assert.equal(instance.recordedFor, '+381609999999');
    });

test('a new number whose old consent could not be withdrawn: checked again, the next change retries the withdrawal',
    async () => {
        const {state, instance, user, events} = widget([true, false, true]);
        await user.check(true);
        await user.number('+381609999999');
        assert.deepEqual(state.posts.slice(1), [{recipient: '+381601234567', given: false}]);
        assert.equal(state.checked, true, 'the server still holds the consent for the old number');
        assert.equal(instance.recordedFor, '+381601234567');
        assert.deepEqual(events(), ['ckAddressConsent:recorded', 'ckAddressConsent:failed']);
        await user.number('+381609999999');
        assert.deepEqual(state.posts.slice(2), [{recipient: '+381601234567', given: false}], 'withdrawn now');
        assert.equal(instance.recordedFor, null);
        assert.equal(state.checked, false);
        assert.equal(state.posts.filter((post) => post.recipient === '+381609999999').length, 0,
            'never a consent for the new number without a check');
    });

test('the same number again (a change event without a change) keeps the consent', async () => {
    const {state, user} = widget([true]);
    await user.check(true);
    await user.number('+381601234567');
    assert.equal(state.checked, true);
    assert.equal(state.posts.length, 1);
});

test('a number changed while unchecked records nothing', async () => {
    const {state, user} = widget([]);
    await user.number('+381609999999');
    assert.deepEqual(state.posts, []);
    assert.equal(state.checked, false);
});

test('a cleared number withdraws the consent; if that fails, the box stays checked', async () => {
    const {state, instance, user} = widget([true, false]);
    await user.check(true);
    await user.number('');
    assert.deepEqual(state.posts.slice(1), [{recipient: '+381601234567', given: false}]);
    assert.equal(state.checked, true);
    assert.equal(instance.recordedFor, '+381601234567');
});

test('a wording with {{number}} is shown with the number, and follows a changed number (checked or not)', async () => {
    const {state, user} = widget([], {wording: 'Yes, text me at {{number}}. Reply STOP to stop.', noNumber: 'this number'});
    assert.equal(state.label, 'Yes, text me at +381 60 123 4567. Reply STOP to stop.');
    await user.number('+381611234530');
    assert.equal(state.label, 'Yes, text me at +381 61 123 4530. Reply STOP to stop.');
    assert.deepEqual(state.posts, [], 'unchecked: only the label changes');
    await user.number('');
    assert.equal(state.label, 'Yes, text me at this number. Reply STOP to stop.');
});

test('a wording without the placeholder is left as the template rendered it', async () => {
    const {state, user} = widget([], {wording: 'Yes, text me offers at this number.'});
    assert.equal(state.label, 'rendered by the template');
    await user.number('+381611234530');
    assert.equal(state.label, 'rendered by the template');
});
