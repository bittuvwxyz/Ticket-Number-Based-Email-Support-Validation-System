# Ticket Number-Based Email Support & Validation System

A production-grade, secure, email-based customer support desk and validation platform engineered with PHP 8.1+, MySQL/MariaDB, PDO, and cPanel shared hosting compatibility.

---

## 📌 Project Overview

Traditional email helpdesks either accept incoming messages blindly (exposing systems to spam, spoofing, and forged replies) or require dedicated server daemons (such as Redis, Node.js, Docker, or root Postfix access) that are unavailable on standard shared hosting.

This system provides **application-level tokenized validation** designed specifically for Apache + cPanel environments:
- **Human-Readable Ticket Numbers**: Every request is assigned a unique tracking number (e.g. `REQ-2026-10001`).
- **Cryptographic Reply Tokens**: Unique 256-bit cryptographically secure tokens are generated per ticket. **Only SHA-256 hashes are stored in the database**; plaintext tokens are never persisted.
- **Strict Sender Authorization**: Inbound replies are validated against the authorized ticket participant list (`authorized_ticket_emails`). Unauthorized senders and tampered tokens are rejected or quarantined.
- **Database-First Email Queue**: Outgoing notifications (confirmations, verification links, password resets) are staged in MySQL before transmission, preventing message loss during network failures.
- **Brute-Force & Session Security**: Built-in IP and email rate limiting, strict session fixation protection, and CSRF defense across all state-changing endpoints.

---

## 📂 Architecture & Directory Structure

To protect database credentials, configuration files, logs, and attachments on shared cPanel hosting, private files are isolated outside the public web root:

```text
/
├── helpdesk-private/                  <-- Placed outside public_html (or protected via .htaccess)
│   ├── .env                           <-- Local environment secrets (ignored by Git)
│   ├── .env.example                   <-- Distribution environment template
│   ├── .htaccess                      <-- Hard denial: "Require all denied"
│   ├── config/
│   │   ├── bootstrap.php              <-- Environment parser, class autoloader, error handlers
│   │   ├── app.php                    <-- Application paths, session configuration, ticket rules
│   │   ├── database.php               <-- PDO connection definitions (UTF8mb4)
│   │   └── mail.php                   <-- Inbound IMAP & Outbound SMTP configuration
│   ├── database/
│   │   ├── Database.php               <-- PDO Singleton & transaction manager
│   │   ├── Migrator.php               <-- Automated migration runner engine
│   │   ├── migrate.php                <-- CLI migration runner script
│   │   ├── backup.php                 <-- SQL backup & recovery generator
│   │   ├── schema.sql                 <-- Master DDL schema (15 tables)
│   │   └── migrations/
│   │       ├── 001_initial_schema.sql         <-- Base normalized schema
│   │       └── 002_seed_initial_settings.sql   <-- System settings & default admin
│   ├── services/
│   │   ├── SecurityService.php        <-- CSRF, rate-limiting, audit logging, escaping
│   │   ├── AuthService.php            <-- Registration, login, password reset, sessions
│   │   ├── TicketService.php          <-- Number generation, token hashing, ticket CRUD
│   │   └── MailQueueService.php       <-- Database-first outbound email queueing
│   ├── logs/                          <-- Application logs & security audits
│   ├── storage/
│   │   ├── attachments/               <-- Secure attachment repository
│   │   └── backups/                   <-- Automated SQL database dumps
│   └── tests/
│       ├── phase1_test.php            <-- Phase 1 DB & connection test suite
│       ├── phase2_test.php            <-- Phase 2 Auth & ticket creation test suite
│       └── test_email_credentials.php <-- Live SMTP & IMAP socket credential tester
└── helpdesk/                          <-- Public web root (public_html/helpdesk)
    ├── .htaccess                      <-- Security headers, disabled directory indexing
    ├── index.php                      <-- Diagnostic health check & status dashboard
    ├── register.php                   <-- Customer registration page
    ├── login.php                      <-- Authentication & login page
    ├── logout.php                     <-- Secure session termination
    ├── verify-email.php               <-- Email verification token endpoint
    ├── forgot-password.php            <-- Password reset request form
    ├── reset-password.php             <-- Password update form
    ├── dashboard.php                  <-- Customer portal dashboard
    ├── create-ticket.php              <-- Ticket creation form
    ├── tickets.php                    <-- Ticket listing with status filters
    ├── ticket-view.php                <-- Threaded conversation view & reply composer
    └── includes/
        ├── header.php                 <-- Responsive Bootstrap 5 navbar & layout
        └── footer.php                 <-- Reusable application footer
```

