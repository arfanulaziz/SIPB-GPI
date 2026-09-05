# ✅ Deployment Checklist - InfinityFree

**Print this page & check off as you go!**

---

## PHASE 1️⃣: Pre-Deployment (Before uploading files)

### Database Setup
- [ ] Create akun InfinityFree di https://infinityfree.net
- [ ] Create Hosting Account (subdomain: sipbgpi)
- [ ] Create MySQL Database (name: sipbgpi_test_db)
- [ ] Create MySQL User (username: sipbgpi_user)
- [ ] **CATAT CREDENTIALS**:
  ```
  Host: ___________________
  DB Name: ___________________
  DB User: ___________________
  DB Password: ___________________
  Domain: ___________________
  ```

### File Preparation
- [ ] Read `QUICK_START.md` (2 menit)
- [ ] Open file: `.env.infinityfree`
- [ ] Edit nilai-nilai:
  - [ ] DB_NAME = sipbgpi_test_db
  - [ ] DB_USER = sipbgpi_user
  - [ ] DB_PASS = [password kamu]
  - [ ] APP_URL = http://sipbgpi.rf.gd/
- [ ] Rename `.env.infinityfree` → `.env`
- [ ] Verify `.env` has all values filled
- [ ] Check public_html/ folder exists
- [ ] Check database/migrations/ folder exists

### File Integrity
- [ ] Verify file count ~50+ files
- [ ] Check no leftover .md docs (optional)
- [ ] Confirm uploads/ folder exists (empty ok)
- [ ] Confirm config/ folder has all files

---

## PHASE 2️⃣: File Upload (Upload to server)

### Option A: Zip Upload (Recommended)
- [ ] Create backup of original project (optional)
- [ ] Zip entire folder (or just public_html + database + .env)
- [ ] Upload .zip to File Manager → /public_html/
- [ ] Extract .zip in File Manager
- [ ] Delete .zip after extraction
- [ ] Verify folder structure:
  ```
  /public_html/
  ├── sipbgpi/        (atau bisa langsung files di sini)
  │   ├── config/
  │   ├── sipb/
  │   ├── admin/
  │   ├── .env        (sudah di-edit)
  │   └── index.php
  └── database/
      └── migrations/
  ```

### Option B: File Manager Upload
- [ ] Open InfinityFree File Manager
- [ ] Navigate to `/public_html/`
- [ ] Create folder: `sipbgpi` (if needed)
- [ ] Upload folder: `public_html/` contents
- [ ] Upload folder: `database/`
- [ ] Upload file: `.env`
- [ ] Verify all files uploaded (check file count)

### Option C: FTP Upload
- [ ] Get FTP credentials from InfinityFree
- [ ] Open FTP client (FileZilla / WinSCP)
- [ ] Connect to server
- [ ] Navigate to `/public_html/`
- [ ] Upload all files
- [ ] Verify upload complete (check file sizes)

### Post Upload Verification
- [ ] Check `.env` file exists
- [ ] Verify public_html/ folder structure
- [ ] Verify database/migrations/ folder exists
- [ ] Create uploads/ folder jika missing
- [ ] Set permissions 755 on uploads/ folder

---

## PHASE 3️⃣: Database Setup (Import schema)

### Access phpMyAdmin
- [ ] File Manager → cari link phpMyAdmin
- [ ] Login dengan MySQL credentials
- [ ] Select database: `sipbgpi_test_db`

### Import SQL Files - Part 1
- [ ] Click tab: **Import**
- [ ] Choose file: `database/migrations/001_SIPB_SCHEMA.sql`
- [ ] Click: **Go** atau **Import**
- [ ] Wait for completion (~30 detik)
- [ ] ✅ Verify: "Import successful" message

### Import SQL Files - Part 2
- [ ] Upload file: `database/migrations/002_SIPB_SCHEMA_UPDATES.sql`
- [ ] Click: **Go** atau **Import**
- [ ] Wait for completion
- [ ] ✅ Verify: "Import successful" message

### Database Verification
- [ ] Click database: `sipbgpi_test_db`
- [ ] Check tables exist:
  - [ ] `users` table
  - [ ] `sipb_forms` table
  - [ ] `sipb_items` table
  - [ ] `sipb_audit_log` table
  - [ ] Other tables (verify count)
