// Preview uploaded client photo
document.getElementById("clientPhoto").addEventListener("change", function (e) {
    const file = e.target.files[0];
    const preview = document.getElementById("photoPreview");

    if (!file) {
        preview.innerHTML = "<span class = 'photoPreviewText'>No image</span>";
        return;
    }

    const reader = new FileReader();
    reader.onload = function (event) {
        preview.innerHTML = `<img src="${event.target.result}" 
                                style="width:100%;height:100%;object-fit:cover;">`;
    };
    reader.readAsDataURL(file);
});

// client.js
document.addEventListener("DOMContentLoaded", () => {
     initFormValidation('addClientForm', 'saveClientBtn', 'addClientMessage', {
    maxFileSizeMB: 2, // Override default
    allowedFileTypes: ['jpg', 'jpeg', 'png'] // Override default
});
    const clientManager = new DataManager({

        // === DOM Element IDs ===
        tableId: "clientSummary",
        tableBodyId: "clientSummaryBody",
        modalId: "clientModal",
        addModalId: "addClientModal",
        formId: "addClientForm",    // (we will handle manually because you don't have a <form>)
        paginationId: "clientPagination",
        searchInputId: "clientLiveSearch",
        addButtonId: "addNewClientBtn",
        csrfTokenName: "add_client_form",

        // === API Endpoints ===
        fetchUrl: "../backend/clients/get_client.php",
        addUrl: "../backend/clients/client_onboarding.php",
        updateUrl: "../backend/clients/update_client.php",
        fetchDetailsUrl: "../backend/clients/fetch_client_details.php",

        // === Item Definitions ===
        itemName: "client",
        itemNamePlural: "clients",
        idField: "client_code",
        statusField: "status",
        detailsKey: "client_details",

        // === Columns (match HTML header) ===
        columns: [
            {
                field: "client_code",
                label: "Client ID",
                render: (item) => `<strong>${item.client_code}</strong>`
            },
            {
                field: "firstname",
                label: "Client Name",
                render: (item) => `${item.firstname} ${item.lastname}`
            },
            {
                field: "email",
                label: "Email",
                render: (item) => item.email
            },
            {
                field: "status",
                label: "Status",
                render: (item) =>
                    item.status == 1
                        ? `<span style="color:green">Active</span>`
                        : `<span style="color:red">Inactive</span>`
            }
        ],

        // === Row Rendering Logic ===
        renderRow: function (client, userRole) {
            console.log("UserRole inside renderRow:", userRole);

            const statusHTML =
                client.status == 1
                    ? `<span style="color: green;">Active</span>`
                    : `<span style="color: red;">Inactive</span>`;

            let row = `
                <td>${client.client_code}</td>
                <td>${client.firstname} ${client.lastname}</td>
                <td>${client.email}</td>
                <td>${statusHTML}</td>`;

            if (client.status == 1) {
                row += `
                    <td><span class="edit-icon" data-id="${client.client_code}">✏️</span></td>
                    <td><span class="delete-icon" data-id="${client.client_code}">🗑️</span></td>`;
            } else {
                row += `
                    <td colspan="2" style="text-align:center;">
                        <span class="restore-icon" data-id="${client.client_code}">↻ Restore</span>
                    </td>`;
            }

            return row;
        },

        // === Populate Edit Modal ===
        populateDetails: function (client) {
            const body = document.querySelector("#clientDetailsTable tbody");
             const photoUrl = `../backend/clients/client_photos/${client.photo}`;

            body.innerHTML = `
                <tr>
            <td colspan="2" style="text-align:center;">
                <img 
                    src="${photoUrl}" 
                    alt="Client Photo" 
                    style="
                        width:120px;
                        height:120px;
                        object-fit:cover;
                        border-radius:8px;
                        border:1px solid #ccc;
                        margin-bottom:10px;
                    "
                >
            </td>
        </tr>
                <tr>
                    <td><strong>Client Code</strong></td>
                    <td><input type="text" id="edit_client_code" value="${client.client_code}" readonly></td>
                </tr>

                <tr>
                    <td><strong>First Name</strong></td>
                    <td><input type="text" id="edit_firstname" value="${client.firstname}"></td>
                </tr>

                <tr>
                    <td><strong>Last Name</strong></td>
                    <td><input type="text" id="edit_lastname" value="${client.lastname}"></td>
                </tr>

                <tr>
                    <td><strong>Email</strong></td>
                    <td><input type="email" id="edit_email" value="${client.email}"></td>
                </tr>

                <tr>
                    <td><strong>Phone</strong></td>
                    <td><input type="text" id="edit_phone" value="${client.phone}"></td>
                </tr>

                <tr>
                    <td><strong>Address</strong></td>
                    <td><textarea id="edit_address">${client.address}</textarea></td>
                </tr>

                <tr>
                    <td><strong>Gender</strong></td>
                    <td>
                        <select id="edit_gender">
                            <option value="male" ${client.gender == "male" ? "selected" : ""}>Male</option>
                            <option value="female" ${client.gender == "female" ? "selected" : ""}>Female</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <td><strong>Status</strong></td>
                    <td>
                        <select id="edit_status">
                            <option value="1" ${client.status == 1 ? "selected" : ""}>Active</option>
                            <option value="0" ${client.status == 0 ? "selected" : ""}>Inactive</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <td colspan="2" style="text-align:center;">
                        <button id="updateClientBtn" class="btn-primary">Update Client</button>
                    </td>
                </tr>
            `;

            // Reset event listener to avoid duplicates
            const updateButton = document.getElementById("updateClientBtn");
            updateButton.replaceWith(updateButton.cloneNode(true));

            document.getElementById("updateClientBtn").addEventListener("click", () => {
                UI.confirm("Are you sure you want to update this client?", () => {
                    this.updateItem(client.client_code, {
                        client_code: client.client_code,
                        firstname: document.getElementById("edit_firstname").value,
                        lastname: document.getElementById("edit_lastname").value,
                        email: document.getElementById("edit_email").value,
                        phone: document.getElementById("edit_phone").value,
                        address: document.getElementById("edit_address").value,
                        gender: document.getElementById("edit_gender").value,
                        status: document.getElementById("edit_status").value,
                        action_type: "update_all"
                    });
                });
            });
        },

        // === Custom Initialization ===
        onInit: function () {
            window.clientManager = this;

            // Load initial data
            // this.fetchData();

            // Handle photo preview
            const photoInput = document.getElementById("clientPhoto");
            const photoPreview = document.getElementById("photoPreview");

            photoInput.addEventListener("change", function () {
                const file = this.files[0];
                if (!file) return;

                const reader = new FileReader();
                reader.onload = function (e) {
                    photoPreview.innerHTML =
                        `<img src="${e.target.result}" style="width:120px;height:120px;object-fit:cover;">`;
                };
                reader.readAsDataURL(file);
            });

            console.log("Client Manager initialized successfully");
        }
    });

    window.agm = clientManager; // expose for debugging
    console.log('Client Manager instance created:', clientManager);
});

