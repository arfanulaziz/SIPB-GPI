# 🚀 SIPB-GPI Deployment Guide - InfinityFree

**Status**: Testing/Staging Environment  
**Final Production**: ctkry.salimagro.com  
**Date**: September 4, 2026

---

## 📋 Pre-Deployment Checklist

- [x] Database migrations ready (001_SIPB_SCHEMA.sql, 002_SIPB_SCHEMA_UPDATES.sql)
- [x] Environment configuration template prepared (.env.infinityfree)
- [x] All source files copied
- [x] Sensitive files excluded (.env with live credentials)

---

## Step 1️⃣: Buat Akun & Database di InfinityFree

### 1.1 Sign Up
- Go to: https://www.infinityfree.net
- Click **Sign Up**
- Gunakan email: `muhammadarfanulaziz@gmail.com`
- Set password

### 1.2 Buat Hosting Account
1. Login ke dashboard
2. Klik **Hosting Accounts**
3. Klik **Create New Account**
4. **Subdomain Name**: `sipbgpi` (akan jadi: `sipbgpi.rf.gd`)
5. Klik **Create Account** → Tunggu 2-3 menit

### 1.3 Setup Database MySQL
1. Di dashboard, buka hosting account kamu
2. Klik **Manage** → pilih tab **MySQL Manager**
3. **Buat Database Baru**:
   - **Database Name**: `sipbgpi_test_db`
   - **Klik "Create Database"**
4. **Buat MySQL User** (jika belum otomatis):
   - **Username**: `sipbgpi_user`
   - **Password**: Buat password kuat (catat baik-baik!)
   - Assign ke database `sipbgpi_test_db`

### 1.4 Catat Credentials
```
Database Host: localhost
Database Name: sipbgpi_test_db
Database User: sipbgpi_user
Database Password: [YOUR_PASSWORD]
Domain: sipbgpi.rf.gd
```

---

## Step 2️⃣: Siapkan File untuk Upload

### 2.1 Edit .env Configuration
1. **Buka file**: `.env.infinityfree` (di folder ini)
2. **Replace values** dengan InfinityFree credentials:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=sipbgpi_test_db          # ← Ganti dengan database name kamu
DB_USER=sipbgpi_user             # ← Ganti dengan username kamu
DB_PASS=YOUR_PASSWORD_HERE       # ← Ganti dengan password kamu
APP_URL=http://sipbgpi.rf.gd/   # ← Ganti dengan domain kamu
```

3. **Rename** `.env.infinityfree` → `.env`

### 2.2 Folder Structure untuk Upload
```
SIPB-GPI-DEPLOYMENT/
├── public_html/              # ← Upload ke public_html/sipbgpi/
│   ├── config/
│   ├── sipb/
│   ├── admin/
│   ├── approval/
│   ├── uploads/              # ← Buat folder ini
│   ├── index.php
│   ├── login.php
│   └── ... (semua PHP files)
├── database/
│   └── migrations/
│       ├── 001_SIPB_SCHEMA.sql
│       └── 002_SIPB_SCHEMA_UPDATES.sql
├── .env                      # ← RENAME dari .env.infinityfree
└── DEPLOYMENT_GUIDE.md
```

---

## Step 3️⃣: Upload Files ke InfinityFree

### 3.1 Metode 1: File Manager (Mudah)
1. Klik **Manage** → **File Manager**
2. Masuk ke folder `/public_html/`
3. **Buat folder baru**: `sipbgpi`
4. Upload file dengan cara:
   - Zip semua file (kecuali .env dengan credentials lama)
   - **Upload .zip** ke `/public_html/sipbgpi/`
   - **Extract** (klik kanan → Extract)
5. Upload `.env` file (yang sudah di-edit)

### 3.2 Metode 2: FTP (Lebih cepat untuk file besar)
1. Dapatkan FTP credentials dari File Manager
2. Gunakan FTP Client (WinSCP, FileZilla):
   - Host: infinityfree-server (dari credentials)
   - User: your-username
   - Pass: your-password
3. Upload ke: `/public_html/sipbgpi/`

---

## Step 4️⃣: Import Database

### 4.1 Menggunakan phpMyAdmin
1. Di File Manager, cari link **phpMyAdmin**
2. Login dengan credentials MySQL
3. Pilih database: `sipbgpi_test_db`
4. Klik tab **Import**
5. **Upload file SQL** (pilih dari `database/migrations/`):
   - Upload `001_SIPB_SCHEMA.sql` dulu
   - Tunggu selesai
   - Kemudian upload `002_SIPB_SCHEMA_UPDATES.sql`
6. Klik **Go/Import**

### 4.2 Verifikasi Database
1. Di phpMyAdmin, klik database `sipbgpi_test_db`
2. Verifikasi tables sudah ada:
   - `users`
   - `sipb_forms`
   - `sipb_items`
   - `sipb_audit_log`
   - dll...

---

## Step 5️⃣: Test Access

### 5.1 Buka Aplikasi
- Buka browser: `http://sipbgpi.rf.gd/`
- Atau: `http://sipbgpi.rf.gd/index.php`

### 5.2 Troubleshooting

**❌ "Database connection failed"**
- Cek `.env` file tersimpan dengan benar
- Verifikasi DB credentials di phpMyAdmin
- Check DB host = `localhost` (bukan IP)

**❌ "404 Not Found"**
- Pastikan folder struktur benar:
  - Files di `/public_html/sipbgpi/public_html/`
  - atau copy langsung ke `/public_html/`
- Check `.htaccess` jika ada

**❌ "Upload folder missing"**
- Buat folder `/public_html/sipbgpi/uploads/` secara manual
- Set permissions: 755 (read/write)

---

## Step 6️⃣: Test Fitur Utama

Sebelum pindah ke production, test:

- [ ] **Login** dengan user yang ada di database
- [ ] **Create SIPB** (form baru)
- [ ] **Photo Upload** (test fungsi resize)
- [ ] **QR Scan** (dengan IP testing: 192.168.150.25)
- [ ] **Email Notification** (check inbox)
- [ ] **PDF Export** (test print/export)
- [ ] **Approval Flow** (jika ada)

---

## Step 7️⃣: Production Deployment (Nanti)

Setelah testing OK di InfinityFree, deploy ke:
- **Domain**: ctkry.salimagro.com
- **Server**: hestia.salimagro.com:8083
- **Use**: `.env.production` (sudah ada di project original)

---

## 📞 Contacts & Resources

**InfinityFree Support**: https://infinityfree.net/support  
**phpMyAdmin**: Built-in di File Manager  
**FTP Credentials**: Check di InfinityFree dashboard  

**Project Memory**:
- PHP Built-in Function Conflict: Use `get_logged_in_user()` not `get_current_user()`
- Email System: Gmail SMTP (suspended), use noreply@domain untuk production
- QR Scanner: Works with IP 192.168.150.25

---

## ✅ Summary

```
Development (XAMPP)
    ↓
Testing (InfinityFree - sipbgpi.rf.gd)  ← YOU ARE HERE
    ↓
Production (ctkry.salimagro.com)
```

**Next Step**: Follow Step 1-4 di atas, test, then report findings! 🚀
