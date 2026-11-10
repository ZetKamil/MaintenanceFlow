# MaintenanceFlow — Architectural & Functional Summary
**Case:** Verlinde Beveiliging BV (Zwevegem) · **Stack:** Laravel 13, Vue 3 SPA, Tailwind CSS v4, SQLite · **Tests:** 14 PHPUnit tests (122 assertions)

---

## 1. Why BuildDesk Pro Failed (CSV Audit Findings)
Auditing the raw BuildDesk exports (`BD_EXP_*_20261109.csv`) revealed why Dirk lost oversight and avoided scheduling:
* **Unreliable compliance dates:** **185 of 193 installations (96%)** had an empty `volgende` due date (and 4 more were inverted earlier than `laatste` — leaving only 4 valid dates), while **56 statutory fire-safety (`BRAND` / `BRANDDET`)** sites lacked automated tracking.
* **Corrupted & contradictory records:** Free-text intervals (`1J`, `12M`, `jaarlijks`, `?`), **49 appointments ending before or at start time** (`13:00–10:00`), **6 inconsistent phone formats**, **7 inactive customers (`actief=N`)** linked to active plans and 4 planned visits, **3 double-booked technician slots**, and **14 orphan installations** missing a customer code.
* **No field workflow:** Out of **559 work orders**, only **53 execution hour logs** existed because technicians lacked a mobile-friendly report screen.

---

## 2. Chosen Solution & Key Engineering Decisions
* **Zero-Config Idempotent Setup (`php artisan migrate --seed`):** The bundled CSV dataset seeds cleanly and deterministically. `BuildDeskNormalizer` converts free-text intervals into standard months, enforces a **12-month minimum on all `BRAND` systems**, repairs inverted slots (`+2h`), normalizes Belgian phone numbers to **E.164 (`+32...`)**, propagates `customer.is_active` to plans, and logs **117 data-cleansing warnings** instead of failing silently.
* **Calm, Role-Focused SPA (No "SAP Bloat"):**
  * **Dirk (Owner):** Starts on a prioritized **"Needs Contact"** queue (`BRAND` fire systems and overdue sites first) alongside team workload and 1-click scheduling. The **Dispatch Planning** view uses a clean 5-day **Technician × Weekday Matrix** that exposes free capacity at a glance, flags the **3 legacy `Overlap` conflicts**, and blocks new double-bookings server-side (`WorkOrder::hasScheduleConflict`).
  * **Technicians (Field Mobile):** See only their own upcoming visits with `tel:` calling, collapsible installed hardware (progressive disclosure for **1,010 components**), `Start Visit`, and a **Service Report** form. Submitting executes inside a single **`DB::transaction()`**—recording findings/materials, locking the visit as `completed`, and advancing `next_due_at` automatically.
* **Same-Origin Security & Automated Tests:** Uses native encrypted `HttpOnly` session cookies + CSRF (zero Sanctum/JWT complexity) and `WorkOrderPolicy` to block technician IDOR on unassigned orders (`403`). Verified by unit tests on normalization and feature tests covering auth/IDOR, schedule overlap rules, idempotent imports, and atomic report completion.

---

## 3. Intentional Omissions & First Questions for Dirk
* **Deliberately left out of MVP:** Invoicing/pricing (`BD_EXP_ART`), signed PDF certificates, `Dispatcher`/`Sales` sub-roles (designed to add cleanly later via `UserRole`), and auto-merging duplicate customer entries (*Kapsalon Mieke* / *VZW De Klaproos* variants) to preserve accounting audit trails.
* **Next questions for Dirk before go-live:**
  1. Which customers own the **14 skipped orphan installations** (missing `klant`) and **16 historical work orders**?
  2. Should the **4 planned orders** tied to inactive (`actief=N`) customers be cancelled, and how should we reschedule the **3 flagged `Overlap` visits**?
  3. Which duplicate customer accounts can safely be merged?

