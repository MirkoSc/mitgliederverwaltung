'use strict';

/**
 * The code of an authenticator app, computed here so the help screenshots can
 * log in without any back door in the application (docs/spec/12-hilfe.md
 * section 5). RFC 4226 (HOTP) and RFC 6238 (TOTP) on node:crypto, the same
 * arithmetic as App\Service\Account\Totp; tests/js/hilfe-screenshots.test.js
 * checks it against the RFC 6238 test vectors.
 */

const crypto = require('node:crypto');

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** RFC 4648 Base32 (as the authenticator apps and konten.json write it) to bytes. */
function base32Decode(text) {
    const sauber = String(text).toUpperCase().replace(/[^A-Z2-7]/g, '');
    const bytes = [];
    let puffer = 0;
    let bits = 0;
    for (const zeichen of sauber) {
        puffer = (puffer << 5) | ALPHABET.indexOf(zeichen);
        bits += 5;
        if (bits >= 8) {
            bits -= 8;
            bytes.push((puffer >> bits) & 255);
            puffer &= (1 << bits) - 1;
        }
    }
    return Buffer.from(bytes);
}

/** RFC 4226 section 5.3: HMAC over the 8-byte counter, dynamic truncation. */
function hotp(geheimnis, zaehler, stellen = 6, algorithmus = 'sha1') {
    const zaehlerBytes = Buffer.alloc(8);
    zaehlerBytes.writeBigUInt64BE(BigInt(zaehler));
    const hash = crypto.createHmac(algorithmus, geheimnis).update(zaehlerBytes).digest();
    const offset = hash[hash.length - 1] & 0x0f;
    const binaer = ((hash[offset] & 0x7f) << 24)
        | (hash[offset + 1] << 16)
        | (hash[offset + 2] << 8)
        | hash[offset + 3];
    return String(binaer % 10 ** stellen).padStart(stellen, '0');
}

/** RFC 6238: the code for a point in time (unix seconds). */
function totp(geheimnis, sekunden, { stellen = 6, periode = 30, algorithmus = 'sha1' } = {}) {
    return hotp(geheimnis, Math.floor(sekunden / periode), stellen, algorithmus);
}

/** The 6-digit code the app shows now, for a Base32 secret. */
function appCode(base32, jetztMs = Date.now()) {
    return totp(base32Decode(base32), Math.floor(jetztMs / 1000));
}

/** Seconds until the next 30-second step starts (a step is accepted only once). */
function sekundenBisNaechsterSchritt(jetztMs = Date.now(), periode = 30) {
    const sekunden = Math.floor(jetztMs / 1000);
    return periode - (sekunden % periode);
}

module.exports = { base32Decode, hotp, totp, appCode, sekundenBisNaechsterSchritt };
