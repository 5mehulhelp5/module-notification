/**
 * Address consent, framework-free: record() posts a checkbox consent (or its withdrawal) for one address to
 * Notification's REST route; renderWording() shows the configured wording with its {{number}} placeholder filled in
 * (formatNumber(): "+381 61 123 4530"). The evidence is the configured wording itself - its hash is what record()
 * sends - and the number is stored with it as the recipient. Framework-free: a custom storefront can build its
 * checkbox on it (only Luma's is shipped).
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

    /** The placeholder for the number in a configured wording. */
    var NUMBER = '{{number}}';

    /** Country calling codes (ITU, prefix-free): the start of a number in E.164. */
    var CALLING = {};
    (
        '1 7 20 27 30 31 32 33 34 36 39 40 41 43 44 45 46 47 48 49 51 52 53 54 55 56 57 58 60 61 62 63 64 65 66 ' +
        '81 82 84 86 90 91 92 93 94 95 98 211 212 213 216 218 220 221 222 223 224 225 226 227 228 229 230 231 232 ' +
        '233 234 235 236 237 238 239 240 241 242 243 244 245 248 249 250 251 252 253 254 255 256 257 258 260 261 ' +
        '262 263 264 265 266 267 268 269 291 297 298 299 350 351 352 353 354 355 356 357 358 359 370 371 372 373 ' +
        '374 375 376 377 378 380 381 382 383 385 386 387 389 420 421 423 500 501 502 503 504 505 506 507 508 509 ' +
        '590 591 592 593 594 595 596 597 598 599 670 673 674 675 676 677 678 679 680 682 683 685 686 687 688 689 ' +
        '691 692 852 853 855 856 880 886 960 961 962 963 964 965 966 967 968 970 971 972 973 974 975 976 977 992 ' +
        '993 994 995 996 998'
    ).split(' ').forEach(function (code) { CALLING[code] = true; });

    /**
     * A number in E.164 as people read it: the calling code, a short first group, then groups of three, the last of
     * up to four - "+381 61 123 4530", "+1 212 555 0100", "+49 1512 345 6789". Anything else is returned unchanged.
     *
     * @param {string} number
     * @return {string}
     */
    function formatNumber(number) {
        var digits = String(number || ''),
            code = '',
            national,
            first,
            groups = [];

        if (!/^\+[1-9][0-9]{7,14}$/.test(digits)) {
            return digits;
        }
        digits = digits.slice(1);
        for (var length = 3; length >= 1 && code === ''; length--) {
            code = CALLING[digits.slice(0, length)] ? digits.slice(0, length) : '';
        }
        if (code === '') {
            return number;
        }
        national = digits.slice(code.length);
        first = Math.min(4, Math.max(2, national.length - 7));
        groups.push(national.slice(0, first));
        national = national.slice(first);
        while (national.length > 4) {
            groups.push(national.slice(0, 3));
            national = national.slice(3);
        }
        if (national !== '') {
            groups.push(national);
        }
        return '+' + code + ' ' + groups.join(' ');
    }

    /**
     * The wording as shown: every {{number}} replaced by the number (formatted), or by noNumber while there is none. A
     * wording without the placeholder is shown as it is.
     *
     * @param {string} wording the configured wording (the evidence)
     * @param {string} number in E.164, or ''
     * @param {string} noNumber e.g. "this number"
     * @return {string}
     */
    function renderWording(wording, number, noNumber) {
        var shown = number ? formatNumber(number) : String(noNumber || '');

        return String(wording || '').split(NUMBER).join(shown);
    }

    return {record: record, renderWording: renderWording, formatNumber: formatNumber, NUMBER: NUMBER};
});
