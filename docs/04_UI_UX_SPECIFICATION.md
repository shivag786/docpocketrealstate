# UI/UX Specification

## Design goal
Professional, fast and low-confusion Admin panel. Daily entry, search and calculations must be quick.

## Navigation
Dashboard
Members
Sponsor Tree
Projects
Properties/Sites
Daily Sales
Sales History
Calculations
Targets
Upline Rewards
Company Club
Reward Ledger
Reports
Audit Logs
Settings

## Dashboard
KPI cards:
- Total Members
- Active Members
- Today's Sales
- Monthly Sales Sq.Ft.
- Direct Reward
- Upline Reward
- Target Rewards
- Company Club Amount

Below:
- monthly sales trend
- target progress
- branch performance
- recent registry sales
- calculation status

## Daily Sale Entry
Compact form:
Date, Member search, Project, Property/Site, Registry No., Sq.Ft., Notes, Save.
After save, show confirmation and keep the form ready for the next entry.

## Global Search
Member ID, name, mobile, sponsor ID, registry number, property code.

## Member Card
Name, Member ID, Sponsor, Level, Direct Members, Total Team, Own Monthly Sq.Ft., Team Monthly Sq.Ft., Target Progress, Status.
Actions: View, Edit, Tree, Sales, Rewards, Team.

## Tree
Expandable cards with AJAX lazy loading. Never render thousands of nodes initially.
Controls: Search, Expand, Collapse, Focus Member, View Sponsor, View Direct Team, View Full Downline, Level filter.

## Member Detail Tabs
Overview
Sponsor/Upline
Direct Team
Full Tree
Sales
Direct Reward
Upline Reward
Targets
Reward Ledger

## Calculation Center
Buttons:
Calculate Direct
Calculate Upline
Calculate Team Targets
Calculate Company Club
Calculate All

Require period selection. Show run status and calculation ID.

## Tables
Server-side pagination, search, filters, sorting, date range and approved export.

## UX rules
Bootstrap 5. Short forms. Loading states for AJAX. Confirm destructive actions. Do not freeze whole page during tree/report loading. Use responsive design.
