============================================================
DHRUB FOUNDATION ERP - COMPLETE DEPLOYMENT PACKAGE
============================================================

VERIFIED CONTENTS:
==================

DATABASE TABLES (19 total):
---------------------------
1.  roles              - User role definitions (7 default roles)
2.  users              - System users (admin account included)
3.  donors             - Donor management
4.  programs           - Program management
5.  projects           - Project management
6.  donations          - Donation records
7.  expenses           - Expense tracking
8.  employees          - Employee records with salary breakdown
9.  payroll            - Payroll processing
10. volunteers         - Volunteer management
11. inventory_items    - Inventory items
12. inventory_transactions - Stock in/out
13. ledger_entries     - Financial ledger
14. activity_logs      - User activity tracking
15. settings           - System settings
16. members            - Organization leadership (founders, trustees)
17. gallery            - Photo gallery
18. permissions        - Permission definitions
19. role_permissions   - Role-permission mapping

API ENDPOINTS (24 total):
-------------------------
- /api/auth            - Login, logout, password change
- /api/roles           - Role management
- /api/users           - User management
- /api/dashboard       - Dashboard statistics
- /api/counts          - Quick counts
- /api/donors          - CRUD for donors
- /api/donations       - CRUD for donations + receipts
- /api/programs        - CRUD for programs
- /api/projects        - CRUD for projects
- /api/expenses        - CRUD for expenses
- /api/employees       - CRUD for employees
- /api/payroll         - CRUD for payroll
- /api/volunteers      - CRUD for volunteers
- /api/inventory       - Inventory management
- /api/ledger          - Ledger entries
- /api/activity-logs   - View activity logs
- /api/gallery         - Gallery management
- /api/members         - Organization members
- /api/settings        - System settings
- /api/reports         - Various reports
- /api/export          - CSV export
- /api/export-pdf      - PDF export
- /api/import          - CSV import
- /api/search          - Global search
- /api/seed-data       - Load sample data

DEFAULT LOGIN:
--------------
Email: admin@dhrubfoundation.org
Password: admin123
Role: Super Admin (full access)

DEPLOYMENT STEPS:
=================
1. Delete everything in your Hostinger public_html
2. Upload ALL files from this zip to public_html
3. Go to phpMyAdmin and delete existing database tables
4. Import config/database.sql (creates database + tables)
5. Update config/database.php with YOUR database credentials:
   - DB_HOST (usually 'localhost')
   - DB_NAME (your database name)
   - DB_USER (your database username)  
   - DB_PASS (your database password)
6. Set uploads/ folder permissions to 755
7. Visit your domain and login

TROUBLESHOOTING:
================
- "500 Error": Check PHP version (needs 7.4+), check database credentials
- "Failed to fetch": Database not imported or wrong credentials
- "Login fails": Make sure database.sql was imported successfully
- "Bounces back to login": Clear browser cache, try incognito mode

============================================================
