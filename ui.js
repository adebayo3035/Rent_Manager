const UI = {
  // =======================
  // Toast
  // =======================
  toast(message, type = "info", duration = 3000) {
    const container = document.getElementById("toastContainer");
    const toast = document.createElement("div");
    toast.className = `toast toast-${type}`;
    toast.innerText = message;

    container.appendChild(toast);

    setTimeout(() => {
      toast.style.animation = "fadeOut 0.4s forwards";
      setTimeout(() => toast.remove(), 400);
    }, duration);
  },

  // =======================
  // Alert Modal
  // =======================
  alert(message, title = "Alert") {
    document.getElementById("alertTitle").innerText = title;
    document.getElementById("alertMessage").innerText = message;

    const modal = document.getElementById("alertModal");
    modal.style.display = "flex";

    document.getElementById("alertOkBtn").onclick = () => {
      modal.style.display = "none";
    };
  },

  // =======================
  // Confirm Modal
  // =======================
  confirm(message, onConfirm, title = "Confirm Action") {
    document.getElementById("confirmTitle").innerText = title;
    document.getElementById("confirmMessage").innerText = message;

    const modal = document.getElementById("confirmModal");
    modal.style.display = "flex";

    // document.getElementById("confirmCancelBtn").onclick = () => {
    //     modal.style.display = "none";
    // };

    // document.getElementById("confirmOkBtn").onclick = () => {
    //     modal.style.display = "none";
    //     if (typeof onConfirm === "function") onConfirm();
    // };
    document.getElementById("confirmOkBtn").onclick = () => {
      modal.style.display = "none";
      if (typeof onConfirm === "function") {
        if (onConfirm.length > 0) {
          onConfirm(true);
        } else {
          onConfirm();
        }
      }
    };

    document.getElementById("confirmCancelBtn").onclick = () => {
      modal.style.display = "none";
      // Only notify cancel-aware callbacks that explicitly accept a result.
      if (typeof onConfirm === "function" && onConfirm.length > 0) {
        onConfirm(false);
      }
    };
  },

  // =======================
  // Loader
  // =======================
   // =======================
  // Loader (global, blocking, with messages)
  // =======================
  loader: {
    // Internal state
    _active: false,
    _timeoutId: null,
    _defaultMessage: "Processing your request...",
    _slowMessage: "Still working, please wait...",
    _slowThreshold: 15000, // 15 seconds

    // ---------------------------------------------------
    // Show the loader (blocking overlay with optional msg)
    // ---------------------------------------------------
    show(message = null) {
      const overlay = document.getElementById("uiLoaderOverlay");
      if (!overlay) {
        console.warn("[UI.loader] #uiLoaderOverlay not found in DOM");
        return;
      }

      // Update the message element if it exists
      const msgEl = overlay.querySelector(".ui-loader-message");
      if (msgEl) {
        msgEl.textContent = message || this._defaultMessage;
      }

      overlay.style.display = "flex";
      this._active = true;

      // Schedule "still working" soft warning
      this._clearSlowTimeout();
      this._timeoutId = setTimeout(() => {
        if (this._active && msgEl) {
          msgEl.textContent = this._slowMessage;
        }
      }, this._slowThreshold);

      // Block tab close / reload while operation is in flight
      window.addEventListener("beforeunload", this._preventNavigation);
    },

    // ---------------------------------------------------
    // Hide the loader
    // ---------------------------------------------------
    hide() {
      const overlay = document.getElementById("uiLoaderOverlay");
      if (overlay) {
        overlay.style.display = "none";
      }
      this._active = false;
      this._clearSlowTimeout();
      window.removeEventListener("beforeunload", this._preventNavigation);
    },

    // ---------------------------------------------------
    // Check if loader is currently visible
    // ---------------------------------------------------
    isActive() {
      return this._active === true;
    },

    // ---------------------------------------------------
    // Update the message while loader is visible
    // ---------------------------------------------------
    setMessage(message) {
      if (!this._active) return;
      const overlay = document.getElementById("uiLoaderOverlay");
      const msgEl = overlay?.querySelector(".ui-loader-message");
      if (msgEl) {
        msgEl.textContent = message;
      }
    },

    // ---------------------------------------------------
    // Internal helpers (prefix _ to signal private)
    // ---------------------------------------------------
    _clearSlowTimeout() {
      if (this._timeoutId) {
        clearTimeout(this._timeoutId);
        this._timeoutId = null;
      }
    },

    _preventNavigation(e) {
      e.preventDefault();
      e.returnValue = "";
      return "";
    },
  },
};
