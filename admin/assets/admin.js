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

    $('#fs-tree').jstree({
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
            'app': { 'icon': 'jstree-file' }
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
        } else {
            // If a file is selected, set the parent to the file's parent
            parentIdInput.value = node.parent;
            const parentNode = $('#fs-tree').jstree(true).get_node(node.parent);
            selectedDirName.textContent = parentNode ? parentNode.text : '/';
        }
    });

    if (addItemForm) {
        itemTypeSelect.addEventListener('change', function () {
            const type = this.value;
            addContentWrapper.style.display = (type === 'dir') ? 'none' : 'block';
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
        document.getElementById('edit-content-wrapper').style.display = isDir ? 'none' : 'block';
        document.getElementById('edit-password-wrapper').style.display = item.type === 'txt' ? 'block' : 'none';

        if (!isDir) {
            document.getElementById('edit-item-content').value = item.content || '';
        }
        document.getElementById('edit-is-hidden').checked = item.is_hidden == 1;

        modal.style.display = 'flex';
    }

    function closeEditModal() {
        modal.style.display = 'none';
        editForm.reset();
        editFormResponse.textContent = '';
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
        } else {
            alert('Error: ' + (response.message || 'Could not delete item.'));
        }
    }

    // ============================
    // USER MANAGEMENT LOGIC
    // ============================
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