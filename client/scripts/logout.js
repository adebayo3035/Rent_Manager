// In navbar.js, replace your existing handleLogout with this:

async function handleLogout() {
    // Check if user is logged in
    if (!window.currentUser?.unique_id) {
        window.location.href = '../pages/index.php';
        return;
    }
    
    // Show confirmation dialog
    showConfirmationDialog(
        'Logout',
        'Are you sure you want to logout?',
        async () => {
            // User confirmed logout
            try {
                const response = await fetch('../backend/authentication/logout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    credentials: 'include',
                    body: JSON.stringify({ 
                        logout_id: window.currentUser.unique_id 
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Clear local data
                    window.currentUser = null;
                    localStorage.removeItem('userData');
                    sessionStorage.clear();
                    
                    showToast('Logged out successfully', 'success');
                    
                    // Redirect after short delay
                    setTimeout(() => {
                        window.location.href = '../pages/index.php';
                    }, 1000);
                } else {
                    throw new Error(data.message || 'Logout failed');
                }
            } catch (error) {
                console.error('Logout error:', error);
                showToast(error.message || 'Logout failed. Please try again.', 'error');
                
                // Force redirect as fallback
                setTimeout(() => {
                    window.location.href = '../pages/index.php';
                }, 2000);
            }
        },
        () => {
            // User cancelled logout
            console.log('Logout cancelled');
        }
    );
}