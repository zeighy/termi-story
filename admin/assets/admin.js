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
        } else if (tabName === 'story') {
            // Story tab is event-driven; nothing to preload.
        }
    });

    // ============================
    // API HELPER
    // ============================
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    async function apiRequest(action, body) {
        try {
            const response = await fetch('api_admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ action, ...body })
            });
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

    const previewModal = document.getElementById('preview-modal');
    document.getElementById('close-preview-btn').addEventListener('click', () => {
        previewModal.style.display = 'none';
    });

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
            // Load through apiRequest so the tree request carries the JSON body and CSRF header
            'data': function (node, callback) {
                apiRequest('get_fs_tree').then((result) => {
                    callback.call(this, Array.isArray(result) ? result : []);
                });
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
                            triggerDelete(node.id, nodeName(node));
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
            selectedDirName.textContent = nodeName(node);
            renderMainView(node.id); // Call render on directory select
        } else {
            // If a file is selected, set the parent to the file's parent
            parentIdInput.value = node.parent;
            const parentNode = $('#fs-tree').jstree(true).get_node(node.parent);
            selectedDirName.textContent = parentNode ? nodeName(parentNode) : '/';
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
            addPasswordWrapper.style.display = 'block'; // any item type can be locked
        });

        addItemForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const formData = new FormData(addItemForm);
            const data = Object.fromEntries(formData.entries());
            const response = await apiRequest('add_item', { data: data });
            handleFormResponse(response, addFormResponse, () => {
                $('#fs-tree').jstree(true).refresh();
                renderMainView(currentDirectoryId); // show the new item in the grid
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
        if (!item || item.success === false) {
            alert('Could not fetch item details.');
            return;
        }

        document.getElementById('edit-item-id').value = item.id;
        document.getElementById('edit-item-name').value = item.name;

        const isDir = item.type === 'dir';
        document.getElementById('edit-content-wrapper').style.display = (item.type === 'txt' || item.type === 'app') ? 'block' : 'none';
        document.getElementById('edit-image-upload-wrapper').style.display = (item.type === 'img') ? 'block' : 'none';
        // Any item type can be locked. Show the current lock state and keep its hint.
        document.getElementById('edit-password-wrapper').style.display = 'block';
        document.getElementById('edit-item-password').value = '';
        document.getElementById('edit-item-hint').value = item.password_hint || '';
        document.getElementById('edit-remove-password').checked = false;
        document.getElementById('edit-lock-status').style.display = item.has_password ? 'block' : 'none';
        document.getElementById('edit-remove-lock-label').style.display = item.has_password ? 'flex' : 'none';

        if (!isDir) {
            document.getElementById('edit-item-content').value = item.content || '';
        }
        document.getElementById('edit-is-hidden').checked = item.is_hidden == 1;
        // If the owner account no longer exists, say so instead of silently showing
        // "All Users" (saving would then quietly make the item public).
        const ownerSelect = document.getElementById('edit-item-owner');
        ownerSelect.querySelectorAll('option[data-orphan]').forEach(o => o.remove());
        const ownerValue = item.owner_id ? String(item.owner_id) : 'null';
        if (![...ownerSelect.options].some(o => o.value === ownerValue)) {
            const orphan = new Option(`(deleted user #${ownerValue}) - choose a new owner`, ownerValue);
            orphan.dataset.orphan = '1';
            ownerSelect.add(orphan);
        }
        ownerSelect.value = ownerValue;

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
            renderMainView(currentDirectoryId); // reflect renames in the grid
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
                const selectedNodes = $('#fs-tree').jstree(true).get_selected();
                if (selectedNodes.length > 0) renderMainView(selectedNodes[0]);
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
                        <button class="delete-user-btn" data-userid="${user.id}" data-username="${escapeHtml(user.username)}">Delete</button>
                    </td>
                `;
                usersTableBody.appendChild(tr);
            });
            loadUserDropdowns(); // keep the add/edit item owner lists in sync
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
            openDeleteUserModal(e.target.dataset.userid, e.target.dataset.username);
        }
    });

    // ============================
    // DELETE USER (with ownership transfer)
    // ============================
    // A user who owns files/directories can only be deleted after the admin decides
    // where those items go: another user, or all users. Confirm stays disabled until then.
    const deleteUserModal = document.getElementById('delete-user-modal');
    const deleteUserForm = document.getElementById('delete-user-form');
    const deleteUserName = document.getElementById('delete-user-name');
    const deleteUserSummary = document.getElementById('delete-user-summary');
    const deleteUserItems = document.getElementById('delete-user-items');
    const deleteUserChoices = document.getElementById('delete-user-choices');
    const transferModeUser = document.getElementById('transfer-mode-user');
    const transferModeAll = document.getElementById('transfer-mode-all');
    const transferToSelect = document.getElementById('delete-user-transfer-to');
    const noOtherUsersNote = document.getElementById('delete-user-no-others');
    const confirmDeleteUserBtn = document.getElementById('confirm-delete-user-btn');
    const deleteUserResponse = document.getElementById('delete-user-response');
    let deleteUserId = null;
    let deleteUserNeedsChoice = false;

    // The admin's decision: a user ID, 'all', or null while undecided
    function selectedTransfer() {
        if (transferModeAll.checked) return 'all';
        if (transferModeUser.checked) return transferToSelect.value || null;
        return null;
    }

    function updateDeleteUserState() {
        transferToSelect.disabled = !transferModeUser.checked;
        confirmDeleteUserBtn.disabled = deleteUserNeedsChoice && selectedTransfer() === null;
    }

    function closeDeleteUserModal() {
        deleteUserModal.style.display = 'none';
        deleteUserId = null;
    }

    async function openDeleteUserModal(id, username) {
        deleteUserForm.reset();
        deleteUserResponse.textContent = '';
        deleteUserItems.innerHTML = '';
        confirmDeleteUserBtn.disabled = true;

        const [ownership, users] = await Promise.all([
            apiRequest('get_user_ownership', { id: id }),
            apiRequest('get_users')
        ]);
        if (!ownership.success) {
            alert(ownership.message || 'Could not load this user.');
            return;
        }

        deleteUserId = id;
        deleteUserName.textContent = username;
        const count = ownership.data.count;
        deleteUserNeedsChoice = count > 0;

        // Other users the files can be transferred to
        const others = users.success ? users.data.filter(u => String(u.id) !== String(id)) : [];
        transferToSelect.innerHTML = '';
        transferToSelect.add(new Option('Select a user…', ''));
        others.forEach(u => transferToSelect.add(new Option(u.username, u.id)));
        transferModeUser.disabled = others.length === 0;
        noOtherUsersNote.style.display = others.length === 0 ? 'block' : 'none';

        if (count === 0) {
            deleteUserSummary.textContent = `${username} doesn't own any files or directories.`;
            deleteUserChoices.style.display = 'none';
        } else {
            deleteUserSummary.textContent = `${username} owns ${count} item${count === 1 ? '' : 's'}:`;
            ownership.data.items.forEach(path => {
                const li = document.createElement('li');
                li.textContent = path;
                deleteUserItems.appendChild(li);
            });
            if (count > ownership.data.items.length) {
                const li = document.createElement('li');
                li.textContent = `…and ${count - ownership.data.items.length} more`;
                deleteUserItems.appendChild(li);
            }
            deleteUserChoices.style.display = 'block';
        }

        updateDeleteUserState();
        deleteUserModal.style.display = 'flex';
    }

    [transferModeUser, transferModeAll, transferToSelect].forEach(el => el.addEventListener('change', updateDeleteUserState));
    document.getElementById('cancel-delete-user-btn').addEventListener('click', closeDeleteUserModal);

    deleteUserForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const transferTo = selectedTransfer();
        if (deleteUserId === null || (deleteUserNeedsChoice && transferTo === null)) {
            return; // no decision yet
        }
        confirmDeleteUserBtn.disabled = true;
        const data = transferTo !== null ? { transfer_to: transferTo } : {};
        const response = await apiRequest('delete_user', { id: deleteUserId, data: data });
        if (response.success) {
            closeDeleteUserModal();
            userFormResponse.style.color = 'green';
            userFormResponse.textContent = response.message;
            setTimeout(() => { userFormResponse.textContent = ''; }, 4000);
            loadUsers();
        } else {
            deleteUserResponse.style.color = 'red';
            deleteUserResponse.textContent = response.message || 'Could not delete the user.';
            updateDeleteUserState();
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

let selectedFileId = null;
let selectedFileName = null;
let currentDirectoryId = '1'; // directory shown in the main view

async function renderMainView(directoryId) {
    currentDirectoryId = String(directoryId);
    const mainView = document.getElementById('fs-main-view');
    const currentPathEl = document.getElementById('fs-current-path');

    // Clear selection state when navigating
    clearSelection();

    mainView.innerHTML = '<p>Loading...</p>';

    try {
        const response = await apiRequest('get_fs_tree');
        // 'get_fs_tree' returns a flat array for jsTree, not {success: true, data: ...}
        if (Array.isArray(response)) {
            const data = response;
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
                    const isDir = item.type === 'dir';
                    const iconChar = isDir ? '📁' : '📄';
                    const itemEl = document.createElement('div');
                    itemEl.className = 'fs-item ' + (isDir ? 'dir' : 'file');
                    itemEl.dataset.id = item.id;
                    itemEl.dataset.name = nodeName(item);
                    itemEl.innerHTML = `
                        <div class="fs-item-icon">${iconChar}</div>
                        <div class="fs-item-name">${escapeHtml(nodeName(item))}</div>
                    `;

                    itemEl.addEventListener('click', (e) => {
                        e.stopPropagation();

                        // Clear previous selection visually
                        const prevSelected = document.querySelector('.fs-item.selected');
                        if (prevSelected) {
                            prevSelected.classList.remove('selected');
                        }

                        itemEl.classList.add('selected');
                        selectedFileId = item.id;
                        selectedFileName = nodeName(item);

                        document.getElementById('fs-main-actions').style.display = 'flex';
                    });

                    if (isDir) {
                        itemEl.addEventListener('dblclick', () => {
                            $('#fs-tree').jstree('select_node', item.id);
                        });
                    } else {
                        itemEl.addEventListener('dblclick', () => {
                            triggerPreview(item.id);
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

function clearSelection() {
    selectedFileId = null;
    selectedFileName = null;
    document.getElementById('fs-main-actions').style.display = 'none';
    const selectedItem = document.querySelector('.fs-item.selected');
    if (selectedItem) {
        selectedItem.classList.remove('selected');
    }
}

// Clear selection when clicking empty area in main view
document.querySelector('.filesystem-main').addEventListener('click', () => {
    clearSelection();
});

document.getElementById('main-edit-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    if (selectedFileId) {
        triggerEdit(selectedFileId);
    }
});

document.getElementById('main-delete-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    if (selectedFileId && selectedFileName) {
        triggerDelete(selectedFileId, selectedFileName);
    }
});

const moveModal = document.getElementById('move-modal');
const moveForm = document.getElementById('move-item-form');
const moveFormResponse = document.getElementById('move-form-response');
const cancelMoveBtn = document.getElementById('cancel-move-btn');

document.getElementById('main-move-btn').addEventListener('click', async (e) => {
    e.stopPropagation();
    if (!selectedFileId) return;

    document.getElementById('move-item-id').value = selectedFileId;

    // Load directories
    const response = await apiRequest('get_fs_tree');
    if (Array.isArray(response)) {
        const select = document.getElementById('move-target-dir');
        select.innerHTML = '';

        // Helper to find descendants to exclude
        function getDescendants(data, id) {
            let descendants = [];
            let children = data.filter(item => item.parent.toString() === id.toString());
            children.forEach(child => {
                descendants.push(child.id);
                descendants = descendants.concat(getDescendants(data, child.id));
            });
            return descendants;
        }

        const excludedIds = [selectedFileId, ...getDescendants(response, selectedFileId)].map(id => id.toString());

        // Build hierarchy map
        const nodeMap = {};
        response.forEach(item => {
            if (item.type === 'dir') {
                nodeMap[item.id] = { ...item, children: [] };
            }
        });

        const rootNodes = [];
        Object.values(nodeMap).forEach(node => {
            if (node.parent === '#' || node.parent === null) {
                rootNodes.push(node);
            } else if (nodeMap[node.parent]) {
                nodeMap[node.parent].children.push(node);
            }
        });

        function sortNodes(nodes) {
            nodes.sort((a, b) => nodeName(a).localeCompare(nodeName(b)));
            nodes.forEach(node => {
                if (node.children.length > 0) {
                    sortNodes(node.children);
                }
            });
        }
        sortNodes(rootNodes);

        function populateSelect(nodes, level) {
            nodes.forEach(node => {
                if (!excludedIds.includes(node.id.toString())) {
                    const option = document.createElement('option');
                    option.value = node.id;
                    const prefix = '> '.repeat(level);
                    option.textContent = prefix + nodeName(node);
                    select.appendChild(option);
                }
                populateSelect(node.children, level + 1);
            });
        }

        populateSelect(rootNodes, 0);

        moveModal.style.display = 'flex';
    }
});

function closeMoveModal() {
    moveModal.style.display = 'none';
    moveForm.reset();
    moveFormResponse.textContent = '';
}

if (cancelMoveBtn) {
    cancelMoveBtn.addEventListener('click', closeMoveModal);
}

if (moveForm) {
    moveForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(moveForm);
        const data = Object.fromEntries(formData.entries());

        const response = await apiRequest('move_item', { data: data });
        handleFormResponse(response, moveFormResponse, () => {
            closeMoveModal();
            window.location.reload();
        });
    });
}

async function triggerPreview(id) {
    const item = await apiRequest('get_item', { id: id });
    if (!item || item.success === false) {
        alert('Could not fetch item details.');
        return;
    }

    const previewModal = document.getElementById('preview-modal');
    const previewTitle = document.getElementById('preview-title');
    const previewContent = document.getElementById('preview-content');
    const previewEditBtn = document.getElementById('preview-edit-btn');

    previewTitle.innerText = item.name;

    if (item.type === 'img' && item.content) {
        // Format of img content: width;0s and 1s
        const parts = item.content.split(';');
        if (parts.length >= 2) {
            const width = parseInt(parts[0], 10);
            const pixelData = parts[1];
            if (!isNaN(width) && width > 0 && width <= 4096 && pixelData.length / width <= 4096) {
                const height = Math.ceil(pixelData.length / width);

                // Clear content and append canvas
                previewContent.innerHTML = '';
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                canvas.style.maxWidth = '100%';
                canvas.style.display = 'block';
                canvas.style.border = '1px solid var(--border-color)';

                // We'll just use the admin theme colors for preview, or terminal colors if available
                const computedStyle = getComputedStyle(document.body);
                const bgColor = computedStyle.getPropertyValue('--theme-bg-color').trim() || '#1a1a1a';
                const textColor = computedStyle.getPropertyValue('--theme-text-color').trim() || '#00ff00';

                const ctx = canvas.getContext('2d');
                ctx.fillStyle = bgColor;
                ctx.fillRect(0, 0, width, height);
                ctx.fillStyle = textColor;

                for (let y = 0; y < height; y++) {
                    for (let x = 0; x < width; x++) {
                        const idx = y * width + x;
                        if (idx < pixelData.length && pixelData[idx] === '1') {
                            ctx.fillRect(x, y, 1, 1);
                        }
                    }
                }

                previewContent.appendChild(canvas);
            } else {
                previewContent.innerHTML = `<em>[Invalid Image Data]</em>`;
            }
        } else {
            previewContent.innerHTML = `<em>[Invalid Image Format]</em>`;
        }
    } else {
        // Content arrives decoded (plain text) from get_item, so show it as text
        if (item.content) {
            previewContent.innerText = item.content;
        } else {
            previewContent.innerHTML = '<em>[Empty File]</em>';
        }
    }

    previewEditBtn.onclick = () => {
        previewModal.style.display = 'none';
        triggerEdit(id);
    };

    previewModal.style.display = 'flex';
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

function triggerDelete(id, name) {
    const itemName = name ? `"${name}"` : 'this item';
    if(confirm(`Are you sure you want to delete ${itemName}? This cannot be undone.`)) {
        deleteItem(id);
    }
}


    // ============================
    // STORY EXPORT / IMPORT
    // ============================
    const exportStoryBtn = document.getElementById('btn-export-story');
    const exportStoryResponse = document.getElementById('export-story-response');
    const importStoryForm = document.getElementById('import-story-form');
    const importStoryResponse = document.getElementById('import-story-response');

    if (exportStoryBtn) {
        exportStoryBtn.addEventListener('click', async () => {
            exportStoryResponse.style.color = '';
            exportStoryResponse.textContent = 'Preparing export…';
            const result = await apiRequest('export_story');
            if (!result.success || !result.data) {
                exportStoryResponse.style.color = 'red';
                exportStoryResponse.textContent = result.message || 'Export failed.';
                return;
            }
            const blob = new Blob([JSON.stringify(result.data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
            a.href = url;
            a.download = `termi-story-export-${stamp}.json`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            exportStoryResponse.style.color = 'green';
            exportStoryResponse.textContent = `Exported ${result.data.filesystem.length} item(s).`;
        });
    }

    if (importStoryForm) {
        importStoryForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fileInput = document.getElementById('import-story-file');
            const mode = document.getElementById('import-story-mode').value;
            const includeTheme = document.getElementById('import-include-theme').checked;
            const file = fileInput.files && fileInput.files[0];
            if (!file) {
                importStoryResponse.style.color = 'red';
                importStoryResponse.textContent = 'Choose a JSON export file first.';
                return;
            }
            if (mode === 'replace') {
                const ok = confirm('Replace mode deletes ALL story files and folders under / (except the root), then imports the file. This cannot be undone. Continue?');
                if (!ok) return;
            }
            importStoryResponse.style.color = '';
            importStoryResponse.textContent = 'Importing…';
            let payload;
            try {
                const text = await file.text();
                payload = JSON.parse(text);
            } catch (err) {
                importStoryResponse.style.color = 'red';
                importStoryResponse.textContent = 'Could not parse JSON file.';
                return;
            }
            const result = await apiRequest('import_story', {
                data: {
                    payload,
                    mode,
                    include_theme: includeTheme
                }
            });
            if (result.success) {
                importStoryResponse.style.color = 'green';
                importStoryResponse.textContent = result.message; // stays visible (may list unmatched owners)
                if ($('#fs-tree').jstree(true)) {
                    $('#fs-tree').jstree(true).refresh();
                }
                fileInput.value = '';
            } else {
                importStoryResponse.style.color = 'red';
                importStoryResponse.textContent = result.message || 'Import failed.';
            }
        });
    }


// Helper functions for path building (Assuming data is flat array with .id and .parent)

// A tree item's plain-text name. 'text' is HTML-escaped for jstree, so display code
// (textContent, escapeHtml, confirm dialogs) must use the raw name in data.name.
function nodeName(node) {
    if (node && node.data && typeof node.data.name === 'string') {
        return node.data.name;
    }
    return node ? String(node.text ?? '') : '';
}

function findNodeInTree(data, id) {
    return data.find(item => item.id.toString() === id.toString());
}

function buildPath(data, id) {
    let path = [];
    let current = findNodeInTree(data, id);
    // Stop at the root (parent '#'); its own name is the leading '/'
    while (current && current.id !== '#' && current.parent !== '#') {
        path.unshift(nodeName(current));
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

});