/* Walkie QR helper — extract a pairing token from a scanned/opened link.
   Pairing is one-way: you show your QR and the other person opens it with their
   phone camera (deep link "#p=TOKEN"), so no in-app camera scanner is needed. */
(function () {
    'use strict';

    // Extract a pairing token from a string (URL "#p=TOKEN" or a bare token).
    function extractToken(text) {
        if (!text) return null;
        text = text.trim();
        var m = text.match(/[#?&]p=([A-Za-z0-9\-_]+)/);
        if (m) return m[1];
        if (/^[A-Za-z0-9\-_]{16,128}$/.test(text)) return text;
        return null;
    }

    window.QrScanner = { extractToken: extractToken };
})();
