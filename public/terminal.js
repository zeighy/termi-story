document.addEventListener('DOMContentLoaded', () => {
    const terminalContainer = document.getElementById('terminal-container');
    const terminalOutput = document.getElementById('terminal-output');
    const terminalInput = document.getElementById('terminal-input');
    const promptLabel = document.getElementById('prompt-label');
    const passwordDots = document.getElementById('password-dots');
    const inputMirror = document.getElementById('input-mirror');
    const inputMirrorAfter = document.getElementById('input-mirror-after');

    const terminalInputLine = document.getElementById('terminal-input-line');

    const settingsRoot = document.getElementById('settings');
    const settingsToggle = document.getElementById('settings-toggle');
    const settingsPanel = document.getElementById('settings-panel');

    let terminalState = 'login-username';
    let tempUsername = '';
    const commandHistory = [];
    let historyIndex = -1;

    let autocompleteMatches = [];
    let autocompleteIndex = 0;
    let lastPartial = '';

    let isExecuting = false;

    // ============================
    // PLAYER SETTINGS (kept in this browser only)
    // ============================

    // Animation pacing for each text speed. 'instant' prints everything at once,
    // but script WAITs still pause (the player can skip those too).
    const SPEED_PROFILES = {
        slow:    { charMs: 45, chunkChars: 40,  chunkMs: 50, imageRowsPerSec: 15 },
        normal:  { charMs: 25, chunkChars: 100, chunkMs: 50, imageRowsPerSec: 30 },
        fast:    { charMs: 8,  chunkChars: 400, chunkMs: 30, imageRowsPerSec: 120 },
        instant: { instant: true },
    };
    // Must match the levels in index.php and Admin::CRT_LEVELS
    const FX_LEVELS = ['off', 'subtle', 'full'];
    // Master volume for each sound level. Must match index.php and Admin::SOUND_LEVELS
    const SOUND_VOLUMES = { off: 0, low: 0.35, high: 0.8 };

    // Storage can be missing or throw (private windows, blocked site data), so every
    // access is guarded and the terminal works the same without it.
    function readSettings() {
        try {
            const parsed = JSON.parse(localStorage.getItem(SETTINGS_STORAGE_KEY) || '{}');
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (err) {
            return {};
        }
    }

    function saveSetting(key, value) {
        const current = readSettings();
        current[key] = value;
        try {
            localStorage.setItem(SETTINGS_STORAGE_KEY, JSON.stringify(current));
        } catch (err) {
            // Not saved; the choice still applies until the page is closed.
        }
    }

    const savedSettings = readSettings();
    let speedName = SPEED_PROFILES[savedSettings.speed] ? savedSettings.speed : 'normal';
    // index.php already applied the saved level (or the author's default) before first paint
    let fxLevel = FX_LEVELS.includes(document.documentElement.dataset.fx)
        ? document.documentElement.dataset.fx
        : authorFxLevel;

    let soundLevel = Object.hasOwn(SOUND_VOLUMES, savedSettings.sound) ? savedSettings.sound : authorSoundLevel;

    function speed() {
        return SPEED_PROFILES[speedName];
    }

    function checkRadio(name, value) {
        const radio = settingsPanel.querySelector(`input[name="${name}"][value="${value}"]`);
        if (radio) radio.checked = true;
    }
    checkRadio('speed', speedName);
    checkRadio('fx', fxLevel);
    checkRadio('sound', soundLevel);

    settingsPanel.addEventListener('change', (e) => {
        const { name, value } = e.target;
        if (name === 'speed' && SPEED_PROFILES[value]) {
            speedName = value;
            saveSetting('speed', value);
        } else if (name === 'fx' && FX_LEVELS.includes(value)) {
            fxLevel = value;
            document.documentElement.dataset.fx = value;
            saveSetting('fx', value);
        } else if (name === 'sound' && Object.hasOwn(SOUND_VOLUMES, value)) {
            soundLevel = value;
            saveSetting('sound', value);
            playSound('beep'); // a sample at the new volume
        }
    });

    function isMenuOpen() {
        return !settingsPanel.hidden;
    }

    function setMenuOpen(open) {
        settingsPanel.hidden = !open;
        settingsToggle.setAttribute('aria-expanded', String(open));
    }

    settingsToggle.addEventListener('click', () => {
        setMenuOpen(!isMenuOpen());
    });

    // Escape closes the menu and puts focus back on its button
    settingsRoot.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isMenuOpen()) {
            e.preventDefault();
            setMenuOpen(false);
            settingsToggle.focus();
        }
    });

    // Clicking or tapping anywhere else closes it
    document.addEventListener('pointerdown', (e) => {
        if (isMenuOpen() && !settingsRoot.contains(e.target)) {
            setMenuOpen(false);
        }
    });

    // Return to the prompt, unless the player is using the settings menu
    function focusTerminal() {
        if (!isMenuOpen() && !settingsRoot.contains(document.activeElement)) {
            terminalInput.focus();
        }
    }

    // ============================
    // SOUND (synthesized by sound.js)
    // ============================

    // Browsers only allow audio after the player interacts with the page, so the audio
    // context is created on the first key press or tap, and only if sound is on.
    let audio = null;
    let playerHasInteracted = false;

    function getAudio() {
        if (!audio) {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return null;
            try {
                const ctx = new AudioCtx();
                const master = ctx.createGain();
                // Keeps overlapping sounds from clipping
                const limiter = ctx.createDynamicsCompressor();
                master.connect(limiter);
                limiter.connect(ctx.destination);
                audio = { ctx, master };
            } catch (err) {
                return null;
            }
        }
        if (audio.ctx.state === 'suspended') {
            audio.ctx.resume().catch(() => {});
        }
        return audio;
    }

    function playSound(name, opts) {
        if (soundLevel === 'off' || !playerHasInteracted || !window.TermiSound || !TermiSound.has(name)) return;
        const out = getAudio();
        if (!out) return;
        out.master.gain.value = SOUND_VOLUMES[soundLevel];
        TermiSound.play(name, out.ctx, out.master, out.ctx.currentTime + 0.005, opts);
    }

    for (const type of ['keydown', 'pointerdown']) {
        document.addEventListener(type, () => {
            playerHasInteracted = true;
            if (soundLevel !== 'off') getAudio();
        }, { capture: true });
    }

    // Printing ticks are throttled so fast text purrs instead of rattling
    let lastTickAt = 0;
    function printTick() {
        const now = performance.now();
        if (now - lastTickAt >= 55) {
            lastTickAt = now;
            playSound('tick');
        }
    }

    // ============================
    // SCRIPT EFFECTS (GLITCH, SHAKE)
    // ============================

    const reducedMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };

    // Movement only at Full, and never for players who asked for reduced motion
    function motionAllowed() {
        return fxLevel === 'full' && !reducedMotion.matches;
    }

    function jitter(max) {
        return (Math.random() * 2 - 1) * max;
    }

    let glitchTimer = null;
    function glitch(ms) {
        playSound('static', { duration: ms / 1000 });
        if (fxLevel === 'off') return;

        // Colour fringing at Subtle and Full (it doesn't move)
        document.body.classList.add('fx-glitch');
        clearTimeout(glitchTimer);
        glitchTimer = setTimeout(() => document.body.classList.remove('fx-glitch'), ms);

        // At Full, the picture also jumps, skews, tears and shifts colour
        if (motionAllowed() && terminalOutput.animate) {
            const frames = [];
            const count = Math.max(4, Math.round(ms / 45));
            for (let i = 0; i < count; i++) {
                const tear = Math.random() < 0.25;
                frames.push({
                    transform: `translate(${jitter(8).toFixed(1)}px, ${jitter(3).toFixed(1)}px) skewX(${jitter(6).toFixed(1)}deg)`,
                    filter: Math.random() < 0.35 ? `hue-rotate(${Math.round(jitter(120))}deg) contrast(1.6)` : 'none',
                    clipPath: tear ? `inset(${Math.round(Math.random() * 50)}% 0 ${Math.round(Math.random() * 30)}% 0)` : 'inset(0 0 0 0)',
                    easing: 'steps(1, end)',
                });
            }
            frames.push({ transform: 'none', filter: 'none', clipPath: 'inset(0 0 0 0)' });
            terminalOutput.animate(frames, { duration: ms });
        }
    }

    function shake(ms) {
        playSound('thud');
        if (!motionAllowed() || !terminalContainer.animate) return;
        const frames = [];
        const count = Math.max(4, Math.round(ms / 40));
        for (let i = 0; i < count; i++) {
            const strength = 12 * (1 - i / count); // dies away
            frames.push({ transform: `translate(${jitter(strength).toFixed(1)}px, ${jitter(strength * 0.6).toFixed(1)}px)` });
        }
        frames.push({ transform: 'none' });
        terminalContainer.animate(frames, { duration: ms, easing: 'linear' });
    }

    // ============================
    // SKIPPING ANIMATIONS
    // ============================

    // One press advances one step: it finishes the text being printed (up to the next
    // WAIT), or, during a WAIT, ends the pause early.
    const playback = { skip: false, cancelPause: null };

    function requestSkip() {
        if (playback.cancelPause) {
            playback.cancelPause();
        } else {
            playback.skip = true;
        }
    }

    function isRushing() {
        return playback.skip || speed().instant === true;
    }

    // Mark the terminal busy or idle; either way, a skip from the last command is forgotten
    function setBusy(busy) {
        isExecuting = busy;
        playback.skip = false;
        playback.cancelPause = null;
    }

    function delay(ms) {
        return new Promise(r => setTimeout(r, ms));
    }

    // A pause the player can cut short
    function pause(ms) {
        return new Promise(resolve => {
            const done = () => {
                clearTimeout(timer);
                if (playback.cancelPause === done) playback.cancelPause = null;
                resolve();
            };
            const timer = setTimeout(done, ms);
            playback.cancelPause = done;
        });
    }

    const MODIFIER_KEYS = ['Tab', 'Shift', 'Control', 'Alt', 'Meta', 'CapsLock', 'Escape'];

    // Capture phase, so the Enter that starts a command doesn't also skip its output
    document.addEventListener('keydown', (e) => {
        if (!isExecuting || e.repeat || settingsRoot.contains(e.target)) return;
        if (MODIFIER_KEYS.includes(e.key) || e.ctrlKey || e.metaKey || e.altKey) return;
        requestSkip();
    }, { capture: true });

    // Add boot sequence effect. Remove the class once it has played (1.5s in the CSS),
    // so changing the effects level later doesn't replay it.
    terminalContainer.classList.add('boot-sequence');
    setTimeout(() => terminalContainer.classList.remove('boot-sequence'), 1600);

    // Run text-based boot animation
    async function runBootSequence() {
        setBusy(true);

        // Wait for the CSS fade-in to start
        await delay(500);

        const bootText = "Establishing connection to terminal...\n%%WAIT:500\nAuthenticating connection...\n%%WAIT:800\nConnection secure.\n%%WAIT:300\nInitializing system...\n%%WAIT:600\nDone.\n%%WAIT:200\n";

        await typewriterEffect(bootText);

        const motdDiv = document.createElement('div');
        motdDiv.className = 'motd';
        motdDiv.innerHTML = escapeHtml(loginGreeting).replace(/\n/g, '<br>');
        terminalOutput.appendChild(motdDiv);

        terminalInputLine.style.display = 'flex';
        focusTerminal();
        setBusy(false);
        scrollToBottom();
    }

    function newOutputLine() {
        const outputLine = document.createElement('div');
        outputLine.classList.add('output-line');
        terminalOutput.appendChild(outputLine);
        return outputLine;
    }

    // A marker line from the scripting engine: %%NAME%% or %%NAME:arg%%. (The boot text
    // writes %%WAIT:ms without the closing %%.) Anything else is printed as text.
    const MARKER = /^%%([A-Z]+)(?::([a-z0-9]+))?(?:%%)?$/;
    // TYPE scales the player's chosen speed rather than replacing it
    const PACE_FACTORS = { slow: 2.5, normal: 1, fast: 0.3 };
    const TEXT_STYLES = ['dim', 'bright', 'warn', 'alert']; // plus 'normal'

    // Script output: typed line by line, acting on markers as they come up
    async function typewriterEffect(text) {
        const state = { line: null, target: null, style: 'normal', pace: 1 };

        // Text goes into a styled span while a STYLE is active
        function startTarget() {
            if (!state.line) state.line = newOutputLine();
            if (state.style === 'normal') {
                state.target = state.line;
            } else {
                state.target = document.createElement('span');
                state.target.className = `text-${state.style}`;
                state.line.appendChild(state.target);
            }
        }
        startTarget();

        for (const line of text.split('\n')) {
            const marker = MARKER.exec(line);
            if (!marker) {
                await typeLine(state.target, line, state.pace);
                continue;
            }
            const [, name, arg = ''] = marker;
            // While the player is skipping, one-off effects are dropped; lasting changes still apply
            const skipping = playback.skip;
            switch (name) {
                case 'WAIT': {
                    // A skip only reaches the next WAIT; the text after it types normally again
                    playback.skip = false;
                    const ms = parseInt(arg, 10);
                    await pause(Number.isNaN(ms) ? 1000 : ms);
                    break;
                }
                case 'CLEAR':
                    terminalOutput.innerHTML = '';
                    state.line = null;
                    startTarget();
                    break;
                case 'GLITCH':
                    if (!skipping) glitch(parseInt(arg, 10) || 400);
                    break;
                case 'SHAKE':
                    if (!skipping) shake(parseInt(arg, 10) || 500);
                    break;
                case 'BEEP':
                    if (!skipping) playSound(arg || 'beep');
                    break;
                case 'TYPE':
                    state.pace = PACE_FACTORS[arg] ?? 1;
                    break;
                case 'STYLE':
                    state.style = TEXT_STYLES.includes(arg) ? arg : 'normal';
                    startTarget();
                    break;
                // Unknown markers are ignored
            }
        }
    }

    // Appends to one text node rather than re-parsing innerHTML for every character
    async function typeLine(element, text, pace = 1) {
        const node = document.createTextNode('');
        element.appendChild(node);

        // Split by code point so emoji and other astral characters never appear half-typed
        const chars = Array.from(text);
        for (let i = 0; i < chars.length; i++) {
            if (isRushing()) {
                node.appendData(chars.slice(i).join(''));
                break;
            }
            node.appendData(chars[i]);
            if (chars[i].trim() !== '') printTick();
            scrollToBottom();
            await delay(speed().charMs * pace);
        }
        element.appendChild(document.createElement('br'));
        scrollToBottom();
    }

    function scrollToBottom() {
        terminalOutput.scrollTop = terminalOutput.scrollHeight;
    }

    function escapeHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Start it
    runBootSequence();

    // Mobile Virtual Keyboard Handling
    if (window.visualViewport) {
        const handleViewportChange = () => {
            // Adjust container height to match the visual viewport (visible area above keyboard)
            terminalContainer.style.height = window.visualViewport.height + 'px';

            // Scroll to the bottom to ensure input and latest output are visible
            setTimeout(() => {
                scrollToBottom();
                terminalInput.scrollIntoView({ behavior: "smooth", block: "end" });
            }, 100);
        };

        window.visualViewport.addEventListener('resize', handleViewportChange);
        window.visualViewport.addEventListener('scroll', () => {
            // Ensure we stick to the bottom if the viewport scrolls
            setTimeout(scrollToBottom, 50);
        });
    }

    terminalContainer.addEventListener('click', () => {
        if (isExecuting) {
            // A tap skips ahead, unless the player was selecting text to copy
            const selection = window.getSelection();
            if (!selection || selection.isCollapsed) {
                requestSkip();
            }
        }
        terminalInput.focus();
    });

    terminalInput.addEventListener('input', async () => {
        autocompleteMatches = [];
        autocompleteIndex = 0;

        // Triple comma detection for autocomplete
        if (terminalInput.value.endsWith(',,,')) {
            terminalInput.value = terminalInput.value.slice(0, -3);
            await handleAutocomplete();
            return;
        }

        if (terminalState === 'login-password') {
            passwordDots.textContent = '*'.repeat(terminalInput.value.length);
            inputMirror.textContent = ''; // Clear mirror so dots show
            inputMirrorAfter.textContent = '';
        } else {
            inputMirror.textContent = terminalInput.value.substring(0, terminalInput.selectionStart);
            inputMirrorAfter.textContent = terminalInput.value.substring(terminalInput.selectionStart);
        }
        updateCursorPosition();
    });

    // Handle cursor movement via click or keyboard
    terminalInput.addEventListener('keyup', updateCursorPosition);
    terminalInput.addEventListener('click', updateCursorPosition);

    function updateCursorPosition() {
        if (terminalState === 'login-password') {
            const cursorBlock = document.querySelector('.cursor-block');
            cursorBlock.style.marginLeft = (passwordDots.offsetWidth + 1) + 'px';
            cursorBlock.style.position = 'absolute';
            cursorBlock.style.left = '0';
        } else {
            const cursorBlock = document.querySelector('.cursor-block');
            cursorBlock.style.marginLeft = '1px';
            cursorBlock.style.position = 'relative';
            cursorBlock.style.left = 'auto';
            inputMirror.textContent = terminalInput.value.substring(0, terminalInput.selectionStart);
            inputMirrorAfter.textContent = terminalInput.value.substring(terminalInput.selectionStart);
        }
    }

    terminalInput.addEventListener('keydown', async (e) => {
        if (isExecuting) {
            // The document-level listener has already treated this key as a skip
            e.preventDefault();
            return;
        }

        if (e.key === 'Enter') {
            playSound('key', { heavy: true });
        } else if ((e.key.length === 1 || e.key === 'Backspace' || e.key === 'Delete') && !e.ctrlKey && !e.metaKey) {
            playSound('key');
        }

        if (e.key === 'Tab') {
            e.preventDefault();
            await handleAutocomplete();
        } else if (e.key === 'Enter') {
            autocompleteMatches = [];
            const command = terminalInput.value.trim();
            const commandParts = command.split(' ');
            const baseCommand = commandParts[0].toLowerCase();

            terminalInput.value = '';

            inputMirror.textContent = ''; // Clear mirror on enter
            inputMirrorAfter.textContent = '';

            if (terminalState === 'login-username') {
                setBusy(true);
                tempUsername = command;
                displayLine(`Username: ${tempUsername}`);
                promptLabel.textContent = 'Password:';
                terminalInput.classList.add('password-mask');
                passwordDots.style.left = '0px'; // now inside wrapper, so 0
                terminalState = 'login-password';
                setBusy(false);

            } else if (terminalState === 'login-password') {
                setBusy(true);
                const password = command;
                displayLine('Password: ********');
                terminalInput.classList.remove('password-mask');
                passwordDots.textContent = '';
                await handleLogin(tempUsername, password);
                setBusy(false);

            } else if (terminalState === 'active') {
                if (command) {
                    setBusy(true);
                    terminalInput.disabled = true;

                    displayCommand(command);
                    commandHistory.unshift(command);
                    historyIndex = -1;

                    if (baseCommand === 'clear') {
                        terminalOutput.innerHTML = '';
                    } else if (baseCommand === 'logout') {
                        // End the server-side session before returning to the login prompt
                        try {
                            await fetch('api.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'logout' })
                            });
                        } catch (err) {
                            console.error('Logout request failed:', err);
                        }
                        location.reload();
                        return;
                    } else {
                        await processCommand(command);
                        if (baseCommand === 'reset') {
                            await simulateCommand('run init.app');
                        }
                    }

                    setBusy(false);
                    terminalInput.disabled = false;
                    focusTerminal();
                }
            }
        } else if (e.key === 'ArrowUp' && terminalState === 'active') {
            e.preventDefault();
            if (historyIndex < commandHistory.length - 1) {
                historyIndex++;
                terminalInput.value = commandHistory[historyIndex];
                inputMirror.textContent = terminalInput.value;
                inputMirrorAfter.textContent = '';
                updateCursorPosition();
            }
        } else if (e.key === 'ArrowDown' && terminalState === 'active') {
            e.preventDefault();
            if (historyIndex > 0) {
                historyIndex--;
                terminalInput.value = commandHistory[historyIndex];
                inputMirror.textContent = terminalInput.value;
                inputMirrorAfter.textContent = '';
                updateCursorPosition();
            } else {
                historyIndex = -1;
                terminalInput.value = '';
                inputMirror.textContent = '';
                inputMirrorAfter.textContent = '';
                updateCursorPosition();
            }
        }
    });

    async function handleAutocomplete() {
        const fullLine = terminalInput.value;
        const parts = fullLine.split(' ');
        const partial = parts[parts.length - 1] || '';

        if (autocompleteMatches.length === 0 || partial !== lastPartial) {
            lastPartial = partial;
            const data = await postJson({ action: 'autocomplete', partial: partial, full_line: fullLine });
            autocompleteMatches = (data && Array.isArray(data.matches)) ? data.matches : [];
            autocompleteIndex = 0;
        }

        if (autocompleteMatches.length > 0) {
            const match = autocompleteMatches[autocompleteIndex];
            parts[parts.length - 1] = match;
            terminalInput.value = parts.join(' ');
            inputMirror.textContent = terminalInput.value;
            inputMirrorAfter.textContent = '';
            updateCursorPosition();
            autocompleteIndex = (autocompleteIndex + 1) % autocompleteMatches.length;
        }
    }

    // POST to the API and return the parsed JSON, or null if the server or network failed
    async function postJson(body) {
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            return await response.json();
        } catch (err) {
            console.error('API request failed:', err);
            return null;
        }
    }

    async function handleLogin(username, password) {
        const data = await postJson({ action: 'login', username, password });
        if (!data) {
            playSound('error');
            displayText('Connection error. Please try again.');
            promptLabel.textContent = 'Username:';
            terminalState = 'login-username';
            return;
        }

        if (data.success) {
            playSound('power');
            displayText(data.output + "\n\n" + motd);
            terminalState = 'active';
            await processCommand('');

            if (data.run_init) {
                await simulateCommand('run init.app');
            }
        } else {
            playSound('error');
            displayText(data.output);
            promptLabel.textContent = 'Username:';
            terminalState = 'login-username';
        }
    }

    async function simulateCommand(command) {
        setBusy(true);
        terminalInput.disabled = true;

        displayCommand(command);
        await processCommand(command);

        setBusy(false);
        terminalInput.disabled = false;
        focusTerminal();
    }

    function displayLine(text) {
        const line = document.createElement('div');
        line.textContent = text;
        terminalOutput.appendChild(line);
        scrollToBottom();
    }

    function displayCommand(command) {
        const commandLine = document.createElement('div');
        commandLine.innerHTML = `<span class="prompt-path-display">${promptLabel.innerHTML}</span><span class="output-line">${escapeHtml(command)}</span>`;
        terminalOutput.appendChild(commandLine);
        scrollToBottom();
    }

    // Server output is plain text unless the response says format: 'html' (only ls does).
    function displayText(text) {
        displayHtml(escapeHtml(text));
    }

    // Only for trusted markup: the server's escaped ls listing, or HTML built here from escaped parts.
    function displayHtml(html) {
        const outputLine = document.createElement('div');
        outputLine.classList.add('output-line'); // white-space: pre-wrap, like animated output
        outputLine.innerHTML = String(html).replace(/\n/g, '<br>');
        terminalOutput.appendChild(outputLine);
        scrollToBottom();
    }

    async function processCommand(command) {
        const data = await postJson({ command: command });
        if (!data) {
            playSound('error');
            displayHtml('<span style="color: red;">Connection error. Please try again.</span>');
            return;
        }

        // The server names a sound for some responses (e.g. 'error' for Access Denied)
        if (typeof data.sound === 'string') {
            playSound(data.sound);
        }

        if (data.output) {
            if (data.animation === 'typewriter') {
                await typewriterEffect(data.output);
            } else if (data.animation === 'typewriterChunk') {
                await typewriterChunkEffect(data.output);
            } else if (data.animation === 'imageRender') {
                await renderImageProgressive(data.output);
            } else if (data.format === 'html') {
                displayHtml(data.output);
            } else {
                displayText(data.output);
            }
        }
        if (data.path && data.username) {
            const userColor = getComputedStyle(document.documentElement).getPropertyValue('--prompt-user-color').trim();
            const pathColor = getComputedStyle(document.documentElement).getPropertyValue('--prompt-path-color').trim();
            promptLabel.innerHTML = `<span style="color: ${escapeHtml(userColor)};">${escapeHtml(data.username)}</span>@term:<span style="color: ${escapeHtml(pathColor)};">${escapeHtml(data.path)}</span>$ `;
        }
        if (data.error) {
            displayHtml(`<span style="color: red;">${escapeHtml(data.error)}</span>`);
        }
        scrollToBottom();
    }

    // File contents: printed a block at a time. The output line is white-space: pre-wrap,
    // so newlines in the text node render as line breaks.
    async function typewriterChunkEffect(text) {
        const node = document.createTextNode('');
        newOutputLine().appendChild(node);

        let i = 0;
        while (i < text.length) {
            if (isRushing()) {
                node.appendData(text.slice(i));
                break;
            }
            const { chunkChars, chunkMs } = speed();
            let end = Math.min(text.length, i + chunkChars);
            // Don't split a surrogate pair (emoji etc.) across two chunks
            const lastCode = text.charCodeAt(end - 1);
            if (end < text.length && lastCode >= 0xD800 && lastCode <= 0xDBFF) {
                end++;
            }
            node.appendData(text.slice(i, end));
            i = end;
            scrollToBottom();
            await delay(chunkMs);
        }
        scrollToBottom();
    }

    async function renderImageProgressive(dataString) {
        const parts = dataString.split(';');
        if (parts.length < 2) {
            displayText("Error parsing image data.");
            return;
        }

        const width = parseInt(parts[0], 10);
        const pixelData = parts[1];

        // Reject absurd sizes (e.g. from a hand-edited import) that would hang the browser
        if (isNaN(width) || width <= 0 || width > 4096 || pixelData.length / width > 4096) {
            displayText("Invalid image dimensions.");
            return;
        }

        const height = Math.ceil(pixelData.length / width);

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        canvas.style.maxWidth = '100%';
        canvas.style.display = 'block';
        canvas.style.marginBottom = '10px';
        terminalOutput.appendChild(canvas);

        const ctx = canvas.getContext('2d');

        const bodyStyle = getComputedStyle(document.body);
        const bgColor = bodyStyle.getPropertyValue('--background-color').trim() || '#1a1a1a';
        const textColor = bodyStyle.getPropertyValue('--text-color').trim() || '#00ff00';

        ctx.fillStyle = bgColor;
        ctx.fillRect(0, 0, width, height);
        ctx.fillStyle = textColor;

        // Draw a row as runs of lit pixels: one rectangle per run, not per pixel
        function drawRow(y) {
            const rowStart = y * width;
            const rowEnd = Math.min(rowStart + width, pixelData.length);
            let runStart = -1;
            for (let idx = rowStart; idx <= rowEnd; idx++) {
                const lit = idx < rowEnd && pixelData[idx] === '1';
                if (lit && runStart < 0) {
                    runStart = idx;
                } else if (!lit && runStart >= 0) {
                    ctx.fillRect(runStart - rowStart, y, idx - runStart, 1);
                    runStart = -1;
                }
            }
        }

        let y = 0;
        while (y < height) {
            if (isRushing()) {
                while (y < height) drawRow(y++);
                break;
            }
            const rowsPerSec = speed().imageRowsPerSec;
            // Redraw at most about once a frame, drawing several rows per tick at high speeds
            const tickMs = Math.max(16, 1000 / rowsPerSec);
            const rowsPerTick = Math.max(1, Math.round(rowsPerSec * tickMs / 1000));
            for (let r = 0; r < rowsPerTick && y < height; r++) {
                drawRow(y++);
            }
            scrollToBottom();
            await delay(tickMs);
        }
        scrollToBottom();
    }

});
