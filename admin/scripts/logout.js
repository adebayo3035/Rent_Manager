let userInfo;

    // Fetch user info from PHP
    fetch('backend/authentication/logout.php') // Adjust the path as necessary
        .then(response => response.json())
        .then(data => {
            userInfo = data; // Store the user info
            console.log(userInfo); // Debug: check user info
        })
        .catch(error => {
            console.error('Error fetching user info:', error);
        });

    function logout() {
        if (userInfo && userInfo.unique_id) {
            fetch(`backend/authentication/logout.php?logout_id=${userInfo.unique_id}`, {
                method: 'GET',
                credentials: 'same-origin', // Ensure cookies are sent with the request
            })
            .then(response => {
                if (response.ok) {
                    // Redirect to the login page after logout
                    window.location.href = 'login.php';
                } else {
                    console.error('Logout failed:', response.statusText);
                }
            })
            .catch(error => {
                console.error('Error during logout:', error);
            });
        } else {
            console.error('User info is not available.');
        }
    }

