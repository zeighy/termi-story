document.addEventListener('DOMContentLoaded', () => {
    const userForm = document.getElementById('user-form');
    const userFormTitle = document.getElementById('user-form-title');
    const userIdInput = document.getElementById('user-id');
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const cancelBtn = document.getElementById('cancel-user-edit');
    const formResponse = document.getElementById('user-form-response');

    document.querySelector('table').addEventListener('click', async (e) => {
        // Handle Edit Button
        if (e.target.classList.contains('edit-user-btn')) {
            const id = e.target.dataset.userid;
            const username = e.target.dataset.username;
            
            userFormTitle.textContent = `Edit User: ${username}`;
            userIdInput.value = id;
            usernameInput.value = username;
            passwordInput.placeholder = "Leave blank to keep current password";
            cancelBtn.style.display = 'inline-block';
        }

        // Handle Delete Button
        if (e.target.classList.contains('delete-user-btn')) {
            const id = e.target.dataset.userid;
            if (confirm('Are you sure you want to delete this user? This is permanent.')) {
                const response = await apiRequest('delete_user', { id: id });
                handleFormResponse(response);
            }
        }
    });

    // Handle Cancel Edit
    cancelBtn.addEventListener('click', () => {
        userForm.reset();
        userFormTitle.textContent = 'Add New User';
        userIdInput.value = '';
        passwordInput.placeholder = '';
        cancelBtn.style.display = 'none';
    });

    // Handle Form Submission (Add & Update)
    userForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = userIdInput.value;
        const action = id ? 'update_user' : 'add_user';
        
        const formData = new FormData(userForm);
        const data = Object.fromEntries(formData.entries());

        const response = await apiRequest(action, { data: data });
        handleFormResponse(response);
    });

    // Helper functions
    async function apiRequest(action, body) {
        const response = await fetch('api_admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...body })
        });
        return await response.json();
    }

    function handleFormResponse(result) {
        if (result.success) {
            formResponse.style.color = 'green';
            formResponse.textContent = result.message;
            setTimeout(() => location.reload(), 1200);
        } else {
            formResponse.style.color = 'red';
            formResponse.textContent = result.message || 'An unknown error occurred.';
        }
    }
});