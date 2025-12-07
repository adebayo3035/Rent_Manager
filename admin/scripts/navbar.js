document.addEventListener('DOMContentLoaded', function() {
    fetchUserData();
    fetchNotificationsAndCount();
});

// Global variables
let userId;  // To store `unique_id`
//const inactivityTimeout = 60 * 1000; // 1 minute for testing
const inactivityTimeout = 60 * 1000000;
let inactivityTimers = {}; // Object to hold inactivity timers per user

// Function to fetch user data
function fetchUserData() {
    fetch('../backend/authentication/navbar.php')
        .then(response => response.json())
        .then(data => {
            if (data && data.unique_id) {
                userId = data.unique_id;
                
                // Update the welcome message
                document.getElementById('welcomeMessage').innerHTML = `Welcome, ${data.firstname} ${data.lastname} <i class="fa fa-caret-down"></i>`;
                
                // Initialize inactivity timer after fetching user data
                initializeInactivityTimer(userId);
            } else {
                console.error('No user data found.');
                window.location.href = 'index.php';
            }
        })
        .catch(error => {
            console.error('Error fetching user data:', error);
            window.location.href = 'index.php';
        });
}

// Handle logout
document.getElementById('logoutButton').addEventListener('click', function() {
    if (!userId) return;
    if(!confirm("Are you sure you want to logout?")){
        return;
    }
    fetch('../backend/authentication/logout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ logout_id: userId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'index.php';
        } else {
            console.error('Logout failed:', data.message);
        }
    })
    .catch(error => {
        console.error('Error during logout:', error);
    });
});

// Function to toggle the responsive navigation menu
function myFunction() {
    const nav = document.getElementById("myTopnav");
    nav.className = nav.className === "topnav" ? "topnav responsive" : "topnav";
}

// Fetch notifications count
const notificationBadge = document.getElementById('notification-badge');
function fetchNotificationsAndCount() {
    fetch('../backend/authentication/notification.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                notificationBadge.textContent = data.totalNotifications || '0';
            } else {
                console.error('Error fetching notifications:', data.message);
                notificationBadge.textContent = '0';
            }
        })
        .catch(error => {
            console.error('Error fetching notifications:', error);
            notificationBadge.textContent = '0';
        });
}

// Inactivity timer logic
function initializeInactivityTimer(userId) {
    resetInactivityTimer(userId);

    // Reset inactivity timer on user interaction
    ['mousemove', 'keydown'].forEach(event => 
        document.addEventListener(event, () => resetInactivityTimer(userId))
    );
}

function resetInactivityTimer(userId) {
    // Clear any existing timer for this user
    if (inactivityTimers[userId]) clearTimeout(inactivityTimers[userId]);

    // Set a new timeout for this user
    inactivityTimers[userId] = setTimeout(() => {
        if (userId) {
            logoutUserDueToInactivity(userId);
        }
    }, inactivityTimeout);
}

function logoutUserDueToInactivity(userId) {
    fetch('../backend/authentication/logout.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ logout_id: userId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'index.php';
        } else {
            console.error('Auto-logout failed:', data.message);
        }
    })
    .catch(error => {
        console.error('Error during auto-logout:', error);
    });
}
