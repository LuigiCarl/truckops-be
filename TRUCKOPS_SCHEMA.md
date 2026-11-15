# TRUCKOPS - SYSTEM SCHEMA & API REFERENCE
**Tech Stack:** Laravel (API), Next.js (Frontend), MySQL.
**Usage:** Read this file to understand database relations and available API endpoints without scanning the backend codebase.

## 1. DATABASE SCHEMA

### `users` (Single Operator / Admin)
- `id` (PK)
- `name` (string)
- `email` (string)
- `password` (string)

### `employees` (Drivers & Helpers)
- `id` (PK)
- `first_name` (string)
- `last_name` (string)
- `contact_number` (string, nullable)
- `role` (enum: 'Driver', 'Helper')
- `pay_type` (enum: 'Daily', 'Trip')
- `base_rate` (decimal 10,2)
- `ot_per_trip_rate` (decimal 10,2)
- `status` (enum: 'Active', 'Inactive')

### `trucks`
- `id` (PK)
- `plate_number` (string, unique)
- `description` (string, nullable)
- `registration_expiry` (date, nullable)
- `status` (enum: 'Available', 'Assigned', 'Under Maintenance', 'Inactive')

### `trips` (Core Revenue Driver)
- `id` (PK)
- `date` (date)
- `truck_id` (FK -> trucks.id)
- `driver_id` (FK -> employees.id)
- `helper_id` (FK -> employees.id, nullable)
- `destination` (string)
- `reference_no` (string, nullable) - Physical Monitoring Sheet reference
- `is_overtime` (boolean, default: false)
- `ab_flat_rate_applied` (decimal 10,2)
- `driver_pay_applied` (decimal 10,2)
- `helper_pay_applied` (decimal 10,2, nullable)
- `remarks` (text, nullable)
- `status` (enum: 'Draft', 'Completed', 'Cancelled')

### `collections` (AB Payments)
- `id` (PK)
- `payment_date` (date)
- `amount_received` (decimal 12,2)
- `reference_notes` (text, nullable)

### `cash_advances`
- `id` (PK)
- `employee_id` (FK -> employees.id)
- `date_issued` (date)
- `total_amount` (decimal 10,2)
- `fixed_deduction_rate` (decimal 10,2)
- `remaining_balance` (decimal 10,2)
- `status` (enum: 'Active', 'Paid')

### `payroll_records`
- `id` (PK)
- `employee_id` (FK -> employees.id)
- `period_start` (date)
- `period_end` (date)
- `total_base_pay` (decimal 10,2)
- `total_ot_pay` (decimal 10,2)
- `advance_deduction_applied` (decimal 10,2)
- `net_payable` (decimal 10,2)
- `status` (enum: 'Unpaid', 'Paid')

### `expense_categories`
- `id` (PK)
- `name` (string, unique) - e.g., Fuel, Maintenance, Office

### `expenses`
- `id` (PK)
- `date` (date)
- `category_id` (FK -> expense_categories.id)
- `amount` (decimal 10,2)
- `description` (text, nullable)
- `truck_id` (FK -> trucks.id, nullable)

---

## 2. API ROUTE MAP (Laravel Sanctum Protected: `/api/v1/`)

### Auth (`/auth`)
- `POST /login` (email, password)
- `POST /logout`
- `GET /user`

### Dashboard (`/dashboard`)
- `GET /summary?start_date=X&end_date=Y` -> Returns { active_trucks, active_employees, total_completed_trips, calculated_earnings, total_expenses, total_payroll_paid, total_collections, outstanding_ab_balance, net_cash_flow }

### Master Data
- `GET, POST, GET /{id}, PUT /{id} /employees`
- `PATCH /employees/{id}/status`
- `GET, POST, GET /{id}, PUT /{id} /trucks`
- `PATCH /trucks/{id}/status`

### Operations
- `GET, POST, GET /{id}, PUT /{id} /trips`
- `PATCH /trips/{id}/status` (Draft -> Completed)
- `GET, POST, GET /{id}, PUT /{id}, DELETE /{id} /collections`
- `GET /expenses/categories`
- `GET, POST, GET /{id}, PUT /{id}, DELETE /{id} /expenses`

### Payroll & Advances
- `GET, POST, GET /{id}, PUT /{id} /advances`
- `GET /payroll`
- `POST /payroll/generate` (period_start, period_end)
- `GET /payroll/{id}`
- `PATCH /payroll/{id}/pay`

### Reports (Exports)
- `GET /reports/gross-revenue/export`
- `GET /reports/expenses/export`
- `GET /reports/payroll/export`