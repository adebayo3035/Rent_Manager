
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

# Wallet Service Documentation

I'll give you a complete, drop-in `WALLET.md` file. It's structured so you can hand it to a future developer (or your future self) and they'll understand the whole system in one sitting.

Save it at the project root: `WALLET.md`.

---

```markdown
# Wallet Service Documentation

> **Last updated:** 2026-10-27
> **Maintained by:** Backend team
> **Status:** Production — feature complete for tenants, clients, admins (approvals), and admin self-service

---

## Table of Contents

1. [Overview](#overview)
2. [User Types & Their Wallets](#user-types--their-wallets)
3. [Wallet Invariants](#wallet-invariants)
4. [Database Schema](#database-schema)
5. [Endpoint Map](#endpoint-map)
6. [Request Lifecycle Flows](#request-lifecycle-flows)
7. [Security Model](#security-model)
8. [Transaction Type Reference](#transaction-type-reference)
9. [Reconciliation Queries](#reconciliation-queries)
10. [Testing Checklist](#testing-checklist)
11. [Known Limitations & Deferred Features](#known-limitations--deferred-features)
12. [Operational Playbook](#operational-playbook)

---

## Overview

The wallet service handles all money movement internal to the platform. Every naira that enters or leaves the system — whether it's a rent payment, a security deposit refund, a settlement distribution, or a manual adjustment — passes through one of the four wallet tables and leaves an immutable ledger trail.

### Design principles

1. **Money is never a number on a screen.** Every balance change is backed by a `*_wallet_transactions` row. The `wallet_balance` column on the master table (e.g. `clients`) is a **cache** of the sum of those rows — if they ever diverge, that's a bug.

2. **All money movements are transactional.** Every endpoint that touches a balance wraps its work in `begin_transaction()` / `commit()` / `rollback()`. If any step fails, the whole operation is undone.

3. **Admins are the trust root for tenant/client money.** Tenant and client withdrawals require admin approval. Admins withdraw from their own wallet without an approval queue — no admin is going to approve another admin's payout in a small platform.

4. **Audit trail is permanent.** Ledger rows are never deleted, only superseded. Bank history is preserved across changes. Every state transition has a timestamp, actor, and reason.

5. **Defense in depth.** Session-scoped queries, CSRF tokens, role checks, secret-answer lockouts, bank uniqueness, cooldowns — multiple independent controls stand between any single point of failure and money leaving the platform.

### What the wallet is NOT

- **Not a payment processor.** The wallet does not initiate real bank transfers. It records what the platform has done (or is about to do) via manual bank transfers.
- **Not a lending product.** No negative balances, no overdrafts, no credit.
- **Not a money-transfer service.** Users cannot send money to each other through the wallet. All transfers route through the platform's bank account.
- **Not multi-currency.** Nigerian Naira (₦) only.

---

## User Types & Their Wallets

The four wallet types work fundamentally differently because the underlying relationships differ.

### Tenant Wallet

| Aspect | Detail |
|---|---|
| **What is it?** | A **refund account** — money the platform holds on the tenant's behalf |
| **Source of credits** | Evacuation refunds, deposit releases, overpayment reversals, admin adjustments |
| **Source of debits** | Withdrawals (admin-approved) |
| **Can tenant top up?** | **No.** A tenant should never add money to their wallet — rent is paid directly to the platform's bank account |
| **Can tenant withdraw?** | Yes, via 4-step flow with secret-answer auth + admin approval |
| **Balance meaning** | What the platform owes the tenant at end of tenancy |

### Client (Landlord) Wallet

| Aspect | Detail |
|---|---|
| **What is it?** | An **earnings account** — the client's rental income and prepaid credits |
| **Source of credits** | Settlement shares (rent credits), deposit releases, top-ups, admin adjustments |
| **Source of debits** | Withdrawals (admin-approved) |
| **Can client top up?** | **Yes**, via 3-step flow with proof of bank transfer + admin approval |
| **Can client withdraw?** | Yes, same flow as tenant |
| **Balance meaning** | The client's earned (but not yet withdrawn) income |

### Admin Wallet

| Aspect | Detail |
|---|---|
| **What is it?** | An **earnings account** — the admin's share of every settlement |
| **Source of credits** | Settlement shares (10% default), admin adjustments |
| **Source of debits** | Admin self-service withdrawals |
| **Can admin top up?** | No — earnings only |
| **Can admin withdraw?** | Yes, immediate debit (no approval queue), password-authenticated |
| **Balance meaning** | Cash the platform owes the admin |

### Agent Wallet

| Aspect | Detail |
|---|---|
| **What is it?** | A **commission account** — the agent's share of every settlement on their properties |
| **Source of credits** | Settlement shares (5% default), admin adjustments |
| **Source of debits** | Agent self-service withdrawals |
| **Can agent top up?** | No — commission only |
| **Can agent withdraw?** | Not built yet (deferred — see [Known Limitations](#known-limitations--deferred-features)) |

### Summary matrix

| | Tenant | Client | Admin | Agent |
|---|---|---|---|---|
| Top-up allowed? | ❌ | ✅ | ❌ | ❌ |
| Withdrawal flow | 4 steps + admin approval | 4 steps + admin approval | Immediate, no approval | (Not built) |
| Auth for withdrawal | Secret answer | Secret answer | Password | (TBD) |
| Approval required? | ✅ | ✅ | ❌ | (TBD) |
| Debit timing | At request | At request | At withdrawal | (TBD) |

---

## Wallet Invariants

These are the rules the system depends on. If you change any of them, you must:

1. Update the affected endpoints
2. Write a migration for existing data
3. Update this documentation

**Never break these silently.**

### Invariant 1: `status` is always explicit

Every `INSERT` into any `*_wallet_transactions` table sets `status` explicitly in the column list. Never relies on the column default.

| Row type | Status on insert |
|---|---|
| Withdrawal debit (tenant/client) | `'Pending'` |
| Withdrawal debit (admin/agent) | `'Approved'` |
| Top-up credit | `'Approved'` |
| Reversal (rejection refund) | `'Approved'` |
| Settlement credit | `'Approved'` |
| Admin adjustment | `'Approved'` |

### Invariant 2: Money movement is transactional

Every money-movement endpoint wraps its work in `begin_transaction()` … `commit()` with a `catch (Throwable $e) { rollback(); }` block. No money changes outside a transaction.

### Invariant 3: Withdrawal debit timing differs by user type

- **Tenant / Client**: debited at **request time** (reserve-then-approve). The money leaves the wallet immediately when the request is submitted. If rejected, it's refunded.
- **Admin / Agent**: debited at **approval time** (which is immediate). The wallet IS the bank; there's no reservation window.

**Why?** Because tenant/client wallets hold *their* money (platform holds in trust) — if you don't debit at request, they could request the same balance twice. Admin/agent wallets hold *their own* earnings — no risk of double-spending their own balance.

### Invariant 4: Every money-movement request has a matching ledger row

Every `wallet_requests` row that involves money (any top-up or withdrawal, regardless of status) must have at least one matching row in the appropriate `*_wallet_transactions` table, joined by `request_id = request_code`.

### Invariant 5: State transitions are one-way

A withdrawal request starts at `'pending'`. From there, exactly one of these transitions occurs:

- `'pending'` → `'approved'` (admin confirms)
- `'pending'` → `'rejected'` (admin declines with reason)
- `'pending'` → `'cancelled'` (self-cancel — not yet built)

The `UPDATE` statements include `WHERE status = 'pending'`, which means re-attempting any transition is a no-op (`affected_rows = 0`), which throws a 409. **Idempotency is enforced at the SQL layer.**

### Invariant 6: Secret-answer lockout is shared per user type

A user's failed secret-answer attempts count toward a single shared lockout across **all** wallet endpoints of that user type. A tenant who fails 3 times on `save_bank_account` is also locked out of `request_withdrawal` and `change_bank_account`.

Lockout state lives in:
- `client_secret_attempts` (for clients)
- `tenant_secret_attempts` (for tenants)

Fields: `attempts` (int), `locked_until` (datetime).

Rule: 3 failed attempts → 15-minute lockout → auto-reset on expiry or successful attempt.

### Invariant 7: Bank account uniqueness is per-user-type, not global

- A bank account used by Client A **cannot** be saved by Client B → 409
- A bank account used by Tenant A **cannot** be saved by Tenant B → 409
- A bank account used by a **Client can also be used by a Tenant** (no cross-type check)

**Why?** Different business entities. A landlord and their tenant might legitimately be the same legal person (self-rental, family property). Cross-type uniqueness would create false positives.

Enforcement tables: `client_bank_history`, `tenant_bank_history`. Every claim is recorded; every subsequent claim by a *different* user of the same type is rejected.

### Invariant 8: Master balance = sum of ledger rows

For each user, the `wallet_balance` column in the master table (`clients`, `tenants`, `admin_tbl`, `agents`) equals the sum of all `amount` values in that user's ledger table.

If this diverges, a bug was introduced. See [Reconciliation Queries](#reconciliation-queries).

### Invariant 9: `running_balance` matches master balance after each transaction

Every ledger row's `running_balance` is the master table's `wallet_balance` **immediately after** that transaction completes. This makes the ledger readable top-to-bottom as a statement, and lets you verify any historical balance by looking at the most recent ledger row before a given timestamp.

### Invariant 10: Bank history preserves every change

Every change to `clients.bank_account_number` or `tenants.bank_account_number` inserts a new row into the corresponding `*_bank_history` table with `is_current = 1`, and marks the previous current row `is_current = 0` with `removed_at = NOW()`.

Rows are never deleted.

### Invariant 11: All wallet endpoints require authentication

Every endpoint under `*/backend/wallet/` calls `requireAuth()` with:
- Session check
- Rate limit
- CSRF token validation (for POST)
- Role validation (only that user type can access their own endpoints)

Query parameters never include user identity — always read from `$_SESSION['tenant_code']`, `$_SESSION['client_code']`, or `$auth['user_id']`.

---

## Database Schema

### Master wallet tables

Each master table has a `wallet_balance` column and (for tenant/client) bank details.

```sql
-- clients.wallet_balance          DECIMAL(14,2)  -- earnings
-- clients.settlement_balance      DECIMAL(14,2)  -- legacy counter
-- clients.total_settlement_earned DECIMAL(14,2)  -- lifetime earnings
-- clients.bank_name               VARCHAR(100)
-- clients.bank_code               VARCHAR(10)
-- clients.bank_account_name       VARCHAR(100)
-- clients.bank_account_number     VARCHAR(20)
-- clients.bank_changed_at         DATETIME

