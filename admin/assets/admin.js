document.addEventListener('DOMContentLoaded', function() {
    const tree = document.getElementById('fs-tree');
    
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

    // --- Main Event Listener for the Filesystem Tree ---
    tree.addEventListener('click', async function(e) {
        const target = e.target;

        // **FIX**: Handle Directory Selection by specifically checking for 'item-name' class
        if (target.classList.contains('item-name') && target.closest('li.dir')) {
            const listItem = target.closest('li.dir');
            document.querySelectorAll('#fs-tree li.selected').forEach(el => {
                el.classList.remove('selected');
            });
            listItem.classList.add('selected');
            parentIdInput.value = listItem.dataset.id;
            selectedDirName.textContent = listItem.dataset.path;
        }

        // Handle Edit Button Click
        if (target.classList.contains('edit-btn')) {
            const id = target.dataset.id;
            await openEditModal(id);
        }

        // Handle Delete Button Click
        if (target.classList.contains('delete-btn')) {
            const id = target.dataset.id;
            if (confirm('Are you sure you want to delete this item? This cannot be undone.')) {
                await deleteItem(id);
            }
        }
    });

    // --- Add Item Form Logic ---
    if (addItemForm) {
        itemTypeSelect.addEventListener('change', function() {
            const type = this.value;
            addContentWrapper.style.display = (type === 'dir') ? 'none' : 'block';
            addPasswordWrapper.style.display = (type === 'txt') ? 'block' : 'none';
        });

        addItemForm.addEventListener('submit', async function(e) {
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
            document.getElementById('edit-item-password').value = item.password || '';
            document.getElementById('edit-item-hint').value = item.password_hint || '';
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

    editForm.addEventListener('submit', async function(e) {
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
            location.reload();
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
            setTimeout(() => location.reload(), 1200);
        } else {
            responseElement.style.color = 'red';
            responseElement.textContent = result.message || 'An unknown error occurred.';
        }
    }
});