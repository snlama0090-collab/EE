# Implementation Plan: 9-Page Admin/Portal Dashboard

## Phase 1: Create Missing Section Files

### Admin (needs 9 pages - currently has 6)
- [x] Overview (overview.php) ✅ exists
- [ ] Analytics (analytics.php) - new, from reports.php
- [ ] Orders/Sessions (orders.php) - new
- [ ] Customers/Drivers (customers.php) - new, split from users.php
- [ ] Invoices/Billing (invoices.php) - new
- [x] Users & Team (users.php) ✅ exists
- [ ] Notifications (notifications.php) - new
- [x] Settings (settings.php) ✅ exists
- [ ] Help & Support (support.php) - new

### Station Owner (needs 7 pages - currently has 5)
- [x] Overview (overview.php) ✅ exists
- [ ] Analytics (analytics.php) - new
- [ ] Invoices (invoices.php) - new
- [ ] Team/Users (team.php) - new
- [ ] Notifications (notifications.php) - new
- [ ] Settings (settings.php) - new
- [ ] Help & Support (support.php) - new

### Driver (needs 5 pages - currently has 5)
- [x] Overview/My Hub (dashboard.php) ✅ exists
- [x] Orders/My Sessions (bookings.php) ✅ exists
- [ ] Invoices/Receipts (receipts.php) - new
- [ ] Settings/Profile (profile.php) ✅ exists
- [ ] Help & Support (support.php) - new

## Phase 2: Update Dashboard Shells with RBAC Sidebar
- [ ] Update admin.php - add 9 nav items, RBAC redirect
- [ ] Update owner.php - add 7 nav items, RBAC redirect
- [ ] Update driver.php - add 5 nav items, RBAC redirect

## Phase 3: Add RBAC Middleware
- [ ] Add role-check redirect at top of every page