-- tenants.wallet_balance          DECIMAL(14,2)  -- refunds
-- tenants.bank_name               VARCHAR(100)
-- tenants.bank_code               VARCHAR(10)
-- tenants.bank_account_name       VARCHAR(100)
-- tenants.bank_account_number     VARCHAR(20)
-- tenants.bank_changed_at         DATETIME

-- admin_tbl.wallet_balance        DECIMAL(14,2)  -- settlement earnings
-- admin_tbl.settlement_balance    DECIMAL(14,2)
-- admin_tbl.total_settlement_earned DECIMAL(14,2)

-- agents.wallet_balance           DECIMAL(14,2)  -- commission earnings
-- agents.settlement_balance       DECIMAL(14,2)
-- agents.total_settlement_earned  DECIMAL(14,2)
```

### Ledger tables

Four ledger tables, one per user type. Structurally identical.

```sql
CREATE TABLE {user_type}_wallet_transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    {code_column}   VARCHAR(50) NOT NULL,        -- tenant_code / client_code / unique_id (admin) / agent_code
    transaction_type ENUM(...) NOT NULL,
    amount          DECIMAL(14,2) NOT NULL,       -- signed; positive = credit, negative = debit
    running_balance DECIMAL(15,2) NOT NULL,
    request_id      VARCHAR(50) NULL,             -- links to wallet_requests.request_code
    status          ENUM('Failed','Approved','Rejected','Pending') NOT NULL DEFAULT 'Approved',
    notes           TEXT NULL,
    created_by      INT NULL,                     -- admin_id who performed the action
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_{code_column} ({code_column}),
    KEY idx_request (request_id),
    KEY idx_created (created_at)
);
```

### `wallet_requests` — the queue

```sql
CREATE TABLE wallet_requests (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    request_code        VARCHAR(50) UNIQUE NOT NULL,   -- 'WRQ_XXXXXX' or 'CWD_XXXXXX' or 'CWP_XXXXXX'
    user_type           ENUM('tenant','client') NOT NULL,
    user_code           VARCHAR(50) NOT NULL,
    request_type        ENUM('topup','withdrawal') NOT NULL,
    amount              DECIMAL(14,2) NOT NULL,
    status              ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    reference_number    VARCHAR(50) NULL,               -- bank reference for top-ups
    bank_proof_path     VARCHAR(255) NULL,              -- uploaded proof file for top-ups
    bank_proof_notes    TEXT NULL,
    bank_name           VARCHAR(100) NULL,
    bank_account_name   VARCHAR(100) NULL,
    bank_account_number VARCHAR(20) NULL,
    rejection_reason    TEXT NULL,
    notes               TEXT NULL,
    approved_by         INT NULL,
    approved_at         DATETIME NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_type, user_code),
    KEY idx_status (status),
    KEY idx_created (created_at)
);
```

### Bank history tables

```sql
-- Both structurally identical
CREATE TABLE {user_type}_bank_history (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    {code_column}  VARCHAR(50) NOT NULL,
    bank_code      VARCHAR(10) NOT NULL,
    account_number VARCHAR(20) NOT NULL,
    account_name   VARCHAR(100) NOT NULL,
    is_current     TINYINT(1) NOT NULL DEFAULT 1,
    added_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    removed_at     DATETIME NULL,
    KEY idx_{code_column} ({code_column}),
    KEY idx_account (bank_code, account_number),
    KEY idx_current (is_current)
);
```

### Lockout tables

```sql
-- One per user type (tenant, client)
CREATE TABLE {user_type}_secret_attempts (
    {code_column} VARCHAR(50) PRIMARY KEY,
    attempts      INT NOT NULL DEFAULT 0,
    locked_until  DATETIME NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Settlement linkage

```sql
-- Settlement transactions reference ledger rows for idempotency
ALTER TABLE settlement_transactions
  ADD UNIQUE KEY uniq_tracker_settlement (tracker_id);
-- This guarantees at most one settlement per rent_payment_tracker row.
```

---

## Endpoint Map

### Tenant wallet (`tenant/backend/wallet/`)

| Endpoint | Method | Purpose | Auth |
|---|---|---|---|
| `get_balance.php` | GET | Balance + filtered transactions + pagination | Session |
| `get_banks.php` | GET | Bank list + user identity for autofill | Session |
| `inquiry_beneficiary.php` | POST | Verify saved bank matches submitted | Session + CSRF |
| `save_bank_account.php` | POST | First-time bank setup | Session + CSRF + secret |
| `change_bank_account.php` | POST | Change bank (cooldown + uniqueness) | Session + CSRF + secret |
| `request_withdrawal.php` | POST | Submit withdrawal request | Session + CSRF + secret + approval |
| `get_transaction_detail.php` | GET | Single transaction + audit trail | Session |
| `get_bank_history.php` | GET | Bank account history | Session |

### Client wallet (`client/backend/wallet/`)

All tenant endpoints, plus:

| Endpoint | Method | Purpose | Auth |
|---|---|---|---|
| `get_topup_destination.php` | POST | Platform bank account + suggested reference | Session + CSRF |
| `topup_initiate.php` | POST | Submit top-up request with proof | Session + CSRF |

### Admin wallet — approvals (`admin/backend/wallet/`)

| Endpoint | Method | Purpose | Auth |
|---|---|---|---|
| `report.php` | GET | Unified reporting: inflows, outflows, ledger | Admin role |
| `list_requests.php` | GET | (Legacy) list pending requests | Admin role |
| `request_detail.php` | GET | Single request + full audit trail | Admin role |
| `approve_topup.php` | POST | Credit wallet, mark approved | Admin + CSRF |
| `approve_withdrawal.php` | POST | Flip ledger to Approved | Admin + CSRF |
| `reject_request.php` | POST | Reject + refund (withdrawals only) | Admin + CSRF |
| `get_proof.php` | GET | Serve uploaded proof file | Admin |

### Admin wallet — self-service

**Status: NOT YET BUILT** — see [Known Limitations](#known-limitations--deferred-features).

Planned:
- `get_admin_wallet.php`
- `save_admin_bank.php`
- `withdraw_funds.php`
- `reconcile.php`

### Agent wallet

**Status: NOT YET BUILT** — see [Known Limitations](#known-limitations--deferred-features).

### Shared utilities (`utilities/`)

| File | Purpose |
|---|---|
| `wallet_security.php` | Secret-answer lockout, bank uniqueness, pending counter |
| `reference_helper.php` | Deterministic top-up reference generation |

### Frontend pages

| Page | Files | Purpose |
|---|---|---|
| Tenant wallet | `tenant/scripts/wallet.js`, `tenant/css/wallet.css` | Transactions + withdrawals + bank history |
| Client wallet | `client/scripts/wallet.js`, `client/css/wallet.css` | Transactions + withdrawals + top-ups + bank history |
| Admin reporting | `admin/wallet_requests_v2.php`, `admin/scripts/wallet_requests_v2.js`, `admin/css/wallet_requests_v2.css` | Inflows/outflows/ledger with filters |

---

## Request Lifecycle Flows

### Client top-up flow

```
┌───────────────────────────────────────────────────────────┐
│ 1. Client clicks "Top Up"                                 │
│ 2. GET /client/backend/wallet/get_topup_destination.php   │
│    → Platform bank details + suggested reference          │
│ 3. Client transfers money to platform's bank              │
│ 4. Client uploads proof + amount + reference              │
│ 5. POST /client/backend/wallet/topup_initiate.php         │
│    → Creates wallet_requests row, status='pending'        │
│    → NO wallet change yet                                 │
│ 6. Admin reviews proof in /admin/wallet_requests_v2.php   │
│ 7. Admin clicks "Approve"                                 │
│ 8. POST /admin/backend/wallet/approve_topup.php           │
│    ┌────────────────────────────────────────────┐         │
│    │ BEGIN TRANSACTION                          │         │
│    │  UPDATE clients SET wallet_balance += amt  │         │
│    │  INSERT client_wallet_transactions          │         │
│    │   (transaction_type='topup',                │         │
│    │    status='Approved',                       │         │
│    │    request_id=request_code)                 │         │
│    │  UPDATE wallet_requests SET status='approved'│        │
│    │ COMMIT                                     │         │
│    └────────────────────────────────────────────┘         │
│ 9. Client notified: "Your wallet was credited ₦X"         │
└───────────────────────────────────────────────────────────┘
```

### Tenant/client withdrawal flow

```
┌───────────────────────────────────────────────────────────┐
│ 1. User clicks "Withdraw"                                 │
│ 2. Bank must be on file (else setup flow first)           │
│ 3. User enters amount + secret answer                     │
│ 4. POST /{user}/backend/wallet/request_withdrawal.php     │
│    ┌────────────────────────────────────────────┐         │
│    │ BEGIN TRANSACTION                          │         │
│    │  Lock user row (FOR UPDATE)                │         │
│    │  Verify bank matches saved                 │         │
│    │  Verify secret (with lockout check)        │         │
│    │  Check balance sufficient                  │         │
│    │  Block if pending withdrawal exists        │         │
│    │  UPDATE wallet_balance -= amount           │         │
│    │  INSERT ledger row                         │         │
│    │   (transaction_type='withdrawal',          │         │
│    │    status='Pending',                       │         │
│    │    request_id=request_code)                │         │
│    │  INSERT wallet_requests row                │         │
│    │   (status='pending')                       │         │
│    │  Notify admins                             │         │
│    │ COMMIT                                     │         │
│    └────────────────────────────────────────────┘         │
│ 5. Admin reviews in /admin/wallet_requests_v2.php         │
│ 6. Admin makes the bank transfer manually                 │
│ 7. Admin clicks "Mark as Paid"                            │
│ 8. POST /admin/backend/wallet/approve_withdrawal.php      │
│    ┌────────────────────────────────────────────┐         │
│    │ BEGIN TRANSACTION                          │         │
│    │  UPDATE ledger row status='Approved'       │         │
│    │   WHERE request_id=X AND status='Pending'  │         │
│    │  UPDATE wallet_requests SET status='approved'│        │
│    │ COMMIT                                     │         │
│    └────────────────────────────────────────────┘         │
│ 9. User notified: "Your withdrawal was processed"         │
└───────────────────────────────────────────────────────────┘
```

### Rejection flow (withdrawal)

```
┌───────────────────────────────────────────────────────────┐
│ Admin clicks "Reject" on a pending withdrawal             │
│ POST /admin/backend/wallet/reject_request.php             │
│    ┌────────────────────────────────────────────┐         │
│    │ BEGIN TRANSACTION                          │         │
│    │  Lock user row (FOR UPDATE)                │         │
│    │  UPDATE wallet_balance += amount (refund)  │         │
│    │  INSERT reversal ledger row                │         │
│    │   (transaction_type='admin_adjustment',    │         │
│    │    amount=+amt, status='Approved')         │         │
│    │  UPDATE original withdrawal row            │         │
│    │   SET status='Rejected'                    │         │
│    │  UPDATE wallet_requests SET status='rejected'│        │
│    │  Notify user with rejection reason         │         │
│    │ COMMIT                                     │         │
│    └────────────────────────────────────────────┘         │
└───────────────────────────────────────────────────────────┘
```

### Settlement flow (background — fires from rent verification)

```
When a tenant rent payment is verified:
1. Admin approves tracker via admin_verify_rent_payment.php
2. Settlement is created (settlement_transactions row)
3. Three ledger credits are inserted, one per party:
   ┌────────────────────────────────────────┐
   │ BEGIN TRANSACTION                      │
   │  INSERT settlement_transactions row    │
   │  UPDATE admin_tbl SET wallet_balance+= │
   │  INSERT admin_wallet_transactions      │
   │    (type='rent_credit', status='Approved')│
   │                                        │
   │  UPDATE clients SET wallet_balance+=   │
   │  INSERT client_wallet_transactions     │
   │                                        │
   │  IF property has agent:                │
   │    UPDATE agents SET wallet_balance+=  │
   │    INSERT agent_wallet_transactions    │
   │ COMMIT                                 │
   └────────────────────────────────────────┘
```

Idempotency: `settlement_transactions` has `UNIQUE(tracker_id)` — a repeated verification attempt hits the constraint → 409 → rollback → no double-credit.

### Bank change flow (tenant + client)

```
1. User clicks "Change bank account"
2. Fills new bank details + current secret answer
3. POST /{user}/backend/wallet/change_bank_account.php
   Checks:
   - Lockout check (before touching anything)
   - No pending withdrawal (would be ambiguous)
   - 24-hour cooldown since last change
   - Secret answer valid (with lockout attempt tracking)
   - Bank not used by another user of same type
   ┌────────────────────────────────────────┐
   │ BEGIN TRANSACTION                      │
   │  UPDATE wallet_requests — no, wait     │
   │  UPDATE tenants/clients SET bank_* = ? │
   │  INSERT into bank_history              │
   │    (new bank, is_current=1)            │
   │  UPDATE old bank_history row           │
   │    SET is_current=0, removed_at=NOW()  │
   │  UPDATE master.bank_changed_at=NOW()   │
   │  Reset secret lockout attempts         │
   │ COMMIT                                 │
   └────────────────────────────────────────┘
```

---

## Security Model

### Authentication layers

| Layer | What it does | Where enforced |
|---|---|---|
| **Session** | PHP session cookie | `requireAuth()` on every endpoint |
| **Role** | User role check (`Tenant`, `Client`, `Admin`, etc.) | `requireAuth(['roles' => [...]])` |
| **CSRF** | Token in POST body, validated against session | `requireAuth(['csrf' => [...]])` |
| **Rate limit** | Per-IP, per-endpoint request counter | `rateLimiter()` |
| **Secret answer** | Secondary auth for money movements | `verifySecretAnswer()` in `wallet_security.php` |
| **Lockout** | Blocks after 3 failed secret attempts | `checkSecretLockout()` |

### Lockout mechanics

**State:** `<userType>_secret_attempts` table.

**Flow on failed attempt:**

```
attempts++
if attempts >= 3:
    locked_until = NOW() + 15 minutes
    log SECURITY event
    INSERT client_lock_history row (for clients)
```

**Flow on successful attempt:**

```
attempts = 0
locked_until = NULL
```

**Flow on subsequent attempt while locked:**

```
IF locked_until > NOW():
    HTTP 429 "Try again in X minutes"
    Do NOT verify secret (prevents timing oracle)
    Do NOT increment attempts (already locked)
```

**Shared across endpoints:** All wallet operations that use the secret answer call `verifySecretAnswer()` which uses the same state. A client who fails 3x on `change_bank` is locked out of `request_withdrawal` too.

### Bank uniqueness enforcement

**Check:** `checkBankAccountAvailability($conn, $bankCode, $accountNo, $excludeClientCode)`

Looks up `client_bank_history` for any row with matching `(bank_code, account_number)` and `client_code != $excludeClientCode`. Returns `['available' => false, 'reason' => '...']` if found.

**Record:** `recordBankClaim($conn, $clientCode, $bankCode, $accountNo, $accountName)` — inserts a new `is_current = 1` row, marks previous current row `is_current = 0`.

**Why history instead of unique constraint on `clients` table:** Because a user can change banks, and later change back. A plain UNIQUE on `(bank_code, account_number)` in `clients` would only catch *current* claims, missing historical ones. The history table preserves all claims.

### Bank-match enforcement

Withdrawal requires the submitted account number + bank code to match the user's *current* saved bank exactly. This prevents:

- Attacker with session hijack redirecting funds to their own account
- User mistyping and requesting withdrawal to the wrong account
- Any form of "change destination at the last minute" attack

The check happens server-side (in `request_withdrawal.php` after the `FOR UPDATE` lock), not client-side. Client-side check is UX only.

### Masking sensitive data

Every log line, every API response, every notification uses `******XXXX` (last 4 digits) for account numbers. Never log the full account number. Never return the full account number except in the one endpoint that legitimately needs it (`get_balance.php` returns the full number to the owner for the withdrawal form pre-fill; no other endpoint returns it).

### CSRF protection

Every POST endpoint uses `requireAuth(['csrf' => ['enabled' => true, 'form_name' => '<form>']])`. The `form_name` scopes tokens — a token for `tenant_withdrawal_form` cannot be used on `admin_wallet_form`.

---

## Transaction Type Reference

Each transaction type has a specific semantic meaning. When you see one in the ledger, you should immediately know what happened.

| Type | Credit/Debit | Meaning | User types |
|---|---|---|---|
| `rent_credit` | Credit | Client's share of a rent settlement | Client |
| `settlement_debit` | Debit | Money moved out for evacuation refund | Client |
| `deposit_release` | Credit | Security deposit released back | Client, Tenant |
| `refund_credit` | Credit | Refund credited from overpayment | Tenant |
| `topup` | Credit | Client added money to their wallet | Client |
| `withdrawal` | Debit | User withdrew money | All |
| `admin_adjustment` | Credit or Debit | Manual correction by admin | All |
| `debt_recovery` | Debit | Money recovered from tenant for damages | Tenant |
| `fee_debit` | Debit | Fee charged to wallet (currently unused) | All |

### Outcome labels (frontend derivation)

The frontend derives a human-readable "Outcome" from `(type, status)`:

| Type + Status | Outcome label |
|---|---|
| `withdrawal` + `Pending` | Awaiting approval |
| `withdrawal` + `Approved` | Completed |
| `withdrawal` + `Rejected` | Rejected → refunded |
| `withdrawal` + `Failed` | Failed |
| `admin_adjustment` (request_id in rejected set) | Reversal of rejected withdrawal |
| `admin_adjustment` (other) | Admin adjustment |
| `rent_credit` | Rent share credited |
| `topup` | Wallet top-up |
| `deposit_release` | Deposit released |
| `debt_recovery` | Debt recovered |
| `settlement_debit` | Settlement debit |

---

## Reconciliation Queries

Run these **monthly** (or after any suspicious activity) to verify data integrity.

### 1. Master balance vs ledger sum

For each user, `wallet_balance` should equal `SUM(ledger.amount)`. Any drift is a bug.

```sql
-- Clients
SELECT c.client_code, c.wallet_balance,
       COALESCE(SUM(t.amount), 0) AS ledger_sum,
       c.wallet_balance - COALESCE(SUM(t.amount), 0) AS drift
FROM clients c
LEFT JOIN client_wallet_transactions t ON t.client_code = c.client_code
GROUP BY c.client_code, c.wallet_balance
HAVING ABS(c.wallet_balance - COALESCE(SUM(t.amount), 0)) > 0.01;

-- Tenants
SELECT t.tenant_code, t.wallet_balance,
       COALESCE(SUM(tx.amount), 0) AS ledger_sum,
       t.wallet_balance - COALESCE(SUM(tx.amount), 0) AS drift
FROM tenants t
LEFT JOIN tenant_wallet_transactions tx ON tx.tenant_code = t.tenant_code
GROUP BY t.tenant_code, t.wallet_balance
HAVING ABS(t.wallet_balance - COALESCE(SUM(tx.amount), 0)) > 0.01;

-- Admins
SELECT a.unique_id, a.wallet_balance,
       COALESCE(SUM(tx.amount), 0) AS ledger_sum,
       a.wallet_balance - COALESCE(SUM(tx.amount), 0) AS drift
FROM admin_tbl a
LEFT JOIN admin_wallet_transactions tx ON tx.unique_id = a.unique_id
GROUP BY a.unique_id, a.wallet_balance
HAVING ABS(a.wallet_balance - COALESCE(SUM(tx.amount), 0)) > 0.01;

-- Agents
SELECT a.agent_code, a.wallet_balance,
       COALESCE(SUM(tx.amount), 0) AS ledger_sum,
       a.wallet_balance - COALESCE(SUM(tx.amount), 0) AS drift
FROM agents a
LEFT JOIN agent_wallet_transactions tx ON tx.agent_code = a.agent_code
GROUP BY a.agent_code, a.wallet_balance
HAVING ABS(a.wallet_balance - COALESCE(SUM(tx.amount), 0)) > 0.01;
```

**Expected result:** 0 rows for all four queries. If any rows appear, the `wallet_balance` for those users was changed outside the ledger (manual SQL update, or a bug).

### 2. Stale pending withdrawals

Pending withdrawals older than 30 days are likely abandoned or missed.

```sql
SELECT wr.request_code, wr.user_type, wr.user_code, wr.amount, wr.created_at,
       DATEDIFF(NOW(), wr.created_at) AS days_pending
FROM wallet_requests wr
WHERE wr.status = 'pending' AND wr.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY wr.created_at ASC;
```

Review these manually — either approve, reject, or ask the user to re-submit.

### 3. Withdrawal rows stuck in Pending

Every `status = 'Pending'` withdrawal row should have a matching `wallet_requests` row with `status = 'pending'` OR `status = 'rejected'` (if the ledger update failed) OR `status = 'approved'` (if the ledger update failed on approval).

```sql
SELECT tx.id, tx.client_code, tx.amount, tx.request_id, tx.created_at,
       wr.status AS request_status
FROM client_wallet_transactions tx
LEFT JOIN wallet_requests wr ON wr.request_code = tx.request_id
WHERE tx.transaction_type = 'withdrawal'
  AND tx.status = 'Pending'
  AND (wr.status IS NULL OR wr.status NOT IN ('pending'));
```

Any row returned here is an orphaned ledger entry — the request either doesn't exist or is resolved but the ledger never flipped. Investigate.

### 4. Duplicate settlement check

```sql
SELECT tracker_id, COUNT(*) AS cnt
FROM settlement_transactions
GROUP BY tracker_id
HAVING cnt > 1;
```

Should always return 0 rows — the unique constraint should prevent this. If it returns rows, the constraint was dropped.

### 5. Bank history vs master bank

Every user with a bank should have exactly one `is_current = 1` history row matching their master bank.

```sql
-- Clients
SELECT c.client_code, c.bank_code, c.bank_account_number
FROM clients c
LEFT JOIN client_bank_history h 
  ON h.client_code = c.client_code 
  AND h.is_current = 1 
  AND h.bank_code = c.bank_code 
  AND h.account_number = c.bank_account_number
WHERE c.bank_account_number IS NOT NULL 
  AND c.bank_account_number <> ''
  AND h.id IS NULL;
```

Same query pattern for tenants with `tenant_bank_history`. Any row means the master table has a bank that isn't in the history — a backfill was missed or a change was made outside the flow.

---

## Testing Checklist

Run these tests after any change to the wallet service.

### Basic flows

- [ ] Client submits top-up → request created, wallet unchanged
- [ ] Admin approves top-up → wallet credited, ledger row inserted, client notified
- [ ] Admin rejects top-up → request marked rejected, wallet unchanged, client notified
- [ ] Tenant requests withdrawal → wallet debited, ledger row Pending, request created
- [ ] Admin approves withdrawal → ledger row Approved, request approved, tenant notified
- [ ] Admin rejects withdrawal → wallet refunded, reversal row inserted, original row Rejected, tenant notified
- [ ] Client requests withdrawal → same as tenant
- [ ] Client changes bank → master bank updated, history row inserted, 24h cooldown set
- [ ] Tenant changes bank → same
- [ ] Client/tenant first-time bank save → allowed, history row created

### Security

- [ ] Wrong secret 3 times → 429 lockout for 15 minutes
- [ ] During lockout, correct secret still rejected with 429
- [ ] After lockout expires, correct secret works → attempts reset
- [ ] Lockout state shared across save_bank, change_bank, request_withdrawal
- [ ] Attempting to use bank account owned by another client → 409
- [ ] Attempting to use bank account owned by another tenant → 409
- [ ] Attempting withdrawal with wrong account number → 400
- [ ] Attempting withdrawal with wrong bank code → 400
- [ ] Attempting change bank within 24h of previous change → 429
- [ ] Attempting change bank with pending withdrawal → 409
- [ ] Session expired → 401
- [ ] Missing CSRF token → 403
- [ ] Wrong role → 403

### Idempotency & races

- [ ] Approve same withdrawal twice → second is 400 "already approved"
- [ ] Reject same withdrawal twice → second is 400 "already rejected"
- [ ] Approve after reject → 400 "already rejected"
- [ ] Reject after approve → 400 "already approved"
- [ ] Two admins approve simultaneously → first succeeds, second blocks on FOR UPDATE, sees approved status, returns 400
- [ ] Two withdrawals submitted simultaneously → second blocks on FOR UPDATE, sees pending request, returns 409

### Data integrity

- [ ] After every operation, master balance = ledger sum (run reconciliation query)
- [ ] `running_balance` on newest ledger row = master balance
- [ ] Every money-moving wallet_request has matching ledger row
- [ ] Settlement: approving same rent tracker twice → second 409, no double-credit

### Frontend

- [ ] Filters work (status, type, search, date range)
- [ ] Pagination works
- [ ] Click transaction row → detail drawer opens with full info
- [ ] Bank tab shows current bank + history
- [ ] Top-up modal: 3-step flow works end-to-end
- [ ] Withdrawal modal: 4-step flow works end-to-end
- [ ] Change bank modal: 2-step flow works end-to-end
- [ ] Mobile viewport (<720px): cards render, no horizontal scroll

### Admin reporting

- [ ] Inflows tab shows top-ups with filters
- [ ] Outflows tab shows withdrawals with filters
- [ ] Ledger tab shows all transactions with user type filter
- [ ] Click any row → detail drawer opens
- [ ] Approve/reject buttons appear only on pending rows
- [ ] Approve/reject actions update the list without full page reload

---

## Known Limitations & Deferred Features

These are **intentionally not built yet**. Each has a rationale.

### 1. Admin self-service wallet — HIGH priority

**Status:** Endpoints not built. Admin can earn settlement shares into `admin_tbl.wallet_balance` but cannot withdraw them.

**Why it's deferred:** Admin wallet was out of scope for the initial build; the settlement flow credits it correctly, but there's no UI or endpoint for admins to view or withdraw.

**What it needs:**
- `get_admin_wallet.php` — view balance, transactions, reconciliations
- `save_admin_bank.php` — one-time bank setup
- `withdraw_funds.php` — self-service withdrawal, password-authenticated
- `reconcile.php` — monthly reconciliation against bank statement

**Design decisions to remember:**
- No approval queue (admin is the trust root)
- Password authentication (not secret answer)
- Threshold for triggering admin approval (if multi-admin later)
- Monthly reconciliation record for audit

**When to build:** As soon as you have real admin earnings to withdraw.

### 2. Agent wallet — MEDIUM priority (only if agents exist)

**Status:** No endpoints.

**Why it's deferred:** If agents don't currently exist in the system, there's nothing to build. When they do, they need a way to view and withdraw commission.

**What it needs:**
- `agent/backend/wallet/get_balance.php`
- `agent/backend/wallet/save_bank_account.php`
- `agent/backend/wallet/change_bank_account.php`
- `agent/backend/wallet/withdraw_funds.php` (self-service, no approval — agents are contractors)

**When to build:** When agents are onboarded to the platform.

### 3. Self-cancel pending withdrawal — MEDIUM priority

**Status:** Not built. Users cannot cancel a pending withdrawal — their only option is to wait for the admin.

**What it needs:**
- `{user}/backend/wallet/cancel_withdrawal.php` — refund wallet, insert reversal, mark request cancelled

**Why it matters:** Admin approval can take hours or days. A user who changes their mind is stuck. It's a UX gap, not a security issue.

**When to build:** When users start complaining.

### 4. Withdrawal history in wallet_requests

**Status:** The "Requests" tab in tenant/client wallet shows only **pending** requests. Resolved requests disappear.

**Why:** Transaction ledger already captures the full picture (approved/rejected rows show up there). The Requests tab is really "pending actions".

**Alternative:** Extend `get_requests.php` to optionally return resolved requests. Not urgent.

### 5. Bank API integration for account verification

**Status:** `inquiry_beneficiary.php` returns the name from your DB, not from a real bank lookup.

**Why it's deferred:**
- Requires NIBSS/NIP provider account (monthly cost)
- Only worth it at scale
- Current flow (user types name, admin visually confirms proof) works

**When to build:** When transfer volume justifies the cost, or when you have multiple admins and want to reduce fraud risk.

### 6. Automated bank feed reconciliation

**Status:** Manual only (`reconcile.php` for admins, planned).

**Why deferred:** Requires a bank API integration + webhook infrastructure.

### 7. Multi-admin approval for withdrawals

**Status:** Every admin can approve any withdrawal; no second-party check.

**Why deferred:** For a single-admin platform, there's no second party. When you have multiple admins with different roles, you may want:
- Threshold-based approval (large amounts require Super Admin)
- Agent-specific approval (agent withdrawals > threshold require admin)

**When to build:** When you have 3+ admins and any of them aren't fully trusted.

### 8. Deliberately NOT going to build

- **Wallet-to-wallet transfers** — makes you a money services business (regulatory)
- **Recurring/scheduled transfers** — same reason; also changes wallet from "balance" to "primary account"
- **Multi-currency** — Naira only, don't add currency columns
- **Real-time balance updates via websockets** — polling is fine
- **In-wallet payments to other users** — all external transfers go through the platform bank

---

## Operational Playbook

### Monthly reconciliation

**First of every month:**

1. Run all five reconciliation queries (see [Reconciliation Queries](#reconciliation-queries))
2. If any drift exists → investigate before doing anything else
3. Review pending withdrawals older than 30 days (query 2)
4. Export the ledger to CSV for accounting (build the export tool if not yet done)
5. Compare `admin_tbl.wallet_balance` + `clients SUM(wallet_balance)` + `tenants SUM(wallet_balance)` against the platform's actual bank balance

**Expected:** Sum of all wallet balances (tenant + client + admin + agent) should equal the platform's **cash liability**. It might differ from the bank account balance (which is the platform's **cash asset**) by the amount held as security deposits or reserves. If the two don't reconcile with your accounting, one of them is wrong.

### Handling a user complaint: "My balance is wrong"

1. Get the user's code (`TNT...` or `CLIENT...`)
2. Run reconciliation for that user:
   ```sql
   SELECT wallet_balance FROM clients WHERE client_code = 'CLIENT...';
   SELECT SUM(amount) AS ledger_sum FROM client_wallet_transactions WHERE client_code = 'CLIENT...';
   ```
3. If they match → the balance is correct; show the user their ledger (via the app, or export it)
4. If they don't match → **STOP.** This is a bug. Do not adjust the balance manually. Investigate:
   - Was a transaction deleted from the ledger? (Should never happen)
   - Was the wallet_balance changed by manual SQL?
   - Did an endpoint fail mid-transaction without rolling back?
5. Fix the root cause, then reconcile.

### Handling "I never received my withdrawal"

1. Look up the request: `SELECT * FROM wallet_requests WHERE request_code = 'WRQ...'`
2. Check `status`:
   - **pending** → not processed yet; check admin queue
   - **approved** → admin marked as paid; verify against bank statement
   - **rejected** → check `rejection_reason`; wallet was refunded
3. Check ledger: `SELECT * FROM {user_type}_wallet_transactions WHERE request_id = 'WRQ...'`
4. If approved but user says no money → compare the reference in `wallet_requests.notes` against bank statement
5. If it's genuinely missing → this is a bank-side issue, not a wallet issue; process a manual refund via `admin_adjustment` (but escalate first — someone needs to understand how this happened)

### Suspected fraud

1. Freeze the user's wallet access: `UPDATE {user_type}s SET has_secret_set = 0 WHERE ...` (forces re-setup before next withdrawal)
2. Review their ledger: `SELECT * FROM {user_type}_wallet_transactions WHERE {code_column} = '...' ORDER BY id DESC LIMIT 50`
3. Check lockout history: `SELECT * FROM {user_type}_secret_attempts WHERE {code_column} = '...'`
4. Check bank history: `SELECT * FROM {user_type}_bank_history WHERE {code_column} = '...' ORDER BY added_at`
5. If confirmed fraud → manual reversal via `admin_adjustment` with detailed notes; escalate
6. Never delete ledger rows — mark them with a reversal + adjustment

### Rotating admin credentials

Admin password hash is stored in `admin_tbl.password`. Any password change should:

1. Invalidate existing sessions
2. Log the change
3. NOT touch wallet access (wallet is protected by password + session; changing password doesn't break wallet, but old sessions become invalid)

---

## Change Log

| Date | Change | Author |
|---|---|---|
| 2026-10-27 | Initial documentation | (Your name) |

---

## Contact

For questions about this system, contact the backend team. If you're reading this and the "Maintained by" field is stale, please update it — the documentation is only useful when someone is responsible for keeping it accurate.
```

---

## Where to save it and how to use it

**Save at project root:**

```
/rent_manager/
├── WALLET.md          ← this file
├── admin/
├── client/
├── tenant/
└── ...
```

**Why root?** Because the wallet spans all modules, it doesn't belong in any one of them. Root is where a new dev will look first.

**Link it from your main `README.md`:**

```markdown
## Documentation

- [Wallet Service](./WALLET.md) — money movement, security model, invariants
- ...
```

**Update it when:**

- You add a new endpoint → add to [Endpoint Map](#endpoint-map)
- You change a security rule → update [Security Model](#security-model)
- You add a transaction type → update [Transaction Type Reference](#transaction-type-reference)
- You discover a new invariant → add to [Wallet Invariants](#wallet-invariants)
- You build a deferred feature → remove from [Known Limitations](#known-limitations--deferred-features)
- You make a data-model change → update [Database Schema](#database-schema)

**What NOT to put in it:**

- Implementation details of specific functions (they live in the code)
- Design decisions that were overruled (if you decided against something, note it once in "Deliberately NOT going to build" and move on)
- TODOs (those go in an issue tracker, or a `TODO.md`)

---

## One last thing — a "cheat sheet" you might print

For your own desk, or the wall, or the first page of a runbook:

```
WALLET QUICK REFERENCE
======================

BALANCE = SUM(ledger.amount)             ✓ must always be true
      ≠ bank account balance             (liability vs asset)

USER TYPES
  Tenant  → refunds only, no top-up
  Client  → earnings + prepaid
  Admin   → settlement earnings
  Agent   → commission

WITHDRAWAL APPROVAL
  Tenant, Client  → requires admin approval (reserve-then-approve)
  Admin, Agent    → no approval (self-service, immediate debit)

LOCKOUT
  3 failed secret attempts → 15 min lock
  Shared across ALL wallet endpoints of that user type

BANK CHANGES
  Cooldown: 24 hours
  Unique per user type (tenant↔tenant, client↔client)
  Never unique across types (tenant↔client allowed)

DATA SAFETY
  Every money movement = transaction (begin / commit / rollback)
  Every INSERT sets status explicitly (never rely on default)
  Every state transition has WHERE status='pending' guard
  Ledger rows are never deleted (only superseded)

SETTLEMENT
  UNIQUE(tracker_id) prevents double-credit
  Retry → 409 → rollback → no double money

RECONCILIATION (run monthly)
  master.wallet_balance = SUM(ledger.amount) for every user
  Any drift = bug, investigate immediately
```

That's your complete documentation package. Ship it, and then build the admin self-service wallet next — it's the one thing on the "deferred" list that has actual users waiting for it.


