/**
 * Address consent, framework-free: record() posts a checkbox consent (or its withdrawal) for one address to
 * Notification's REST route. Framework-free: a custom storefront can build its checkbox on it (only Luma's is shipped).
 */
define([], function () {
    'use strict';

    /**
     * @param {Object} options url (REST route: guest or /mine), type, reference, recipient, wordingHash, given,
     *     fetch (optional, defaults to window.fetch)
     * @return {Promise<boolean>} resolves true when recorded; rejects when refused or failed
     */
    function record(options) {
        var send = options.fetch || window.fetch.bind(window);

        return send(options.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({
                type: options.type,
                reference: String(options.reference),
                recipient: options.recipient,
                wordingHash: options.wordingHash,
                given: options.given !== false
            })
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Address consent not recorded (' + response.status + ')');
            }
            return response.json();
        }).then(function (result) {
            if (result !== true) {
                throw new Error('Address consent refused');
            }
            return true;
        });
    }

    return {record: record};
});
