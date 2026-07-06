$(function () {
    // ============================
    // TAB NAVIGATION
    // ============================
    $('.tab-link').on('click', function() {
        const tabName = $(this).data('tab');

        // Update Tab Links
        $('.tab-link').removeClass('active');
        $(this).addClass('active');

        // Update Content Area
        $('.tab-content').hide();
        $('#tab-content-' + tabName).show();

        // Update Help Content
        $('.help-tab').hide();
        $(`.help-tab[data-tab="${tabName}"]`).show();

        // Load Data if needed
        if (tabName === 'users') {
            loadUsers();
        } else if (tabName === 'theme') {
            loadThemeSettings();
        }
    });

    // ============================
    // API HELPER
    // ============================
    async function apiRequest(action, body) {
        try {
            const response = await fetch('api_admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...body })
            });
            if (action === 'get_fs_tree') {
                return await response.json();
            }
            return await response.json();
        } catch (error) {
            console.error('API Request Error:', error);
            return { success: false, message: 'Failed to communicate with the server.' };
        }
    }

    function handleFormResponse(result, responseElement, onSuccessCallback) {
        if (result.success) {
            responseElement.style.color = 'green';
            responseElement.textContent = result.message;
            setTimeout(() => {
                responseElement.textContent = '';
                if (onSuccessCallback) onSuccessCallback();
            }, 1200);
        } else {
            responseElement.style.color = 'red';
            responseElement.textContent = result.message || 'An unknown error occurred.';
        }
    }

    // ============================
    // FILESYSTEM LOGIC
    // ============================
    const addItemForm = document.getElementById('add-item-form');

    // Add Modal Elements
    const addModal = document.getElementById('add-modal');
    const btnOpenAddModal = document.getElementById('btn-open-add-modal');
    const cancelAddBtn = document.getElementById('cancel-add-btn');

    btnOpenAddModal.addEventListener('click', () => {
        addModal.style.display = 'flex';
        // Ensure form is reset properly when opened, but keep parent id
        const parentId = document.getElementById('parent-id').value;
        const dirName = document.getElementById('selected-dir-name').innerText;
        document.getElementById('add-item-form').reset();
        document.getElementById('parent-id').value = parentId;
        document.getElementById('selected-dir-name').innerText = dirName;
        // Trigger change to hide unneeded fields
        document.getElementById('item-type').dispatchEvent(new Event('change'));
    });

    cancelAddBtn.addEventListener('click', () => {
        addModal.style.display = 'none';
        document.getElementById('form-response').innerText = '';
    });

    const parentIdInput = document.getElementById('parent-id');
    const selectedDirName = document.getElementById('selected-dir-name');
    const itemTypeSelect = document.getElementById('item-type');
    const addContentWrapper = document.getElementById('content-wrapper');
    const addPasswordWrapper = document.getElementById('password-wrapper');
    const addFormResponse = document.getElementById('form-response');

    // Edit Modal Elements
    const modal = document.getElementById('edit-modal');
    const editForm = document.getElementById('edit-item-form');
    const cancelEditBtn = document.getElementById('cancel-edit-btn');
    const editFormResponse = document.getElementById('edit-form-response');

    $('#fs-tree').on('ready.jstree', function() {
        // Load root dir automatically on start
        renderMainView('1');
    }).jstree({
        'core': {
            'data': {
                'url': 'api_admin.php',
                'type': 'POST',
                'dataType': 'json',
                'data': function (node) {
                    return { 'action': 'get_fs_tree' };
                }
            },
            'check_callback': true,
            'themes': {
                'responsive': true
            }
        },
        'plugins': ['types', 'contextmenu'],
        'types': {
            'dir': { 'icon': 'jstree-folder' },
            'txt': { 'icon': 'jstree-file' },
            'app': { 'icon': 'jstree-file' }, 'img': { 'icon': 'jstree-file' }
        },
        'contextmenu': {
            'items': function (node) {
                const items = {
                    'edit': {
                        'label': 'Edit',
                        'action': function () {
                            openEditModal(node.id);
                        }
                    },
                    'delete': {
                        'label': 'Delete',
                        'action': function () {
                            if (confirm('Are you sure you want to delete this item? This cannot be undone.')) {
                                deleteItem(node.id);
                            }
                        }
                    }
                };

                if (node.id === '1') { // Assuming '1' is the root ID
                    delete items.edit;
                    delete items.delete;
                }

                return items;
            }
        }
    }).on('select_node.jstree', function (e, data) {
        const node = data.node;
        if (node.type === 'dir') {
            parentIdInput.value = node.id;
            selectedDirName.textContent = node.text;
            renderMainView(node.id); // Call render on directory select
        } else {
            // If a file is selected, set the parent to the file's parent
            parentIdInput.value = node.parent;
            const parentNode = $('#fs-tree').jstree(true).get_node(node.parent);
            selectedDirName.textContent = parentNode ? parentNode.text : '/';
            renderMainView(node.parent); // Render parent dir if file selected
        }

        // Reset the item type to Directory and trigger change to update UI
        if (addItemForm && itemTypeSelect) {
            itemTypeSelect.value = 'dir';
            itemTypeSelect.dispatchEvent(new Event('change'));
        }
    });

    if (addItemForm) {
        itemTypeSelect.addEventListener('change', function () {
            const type = this.value;
            addContentWrapper.style.display = (type === 'txt' || type === 'app') ? 'block' : 'none';
            document.getElementById('image-upload-wrapper').style.display = (type === 'img') ? 'block' : 'none';
            addPasswordWrapper.style.display = (type === 'txt') ? 'block' : 'none';
        });

        addItemForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const formData = new FormData(addItemForm);
            const data = Object.fromEntries(formData.entries());
            const response = await apiRequest('add_item', { data: data });
            handleFormResponse(response, addFormResponse, () => {
                $('#fs-tree').jstree(true).refresh();
                addItemForm.reset();
                const addPreview = document.getElementById('image-preview');
                if (addPreview) addPreview.style.display = 'none';
                // Ensure UI resets to default visibility state
                itemTypeSelect.dispatchEvent(new Event('change'));
            });
        });

        itemTypeSelect.dispatchEvent(new Event('change'));
    }

    async function openEditModal(id) {
        const item = await apiRequest('get_item', { id: id });
        if (!item) {
            alert('Could not fetch item details.');
            return;
        }

        document.getElementById('edit-item-id').value = item.id;
        document.getElementById('edit-item-name').value = item.name;

        const isDir = item.type === 'dir';
        document.getElementById('edit-content-wrapper').style.display = (item.type === 'txt' || item.type === 'app') ? 'block' : 'none';
        document.getElementById('edit-image-upload-wrapper').style.display = (item.type === 'img') ? 'block' : 'none';
        document.getElementById('edit-password-wrapper').style.display = item.type === 'txt' ? 'block' : 'none';

        if (!isDir) {
            document.getElementById('edit-item-content').value = item.content || '';
        }
        document.getElementById('edit-is-hidden').checked = item.is_hidden == 1;
        document.getElementById('edit-item-owner').value = item.owner_id ? item.owner_id : 'null';

        modal.style.display = 'flex';
    }

    function closeEditModal() {
        modal.style.display = 'none';
        editForm.reset();
        editFormResponse.textContent = '';
        const editPreview = document.getElementById('edit-image-preview');
        if (editPreview) editPreview.style.display = 'none';
    }

    cancelEditBtn.addEventListener('click', closeEditModal);

    editForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        const formData = new FormData(editForm);
        const data = Object.fromEntries(formData.entries());
        data.is_hidden = document.getElementById('edit-is-hidden').checked ? 1 : 0;

        const response = await apiRequest('update_item', { data: data });
        handleFormResponse(response, editFormResponse, () => {
            $('#fs-tree').jstree(true).refresh();
            closeEditModal();
        });
    });

    async function deleteItem(id) {
        const response = await apiRequest('delete_item', { id: id });
        if (response.success) {
            alert(response.message);
            $('#fs-tree').jstree(true).refresh();
            // Re-render current main view after a delay to allow jstree refresh
            setTimeout(() => {
                const currentDir = document.getElementById('parent-id').value;
                if(currentDir) renderMainView(currentDir);
            }, 300);
        } else {
            alert('Error: ' + (response.message || 'Could not delete item.'));
        }
    }

    // ============================
    // USER MANAGEMENT LOGIC
    // ============================

    async function loadUserDropdowns() {
        const result = await apiRequest('get_users');
        if (result.success) {
            const addOwnerSelect = document.getElementById('item-owner');
            const editOwnerSelect = document.getElementById('edit-item-owner');

            let optionsHtml = '<option value="null">All Users</option>';
            result.data.forEach(user => {
                optionsHtml += `<option value="${user.id}">${escapeHtml(user.username)}</option>`;
            });

            if (addOwnerSelect) addOwnerSelect.innerHTML = optionsHtml;
            if (editOwnerSelect) editOwnerSelect.innerHTML = optionsHtml;
        }
    }

    // Load on start
    loadUserDropdowns();

    const userForm = document.getElementById('user-form');
    const userFormTitle = document.getElementById('user-form-title');
    const userIdInput = document.getElementById('user-id');
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const cancelUserBtn = document.getElementById('cancel-user-edit');
    const userFormResponse = document.getElementById('user-form-response');
    const usersTableBody = document.querySelector('#users-table tbody');

    async function loadUsers() {
        const result = await apiRequest('get_users');
        if (result.success) {
            usersTableBody.innerHTML = '';
            result.data.forEach(user => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${user.id}</td>
                    <td>${escapeHtml(user.username)}</td>
                    <td>
                        <button class="edit-user-btn" data-userid="${user.id}" data-username="${escapeHtml(user.username)}">Edit</button>
                        <button class="delete-user-btn" data-userid="${user.id}">Delete</button>
                    </td>
                `;
                usersTableBody.appendChild(tr);
            });
        }
    }

    document.querySelector('#users-table').addEventListener('click', async (e) => {
        if (e.target.classList.contains('edit-user-btn')) {
            const id = e.target.dataset.userid;
            const username = e.target.dataset.username;

            userFormTitle.textContent = `Edit User: ${username}`;
            userIdInput.value = id;
            usernameInput.value = username;
            passwordInput.placeholder = "Leave blank to keep current password";
            cancelUserBtn.style.display = 'inline-block';
        }

        if (e.target.classList.contains('delete-user-btn')) {
            const id = e.target.dataset.userid;
            if (confirm('Are you sure you want to delete this user? This is permanent.')) {
                const response = await apiRequest('delete_user', { id: id });
                if(response.success) {
                    loadUsers();
                } else {
                    alert(response.message);
                }
            }
        }
    });

    cancelUserBtn.addEventListener('click', () => {
        userForm.reset();
        userFormTitle.textContent = 'Add New User';
        userIdInput.value = '';
        passwordInput.placeholder = '';
        cancelUserBtn.style.display = 'none';
    });

    userForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = userIdInput.value;
        const action = id ? 'update_user' : 'add_user';

        const formData = new FormData(userForm);
        const data = Object.fromEntries(formData.entries());

        const response = await apiRequest(action, { data: data });
        handleFormResponse(response, userFormResponse, () => {
            userForm.reset();
            cancelUserBtn.click(); // Reset form state
            loadUsers();
        });
    });

    // ============================
    // THEME MANAGEMENT LOGIC
    // ============================
    const themeForm = document.getElementById('theme-form');
    const themeFormResponse = document.getElementById('theme-form-response');

    async function loadThemeSettings() {
        const result = await apiRequest('get_theme_settings');
        if (result.success) {
            const data = result.data;
            document.getElementById('theme_terminal_title').value = data.terminal_title || '';
            document.getElementById('theme_login_greeting').value = data.login_greeting || '';
            document.getElementById('theme_motd').value = data.motd || '';
            document.getElementById('theme_background_color').value = data.background_color || '#1a1a1a';
            document.getElementById('theme_text_color').value = data.text_color || '#00ff00';
            document.getElementById('theme_prompt_color_user').value = data.prompt_color_user || '#50fa7b';
            document.getElementById('theme_prompt_color_path').value = data.prompt_color_path || '#bd93f9';
        }
    }

    themeForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(themeForm);
        const data = Object.fromEntries(formData.entries());

        const response = await apiRequest('update_theme', { data: data });
        handleFormResponse(response, themeFormResponse);
    });

    // Utils
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.innerText = text;
        return div.innerHTML;
    }
});
    // --- Image Processing ---
    function processImageFile(file, previewCanvasId, stringInputId, widthInputId) {
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(event) {
            const img = new Image();
            img.onload = function() {
                const MAX_SIZE = 600;
                let width = img.width;
                let height = img.height;

                if (width > height) {
                    if (width > MAX_SIZE) {
                        height *= MAX_SIZE / width;
                        width = MAX_SIZE;
                    }
                } else {
                    if (height > MAX_SIZE) {
                        width *= MAX_SIZE / height;
                        height = MAX_SIZE;
                    }
                }

                width = Math.floor(width);
                height = Math.floor(height);

                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);
                const imageData = ctx.getImageData(0, 0, width, height);
                const data = imageData.data;

                const bayerMatrix = [
                    [ 0,  8,  2, 10],
                    [12,  4, 14,  6],
                    [ 3, 11,  1,  9],
                    [15,  7, 13,  5]
                ];

                let binaryString = '';

                for (let y = 0; y < height; y++) {
                    for (let x = 0; x < width; x++) {
                        const index = (y * width + x) * 4;
                        const r = data[index];
                        const g = data[index + 1];
                        const b = data[index + 2];
                        const grayscale = 0.299 * r + 0.587 * g + 0.114 * b;

                        const normalizedValue = grayscale / 255.0;
                        const threshold = (bayerMatrix[y % 4][x % 4] + 0.5) / 16.0;

                        const isWhite = normalizedValue > threshold;
                        binaryString += isWhite ? '1' : '0';

                        const color = isWhite ? 255 : 0;
                        data[index] = color;
                        data[index+1] = color;
                        data[index+2] = color;
                        data[index+3] = 255;
                    }
                }

                ctx.putImageData(imageData, 0, 0);

                const previewCanvas = document.getElementById(previewCanvasId);
                previewCanvas.style.display = 'block';
                previewCanvas.width = width;
                previewCanvas.height = height;
                const previewCtx = previewCanvas.getContext('2d');
                previewCtx.drawImage(canvas, 0, 0);

                document.getElementById(stringInputId).value = binaryString;
                document.getElementById(widthInputId).value = width;
            };
            img.src = event.target.result;
        };
        reader.readAsDataURL(file);
    }

    const itemImageInput = document.getElementById('item-image');
    if (itemImageInput) {
        itemImageInput.addEventListener('change', function() {
            processImageFile(this.files[0], 'image-preview', 'item-image-string', 'item-image-width');
        });
    }

    const editItemImageInput = document.getElementById('edit-item-image');
    if (editItemImageInput) {
        editItemImageInput.addEventListener('change', function() {
            processImageFile(this.files[0], 'edit-image-preview', 'edit-item-image-string', 'edit-item-image-width');
        });
    }


// --- Modern File Explorer Logic ---

async function renderMainView(directoryId) {
    const mainView = document.getElementById('fs-main-view');
    const currentPathEl = document.getElementById('fs-current-path');

    mainView.innerHTML = '<p>Loading...</p>';

    try {
        const response = await apiRequest('get_fs_tree');
        if (response.success) {
            const data = response.data;
            // Find current node path
            const node = findNodeInTree(data, directoryId);
            if (node) {
                currentPathEl.innerText = buildPath(data, directoryId) || '/';

                // Find children of this directory
                // Actually the API returns flat data for jsTree or nested?
                // Let's assume the API returns jsTree formatted data (which has 'parent')
                // Wait, let's check what format jsTree expects. Usually it's flat with 'parent' property.
                let children = data.filter(item => item.parent === (directoryId.toString() === '#' ? '#' : directoryId.toString()));

                if (children.length === 0) {
                    mainView.innerHTML = '<p style="grid-column: 1 / -1; text-align: center; color: var(--secondary-color);">Directory is empty.</p>';
                    return;
                }

                mainView.innerHTML = '';
                children.forEach(item => {
                    const isDir = item.icon === 'jstree-folder';
                    const iconChar = isDir ? '📁' : '📄';
                    const itemEl = document.createElement('div');
                    itemEl.className = 'fs-item ' + (isDir ? 'dir' : 'file');
                    itemEl.innerHTML = `
                        <div class="fs-item-icon">${iconChar}</div>
                        <div class="fs-item-name">${escapeHtml(item.text)}</div>
                        <div class="fs-item-actions">
                            <button class="fs-item-btn fs-btn-edit" data-id="${item.id}" onclick="event.stopPropagation(); triggerEdit('${item.id}')">Edit</button>
                            <button class="fs-item-btn fs-btn-delete" data-id="${item.id}" onclick="event.stopPropagation(); triggerDelete('${item.id}')">Del</button>
                        </div>
                    `;

                    if (isDir) {
                        itemEl.addEventListener('dblclick', () => {
                            $('#fs-tree').jstree('select_node', item.id);
                        });
                    } else {
                        itemEl.addEventListener('dblclick', () => {
                            triggerEdit(item.id);
                        });
                    }

                    mainView.appendChild(itemEl);
                });
            } else {
                 mainView.innerHTML = '<p>Directory not found.</p>';
            }
        } else {
             mainView.innerHTML = '<p>Error loading contents.</p>';
        }
    } catch (e) {
         mainView.innerHTML = '<p>Failed to communicate with server.</p>';
         console.error(e);
    }
}

function triggerEdit(id) {
    const tree = $('#fs-tree').jstree(true);
    const node = tree.get_node(id);
    if(node) {
        // Find how jstree context menu triggers edit, and call it
        // Or directly call openEditModal if available
        openEditModal(id);
    }
}

function triggerDelete(id) {
    if(confirm('Are you sure you want to delete this item? This cannot be undone.')) {
        deleteItem(id);
    }
}

// Helper functions for path building (Assuming data is flat array with .id and .parent)
function findNodeInTree(data, id) {
    return data.find(item => item.id.toString() === id.toString());
}

function buildPath(data, id) {
    let path = [];
    let current = findNodeInTree(data, id);
    while (current && current.id !== '#') {
        path.unshift(current.text);
        current = findNodeInTree(data, current.parent);
    }
    return '/' + path.join('/');
}

function escapeHtml(unsafe) {
    return (unsafe || '').toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}
