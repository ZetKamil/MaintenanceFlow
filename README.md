# MaintenanceFlow 🛠️
> Focused Maintenance Planning & Service Management for Security Installation Companies  
> *Recruitment Case: Verlinde Beveiliging BV (Zwevegem, Belgium)*

[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat&logo=laravel)](https://laravel.com)
[![Vue 3](https://img.shields.io/badge/Vue-3.5-4FC08D?style=flat&logo=vue.js)](https://vuejs.org)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind-CSS%20v4-06B6D4?style=flat&logo=tailwind-css)](https://tailwindcss.com)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat&logo=php)](https://php.net)
[![Database](https://img.shields.io/badge/Database-SQLite-003B57?style=flat&logo=sqlite)](https://sqlite.org)

---

## 🎯 The Core Business Problem & Solution

### The Business Need (Dirk Verlinde, Owner):
> *"I never know which maintenance is coming up. Fire detection has to be serviced every year by law, and I'm terrified of forgetting customers. And when I try to schedule something in BuildDesk Pro, it takes me an eternity."*

### Why the Legacy System (BuildDesk Pro) Failed:
1. **185 of 193 installations (96%) had NO next due date** (`volgende` was completely blank, and 4 more were inverted earlier than `laatste` — leaving only 4 valid dates). The old ERP never auto-calculated recurring deadlines.
2. **56 fire detection systems (`BRAND` / `BRANDDET`)** lacked statutory annual tracking, exposing the company to severe legal compliance risk.
3. **559 work orders existed**, yet only **53 execution hour rows** were logged—field technicians had no usable mobile view.
4. **Corrupted & inconsistent exports:** Free-text intervals (`1J`, `12M`, `jaarlijks`, `?`), inverted appointment windows (`13:00`–`10:00`), **6 inconsistent phone formats**, double spaces, **7 inactive customers (`actief=N`) still tied to active installations**, and **3 double-booked legacy slots**.

### What MaintenanceFlow Delivers:
* **Idempotent Data Cleansing & Import:** Cleanses legacy BuildDesk CSV exports into canonical structures (E.164 `+32` phones, statutory 12-month `BRAND` cycles, repaired time slots, and inactive customer propagation).
* **"Needs Contact" Priority Queue:** Immediate owner dashboard visibility of overdue and upcoming sites (`BRAND` prioritized first) lacking an active appointment, with one-click scheduling.
* **Technician × Weekday Dispatch Matrix:** Clean 5-day hairline planning board showing technician availability at a glance, flagging legacy overlaps (`Overlap`), and blocking new schedule conflicts server-side (`hasScheduleConflict`).
* **Mobile Technical Service Report:** Technicians start visits and log work, findings, recommendations, and used materials; a single `DB::transaction` closes the visit and advances `next_due_at` automatically.

---

## 🏗️ Architecture & Technical Decisions

* **Monolithic Same-Origin SPA:** Laravel serves the Blade shell, while Vue 3 (Vite) drives the interactive user experience.
* **Session-Based Web Security (No Sanctum/JWT):** Native `HttpOnly` encrypted session cookies with CSRF protection. Eliminates CORS complexity and protects tokens from XSS storage vulnerabilities.
* **Zero Ecosystem Bloat:** Pure, handwritten Laravel and Vue 3. No Breeze, Jetstream, Sanctum, Filament, or third-party calendar packages.
* **Server-Enforced Authorization:** `WorkOrderPolicy` ensures technicians can only access and update their assigned visits (403 Forbidden on direct ID tampering).
* **Strict State Machine:** `WorkOrderStatus` enum governs valid transitions (`needs_contact` → `scheduled` → `in_progress` → `completed`).

---

## 🚀 Quick Start (Local Setup — macOS / Linux / Windows)

The repository bundles the fictional Verlinde Beveiliging BV BuildDesk CSV exports under `database/data/builddesk/`, so **a fresh clone seeds the full normalized dataset automatically**.

### Prerequisites
* PHP 8.3+ (tested with PHP 8.4)
* Composer 2.x
* Node.js 18+ and NPM

### Option 1: One-Command Setup
```bash
git clone https://github.com/ZetKamil/MaintenanceFlow.git
cd MaintenanceFlow
composer run setup
php artisan serve
```
Then open **[http://localhost:8000](http://localhost:8000)** (or your Laravel Herd `.test` domain — Same-Origin auth works on both out of the box).

### Option 2: Step-by-Step Setup
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm run build
php artisan serve
```

> **Re-running the BuildDesk import manually:**  
> `php artisan import:builddesk` reads `database/data/builddesk/` by default (or pass `--path=...`). Both seeding and importing are 100% idempotent.

---

## 👥 Demo Accounts (Pre-configured)

| Role | Name | Email | Password | Scope |
|---|---|---|---|---|
| **Owner / Manager** | Dirk Verlinde | `dirk@verlinde.be` | `secret123` | Full access to all customers, installations, and team dispatch. |
| **Lead Technician** | Tom De Coninck | `tom@verlinde.be` | `secret123` | Assigned schedule only, service report submission. |
| **Technician** | Wout Vanhoutte | `wout@verlinde.be` | `secret123` | Assigned schedule only. |
| **Technician (Part-Time)** | Jens Maes | `jens@verlinde.be` | `secret123` | Assigned schedule only. |
| **Subcontractor** | Brandtec | `contact@brandtec.test` | `secret123` | External contractor view. |

---

## 🧪 Automated Test Suite

Run the full automated test suite (**14 PHPUnit tests, 122 assertions** across Unit & Feature suites using an isolated SQLite `:memory:` database—covering ETL normalization, import idempotency, authentication & IDOR protection, schedule conflicts, and transactional report completion):

```bash
php artisan test
```
