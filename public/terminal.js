document.addEventListener('DOMContentLoaded', () => {
    const terminalContainer = document.getElementById('terminal-container');
    const terminalOutput = document.getElementById('terminal-output');
    const terminalInput = document.getElementById('terminal-input');
    const promptLabel = document.getElementById('prompt-label');
    const passwordDots = document.getElementById('password-dots');
    const inputMirror = document.getElementById('input-mirror');
    const inputMirrorAfter = document.getElementById('input-mirror-after');

    const terminalInputLine = document.getElementById('terminal-input-line');

    let terminalState = 'login-username';
    let tempUsername = '';
    const commandHistory = [];
    let historyIndex = -1;

    let autocompleteMatches = [];
    let autocompleteIndex = 0;
    let lastPartial = '';

    let isExecuting = false;

    // Add boot sequence effect
    terminalContainer.classList.add('boot-sequence');

    // Run text-based boot animation
    async function runBootSequence() {
        isExecuting = true;

        // Use a simple timeout to wait for the CSS fade-in to start
        await new Promise(r => setTimeout(r, 500));

        const bootText = "Establishing connection to terminal...\n%%WAIT:500\nAuthenticating connection...\n%%WAIT:800\nConnection secure.\n%%WAIT:300\nInitializing system...\n%%WAIT:600\nDone.\n%%WAIT:200\n";

        await typewriterEffect(bootText);

        const motdDiv = document.createElement('div');
        motdDiv.className = 'motd';
        motdDiv.innerHTML = escapeHtml(loginGreeting).replace(/\n/g, '<br>');
        terminalOutput.appendChild(motdDiv);

        terminalInputLine.style.display = 'flex';
        terminalInput.focus();
        isExecuting = false;
        scrollToBottom();
    }

    // Hoist functions so they can be called before declaration
    function typewriterEffect(text) {
        return new Promise(resolve => {
            const lines = text.split('\n');
            const outputLine = document.createElement('div');
            outputLine.classList.add('output-line');
            terminalOutput.appendChild(outputLine);

            let lineIndex = 0;

            async function processNextLine() {
                if (lineIndex >= lines.length) {
                    resolve();
                    return;
                }

                const line = lines[lineIndex];
                lineIndex++;

                if (line.startsWith('%%WAIT:')) {
                    const ms = parseInt(line.split(':')[1] || '1000');
                    await new Promise(r => setTimeout(r, ms));
                    await processNextLine();
                } else {
                    await typeLine(outputLine, line);
                    await processNextLine();
                }
            }
            processNextLine();
        });
    }

    function typeLine(element, text) {
        return new Promise(resolve => {
            let i = 0;
            function type() {
                if (i < text.length) {
                    let char = text.charAt(i);
                    element.innerHTML += escapeHtml(char);
                    i++;
                    scrollToBottom();
                    setTimeout(type, 25);
                } else {
                    element.innerHTML += '<br>';
                    resolve();
                }
            }
            type();
        });
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
            e.preventDefault();
            return;
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
                isExecuting = true;
                tempUsername = command;
                displayLine(`Username: ${tempUsername}`);
                promptLabel.textContent = 'Password:';
                terminalInput.classList.add('password-mask');
                passwordDots.style.left = '0px'; // now inside wrapper, so 0
                terminalState = 'login-password';
                isExecuting = false;

            } else if (terminalState === 'login-password') {
                isExecuting = true;
                const password = command;
                displayLine('Password: ********');
                terminalInput.classList.remove('password-mask');
                passwordDots.textContent = '';
                await handleLogin(tempUsername, password);
                isExecuting = false;
            
            } else if (terminalState === 'active') {
                if (command) {
                    isExecuting = true;
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
                    
                    isExecuting = false;
                    terminalInput.disabled = false;
                    terminalInput.focus();
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
            displayText('Connection error. Please try again.');
            promptLabel.textContent = 'Username:';
            terminalState = 'login-username';
            return;
        }

        if (data.success) {
            displayText(data.output + "\n\n" + motd);
            terminalState = 'active';
            await processCommand('');

            if (data.run_init) {
                await simulateCommand('run init.app');
            }
        } else {
            displayText(data.output);
            promptLabel.textContent = 'Username:';
            terminalState = 'login-username';
        }
    }

    async function simulateCommand(command) {
        isExecuting = true;
        terminalInput.disabled = true;

        displayCommand(command);
        await processCommand(command);

        isExecuting = false;
        terminalInput.disabled = false;
        terminalInput.focus();
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
            displayHtml('<span style="color: red;">Connection error. Please try again.</span>');
            return;
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
    
    function typewriterChunkEffect(text) {
        return new Promise(resolve => {
            const outputLine = document.createElement('div');
            outputLine.classList.add('output-line');
            terminalOutput.appendChild(outputLine);
            
            let i = 0;
            const chunkSize = 100;

            function typeChunk() {
                if (i < text.length) {
                    const chunk = text.substring(i, i + chunkSize);
                    outputLine.innerHTML += escapeHtml(chunk).replace(/\n/g, '<br>');
                    i += chunkSize;
                    scrollToBottom();
                    setTimeout(typeChunk, 50);
                } else {
                    resolve();
                }
            }
            typeChunk();
        });
    }
    function renderImageProgressive(dataString) {
        return new Promise(resolve => {
            const parts = dataString.split(';');
            if (parts.length < 2) {
                displayText("Error parsing image data.");
                resolve();
                return;
            }

            const width = parseInt(parts[0], 10);
            const pixelData = parts[1];

            // Reject absurd sizes (e.g. from a hand-edited import) that would hang the browser
            if (isNaN(width) || width <= 0 || width > 4096 || pixelData.length / width > 4096) {
                displayText("Invalid image dimensions.");
                resolve();
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

            let y = 0;
            const rowsPerSecond = 30;
            const msPerRow = 1000 / rowsPerSecond;

            function renderRow() {
                if (y < height) {
                    for (let x = 0; x < width; x++) {
                        const idx = y * width + x;
                        if (idx < pixelData.length) {
                            const pixel = pixelData[idx];
                            if (pixel === '1') {
                                ctx.fillStyle = textColor;
                                ctx.fillRect(x, y, 1, 1);
                            }
                        }
                    }
                    y++;
                    scrollToBottom();
                    setTimeout(renderRow, msPerRow);
                } else {
                    resolve();
                }
            }

            renderRow();
        });
    }

});