---

## 🛠️ Service Architecture & Function Reference

### 1. `Database.php` (Connection & Transaction Manager)
Location: `helpdesk-private/database/Database.php`

| Function | Signature | Description |
| :--- | :--- | :--- |
| `getInstance()` | `Database::getInstance(): self` | Returns the thread-safe singleton instance. |
| `getConnection()` | `Database::getConnection(): PDO` | Returns the active PDO instance configured with `ERRMODE_EXCEPTION` and `utf8mb4`. |
| `query()` | `Database::query(string $sql, array $params): PDOStatement` | Prepares and executes parameterized statements. |
| `fetchOne()` | `Database::fetchOne(string $sql, array $params): ?array` | Fetches a single record as an associative array or `null`. |
| `fetchAll()` | `Database::fetchAll(string $sql, array $params): array` | Fetches all matching rows as an array. |
| `execute()` | `Database::execute(string $sql, array $params): bool` | Executes an `INSERT`, `UPDATE`, or `DELETE` query. |
| `lastInsertId()` | `Database::lastInsertId(): string` | Returns the auto-increment ID from the last insert operation. |
| `transaction()` | `Database::transaction(callable $callback): mixed` | Wraps execution in an atomic database transaction. Automatically rolls back on any `Throwable`. |
| `testConnection()`| `Database::testConnection(): array` | Tests database connectivity, returning server version and database name. |

```php
// Transaction Example:
Database::transaction(function (PDO $pdo) {
    Database::query("INSERT INTO tickets (...) VALUES (...)");
    Database::query("INSERT INTO email_tokens (...) VALUES (...)");
});
```

---

### 2. `SecurityService.php` (Security & Protection)
Location: `helpdesk-private/services/SecurityService.php`

