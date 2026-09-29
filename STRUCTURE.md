# C Tech Fix CRM — Project Structure

## What goes where

```
C Tech Fix - CRM/
│
├── config/                         ← PRIVATE — never upload this to public_html
│   └── config.php                  ← Database credentials, API keys, app settings
│
├── core/                           ← PRIVATE — core PHP classes
│   ├── Auth.php                    ← Login, session, permission checks
│   ├── DB.php                      ← Database connection
│   ├── Audit.php                   ← Audit log writer
│   ├── RecordNumber.php            ← Generates CTF-OS-R-2026-000001 style numbers
│   └── helpers.php                 ← Shared utility functions
│
├── database/
│   └── ctechfix_crm.sql            ← Import this once in cPanel to create all tables
│
├── docs/
│   └── DEPLOY.md                   ← Plain English deployment instructions
│
└── public/                         ← Upload ONLY what is inside here to public_html/CRM/
    └── CRM/
        ├── index.php               ← Entry point — redirects to login or dashboard
        ├── .htaccess               ← Security and URL routing
        │
        ├── assets/
        │   ├── css/app.css         ← All styles (dark theme, responsive)
        │   ├── js/app.js           ← All frontend JavaScript
        │   └── img/                ← Logo, icons
        │
        ├── modules/
        │   ├── auth/               ← Login, logout
        │   ├── dashboard/          ← Owner, Manager, Staff dashboards
        │   ├── calls/              ← Phase 1
        │   ├── customers/          ← Phase 1
        │   ├── repairs/            ← Phase 2
        │   ├── inventory/          ← Phase 3
        │   ├── sales/              ← Phase 4
        │   ├── activations/        ← Phase 6
        │   ├── staff/              ← Phase 5
        │   ├── settings/           ← Phase 0 onwards
        │   └── reports/            ← Phase 6
        │
        ├── webhook/
        │   └── sms.php             ← VoIP.ms sends inbound SMS here
        │
        └── public-status/
            └── index.php           ← Customer repair status lookup (no login needed)
```

## Namecheap Deployment Rule
- config/ and core/ folders NEVER go into public_html
- Only the contents of public/CRM/ go into public_html/CRM/
- config/ and core/ sit one level ABOVE public_html
