// Preview uploaded staff photo
document.getElementById("staffPhoto").addEventListener("change", function (e) {
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

// staff.js
document.addEventListener("DOMContentLoaded", () => {
     initFormValidation('addStaffForm', 'saveStaffBtn', 'addStaffMessage', {
    maxFileSizeMB: 2, // Override default
    allowedFileTypes: ['jpg', 'jpeg', 'png'] // Override default
});
    const staffManager = new DataManager({

        // === DOM Element IDs ===
        tableId: "staffSummary",
        tableBodyId: "staffSummaryBody",
        modalId: "staffModal",
        addModalId: "addStaffModal",
        formId: "addStaffForm",    // (we will handle manually because you don't have a <form>)
        paginationId: "staffPagination",
        searchInputId: "staffLiveSearch",
        addButtonId: "addNewStaffBtn",
        csrfTokenName: "add_staff_form",

        // === API Endpoints ===
        fetchUrl: "../backend/staffs/get_staffs.php",
        addUrl: "../backend/staffs/admin_onboarding.php",
        updateUrl: "../backend/staffs/update_staff.php",
        fetchDetailsUrl: "../backend/staffs/fetch_staff_details.php",

        // === Item Definitions ===
        itemName: "staff",
        itemNamePlural: "staffs",
        idField: "unique_id",
        statusField: "status",
        detailsKey: "staff_details",

        // === Columns (match HTML header) ===
        columns: [
            {
                field: "unique_id",
                label: "Staff ID",
                render: (item) => `<strong>${item.unique_id}</strong>`
            },
            {
                field: "firstname",
                label: "Staff Name",
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
        renderRow: function (staff, userRole) {
            console.log("UserRole inside renderRow:", userRole);

            const statusHTML =
                staff.status == 1
                    ? `<span style="color: green;">Active</span>`
                    : `<span style="color: red;">Inactive</span>`;

            let row = `
                <td>${staff.unique_id}</td>
                <td>${staff.firstname} ${staff.lastname}</td>
                <td>${staff.email}</td>
                <td>${statusHTML}</td>`;

            if (staff.status == 1) {
                row += `
                    <td><span class="edit-icon" data-id="${staff.unique_id}">✏️</span></td>
                    <td><span class="delete-icon" data-id="${staff.unique_id}">🗑️</span></td>`;
            } else {
                row += `
                    <td colspan="2" style="text-align:center;">
                        <span class="restore-icon" data-id="${staff.unique_id}">↻ Restore</span>
                    </td>`;
            }

            return row;
        },

        // === Populate Edit Modal ===
        populateDetails: function (staff) {
            const body = document.querySelector("#staffDetailsTable tbody");
             const photoUrl = `../backend/staffs/admin_photos/${staff.photo}`;

            body.innerHTML = `
                <tr>
            <td colspan="2" style="text-align:center;">
                <img 
                    src="${photoUrl}" 
                    alt="Staff Photo" 
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
                    <td><strong>Staff Code</strong></td>
                    <td><input type="text" id="edit_unique_id" value="${staff.unique_id}" readonly></td>
                </tr>

                <tr>
                    <td><strong>First Name</strong></td>
                    <td><input type="text" id="edit_firstname" value="${staff.firstname}"></td>
                </tr>

                <tr>
                    <td><strong>Last Name</strong></td>
                    <td><input type="text" id="edit_lastname" value="${staff.lastname}"></td>
                </tr>

                <tr>
                    <td><strong>Email</strong></td>
                    <td><input type="email" id="edit_email" value="${staff.email}"></td>
                </tr>

                <tr>
                    <td><strong>Phone</strong></td>
                    <td><input type="text" id="edit_phone" value="${staff.phone}"></td>
                </tr>

                <tr>
                    <td><strong>Address</strong></td>
                    <td><textarea id="edit_address">${staff.address}</textarea></td>
                </tr>

                <tr>
                    <td><strong>Gender</strong></td>
                    <td>
                        <select id="edit_gender">
                            <option value="Male" ${staff.gender == "Male" ? "selected" : ""}>Male</option>
                            <option value="Female" ${staff.gender == "Female" ? "selected" : ""}>Female</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <td><strong>Status</strong></td>
                    <td>
                        <select id="edit_status">
                            <option value="1" ${staff.status == 1 ? "selected" : ""}>Active</option>
                            <option value="0" ${staff.status == 0 ? "selected" : ""}>Inactive</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <td><strong>Enter Secret Answer for Authorization</strong></td>
                   
                        <td><input type="password" id="secret_answer"></td>
               
                    </td>
                </tr>

                <tr>
                    <td colspan="2" style="text-align:center;">
                        <button id="updateStaffBtn" class="btn-primary">Update Staff</button>
                    </td>
                </tr>
            `;

            // Reset event listener to avoid duplicates
            const updateButton = document.getElementById("updateStaffBtn");
            updateButton.replaceWith(updateButton.cloneNode(true));

            document.getElementById("updateStaffBtn").addEventListener("click", () => {
                UI.confirm("Are you sure you want to update this staff?", () => {
                    this.updateItem(staff.unique_id, {
                        unique_id: staff.unique_id,
                        firstname: document.getElementById("edit_firstname").value,
                        lastname: document.getElementById("edit_lastname").value,
                        email: document.getElementById("edit_email").value,
                        phone_number: document.getElementById("edit_phone").value,
                        address: document.getElementById("edit_address").value,
                        gender: document.getElementById("edit_gender").value,
                        status: document.getElementById("edit_status").value,
                        secret_answer: document.getElementById("secret_answer").value,
                        action_type: "update_all"
                    });
                });
            });
        },

        // === Custom Initialization ===
        onInit: function () {
            window.staffManager = this;

            // Load initial data
            // this.fetchData();

            // Handle photo preview
            const photoInput = document.getElementById("staffPhoto");
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

            console.log("Staff Manager initialized successfully");
        }
    });

    window.agm = staffManager; // expose for debugging
    console.log('Staff Manager instance created:', staffManager);
});

