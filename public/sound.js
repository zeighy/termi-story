// public/sound.js
//
// Every terminal sound is synthesized with the Web Audio API, so there are no audio
// files to host or download. Each sound schedules a few short-lived nodes on `ctx`,
// starting at time `t`, into the node `out`. That works the same on a live AudioContext
// and on an OfflineAudioContext (which is how the preview samples are rendered).
//
// The names are shared with the server: script BEEP tones (ScriptingEngine::BEEP_TONES)
// and the 'sound' field of a terminal response (Terminal.php).
(function (global) {
    'use strict';

    const SILENT = 0.0001; // exponential ramps can't reach 0

    function rand(min, max) {
        return min + Math.random() * (max - min);
    }

    // Gain envelope: rise to `peak`, hold, then fade out
    function envelope(ctx, t, { peak, attack = 0.004, hold = 0, release = 0.08 }) {
        const gain = ctx.createGain();
        gain.gain.setValueAtTime(SILENT, t);
        gain.gain.exponentialRampToValueAtTime(peak, t + attack);
        gain.gain.setValueAtTime(peak, t + attack + hold);
        gain.gain.exponentialRampToValueAtTime(SILENT, t + attack + hold + release);
        return { gain, end: t + attack + hold + release };
    }

    function filter(ctx, type, frequency, q = 0.7) {
        const node = ctx.createBiquadFilter();
        node.type = type;
        node.frequency.value = frequency;
        node.Q.value = q;
        return node;
    }

    // One oscillator through an optional filter and an envelope
    function tone(ctx, out, t, opts) {
        const { type = 'square', freq, endFreq, lowpass } = opts;
        const osc = ctx.createOscillator();
        osc.type = type;
        osc.frequency.setValueAtTime(freq, t);
        const { gain, end } = envelope(ctx, t, opts);
        if (endFreq) {
            osc.frequency.exponentialRampToValueAtTime(endFreq, end);
        }
        let node = osc;
        if (lowpass) {
            const lp = filter(ctx, 'lowpass', lowpass);
            node.connect(lp);
            node = lp;
        }
        node.connect(gain);
        gain.connect(out);
        osc.start(t);
        osc.stop(end + 0.02);
    }

    // White noise, generated once per audio context
    const noiseBuffers = new WeakMap();
    function noiseBuffer(ctx) {
        let buffer = noiseBuffers.get(ctx);
        if (!buffer) {
            buffer = ctx.createBuffer(1, ctx.sampleRate * 2, ctx.sampleRate);
            const data = buffer.getChannelData(0);
            for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
            noiseBuffers.set(ctx, buffer);
        }
        return buffer;
    }

    function noise(ctx, out, t, opts) {
        const { type = 'bandpass', freq = 2000, q = 1 } = opts;
        const src = ctx.createBufferSource();
        src.buffer = noiseBuffer(ctx);
        src.loop = true;
        const shaped = filter(ctx, type, freq, q);
        const { gain, end } = envelope(ctx, t, opts);
        src.connect(shaped);
        shaped.connect(gain);
        gain.connect(out);
        src.start(t, Math.random() * 1.5);
        src.stop(end + 0.02);
        return gain;
    }

    // FM synthesis: a sine carrier whose pitch is wobbled by a second sine. A non-integer
    // ratio gives inharmonic, metallic tones (digital, not musical). The modulation
    // fades as the note decays, so each blip starts bright and turns pure.
    function fm(ctx, out, t, opts) {
        const { freq, ratio, index, endFreq, lowpass } = opts;
        const { gain, end } = envelope(ctx, t, opts);
        const carrier = ctx.createOscillator();
        const modulator = ctx.createOscillator();
        const depth = ctx.createGain();
        carrier.type = 'sine';
        modulator.type = 'sine';
        carrier.frequency.setValueAtTime(freq, t);
        modulator.frequency.setValueAtTime(freq * ratio, t);
        if (endFreq) {
            carrier.frequency.exponentialRampToValueAtTime(endFreq, end);
            modulator.frequency.exponentialRampToValueAtTime(endFreq * ratio, end);
        }
        depth.gain.setValueAtTime(index * freq * ratio, t);
        depth.gain.exponentialRampToValueAtTime(Math.max(1, index * freq * ratio * 0.04), end);
        modulator.connect(depth);
        depth.connect(carrier.frequency);
        let node = carrier;
        if (lowpass) {
            const lp = filter(ctx, 'lowpass', lowpass);
            node.connect(lp);
            node = lp;
        }
        node.connect(gain);
        gain.connect(out);
        for (const osc of [carrier, modulator]) {
            osc.start(t);
            osc.stop(end + 0.02);
        }
    }

    // A detuned sawtooth pair through a resonant filter that sweeps upward: a synth "wah"
    // pulse with a sub-octave under it
    function sweptPulse(ctx, out, t, { freq, from, to, q = 7, peak, attack = 0.008, hold, release }) {
        const { gain, end } = envelope(ctx, t, { peak, attack, hold, release });
        const sweep = filter(ctx, 'bandpass', from, q);
        sweep.frequency.setValueAtTime(from, t);
        sweep.frequency.exponentialRampToValueAtTime(to, end);
        for (const f of [freq, freq * 1.019]) {
            const osc = ctx.createOscillator();
            osc.type = 'sawtooth';
            osc.frequency.value = f;
            osc.connect(sweep);
            osc.start(t);
            osc.stop(end + 0.02);
        }
        sweep.connect(gain);
        gain.connect(out);
        tone(ctx, out, t, { type: 'sine', freq: freq / 2, peak: peak * 0.5, attack, hold, release });
    }

    const SOUNDS = {
        // A keyboard key: a short filtered click with a little body under it
        key(ctx, out, t, opts = {}) {
            const heavy = opts.heavy === true; // Enter
            // A fairly narrow band, so the click stays low instead of hissing
            noise(ctx, out, t, { type: 'bandpass', freq: heavy ? rand(800, 1000) : rand(1050, 1450), q: 3, peak: heavy ? 0.85 : 0.65, attack: 0.001, release: heavy ? 0.05 : 0.032 });
            tone(ctx, out, t, { type: 'triangle', freq: heavy ? rand(65, 78) : rand(82, 100), endFreq: 45, peak: heavy ? 0.45 : 0.26, attack: 0.002, release: heavy ? 0.09 : 0.045 });
        },

        // Printing text: a faint, slightly varied blip per character
        tick(ctx, out, t) {
            tone(ctx, out, t, { type: 'square', freq: rand(1150, 1450), peak: 0.04, attack: 0.001, release: 0.018, lowpass: 2400 });
        },

        // A metallic data blip that drops slightly in pitch
        beep(ctx, out, t) {
            fm(ctx, out, t, { freq: 1180, endFreq: 1040, ratio: 3.53, index: 1.4, peak: 0.22, attack: 0.002, hold: 0.04, release: 0.11, lowpass: 6000 });
        },

        // A short, glassy ping
        high(ctx, out, t) {
            fm(ctx, out, t, { freq: 2350, ratio: 2.76, index: 0.9, peak: 0.16, attack: 0.001, hold: 0.02, release: 0.08, lowpass: 7000 });
        },

        // A dark, gritty drone pulse
        low(ctx, out, t) {
            fm(ctx, out, t, { freq: 140, endFreq: 128, ratio: 1.41, index: 3.2, peak: 0.32, attack: 0.006, hold: 0.14, release: 0.16, lowpass: 1800 });
        },

        // A warning: three synth pulses that each sweep open, over a low growl
        alert(ctx, out, t) {
            for (let i = 0; i < 3; i++) {
                sweptPulse(ctx, out, t + i * 0.26, { freq: 92.5, from: 380, to: 2600, q: 7, peak: 0.42, hold: 0.13, release: 0.06 });
            }
        },

        // Access denied: an unpitched electrical buzz. Noise chopped into rapid bursts
        // (a "brrrt") while its filter sinks, with a short deep thump underneath.
        error(ctx, out, t) {
            const duration = 0.34;
            const src = ctx.createBufferSource();
            src.buffer = noiseBuffer(ctx);
            src.loop = true;
            const band = filter(ctx, 'bandpass', 1800, 4);
            band.frequency.setValueAtTime(1800, t);
            band.frequency.exponentialRampToValueAtTime(280, t + duration);
            // Chop: on for 15 ms, off for 11 ms, fading out over the last few bursts
            const gate = ctx.createGain();
            gate.gain.setValueAtTime(0, t);
            for (let at = t, i = 0; at < t + duration; at += 0.026, i++) {
                const fade = Math.min(1, (t + duration - at) / 0.1);
                gate.gain.setValueAtTime(0.9 * fade, at);
                gate.gain.setValueAtTime(0, at + 0.015);
            }
            src.connect(band);
            band.connect(gate);
            gate.connect(out);
            src.start(t, Math.random() * 1.5);
            src.stop(t + duration + 0.05);

            noise(ctx, out, t, { type: 'highpass', freq: 3000, peak: 0.12, attack: 0.001, release: 0.04 });
            tone(ctx, out, t, { type: 'sine', freq: 62, endFreq: 38, peak: 0.45, attack: 0.002, release: 0.2 });
        },

        // Access granted: a filtered whoosh opening up, then two quick handshake blips
        success(ctx, out, t) {
            noise(ctx, out, t, { type: 'bandpass', freq: 500, q: 3, peak: 0.12, attack: 0.08, hold: 0.04, release: 0.1 });
            sweptPulse(ctx, out, t, { freq: 110, from: 300, to: 3200, q: 5, peak: 0.16, attack: 0.03, hold: 0.08, release: 0.08 });
            fm(ctx, out, t + 0.14, { freq: 1046, ratio: 3.53, index: 1.0, peak: 0.16, attack: 0.001, hold: 0.025, release: 0.06, lowpass: 6000 });
            fm(ctx, out, t + 0.21, { freq: 1568, ratio: 3.53, index: 1.0, peak: 0.16, attack: 0.001, hold: 0.04, release: 0.1, lowpass: 6000 });
        },

        // Signal interference: crackling noise that cuts in and out
        static(ctx, out, t, opts = {}) {
            const duration = Math.min(3, Math.max(0.1, Number(opts.duration) || 0.4));
            const gain = noise(ctx, out, t, { type: 'highpass', freq: 900, q: 0.5, peak: 0.28, attack: 0.005, hold: duration - 0.05, release: 0.04 });
            // Chop it into irregular bursts
            let at = t + 0.01;
            while (at < t + duration - 0.05) {
                gain.gain.setValueAtTime(rand(0.02, 0.32), at);
                at += rand(0.015, 0.06);
            }
        },

        // An old monitor switching on: a thump, a crackle, then the high whine and hum
        power(ctx, out, t) {
            tone(ctx, out, t, { type: 'sine', freq: 70, endFreq: 35, peak: 0.6, attack: 0.003, release: 0.35 });
            noise(ctx, out, t + 0.02, { type: 'bandpass', freq: 1400, q: 0.8, peak: 0.18, attack: 0.01, hold: 0.08, release: 0.25 });
            tone(ctx, out, t + 0.05, { type: 'sine', freq: 7800, peak: 0.012, attack: 0.4, hold: 0.5, release: 0.8 });
            tone(ctx, out, t + 0.05, { type: 'sine', freq: 60, peak: 0.08, attack: 0.3, hold: 0.6, release: 0.7 });
            tone(ctx, out, t + 0.05, { type: 'sine', freq: 120, peak: 0.04, attack: 0.3, hold: 0.6, release: 0.7 });
        },

        // A heavy impact (used by SHAKE)
        thud(ctx, out, t) {
            tone(ctx, out, t, { type: 'sine', freq: 95, endFreq: 38, peak: 0.7, attack: 0.003, release: 0.4 });
            noise(ctx, out, t, { type: 'lowpass', freq: 500, peak: 0.35, attack: 0.002, release: 0.12 });
        },
    };

    global.TermiSound = {
        names: Object.keys(SOUNDS),
        has(name) {
            return Object.prototype.hasOwnProperty.call(SOUNDS, name);
        },
        play(name, ctx, out, t, opts) {
            if (this.has(name)) SOUNDS[name](ctx, out, t, opts);
        },
    };
})(window);
