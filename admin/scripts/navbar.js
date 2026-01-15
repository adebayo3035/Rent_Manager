// navbar.js
document.addEventListener("DOMContentLoaded", function () {
  console.log("Navbar script loaded");

  // Initialize functions
  fetchUserData();
  // fetchNotificationsAndCount();

  const mobileToggle = document.getElementById("mobileToggle");
  const mobileClose = document.getElementById("mobileClose");
  const mobileMenu = document.getElementById("mobileMenu");
  const body = document.body;

  // Toggle mobile menu
  if (mobileToggle) {
    mobileToggle.addEventListener("click", function (e) {
      e.stopPropagation();

      const isOpen = mobileMenu.classList.toggle("active");
      body.classList.toggle("mobile-menu-open", isOpen);

      const icon = mobileToggle.querySelector("i");
      icon.className = isOpen ? "fas fa-times" : "fas fa-bars";
    });
  }

  // Close menu via X button
  if (mobileClose) {
    mobileClose.addEventListener("click", function () {
      mobileMenu.classList.remove("active");
      body.classList.remove("mobile-menu-open");

      const icon = mobileToggle.querySelector("i");
      icon.className = "fas fa-bars";
    });
  }
  const mobileLogoutButton = document.getElementById("mobileLogoutButton");

  // Mobile accordion functionality
  const accordionBtns = document.querySelectorAll(".mobile-accordion-btn");
  accordionBtns.forEach((btn) => {
    btn.addEventListener("click", function () {
      const item = this.parentElement;
      item.classList.toggle("active");
    });
  });

  // Close mobile menu when clicking on a link
  const mobileLinks = document.querySelectorAll(
    ".mobile-nav-link:not(.mobile-accordion-btn)"
  );
  mobileLinks.forEach((link) => {
    link.addEventListener("click", function () {
      mobileMenu.classList.remove("active");
      body.classList.remove("mobile-menu-open");
    });
  });

  // Set active link based on current page
  function setActiveLink() {
    const currentPage = window.location.pathname.split("/").pop();
    const allLinks = document.querySelectorAll(
      ".nav-link, .dropdown-item, .mobile-nav-link"
    );

    allLinks.forEach((link) => {
      const href = link.getAttribute("href");
      if (href === currentPage || (href && href.includes(currentPage))) {
        link.classList.add("active");
      } else {
        link.classList.remove("active");
      }
    });
  }

  // Update welcome message
  function updateWelcomeMessage() {
    const now = new Date();
    const hours = now.getHours();
    let greeting;

    if (hours < 12) greeting = "Good Morning";
    else if (hours < 18) greeting = "Good Afternoon";
    else greeting = "Good Evening";

    const welcomeElement = document.getElementById("welcomeMessage");
    const mobileWelcomeElement = document.getElementById(
      "mobileWelcomeMessage"
    );

    if (welcomeElement) {
      welcomeElement.textContent = `${greeting}!`;
    }
    if (mobileWelcomeElement) {
      mobileWelcomeElement.textContent = `${greeting}!`;
    }
  }

  // Handle mobile logout
  // if (mobileLogoutButton) {
  //   mobileLogoutButton.addEventListener("click", function (e) {
  //     e.preventDefault();
  //     if (confirm("Are you sure you want to logout?")) {
  //       // Close mobile menu first
  //       mobileMenu.classList.remove("active");
  //       body.classList.remove("mobile-menu-open");
  //       // Trigger logout
  //       if (window.logoutUser) {
  //         logoutUser();
  //       } else {
  //         document.getElementById("logoutButton").click();
  //       }
  //     }
  //   });
  // }

  // const logoutButton = document.getElementById("logoutButton");
  // const mobileLogoutButton = document.getElementById("mobileLogoutButton")

  if (mobileLogoutButton) {
    mobileLogoutButton.addEventListener("click", (event) => {
      event.preventDefault();
       if (!userId) return;

      UI.confirm("Are you sure you want to logout?", async () => {
        try {
          const response = await fetch("../backend/authentication/logout.php", {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
            },
            credentials: "include", // IMPORTANT for sessions
            body: JSON.stringify({
              logout_id: userId, // ensure this exists
            }),
          });

          const data = await response.json();

          if (data.success) {
            UI.toast("Logged out successfully", "success");
            window.location.href = "index.php";
          } else {
            UI.toast(
              data.message || "Logout failed. Please try again.",
              "danger"
            );
          }
        } catch (error) {
          console.error("Logout error:", error);
          UI.toast("An error occurred while logging out.", "danger");
        }
      });
    });
  }

  // Close mobile menu on escape key
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && mobileMenu.classList.contains("active")) {
      mobileMenu.classList.remove("active");
      body.classList.remove("mobile-menu-open");
      if (mobileToggle) {
        mobileToggle.querySelector("i").className = "fas fa-bars";
      }
    }
  });

  // Initialize
  setActiveLink();
  updateWelcomeMessage();

  // Update notification badge
  function updateNotificationBadge(count) {
    const badges = document.querySelectorAll(
      ".badge, .badge-menu, .mobile-notification-badge"
    );
    badges.forEach((badge) => {
      badge.textContent = count;
      badge.style.display = count > 0 ? "flex" : "none";
    });
  }

  // Global variables
  let userId;
  const inactivityTimeout = 60 * 1000000;
  let inactivityTimers = {};

  // Function to fetch user data
  function fetchUserData() {
    fetch("../backend/authentication/navbar.php")
      .then((response) => response.json())
      .then((data) => {
        if (data && data.unique_id) {
          userId = data.unique_id;

          // Update both desktop and mobile welcome messages
          const welcomeElements = document.querySelectorAll(
            "#welcomeMessage, #mobileWelcomeMessage"
          );
          welcomeElements.forEach((element) => {
            if (element) {
              element.textContent = `Welcome, ${data.firstname} ${data.lastname}`;
            }
          });

          // Initialize inactivity timer
          initializeInactivityTimer(userId);
        } else {
          console.error("No user data found.");
          window.location.href = "index.php";
        }
      })
      .catch((error) => {
        console.error("Error fetching user data:", error);
        window.location.href = "index.php";
      });
  }

  // Handle logout
  const logoutButton = document.getElementById("logoutButton");
  // const mobileLogoutButton = document.getElementById("mobileLogoutButton")

  if (logoutButton) {
    logoutButton.addEventListener("click", (event) => {
      event.preventDefault();
       if (!userId) return;

      UI.confirm("Are you sure you want to logout?", async () => {
        try {
          const response = await fetch("../backend/authentication/logout.php", {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
            },
            credentials: "include", // IMPORTANT for sessions
            body: JSON.stringify({
              logout_id: userId, // ensure this exists
            }),
          });

          const data = await response.json();

          if (data.success) {
            UI.toast("Logged out successfully", "success");
            window.location.href = "index.php";
          } else {
            UI.toast(
              data.message || "Logout failed. Please try again.",
              "danger"
            );
          }
        } catch (error) {
          console.error("Logout error:", error);
          UI.toast("An error occurred while logging out.", "danger");
        }
      });
    });
  }

  // Fetch notifications count
  const notificationBadge = document.getElementById("notification-badge");
  function fetchNotificationsAndCount() {
    fetch("../backend/authentication/notification.php")
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          const count = data.totalNotifications || "0";
          updateNotificationBadge(count);
        } else {
          console.error("Error fetching notifications:", data.message);
          updateNotificationBadge("0");
        }
      })
      .catch((error) => {
        console.error("Error fetching notifications:", error);
        updateNotificationBadge("0");
      });
  }

  // Inactivity timer logic
  function initializeInactivityTimer(userId) {
    resetInactivityTimer(userId);

    // Reset inactivity timer on user interaction
    ["mousemove", "keydown", "click", "touchstart"].forEach((event) =>
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
    fetch("../backend/authentication/logout.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ logout_id: userId }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          window.location.href = "index.php";
        } else {
          console.error("Auto-logout failed:", data.message);
        }
      })
      .catch((error) => {
        console.error("Error during auto-logout:", error);
      });
  }

  // Periodically update notifications
  // setInterval(() => {
  //   fetchNotificationsAndCount();
  // }, 30000);
});
