const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

/**
 * The Luma checkbox (address-consent.js) with a minimal jQuery stand-in and a scripted core: each core.record() call
 * takes the next answer (true: recorded, false: failed).
 */
function widget(answers) {
    const state = {checked: false, phone: '+381601234567', events: [], posts: []};
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
    const root = Object.assign(element('root'), {find: () => box});
    const doc = element('document');
    const phone = element('phone');
    const document = {};
    let proto;
    const $ = (target) => (target === document ? doc : phone);
    $.widget = (name, definition) => { proto = definition; $.commercekings = {ckAddressConsent: definition}; };
    const core = {
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
        options: {...proto.options, type: 'cart_reminder_sms', phone: '#phone', urls: {guest: '/g', mine: '/m'}}});
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

test('a new number whose old consent could not be withdrawn keeps the box checked and retries on the next change',
    async () => {
        const {state, instance, user, events} = widget([true, false, true, true]);
        await user.check(true);
        await user.number('+381609999999');
        assert.deepEqual(state.posts.slice(1), [{recipient: '+381601234567', given: false}], 'the new number waits');
        assert.equal(state.checked, true);
        assert.equal(instance.recordedFor, '+381601234567');
        assert.deepEqual(events(), ['ckAddressConsent:recorded', 'ckAddressConsent:failed']);
        await user.number('+381609999999');
        assert.deepEqual(state.posts.slice(2), [{recipient: '+381601234567', given: false},
            {recipient: '+381609999999', given: true}]);
        assert.equal(instance.recordedFor, '+381609999999');
        assert.equal(state.checked, true);
    });

test('withdrawn, then the new number not recorded: unchecked', async () => {
    const {state, instance, user} = widget([true, true, false]);
    await user.check(true);
    await user.number('+381609999999');
    assert.equal(instance.recordedFor, null);
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
