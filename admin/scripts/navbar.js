// navbar.js - Fixed Auto-Logout Implementation

document.addEventListener("DOMContentLoaded", function () {
  console.log("Navbar script loaded");

  // ==================== GLOBAL VARIABLES ====================
  let userId = null;
  let inactivityTimer = null;
  let warningTimer = null;
  let activityInterval = null;
  let isWarningShowing = false;

  // Configuration - Adjust these values as needed
  const INACTIVITY_TIMEOUT_MS = 2 * 60 * 1000; // 2 minutes
  const WARNING_TIMEOUT_MS = 60 * 1000; // 1 minute warning before logout
  const TOKEN_REFRESH_INTERVAL = 5 * 60 * 1000; // 5 minutes

  // ==================== INITIALIZATION ====================
  initializeNavbar();

  async function initializeNavbar() {
    await fetchUserData();
    fetchNotificationsAndCount();
    setupMobileMenu();
    setupAccordion();
    setupLogoutHandlers();
    updateWelcomeMessage();
    setActiveLink();

    if (userId) {
      initializeInactivityTimer();
    }
  }

  // ==================== USER DATA FETCHING ====================
  async function fetchUserData() {
    try {
      const response = await fetch("../backend/authentication/navbar.php");
      const data = await response.json();

      console.log("FetchUserData response:", data); // Debug log

      if (data && data.unique_id) {
        userId = data.unique_id;
        console.log("userId set to:", userId);

        // Also store in window for global access
        window.currentUser = data;
        sessionStorage.setItem("userId", data.unique_id);

        const welcomeElements = document.querySelectorAll(
          "#welcomeMessage, #mobileWelcomeMessage",
        );
        welcomeElements.forEach((element) => {
          if (element) {
            element.textContent = `Welcome, ${data.firstname} ${data.lastname}`;
          }
        });

        return true;
      } else {
        console.error("No user data found in response:", data);
        window.location.href = "index.php";
        return false;
      }
    } catch (error) {
      console.error("Error fetching user data:", error);
      window.location.href = "index.php";
      return false;
    }
  }

  // ==================== MOBILE MENU SETUP ====================
  function setupMobileMenu() {
    const mobileToggle = document.getElementById("mobileToggle");
    const mobileClose = document.getElementById("mobileClose");
    const mobileMenu = document.getElementById("mobileMenu");
    const body = document.body;

    if (mobileToggle) {
      mobileToggle.addEventListener("click", function (e) {
        e.stopPropagation();

        const isOpen = mobileMenu.classList.toggle("active");
        body.classList.toggle("mobile-menu-open", isOpen);

        const icon = mobileToggle.querySelector("i");
        icon.className = isOpen ? "fas fa-times" : "fas fa-bars";
      });
    }

    if (mobileClose) {
      mobileClose.addEventListener("click", function () {
        mobileMenu.classList.remove("active");
        body.classList.remove("mobile-menu-open");

        const icon = mobileToggle.querySelector("i");
        if (icon) icon.className = "fas fa-bars";
      });
    }

    document.addEventListener("keydown", function (e) {
      if (
        e.key === "Escape" &&
        mobileMenu &&
        mobileMenu.classList.contains("active")
      ) {
        mobileMenu.classList.remove("active");
        body.classList.remove("mobile-menu-open");
        if (mobileToggle) {
          const icon = mobileToggle.querySelector("i");
          if (icon) icon.className = "fas fa-bars";
        }
      }
    });
  }

  // ==================== ACCORDION SETUP ====================
  function setupAccordion() {
    const accordionBtns = document.querySelectorAll(".mobile-accordion-btn");
    accordionBtns.forEach((btn) => {
      btn.addEventListener("click", function () {
        const item = this.parentElement;
        item.classList.toggle("active");
      });
    });

    const mobileLinks = document.querySelectorAll(
      ".mobile-nav-link:not(.mobile-accordion-btn)",
    );
    mobileLinks.forEach((link) => {
      link.addEventListener("click", function () {
        const mobileMenu = document.getElementById("mobileMenu");
        const mobileToggle = document.getElementById("mobileToggle");
        const body = document.body;

        if (mobileMenu) {
          mobileMenu.classList.remove("active");
          body.classList.remove("mobile-menu-open");
          if (mobileToggle) {
            const icon = mobileToggle.querySelector("i");
            if (icon) icon.className = "fas fa-bars";
          }
        }
      });
    });
  }

  // ==================== ACTIVE LINK ====================
  function setActiveLink() {
    const currentPage = window.location.pathname.split("/").pop();
    const allLinks = document.querySelectorAll(
      ".nav-link, .dropdown-item, .mobile-nav-link",
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

  // ==================== WELCOME MESSAGE ====================
  function updateWelcomeMessage() {
    const now = new Date();
    const hours = now.getHours();
    let greeting;

    if (hours < 12) greeting = "Good Morning";
    else if (hours < 18) greeting = "Good Afternoon";
    else greeting = "Good Evening";

    const welcomeElement = document.getElementById("welcomeMessage");
    const mobileWelcomeElement = document.getElementById(
      "mobileWelcomeMessage",
    );

    if (welcomeElement) {
      welcomeElement.textContent = `${greeting}!`;
    }
    if (mobileWelcomeElement) {
      mobileWelcomeElement.textContent = `${greeting}!`;
    }
  }

  // ==================== NOTIFICATION HANDLING ====================
  function updateNotificationBadge(count) {
    const badges = document.querySelectorAll(
      "#notification-badge, .badge-menu, .mobile-notification-badge",
    );
    badges.forEach((badge) => {
      badge.textContent = count;
      badge.style.display = count > 0 ? "flex" : "none";
    });
  }

  async function fetchNotificationsAndCount() {
    try {
      const response = await fetch("../backend/staffs/notifications.php");
      const data = await response.json();

      if (data.success) {
        const count = data.counts.unread || "0";
        updateNotificationBadge(count);
      } else {
        console.error("Error fetching notifications:", data.message);
        updateNotificationBadge("0");
      }
    } catch (error) {
      console.error("Error fetching notifications:", error);
      updateNotificationBadge("0");
    }
  }

  // ==================== LOGOUT HANDLERS ====================
  // ==================== LOGOUT HANDLERS ====================
  function setupLogoutHandlers() {
    const logoutButton = document.getElementById("logoutButton");
    const mobileLogoutButton = document.getElementById("mobileLogoutButton");

    if (logoutButton) {
      logoutButton.addEventListener("click", (event) => {
        event.preventDefault();
        console.log("Logout button clicked, userId:", userId); // Debug log
        handleLogout();
      });
    }

    if (mobileLogoutButton) {
      mobileLogoutButton.addEventListener("click", (event) => {
        event.preventDefault();
        console.log("Mobile logout button clicked, userId:", userId); // Debug log
        handleLogout();
      });
    }
  }

  async function handleLogout() {
    console.log("handleLogout called, userId:", userId); // Debug log

    // Try to get userId from multiple sources if not available
    let activeUserId = userId;

    if (!activeUserId) {
      // Try to get from window.currentUser (set by navbar)
      if (window.currentUser && window.currentUser.unique_id) {
        activeUserId = window.currentUser.unique_id;
        console.log("Using userId from window.currentUser:", activeUserId);
      }
      // Try to get from session storage
      else if (sessionStorage.getItem("userId")) {
        activeUserId = sessionStorage.getItem("userId");
        console.log("Using userId from sessionStorage:", activeUserId);
      }
    }

    if (!activeUserId) {
      console.error("No user ID found, redirecting to login");
      UI.toast("Session expired. Please login again.", "warning");
      setTimeout(() => {
        window.location.href = "index.php";
      }, 1000);
      return;
    }

    UI.confirm("Are you sure you want to logout?", async (confirmed) => {
      console.log("Confirm modal result:", confirmed); // Debug log

      if (confirmed) {
        try {
          console.log("Proceeding with logout for user:", activeUserId);

          if (typeof clearInactivityTimer === "function")
            clearInactivityTimer();
          if (typeof clearWarningTimer === "function") clearWarningTimer();

          const response = await fetch("../backend/authentication/logout.php", {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
            },
            credentials: "include",
            body: JSON.stringify({
              logout_id: activeUserId,
            }),
          });

          const data = await response.json();
          console.log("Logout response:", data); // Debug log

          if (data.success) {
            // Clear local storage
            sessionStorage.clear();
            localStorage.removeItem("userData");
            if (window.currentUser) window.currentUser = null;

            UI.toast("Logged out successfully", "success");
            setTimeout(() => {
              window.location.href = "index.php";
            }, 1000);
          } else {
            UI.toast(
              data.message || "Logout failed. Please try again.",
              "danger",
            );
          }
        } catch (error) {
          console.error("Logout error:", error);
          UI.toast("An error occurred while logging out.", "danger");
        }
      } else {
        console.log("User cancelled logout"); // Debug log
      }
    });
  }
  // ==================== INACTIVITY TIMER IMPLEMENTATION ====================
  function initializeInactivityTimer() {
    if (!userId) {
      console.warn("Cannot initialize inactivity timer: No user ID");
      return;
    }

    console.log("Initializing inactivity timer for user:", userId);
    resetInactivityTimer();

    const activityEvents = [
      "mousemove",
      "mousedown",
      "keydown",
      "click",
      "scroll",
      "touchstart",
      "touchmove",
      "wheel",
    ];

    activityEvents.forEach((event) => {
      document.addEventListener(event, handleUserActivity);
    });

    document.addEventListener("visibilitychange", handleVisibilityChange);
    trackInitialActivity();
  }

  function handleUserActivity() {
    if (userId && !isWarningShowing) {
      resetInactivityTimer();
    }
  }

  function handleVisibilityChange() {
    if (document.visibilityState === "visible" && userId && !isWarningShowing) {
      resetInactivityTimer();
    }
  }

  function resetInactivityTimer() {
    // Clear both timers
    clearInactivityTimer();
    clearWarningTimer();
    isWarningShowing = false;

    // Set new inactivity timer
    inactivityTimer = setTimeout(() => {
      console.log("User inactive for", INACTIVITY_TIMEOUT_MS / 1000, "seconds");
      showInactivityWarning();
    }, INACTIVITY_TIMEOUT_MS);
  }

  function clearInactivityTimer() {
    if (inactivityTimer) {
      clearTimeout(inactivityTimer);
      inactivityTimer = null;
    }
  }

  function clearWarningTimer() {
    if (warningTimer) {
      clearTimeout(warningTimer);
      warningTimer = null;
    }
  }

  function showInactivityWarning() {
    // Don't show warning if already showing
    if (isWarningShowing) return;

    isWarningShowing = true;

    // Set auto-logout timer if user doesn't respond
    warningTimer = setTimeout(() => {
      console.log("Warning timeout reached, logging out...");
      performAutoLogout();
    }, WARNING_TIMEOUT_MS);

    // Show confirmation modal
    UI.confirm(
      "You have been inactive for a while. Do you want to Logout now?",
      (confirmed) => {
        // Clear the auto-logout timer
        clearWarningTimer();

        if (!confirmed) {
          // User wants to stay logged in
          console.log("User chose to stay logged in");
          isWarningShowing = false;
          resetInactivityTimer();

          // Optional: Send heartbeat to server
          sendHeartbeat();

          UI.toast("Session extended", "info", 3000);
        } else {
          // User chose to logout or clicked Cancel
          console.log("User chose to logout");
          performAutoLogout();
        }
      },
      "Session Timeout warning",
    );
  }

  async function sendHeartbeat() {
    try {
      const response = await fetch("../backend/authentication/heartbeat.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        credentials: "include",
        body: JSON.stringify({ user_id: userId }),
      });

      const data = await response.json();
      if (!data.success) {
        console.warn("Heartbeat failed:", data.message);
      }
    } catch (error) {
      console.error("Heartbeat error:", error);
    }
  }

  async function performAutoLogout() {
    if (!userId) return;

    try {
      const response = await fetch("../backend/authentication/logout.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        credentials: "include",
        body: JSON.stringify({
          logout_id: userId,
          auto_logout: true,
        }),
      });

      const data = await response.json();

      if (data.success) {
        console.log("Auto-logout successful");
        window.location.href = "index.php?message=session_expired";
      } else {
        console.error("Auto-logout failed:", data.message);
        window.location.href = "index.php";
      }
    } catch (error) {
      console.error("Error during auto-logout:", error);
      window.location.href = "index.php";
    } finally {
      clearInactivityTimer();
      clearWarningTimer();
      isWarningShowing = false;
    }
  }

  function trackInitialActivity() {
    const activityEvents = [
      "mousemove",
      "mousedown",
      "keydown",
      "click",
      "scroll",
      "touchstart",
    ];

    activityEvents.forEach((event) => {
      document.addEventListener(event, () => {
        localStorage.setItem("lastActivityTime", Date.now().toString());
      });
    });

    localStorage.setItem("lastActivityTime", Date.now().toString());
  }

  // ==================== CLEANUP ON PAGE UNLOAD ====================
  window.addEventListener("beforeunload", function () {
    clearInactivityTimer();
    clearWarningTimer();
    if (activityInterval) {
      clearInterval(activityInterval);
    }
  });

  // ==================== PERIODIC NOTIFICATION REFRESH ====================
  // setInterval(() => {
  //   if (userId && document.visibilityState === "visible") {
  //     fetchNotificationsAndCount();
  //   }
  // }, 300000);
});
