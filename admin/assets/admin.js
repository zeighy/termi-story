$(function () {
    // Add Form Elements
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
            // If a file is selected, open the edit modal
            openEditModal(node.id);
        }
    });

    // --- Add Item Form Logic ---
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
            handleFormResponse(response, addFormResponse);
        });

        itemTypeSelect.dispatchEvent(new Event('change'));
    }

    // --- Edit Modal Logic ---
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
        handleFormResponse(response, editFormResponse);
    });

    // --- Delete Logic ---
    async function deleteItem(id) {
        const response = await apiRequest('delete_item', { id: id });
        if (response.success) {
            alert(response.message);
            $('#fs-tree').jstree(true).refresh();
        } else {
            alert('Error: ' + (response.message || 'Could not delete item.'));
        }
    }

    // --- Reusable Helper Functions ---
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

    function handleFormResponse(result, responseElement) {
        if (result.success) {
            responseElement.style.color = 'green';
            responseElement.textContent = result.message;
            setTimeout(() => {
                $('#fs-tree').jstree(true).refresh();
                responseElement.textContent = '';
                if(responseElement.id === 'edit-form-response') {
                    closeEditModal();
                } else {
                    addItemForm.reset();
                }
            }, 1200);
        } else {
            responseElement.style.color = 'red';
            responseElement.textContent = result.message || 'An unknown error occurred.';
        }
    }
});