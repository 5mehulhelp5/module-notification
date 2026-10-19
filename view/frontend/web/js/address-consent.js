/**
 * Luma: the address consent checkbox (address-consent.phtml). Checking records the consent for the number in the phone
 * field, unchecking withdraws it, and a changed number moves the consent to the new one. The label is the configured
 * wording with the number in place of {{number}} (formatted, or "this number" while there is none). The phone field is
 * looked up when needed, so a field rendered later (e.g. by checkout) works. Triggers "ckAddressConsent:recorded"
 * (given, recipient) on the element when the server has it - subscribe after that - and "ckAddressConsent:failed" when
 * not: the box then shows what the server holds - unchecked when no consent was recorded, checked again when a
 * withdrawal failed (the consent is still there); the next check, uncheck or number change tries again.
 */
define([
    'jquery',
    'CommerceKings_Notification/js/address-consent-core',
    'jquery-ui-modules/widget'
], function ($, core) {
    'use strict';

    $.widget('commercekings.ckAddressConsent', {
        options: {type: '', wordingHash: '', urls: {guest: '', mine: ''}, phone: '', reference: null, mine: null,
            wording: '', noNumber: ''},

        _create: function () {
            var self = this;

            this.box = this.element.find('input[type="checkbox"]');
            this.label = this.element.find('label span').first();
            this.recordedFor = null;
            this.queue = Promise.resolve();
            this.box.on('change', function () { self._sync(); });
            $(document).on('change.ckAddressConsent' + this.uuid, this.options.phone, function () {
                self._renderLabel();
                if (self.box.prop('checked')) {
                    self._sync();
                }
            });
            this._renderLabel();
        },

        /** The wording with the number in it ({{number}}); a wording without the placeholder is left as rendered. */
        _renderLabel: function () {
            var phone = String($(this.options.phone).val() || '').trim();

            if (this.options.wording && this.options.wording.indexOf(core.NUMBER) !== -1) {
                this.label.text(core.renderWording(this.options.wording, phone, this.options.noNumber));
            }
        },

        _config: function () {
            return window.checkoutConfig || {};
        },

        _reference: function () {
            var quote = this._config().quoteData || {};

            return this.options.reference || quote.entity_id || null;
        },

        _mine: function () {
            return this.options.mine !== null && this.options.mine !== undefined
                ? !!this.options.mine : !!this._config().isCustomerLoggedIn;
        },

        _post: function (recipient, given) {
            return core.record({
                url: this._mine() ? this.options.urls.mine : this.options.urls.guest,
                type: this.options.type,
                reference: this._reference(),
                recipient: recipient,
                wordingHash: this.options.wordingHash,
                given: given
            });
        },

        /** Serialized: the server sees the checks, unchecks and number changes in the order they happened. */
        _sync: function () {
            var self = this;

            this.queue = this.queue.then(function () {
                var phone = String($(self.options.phone).val() || '').trim(),
                    given = self.box.prop('checked'),
                    previous = self.recordedFor;

                if (!self._reference()) {
                    return null;
                }
                if (previous !== null && (!given || previous !== phone)) {
                    // unchecked, or another number: the consent for the previous number is withdrawn first
                    return self._post(previous, false).then(function () {
                        self.recordedFor = null;
                        return given && phone ? self._record(phone) : null;
                    });
                }
                return given && phone && previous !== phone ? self._record(phone) : null;
            }).catch(function () {
                // recordedFor is the number the server still holds a consent for (null: none)
                self.box.prop('checked', self.recordedFor !== null);
                self.element.trigger('ckAddressConsent:failed');
            });
        },

        _record: function (phone) {
            var self = this;

            return this._post(phone, true).then(function () {
                self.recordedFor = phone;
                self.element.trigger('ckAddressConsent:recorded', [{given: true, recipient: phone}]);
            });
        },

        _destroy: function () {
            $(document).off('change.ckAddressConsent' + this.uuid);
        }
    });

    return $.commercekings.ckAddressConsent;
});
