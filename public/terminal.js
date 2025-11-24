document.addEventListener('DOMContentLoaded', () => {
    const terminalContainer = document.getElementById('terminal-container');
    const terminalOutput = document.getElementById('terminal-output');
    const terminalInput = document.getElementById('terminal-input');
    const promptLabel = document.getElementById('prompt-label');
    const passwordDots = document.getElementById('password-dots');

    // Mobile Virtual Keyboard Handling
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', () => {
            // When the keyboard opens, the visual viewport shrinks.
            // We scroll to the bottom to keep the input visible.
            setTimeout(() => {
                scrollToBottom();
                terminalInput.scrollIntoView({ behavior: "smooth", block: "end" });
            }, 100);
        });

        window.visualViewport.addEventListener('scroll', () => {
             // Keep input in view if viewport scrolls
             setTimeout(scrollToBottom, 50);
        });
    }

    let terminalState = 'login-username';
    let tempUsername = '';
    const commandHistory = [];
    let historyIndex = -1;

    let autocompleteMatches = [];
    let autocompleteIndex = 0;
    let lastPartial = '';

    let isExecuting = false;

    terminalContainer.addEventListener('click', () => {
        terminalInput.focus();
    });

    terminalInput.addEventListener('input', () => {
        autocompleteMatches = [];
        autocompleteIndex = 0;
        if (terminalState === 'login-password') {
            passwordDots.textContent = '*'.repeat(terminalInput.value.length);
        }
    });

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

            if (terminalState === 'login-username') {
                isExecuting = true;
                tempUsername = command;
                displayLine(`Username: ${tempUsername}`);
                promptLabel.textContent = 'Password:';
                terminalInput.classList.add('password-mask');
                passwordDots.style.left = promptLabel.offsetWidth + 'px';
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
                        location.reload();
                    } else {
                        await processCommand(command);
                        if (baseCommand === 'cd') {
                            await processCommand('');
                        }
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
            }
        } else if (e.key === 'ArrowDown' && terminalState === 'active') {
            e.preventDefault();
            if (historyIndex > 0) {
                historyIndex--;
                terminalInput.value = commandHistory[historyIndex];
            } else {
                historyIndex = -1;
                terminalInput.value = '';
            }
        }
    });

    async function handleAutocomplete() {
        const fullLine = terminalInput.value;
        const parts = fullLine.split(' ');
        const partial = parts[parts.length - 1] || '';

        if (autocompleteMatches.length === 0 || partial !== lastPartial) {
            lastPartial = partial;
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'autocomplete', partial: partial, full_line: fullLine })
            });
            const data = await response.json();
            autocompleteMatches = data.matches || [];
            autocompleteIndex = 0;
        }

        if (autocompleteMatches.length > 0) {
            const match = autocompleteMatches[autocompleteIndex];
            parts[parts.length - 1] = match;
            terminalInput.value = parts.join(' ');
            autocompleteIndex = (autocompleteIndex + 1) % autocompleteMatches.length;
        }
    }

    async function handleLogin(username, password) {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'login', username, password })
        });
        const data = await response.json();
        
        if (data.success) {
            displayOutput(data.output + "\n\n" + motd);
            terminalState = 'active';
            await processCommand('');

            if (data.run_init) {
                await simulateCommand('run init.app');
            }
        } else {
            displayOutput(data.output);
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

    function displayOutput(output) {
        const outputLine = document.createElement('div');
        outputLine.innerHTML = output.replace(/\n/g, '<br>');
        terminalOutput.appendChild(outputLine);
        scrollToBottom();
    }

    async function processCommand(command) {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ command: command })
        });
        const data = await response.json();
        
        if (data.output) {
            if (data.animation === 'typewriter') {
                await typewriterEffect(data.output);
            } else if (data.animation === 'typewriterChunk') {
                await typewriterChunkEffect(data.output);
            } else {
                displayOutput(data.output);
            }
        }
        if (data.path && data.username) {
            const userColor = getComputedStyle(document.documentElement).getPropertyValue('--prompt-user-color').trim();
            const pathColor = getComputedStyle(document.documentElement).getPropertyValue('--prompt-path-color').trim();
            promptLabel.innerHTML = `<span style="color: ${userColor};">${data.username}</span>@term:<span style="color: ${pathColor};">${data.path}</span>$ `;
        }
        if (data.error) {
            displayOutput(`<span style="color: red;">${data.error}</span>`);
        }
        scrollToBottom();
    }
    
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

    function scrollToBottom() {
        terminalOutput.scrollTop = terminalOutput.scrollHeight;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.innerText = text;
        return div.innerHTML;
    }
});