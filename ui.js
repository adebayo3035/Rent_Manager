const UI = {
    // Toast
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

    // Alert Modal
    alert(message, title = "Alert") {
        document.getElementById("alertTitle").innerText = title;
        document.getElementById("alertMessage").innerText = message;

        const modal = document.getElementById("alertModal");
        modal.style.display = "flex";

        document.getElementById("alertOkBtn").onclick = () => {
            modal.style.display = "none";
        };
    },

    // Confirm Modal
    confirm(message, onConfirm, title = "Confirm Action") {
        document.getElementById("confirmTitle").innerText = title;
        document.getElementById("confirmMessage").innerText = message;

        const modal = document.getElementById("confirmModal");
        modal.style.display = "flex";

        document.getElementById("confirmCancelBtn").onclick = () => {
            modal.style.display = "none";
        };

        document.getElementById("confirmOkBtn").onclick = () => {
            modal.style.display = "none";
            if (typeof onConfirm === "function") onConfirm();
        };
    },

    // Loader
    loader: {
        show() {
            document.getElementById("uiLoaderOverlay").style.display = "flex";
        },
        hide() {
            document.getElementById("uiLoaderOverlay").style.display = "none";
        }
    }
};