| Function | Signature | Description |
| :--- | :--- | :--- |
| `getClientIp()` | `SecurityService::getClientIp(): string` | Resolves client IP address with Cloudflare/reverse-proxy header awareness. |
| `generateCsrfToken()` | `SecurityService::generateCsrfToken(): string` | Generates a 32-byte cryptographic hex token stored in `$_SESSION['csrf_token']`. |
| `validateCsrfToken()` | `SecurityService::validateCsrfToken(?string $token): bool` | Validates submitted CSRF token using `hash_equals()` to prevent timing attacks. |
| `isIpRateLimited()` | `SecurityService::isIpRateLimited(string $ip, int $max=5, int $decay=15): bool` | Returns `true` if an IP has $\ge 5$ failed login attempts in the past 15 minutes. |
| `isEmailRateLimited()` | `SecurityService::isEmailRateLimited(string $email, int $max=5, int $decay=15): bool` | Protects specific user accounts from targeted brute-force password guessing. |
| `recordLoginAttempt()` | `SecurityService::recordLoginAttempt(string $email, string $ip, bool $success, ?string $reason): void` | Audits every login attempt in the `login_logs` table. |
| `logSecurityEvent()` | `SecurityService::logSecurityEvent(string $type, ?int $userId, string $ip, ?string $details): void` | Records security events (CSRF failures, token tampering, rate-limit blocks) in `security_logs`. |
| `escape()` / `e()` | `e(string $string): string` | HTML entity escaping helper (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`). |

---

### 3. `AuthService.php` (Authentication & Account Management)
Location: `helpdesk-private/services/AuthService.php`

| Function | Signature | Description |
| :--- | :--- | :--- |
| `register()` | `AuthService::register(array $data): array` | Validates inputs, verifies duplicate email prevention, hashes password using bcrypt (cost 12), generates a 64-hex verification token, and enqueues a verification email. |
| `login()` | `AuthService::login(string $email, string $password): array` | Checks IP and email rate limits, validates credentials via `password_verify()`, enforces `active` account status, regenerates the session ID (`session_regenerate_id(true)`), updates `last_login_at`, and audits the outcome. |
| `logout()` | `AuthService::logout(): void` | Clears all `$_SESSION` data, deletes session cookies with past expiration timestamps, and destroys the session. |
| `getCurrentUser()` | `AuthService::getCurrentUser(): ?array` | Returns the currently authenticated user's record or `null`. |
| `requireAuth()` | `AuthService::requireAuth(?string $role=null): array` | Route protection guard. Redirects unauthenticated users to `login.php` or enforces role permission (`admin`, `agent`, `customer`). |
| `verifyEmail()` | `AuthService::verifyEmail(string $token): array` | Validates the verification token, marks `is_verified = 1`, and clears the redeemed token. |
| `initiatePasswordReset()` | `AuthService::initiatePasswordReset(string $email): array` | Generates a 64-character token, stores its **SHA-256 hash** in `reset_token_hash` (+1 hour expiry), and enqueues reset instructions. |
| `resetPassword()` | `AuthService::resetPassword(string $token, string $newPassword): array` | Validates the token hash and expiration, updates password to a new bcrypt hash, and invalidates the token. |

---

### 4. `TicketService.php` (Ticket Engine & Tokenization)
Location: `helpdesk-private/services/TicketService.php`

| Function | Signature | Description |
| :--- | :--- | :--- |
| `generateTicketNumber()` | `TicketService::generateTicketNumber(): string` | Generates an sequential ticket number format: `REQ-YYYY-XXXXX` (e.g. `REQ-2026-10001`). |
| `generateReplyAddress()` | `TicketService::generateReplyAddress(string $rawToken): string` | Returns the tokenized reply address: `reply+{token}@{domain}`. |
| `createTicket()` | `TicketService::createTicket(int $customerId, string $subject, string $desc, string $priority, string $category): array` | Executes atomic ticket creation: generates ticket number, creates 256-bit cryptographic token, stores SHA-256 hash in `email_tokens`, assigns customer to `authorized_ticket_emails`, saves initial message, logs status history, and enqueues confirmation email. |
| `getCustomerTickets()` | `TicketService::getCustomerTickets(int $customerId, ?string $status, int $limit, int $offset): array` | Fetches tickets for a customer filtered by status (`open`, `pending`, `closed`, `reopened`). |
| `getCustomerTicketStats()` | `TicketService::getCustomerTicketStats(int $customerId): array` | Returns ticket counts grouped by status (`all`, `open`, `pending`, `closed`). |
| `getTicketById()` | `TicketService::getTicketById(int $ticketId, ?int $customerId=null): ?array` | Fetches ticket details with customer ownership validation. |
| `getTicketMessages()` | `TicketService::getTicketMessages(int $ticketId, bool $includeInternal=false): array` | Fetches threaded conversation messages. Filters out agent-only internal notes for customers. |
| `addMessage()` | `TicketService::addMessage(int $ticketId, ?int $userId, string $senderType, string $email, string $name, string $body, bool $internal=false, string $source='web'): int` | Appends a follow-up reply and updates `last_reply_at`. |

---

### 5. `MailQueueService.php` (Database-First Outbound Queue)
Location: `helpdesk-private/services/MailQueueService.php`

| Function | Signature | Description |
| :--- | :--- | :--- |
| `enqueue()` | `MailQueueService::enqueue(...): int` | Inserts an outbound message into `email_queue` with status `pending`, retry attempts `0`, custom RFC headers, and scheduled timestamps. |
| `enqueueVerificationEmail()` | `MailQueueService::enqueueVerificationEmail(string $email, string $name, string $token): int` | Queues account verification message with an activation URL. |
| `enqueuePasswordResetEmail()` | `MailQueueService::enqueuePasswordResetEmail(string $email, string $name, string $token): int` | Queues password recovery instructions with an expiring reset link. |
| `enqueueTicketConfirmationEmail()` | `MailQueueService::enqueueTicketConfirmationEmail(array $ticket, string $email, string $name, string $replyAddress): int` | Queues ticket creation confirmation with human-readable ticket number and tokenized reply-to address. |

---

## 🚀 Installation & Setup Guide

### Prerequisites
- **PHP**: 8.1 or higher (with extensions: `pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `imap`, `json`, `curl`).
- **Database**: MySQL 8.0+ or MariaDB 10.4+.
- **Web Server**: Apache with `mod_rewrite` and `mod_headers` enabled.

### 1. Database Creation
Create your MySQL database:
```sql
CREATE DATABASE helpdesk_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Environment Configuration
Copy the template file to `.env`:
```bash
cp helpdesk-private/.env.example helpdesk-private/.env
```
Edit `helpdesk-private/.env` with your database and email credentials:
```ini
APP_NAME="Secure Ticket Support"
APP_ENV="development"
APP_URL="http://localhost/helpdesk"
ADMIN_EMAIL="workxbittu@gmail.com"

# MySQL Database
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_DATABASE="helpdesk_db"
DB_USERNAME="root"
DB_PASSWORD=""

