# 📦 SIPB-GPI Deployment Package

**Status**: Ready for InfinityFree Hosting  
**Environment**: Testing/Staging  
**Version**: September 4, 2026

---

## 📂 Apa aja di folder ini?

```
SIPB-GPI-DEPLOYMENT/
│
├── 📄 README.md                    ← You are here
├── 🚀 QUICK_START.md               ← Start here! (2 menit baca)
├── 📋 DEPLOYMENT_INFINITYFREE.md   ← Detail lengkap (step-by-step)
│
├── 🔧 .env.example                 ← Template untuk environment variables
├── 🔑 .env.infinityfree            ← Template untuk InfinityFree (EDIT INI!)
│
├── 📁 public_html/                 ← Folder aplikasi utama
│   ├── config/                     ← Database & app configuration
│   │   ├── config.php              ← Load .env & database setup
│   │   ├── Database.php            ← Database class
│   │   ├── Mailer.php
│   │   └── ...
│   ├── sipb/                       ← SIPB form logic
│   │   ├── create.php
│   │   ├── export-pdf.php
│   │   └── ...
│   ├── admin/                      ← Admin pages
│   ├── approval/                   ← Approval workflow
│   ├── uploads/                    ← Photo storage (auto-create)
│   ├── index.php                   ← Home page
│   ├── login.php                   ← Login page
│   ├── register.php
│   └── ...
│
└── 📁 database/
    └── migrations/
        ├── 001_SIPB_SCHEMA.sql     ← Database structure
        └── 002_SIPB_SCHEMA_UPDATES.sql
```

---

## ⚙️ Sebelum Upload

### 1. ✅ Buat Database di InfinityFree
- [x] Database name: `sipbgpi_test_db`
- [x] Database user: `sipbgpi_user`
- [x] Database password: **[Catat password kamu!]**

### 2. ✅ Edit File `.env.infinityfree`

**WAJIB di-edit sebelum upload!**

```
Buka: .env.infinityfree

Cari & ganti:
- DB_NAME=YOUR_INFINITYFREE_DB_NAME      → sipbgpi_test_db
- DB_USER=YOUR_INFINITYFREE_DB_USER      → sipbgpi_user
- DB_PASS=YOUR_INFINITYFREE_DB_PASSWORD  → [password kamu]
- APP_URL=http://YOUR_INFINITYFREE_DOMAIN/ → http://sipbgpi.rf.gd/

Kemudian rename: .env.infinityfree → .env
```

### 3. ✅ Verifikasi Folder
- [ ] `public_html/` ada semua files
- [ ] `database/migrations/` ada 2 SQL files
- [ ] `.env` sudah di-edit (bukan `.env.infinityfree`)

---

## 🚀 Proses Upload

### Option 1: Zip Upload (Recommended)
```
1. Zip semua file di folder ini (kecuali docs README.md)
2. Upload .zip ke InfinityFree File Manager
3. Extract di /public_html/sipbgpi/
4. Done!
```

### Option 2: Upload Folder
```
1. Buka File Manager → /public_html/
2. Buat folder: sipbgpi
3. Upload folder-folder:
   - public_html/* → /public_html/sipbgpi/
   - database/ → /public_html/sipbgpi/database/
4. Upload .env → /public_html/sipbgpi/.env
```

### Option 3: FTP Upload
```
1. Gunakan FTP client (FileZilla, WinSCP)
2. Connect ke InfinityFree FTP
3. Upload ke: /public_html/sipbgpi/
4. Done!
```

---

## 💾 Import Database

Setelah upload:

```
1. File Manager → phpMyAdmin
2. Login dengan MySQL credentials
3. Pilih database: sipbgpi_test_db
4. Tab: Import
5. Upload: database/migrations/001_SIPB_SCHEMA.sql
6. Klik Import → Tunggu selesai
7. Ulangi dengan: 002_SIPB_SCHEMA_UPDATES.sql
```

---

## ✅ Verifikasi Aplikasi

Buka browser:
- **URL**: http://sipbgpi.rf.gd/
- **Expected**: Muncul login page atau dashboard

---

## 🧪 Testing Checklist

Setelah aplikasi load, test ini:

- [ ] **Login Page** - Muncul form login
- [ ] **Database Connection** - Tidak ada error "connection failed"
- [ ] **Session Management** - Bisa login dengan user yang ada
- [ ] **File Upload** - Upload photo works
- [ ] **PDF Export** - Export to PDF works
- [ ] **Email** - Notification email terkirim (check inbox)
- [ ] **QR Scanner** - Scan berjalan (IP: 192.168.150.25)

---

## 📋 File Breakdown

### Critical Files
| File | Purpose |
|------|---------|
| `.env` | Database credentials & app config |
| `public_html/config/config.php` | Load .env & setup DB connection |
| `database/migrations/*.sql` | Database structure |

### Key Directories
| Directory | Purpose |
|-----------|---------|
| `public_html/config/` | Configuration files |
| `public_html/sipb/` | SIPB form processing |
| `public_html/uploads/` | Photo storage (create if missing) |
| `public_html/admin/` | Admin pages |

---

## 🔧 Troubleshooting

### Database Connection Failed
```
Problem: "Database connection failed: Access denied"
Solution:
  1. Check .env file has correct credentials
  2. Verify MySQL user exists in phpMyAdmin
  3. Check host = localhost (not IP)
```

### Upload Folder Permission Error
```
Problem: "uploads folder permission denied"
Solution:
  1. Create folder manually: /public_html/sipbgpi/uploads/
  2. Set permissions: 755
  3. Or create in phpMyAdmin first
```

### 404 Not Found
```
Problem: "404 Page Not Found"
Solution:
  1. Check files uploaded to correct path
  2. Should be: /public_html/sipbgpi/
  3. Try: http://sipbgpi.rf.gd/index.php
  4. Check .htaccess if have routing issues
```

---

## 📞 Support

**InfinityFree Help**: https://infinityfree.net/support  
**phpMyAdmin Guide**: Built-in documentation  

**Project Info**:
- Main Domain (Production): ctkry.salimagro.com
- Testing Domain: sipbgpi.rf.gd
- Original Project: C:\xampp\htdocs\SIPB-GPI

---

## ✨ Next Steps

1. ✅ **Setup DB** - Create database di InfinityFree
2. ✅ **Edit .env** - Update credentials
3. ✅ **Upload Files** - Upload ke hosting
4. ✅ **Import DB** - Run SQL migrations
5. ✅ **Test App** - Verify everything works
6. 📊 **Report Issues** - Document any problems
7. 🎯 **Deploy Production** - Move to ctkry.salimagro.com

---

**Ready? Start with `QUICK_START.md`! 🚀**
