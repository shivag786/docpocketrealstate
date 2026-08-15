# Database & Architecture

## Core tables

### users
id, name, email, password, role, status, timestamps

### members
id, member_code, name, mobile, email, address, sponsor_id, joining_date, status, timestamps, deleted_at

### projects
id, name, location, description, status, timestamps

### properties
id, project_id, property_code, details, status, timestamps

### registry_sales
id, member_id, project_id, property_id, registry_reference, registry_date, sale_date, sqft, status, entered_by, timestamps

### target_cycles
id, leader_id, target_no, target_sqft, period_type, start_date, end_date, achieved_sqft, status, reward_rate, completed_at, timestamps

### target_monthly_progress
id, target_cycle_id, month, sqft, cumulative_sqft, timestamps

### team_calculations
id, leader_id, period, own_sqft, direct_team_sqft, total_team_sqft, target_sqft, achieved, reward_amount, calculation_run_id

### upline_calculations
id, period, seller_id, receiver_id, upline_level, seller_sqft, pool_rate, pool_amount, eligible_upline_count, receiver_amount, calculation_run_id

### company_club_calculations
id, period, total_sqft, rate, amount, calculation_run_id, timestamps

### reward_ledger
id, member_id, reward_type, source_type, source_id, period, sqft, rate, amount, status, calculation_run_id, timestamps

### calculation_runs
id, period, run_type, status, started_at, completed_at, initiated_by, error_message

### audit_logs
id, admin_id, action, module, record_id, old_values, new_values, ip_address, user_agent, timestamps

## Services
- MemberTreeService
- TeamSalesService
- DirectRewardService
- UplineRewardService
- TargetService
- CompanyClubService
- CalculationRunService
- RewardLedgerService

## Architecture rules
- Controllers orchestrate; Services calculate.
- Blade/JS is never the financial source of truth.
- Use database transactions for financial runs.
- Use DECIMAL for money.
- Index sponsor_id, member_code, member_id, sale_date and period.
- Prevent circular sponsor relationships.
- Store source and calculation run for every reward.
- Use lazy AJAX tree loading for large networks.