# Inbound IMAP
MAIL_IMAP_HOST="imap.gmail.com"
MAIL_IMAP_PORT=993
MAIL_IMAP_ENCRYPTION="ssl"
MAIL_IMAP_USERNAME="workxbittu@gmail.com"
MAIL_IMAP_PASSWORD="your_app_password"

# Outbound SMTP
MAIL_SMTP_HOST="smtp.gmail.com"
MAIL_SMTP_PORT=587
MAIL_SMTP_ENCRYPTION="tls"
MAIL_SMTP_AUTH=true
MAIL_SMTP_USERNAME="workxbittu@gmail.com"
MAIL_SMTP_PASSWORD="your_app_password"
MAIL_FROM_ADDRESS="workxbittu@gmail.com"
MAIL_FROM_NAME="Support Desk"

# Reply Routing Domain
MAIL_REPLY_DOMAIN="gmail.com"
MAIL_REPLY_PREFIX="reply+"
```

### 3. Run Database Migrations
Execute the automated migration runner to create all 15 tables and seed initial system settings:
```bash
php helpdesk-private/database/migrate.php
```

### 4. Create Database Backup (On Demand)
Generate an instant SQL backup file in `helpdesk-private/storage/backups/`:
```bash
php helpdesk-private/database/backup.php
```

---

## 🧪 Testing & Verification

The application includes automated integration test suites:

### 1. Run Phase 1 Test Suite (Database & Architecture)
Validates configuration loading, PDO connection, UTF8mb4 charset, schema existence (15 tables), admin seeding, transaction rollback, and foreign key constraint enforcement:
```bash
php helpdesk-private/tests/phase1_test.php
```
*Expected Result: `30 Passed, 0 Failed`*

### 2. Run Phase 2 Test Suite (Auth & Ticket Creation)
Validates customer registration, duplicate email prevention, email verification, brute-force rate-limiting, bcrypt authentication, password reset lifecycle, ticket creation (`REQ-YYYY-XXXXX`), cryptographic token hashing, authorized sender associations, message threading, and outbound email queueing:
```bash
php helpdesk-private/tests/phase2_test.php
```
*Expected Result: `40 Passed, 0 Failed`*

### 3. Test Live Email Credentials (SMTP & IMAP)
Tests real socket connections against mail servers to verify authentication:
```bash
php helpdesk-private/tests/test_email_credentials.php
```

---

## 💻 Browser Walkthrough

1. **System Health & Diagnostic Dashboard**:
   - URL: `http://localhost/helpdesk/` (or `http://localhost/helpdesk/index.php`)
   - Displays real-time checks for PHP version, extensions (including IMAP), database connection, and storage permissions.
2. **Customer Registration**:
   - URL: `http://localhost/helpdesk/register.php`
   - Register a new customer account with full input validation.
3. **Login Portal**:
   - URL: `http://localhost/helpdesk/login.php`
   - Default Administrator: `workxbittu@gmail.com` / `Admin@123456`.
4. **Customer Dashboard**:
   - URL: `http://localhost/helpdesk/dashboard.php`
   - Real-time statistics counters for Total, Open, Pending, and Closed tickets.
5. **Create Support Ticket**:
   - URL: `http://localhost/helpdesk/create-ticket.php`
   - Submit a new request with Subject, Category, Priority, and Description.
6. **Threaded Conversation & Details**:
   - URL: `http://localhost/helpdesk/ticket-view.php?id=1`
   - Review ticket timeline, tokenized email routing notes, and submit follow-up replies.

---

## 🔄 Git Workflow: Staging, Committing & Pushing

Whenever changes are made to the codebase, use the following commands:

```bash
# 1. Check working directory status
git status

# 2. Stage updated files
git add .

# 3. Create a descriptive commit
git commit -m "Phase X: Description of changes"

# 4. Push to remote GitHub repository
git push origin main
```

---

## 📋 Development Roadmap

- [x] **Phase 1: Hosting setup, database architecture & connectivity**
- [x] **Phase 2: User authentication, email verification & ticket creation**
- [ ] **Phase 3: Ticket numbers, secure reply tokens and token validation**
- [ ] **Phase 4: PHPMailer outgoing email system and email queue**
- [ ] **Phase 5: IMAP incoming email processing engine**
- [ ] **Phase 6: Sender validation, rejection, quarantine and security logging**
- [ ] **Phase 7: Ticket conversations and administrator dashboard**
- [ ] **Phase 8: Cron automation, retries and maintenance**
- [ ] **Phase 9: Security audit, testing, optimization and cPanel deployment**
