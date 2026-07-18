/* Walkie push-to-talk recorder — MediaRecorder + WebAudio, no libraries. */
(function () {
    'use strict';

    function pickMime() {
        var candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
        if (typeof MediaRecorder === 'undefined') return '';
        for (var i = 0; i < candidates.length; i++) {
            if (MediaRecorder.isTypeSupported(candidates[i])) return candidates[i];
        }
        return '';
    }

    function Recorder() {
        this._stream = null;
        this._rec = null;
        this._chunks = [];
        this._startedAt = 0;
        this.mime = '';
        this.durationMs = 0;
    }

    Recorder.prototype.supported = function () {
        return typeof MediaRecorder !== 'undefined' &&
            navigator.mediaDevices && !!navigator.mediaDevices.getUserMedia;
    };

    Recorder.prototype.start = async function () {
        if (!this.supported()) throw new Error('Recording not supported');
        this._stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        this.mime = pickMime();
        var opts = this.mime ? { mimeType: this.mime } : undefined;
        this._rec = new MediaRecorder(this._stream, opts);
        this._chunks = [];
        var self = this;
        this._rec.ondataavailable = function (e) { if (e.data && e.data.size) self._chunks.push(e.data); };
        this._rec.start();
        this._startedAt = Date.now();
    };

    // Returns { blob, mime, durationMs } or null if too short.
    Recorder.prototype.stop = function () {
        var self = this;
        return new Promise(function (resolve) {
            if (!self._rec) { resolve(null); return; }
            self.durationMs = Date.now() - self._startedAt;
            self._rec.onstop = function () {
                self._teardown();
                if (!self._chunks.length) { resolve(null); return; }
                var type = self.mime || (self._chunks[0].type || 'audio/webm');
                var blob = new Blob(self._chunks, { type: type });
                resolve({ blob: blob, mime: type.split(';')[0], durationMs: self.durationMs });
            };
            try { self._rec.stop(); } catch (e) { self._teardown(); resolve(null); }
        });
    };

    Recorder.prototype.cancel = function () {
        if (this._rec && this._rec.state !== 'inactive') { try { this._rec.stop(); } catch (e) {} }
        this._teardown();
    };

    Recorder.prototype._teardown = function () {
        if (this._stream) { this._stream.getTracks().forEach(function (t) { t.stop(); }); this._stream = null; }
        this._rec = null;
    };

    // Blob -> base64 string (no data: prefix).
    function blobToBase64(blob) {
        return new Promise(function (resolve, reject) {
            var fr = new FileReader();
            fr.onload = function () {
                var s = fr.result || '';
                var comma = s.indexOf(',');
                resolve(comma >= 0 ? s.slice(comma + 1) : s);
            };
            fr.onerror = reject;
            fr.readAsDataURL(blob);
        });
    }

    window.WalkieAudio = { create: function () { return new Recorder(); }, blobToBase64: blobToBase64 };
})();
