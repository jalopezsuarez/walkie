/* Walkie QR scanning — uses the native BarcodeDetector when available.
   No third-party libraries. On browsers without BarcodeDetector (e.g. iOS
   Safari) the app falls back to pasting the pairing link/code by hand. */
(function () {
    'use strict';

    function supported() {
        return typeof window.BarcodeDetector !== 'undefined';
    }

    // Extract a pairing token from a scanned string (URL "#p=TOKEN" or raw).
    function extractToken(text) {
        if (!text) return null;
        text = text.trim();
        var m = text.match(/[#?&]p=([A-Za-z0-9\-_]+)/);
        if (m) return m[1];
        // A bare token (base64url) is also accepted.
        if (/^[A-Za-z0-9\-_]{16,128}$/.test(text)) return text;
        return null;
    }

    function Scanner() {
        this._stream = null;
        this._raf = null;
        this._detector = null;
        this._active = false;
    }

    Scanner.prototype.start = async function (videoEl, onToken, onError) {
        if (!supported()) { onError && onError(new Error('unsupported')); return; }
        try {
            this._detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            this._stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' }, audio: false
            });
            videoEl.srcObject = this._stream;
            videoEl.setAttribute('playsinline', 'true');
            await videoEl.play();
            this._active = true;

            var self = this;
            var tick = async function () {
                if (!self._active) return;
                try {
                    var codes = await self._detector.detect(videoEl);
                    if (codes && codes.length) {
                        var token = extractToken(codes[0].rawValue);
                        if (token) { self.stop(); onToken(token); return; }
                    }
                } catch (e) { /* transient detect errors are fine */ }
                self._raf = requestAnimationFrame(tick);
            };
            this._raf = requestAnimationFrame(tick);
        } catch (e) {
            this.stop();
            onError && onError(e);
        }
    };

    Scanner.prototype.stop = function () {
        this._active = false;
        if (this._raf) { cancelAnimationFrame(this._raf); this._raf = null; }
        if (this._stream) {
            this._stream.getTracks().forEach(function (t) { t.stop(); });
            this._stream = null;
        }
    };

    window.QrScanner = { supported: supported, extractToken: extractToken, create: function () { return new Scanner(); } };
})();
