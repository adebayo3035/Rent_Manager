// Preview uploaded agent photo
document.getElementById("agentPhoto").addEventListener("change", function (e) {
    const file = e.target.files[0];
    const preview = document.getElementById("photoPreview");

    if (!file) {
        preview.innerHTML = "<span style='font-size:12px;color:#777;'>No image</span>";
        return;
    }

    const reader = new FileReader();
    reader.onload = function (event) {
        preview.innerHTML = `<img src="${event.target.result}" 
                                style="width:100%;height:100%;object-fit:cover;">`;
    };
    reader.readAsDataURL(file);
});

// agent.js
document.addEventListener("DOMContentLoaded", () => {
    const agentManager = new DataManager({

        // === DOM Element IDs ===
        tableId: "agentSummary",
        tableBodyId: "agentSummaryBody",
        modalId: "agentModal",
        addModalId: "addAgentModal",
        formId: "addAgentForm",    // (we will handle manually because you don't have a <form>)
        paginationId: "agentPagination",
        searchInputId: "agentLiveSearch",
        addButtonId: "addNewAgentBtn",

        // === API Endpoints ===
        fetchUrl: "../backend/agents/get_agent.php",
        addUrl: "../backend/agents/agent_onboarding.php",
        updateUrl: "../backend/agents/update_agent.php",
        fetchDetailsUrl: "../backend/agents/fetch_agent_details.php",

        // === Item Definitions ===
        itemName: "agent",
        itemNamePlural: "agents",
        idField: "agent_code",
        statusField: "status",
        detailsKey: "agent_details",

        // === Columns (match HTML header) ===
        columns: [
            {
                field: "agent_code",
                label: "Agent ID",
                render: (item) => `<strong>${item.agent_code}</strong>`
            },
            {
                field: "firstname",
                label: "Agent Name",
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
        renderRow: function (agent, userRole) {
            console.log("UserRole inside renderRow:", userRole);

            const statusHTML =
                agent.status == 1
                    ? `<span style="color: green;">Active</span>`
                    : `<span style="color: red;">Inactive</span>`;

            let row = `
                <td>${agent.agent_code}</td>
                <td>${agent.firstname} ${agent.lastname}</td>
                <td>${agent.email}</td>
                <td>${statusHTML}</td>`;

            if (agent.status == 1) {
                row += `
                    <td><span class="edit-icon" data-id="${agent.agent_code}">✏️</span></td>
                    <td><span class="delete-icon" data-id="${agent.agent_code}">🗑️</span></td>`;
            } else {
                row += `
                    <td colspan="2" style="text-align:center;">
                        <span class="restore-icon" data-id="${agent.agent_code}">↻ Restore</span>
                    </td>`;
            }

            return row;
        },

        // === Populate Edit Modal ===
        populateDetails: function (agent) {
            const body = document.querySelector("#agentDetailsTable tbody");

            body.innerHTML = `
                <tr>
                    <td><strong>Agent Code</strong></td>
                    <td><input type="text" id="edit_agent_code" value="${agent.agent_code}" readonly></td>
                </tr>

                <tr>
                    <td><strong>First Name</strong></td>
                    <td><input type="text" id="edit_firstname" value="${agent.firstname}"></td>
                </tr>

                <tr>
                    <td><strong>Last Name</strong></td>
                    <td><input type="text" id="edit_lastname" value="${agent.lastname}"></td>
                </tr>

                <tr>
                    <td><strong>Email</strong></td>
                    <td><input type="email" id="edit_email" value="${agent.email}"></td>
                </tr>

                <tr>
                    <td><strong>Phone</strong></td>
                    <td><input type="text" id="edit_phone" value="${agent.phone}"></td>
                </tr>

                <tr>
                    <td><strong>Address</strong></td>
                    <td><textarea id="edit_address">${agent.address}</textarea></td>
                </tr>

                <tr>
                    <td><strong>Gender</strong></td>
                    <td>
                        <select id="edit_gender">
                            <option value="male" ${agent.gender == "male" ? "selected" : ""}>Male</option>
                            <option value="female" ${agent.gender == "female" ? "selected" : ""}>Female</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <td><strong>Status</strong></td>
                    <td>
                        <select id="edit_status">
                            <option value="1" ${agent.status == 1 ? "selected" : ""}>Active</option>
                            <option value="0" ${agent.status == 0 ? "selected" : ""}>Inactive</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <td colspan="2" style="text-align:center;">
                        <button id="updateAgentBtn" class="btn-primary">Update Agent</button>
                    </td>
                </tr>
            `;

            // Reset event listener to avoid duplicates
            const updateButton = document.getElementById("updateAgentBtn");
            updateButton.replaceWith(updateButton.cloneNode(true));

            document.getElementById("updateAgentBtn").addEventListener("click", () => {
                UI.confirm("Are you sure you want to update this agent?", () => {
                    this.updateItem(agent.agent_code, {
                        agent_code: agent.agent_code,
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
            window.agentManager = this;

            // Load initial data
            this.fetchData();

            // Handle photo preview
            const photoInput = document.getElementById("agentPhoto");
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

            // // Handle Save Agent Button
            // document.getElementById("saveAgentBtn").addEventListener("click", () => {
            //     const formData = new FormData();
            //     formData.append("firstname", document.getElementById("agentFirstName").value);
            //     formData.append("lastname", document.getElementById("agentLastName").value);
            //     formData.append("email", document.getElementById("agentEmail").value);
            //     formData.append("phone", document.getElementById("agentPhone").value);
            //     formData.append("address", document.getElementById("agentAddress").value);
            //     formData.append("gender", document.getElementById("agentGender").value);
            //     formData.append("photo", document.getElementById("agentPhoto").files[0]);

            //     this.addItem(formData, true); // true = multipart/FormData
            // });

            console.log("Agent Manager initialized successfully");
        }
    });

    window.agm = agentManager; // expose for debugging
    console.log('Agent Manager instance created:', agentManager);
});

