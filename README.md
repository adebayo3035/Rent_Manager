
---

# 🏠 Rent Manager

A lightweight, secure, and efficient system for managing rental properties, tenants, payments, and property types.
This project is built with **PHP**, **MySQL**, **HTML**, **CSS**, and **JavaScript**, using a clean modular backend architecture and responsive frontend UI.

---

## 📌 Features

✔️ Property Type Management (Add, Edit, Delete, Restore)
✔️ Tenant & Agent Management
✔️ Secure Authentication with Session Handling
✔️ Role-Based Access Control (Super Admin / Staff)
✔️ Server-Side Validation & Sanitization
✔️ Rate Limiting & IP Logging for Security
✔️ Centralized Logging System (`logActivity()`)
✔️ JSON-based REST API Endpoints
✔️ Frontend UI with Modals for CRUD Operations
✔️ Soft Delete + Restore Functionality
✔️ Pagination, Search, and Filtering Support

---

## 🏗️ Tech Stack

| Layer               | Technology                                 |
| ------------------- | ------------------------------------------ |
| **Backend**         | PHP (Procedural + Modular Structure)       |
| **Database**        | MySQL                                      |
| **Frontend**        | HTML, CSS, Vanilla JavaScript              |
| **Security**        | Session Auth, Rate Limiting, Activity Logs |
| **Version Control** | Git + GitHub                               |

---

## 📁 Project Structure

```
Rent_Manager/
│
├── api/
│   ├── agents/
│   │   ├── get_agents.php
│   │   ├── update_agent.php
│   │   └── create_agent.php
│   ├── properties/
│   │   ├── get_property_types.php
│   │   ├── add_property_type.php
│   │   ├── update_property_type.php
│   │   └── delete_or_restore.php
│   ├── auth/
│   │   ├── login.php
│   │   └── logout.php
│   └── ...
│
├── utilities/
│   ├── config.php
│   ├── auth_utils.php
│   ├── utils.php
│   └── rate_limit.php
│
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
│
├── pages/
│   ├── dashboard.php
│   ├── property_types.php
│   ├── agents.php
│   └── login.php
│
├── .gitignore
├── README.md
└── index.php
```

---

## ⚙️ Installation & Setup

### 1️⃣ Clone the Repository

```sh
git clone https://github.com/adebayo3035/Rent_Manager.git
cd Rent_Manager
```

### 2️⃣ Configure Database

* Create a MySQL database
* Import `/database/rent_manager.sql` (if available)
* Update credentials in:

```
utilities/config.php
```

Example:

```php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "rent_manager";
```

### 3️⃣ Start Local Server

If using XAMPP/WAMP, place the project in:

```
htdocs/ (XAMPP)
www/     (WAMP)
```

Then visit:

```
http://localhost/Rent_Manager
```

---

## 🔐 Security Features

### ✔ IP Logging

Every request logs the device IP using `getClientIP()`.

### ✔ Rate Limiting

All sensitive endpoints include:

```php
rateLimit("update_agent", 20, 60);
```

Prevents brute force + request flooding.

### ✔ Input Sanitization

All JSON inputs pass through:

```php
sanitize_inputs()
```

### ✔ Role-Based Access

Certain actions only Super Admins can perform:

* Restore
* Delete
* Deactivate users

---

## 📡 API Endpoints (Examples)

### ➤ Update Agent

```
POST /api/agents/update_agent.php
```

Payload:

```json
{
  "agent_code": "AG1234",
  "firstname": "John",
  "lastname": "Doe",
  "email": "johndoe@mail.com",
  "phone": "07012345678",
  "address": "Lekki Phase 1",
  "gender": "Male",
  "status": 1,
  "action_type": "update_all"
}
```

---

## 👥 User Roles

| Role            | Permissions                         |
| --------------- | ----------------------------------- |
| **Super Admin** | Full Access (CRUD, Delete, Restore) |
| **Staff**       | Limited Update Rights               |

---

## 🚀 Deployment Notes

* Disable display_errors in production
* Enable HTTPS
* Use stronger session settings
* Ensure `logs/` folder is not public

---

## 🤝 Contributing

1. Fork the repo
2. Create a feature branch
3. Commit changes
4. Submit a Pull Request

---

## 📜 License

This project is proprietary and owned by **Adebayo Abdul-Rahmon.**
No redistribution allowed without permission.

---

## 💬 Support

For any issues or requests, open an Issue in the repo or contact the maintainer.

