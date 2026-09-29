# How to Deploy C Tech Fix CRM
### Plain English — Step by Step

---

## What You Need Before Starting
- Access to your Namecheap cPanel
- Your cPanel username and password
- The project folder (C Tech Fix - CRM) on your computer

---

## STEP 1 — Create a MySQL Database in cPanel

1. Log in to Namecheap → go to **cPanel**
2. Scroll down and click **MySQL Databases**
3. Under "Create New Database" — type a name like `ctechfix_crm` → click **Create Database**
4. Scroll down to "MySQL Users" → click **Add New User**
   - Username: `ctechfix_user` (or whatever you like)
   - Password: make a strong one and **write it down**
   - Click **Create User**
5. Scroll down to "Add User To Database"
   - Select your new user and your new database
   - Click **Add** → on the next screen, check **ALL PRIVILEGES** → click **Make Changes**

**Write down these 3 things — you'll need them in Step 3:**
- Database name: `_______________`
- Database username: `_______________`
- Database password: `_______________`

---

## STEP 2 — Import the Database Tables

1. In cPanel, click **phpMyAdmin**
2. On the left side, click your database name (`ctechfix_crm`)
3. Click the **Import** tab at the top
4. Click **Choose File** → find the file `database/ctechfix_crm.sql` in your project folder
5. Scroll down and click **Go**
6. You should see a green success message

---

## STEP 3 — Edit the Config File

1. On your computer, open the file: `config/config.php`
2. Fill in these 4 lines with the details from Step 1:

```php
define('DB_NAME',     'ctechfix_crm');      // ← your database name
define('DB_USER',     'ctechfix_user');      // ← your database username
define('DB_PASS',     'your_password');      // ← your database password
```

3. Also fill in your VoIP.ms details:
```php
define('VOIPMS_API_USERNAME', 'your@email.com');   // ← your VoIP.ms login email
define('VOIPMS_API_PASSWORD', 'your_api_password'); // ← your VoIP.ms API password
```

4. Generate a random secret key at https://www.random.org/strings/ (64 characters) and paste it here:
```php
define('APP_SECRET_KEY', 'PASTE_YOUR_64_CHARACTER_STRING_HERE');
```

5. Save the file.

---

## STEP 4 — Upload Files to Namecheap

1. In cPanel, click **File Manager**
2. Navigate to `public_html`
3. Create a folder called `CRM` (if it doesn't exist)
4. Upload EVERYTHING inside the `public/CRM/` folder from your computer into `public_html/CRM/`

**⚠️ Important:** Do NOT upload the `config/` or `core/` folders into public_html.
Those go one level UP — into the folder above public_html.

To upload the private folders:
1. In File Manager, click the folder icon next to `public_html` to go UP one level
2. You should see a folder called `public_html` in the list
3. Create a folder called `ctechfix_private` here (same level as public_html, NOT inside it)
4. Upload the `config/` and `core/` folders into `ctechfix_private/`

Then update `config.php` so PHP can find the core folder.
(Your developer will handle this path adjustment — just upload the files as described.)

---

## STEP 5 — Run the Install Script

1. Open your browser and go to:
   `https://ctecht.ca/CRM/install.php?token=CTECHFIX_INSTALL_2026`

2. You should see green checkmarks for every step.
3. If you see red errors — check that you completed Steps 1–4 correctly.

---

## STEP 6 — Log In

1. Go to: `https://ctecht.ca/CRM`
2. Username: **athavan**
3. Password: **324973130Aa@**
4. You should see the Owner Dashboard.

---

## STEP 7 — Delete the Install File (IMPORTANT)

After logging in successfully:
1. Go to cPanel → File Manager → `public_html/CRM/`
2. Find `install.php` and **delete it**
3. This is a security step — the install file is no longer needed

---

## STEP 8 — Set Up VoIP.ms Inbound SMS

For each of your two phone numbers, do this in VoIP.ms:
1. Log in to voip.ms
2. Go to **DID Numbers → Manage DIDs**
3. Click the pencil (edit) icon next to 905-233-2596 (Oshawa)
4. Find **Short Message Service** section
5. Check the box for **Enable SMS/MMS**
6. Check the box for **SMS URL Callback**
7. Paste this URL exactly:
   `https://ctecht.ca/CRM/webhook/sms.php?did=OS`
8. Check **URL Callback Retry** (so missed messages get retried automatically)
9. Click Save

Repeat for 905-752-0343 (Pickering) — paste this URL:
`https://ctecht.ca/CRM/webhook/sms.php?did=PF`

---

## Troubleshooting

**"A system error occurred"** → Database config is wrong. Check Step 3.
**White blank page** → PHP error. In cPanel → change `APP_ENV` to `development` temporarily.
**Can't log in** → Run the install script again (Step 5).
**SMS not working** → Check VoIP.ms settings (Step 8) and make sure API credentials are in config.

---

## Questions?
All technical work is handled — just follow these steps exactly and it will work.