- [ ] Count total tables: _______ (should be 10+)

---

## PHASE 4️⃣: Application Test (Verify everything works)

### Initial Load Test
- [ ] Open browser
- [ ] Navigate to: `http://sipbgpi.rf.gd/`
- [ ] Expected: Login page atau dashboard
- [ ] Status: ✅ Success / ❌ Error (catat error)

### Connection Test
- [ ] Check browser console (F12)
- [ ] Look for errors (write down any):
  ```
  Error: _____________________
  ```
- [ ] Check server error log (if accessible)

### Database Connection Test
- [ ] Open: `http://sipbgpi.rf.gd/check_db.php`
- [ ] Expected: "Database connected successfully" message
- [ ] If error:
  - [ ] Verify .env credentials
  - [ ] Check MySQL user permissions
  - [ ] Verify database name correct

### Session & Login Test
- [ ] Try login dengan default user (jika ada)
- [ ] Expected: Login successful atau show dashboard
- [ ] Check session cookies set
- [ ] Status: ✅ / ❌ (note any issues)

### File Upload Test (if login works)
- [ ] Navigate to upload feature
- [ ] Try upload photo
- [ ] Expected: File uploaded & resized
- [ ] Check: `/public_html/sipbgpi/uploads/` folder
- [ ] Status: ✅ / ❌

### Feature Testing
- [ ] **Navigation** - Bisa buka semua pages
  - [ ] Home / Dashboard
  - [ ] Forms page
  - [ ] Admin pages
  - [ ] Approval pages
- [ ] **Form Creation** - Bisa buat form baru
- [ ] **Data Entry** - Bisa input data
- [ ] **Export** - Bisa export PDF (jika ada)
- [ ] **QR Scan** - Scan code works (192.168.150.25)
- [ ] **Email** - Check notification email sent

### Error Logging
Jika ada error, catat:
```
Error URL: _____________________
Error Message: _____________________
Time: _____________________
Action: _____________________
```

---

## PHASE 5️⃣: Troubleshooting (Jika ada masalah)

### Database Connection Error
```
[ ] Cek .env file di /public_html/sipbgpi/
[ ] Verify credentials:
    Host: localhost (bukan IP)
    Name: sipbgpi_test_db
    User: sipbgpi_user
    Pass: [sesuai yang di-buat]
[ ] Test connection via check_db.php
```

### 404 Not Found
```
[ ] Verify files uploaded ke /public_html/sipbgpi/
[ ] Try: http://sipbgpi.rf.gd/index.php (add index.php)
[ ] Check .htaccess configuration
[ ] Verify folder structure
```

### Upload Permission Error
```
[ ] Create /public_html/sipbgpi/uploads/ folder
[ ] Set permissions: 755
[ ] Via File Manager → Right click → Properties
```

### Session Issues
```
[ ] Check cookies enabled in browser
[ ] Clear browser cache & cookies
[ ] Try incognito/private window
[ ] Check PHP session settings in phpMyAdmin
```

---

## PHASE 6️⃣: Sign-Off (Deployment Complete)

- [ ] All phases completed
- [ ] No critical errors
- [ ] Login works
- [ ] At least 1 feature tested successfully
- [ ] Date completed: __________
- [ ] Tester name: __________

### Issues Found
```
[ ] No issues - Ready for production
[ ] Minor issues - Noted in log
[ ] Major issues - Needs fix before production

Issue Log:
_____________________________
_____________________________
_____________________________
```

### Next Steps
- [ ] Report findings to project manager
- [ ] Schedule production deployment (ctkry.salimagro.com)
- [ ] Create prod deployment plan
- [ ] Setup production database
- [ ] Deploy to production domain

---

## 📞 Quick Reference

| Item | Value |
|------|-------|
| **Testing Domain** | sipbgpi.rf.gd |
| **DB Host** | localhost |
| **DB Name** | sipbgpi_test_db |
| **DB User** | sipbgpi_user |
| **Web Root** | /public_html/sipbgpi/ |
| **phpMyAdmin** | [In File Manager] |
| **FTP Upload** | [Get from dashboard] |

---

**Estimated Time**: 30-60 minutes total

**Good luck! 🚀**
