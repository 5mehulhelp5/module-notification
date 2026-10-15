/**
 * May the storefront subscribe this visitor for a purpose (SubscriptionTypeInterface::getPurpose())?
 * Notification alone (opt-out): always. A permission module maps this module to its own with requirejs "map"
 * (e.g. the consent bridge: the visitor's consent choice). The server checks the permission again on subscribe.
 */
define([], function () {
    'use strict';

    return {
        /** @param {string} purpose */
        hasPermission: function (purpose) {
            return true;
        },

        /** Calls back with (isOn) when the permission for the purpose changes; never here. */
        onChange: function (purpose, callback) {
        }
    };
});
