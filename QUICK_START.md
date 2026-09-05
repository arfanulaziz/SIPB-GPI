# ⚡ Quick Start - Deployment ke InfinityFree

**Read this first!** Ini adalah checklist cepat. Untuk detail lengkap, lihat `DEPLOYMENT_INFINITYFREE.md`

---

## 🔧 Setup Database (5 menit)

```
1. Login: https://www.infinityfree.net
2. Klik: Hosting Accounts → Create New Account
3. Subdomain: sipbgpi (akan jadi sipbgpi.rf.gd)
4. Klik Create → Tunggu 2-3 menit
5. Manage → MySQL Manager
6. Buat Database: sipbgpi_test_db
7. Buat User: sipbgpi_user (password: sesuai yang kamu inginkan)
8. CATAT credentials di bawah ini ↓
```

---

## 📝 Catat Credentials Kamu

```
Database Host:     localhost
Database Name:     sipbgpi_test_db
Database User:     sipbgpi_user
Database Password: [MASUKKAN PASSWORD KAMU]
Domain:            sipbgpi.rf.gd
```

---

## 📂 Siapkan File (2 menit)

### Langkah 1: Edit File `.env`
1. Buka file: `.env.infinityfree`
2. Ganti nilai-nilai ini:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=sipbgpi_test_db          # ← GANTI
DB_USER=sipbgpi_user             # ← GANTI
DB_PASS=[PASSWORD_KAMU]          # ← GANTI
APP_URL=http://sipbgpi.rf.gd/   # ← GANTI
```

3. Rename `.env.infinityfree` → `.env`
4. Upload `.env` ke server

### Langkah 2: Upload Files
```
Semua file ada di folder ini:
├── public_html/    ← Upload ini ke /public_html/sipbgpi/
├── database/       ← Keep in /database/ atau upload juga
└── .env            ← Upload ini!
```

**Via File Manager**:
- Buka: Manage → File Manager
- Masuk: /public_html/
- Buat folder: sipbgpi
- Upload semua files

---

## 💾 Setup Database (3 menit)

### Import SQL Files
1. File Manager → phpMyAdmin
2. Login database
3. Pilih database: `sipbgpi_test_db`
4. Tab: **Import**
5. Upload: `database/migrations/001_SIPB_SCHEMA.sql`
6. Klik Import → Tunggu
7. Ulangi dengan `002_SIPB_SCHEMA_UPDATES.sql`

---

## ✅ Test Aplikasi

Buka: **http://sipbgpi.rf.gd/**

Seharusnya muncul login page atau dashboard (jika sudah login).

---

## ❌ Masalah?

| Error | Solusi |
|-------|--------|
| "Database connection failed" | Cek credentials di `.env` & MySQL Manager |
| "404 Not Found" | Pastikan files di `/public_html/sipbgpi/` |
| "uploads folder missing" | Buat folder `/public_html/sipbgpi/uploads/` |
| "Session timeout error" | Database sudah ada? Check phpMyAdmin tables |

---

## ✨ Test Feature Checklist

- [ ] Login works
- [ ] Create SIPB form
- [ ] Upload photo
- [ ] Export PDF
- [ ] Email notification sent
- [ ] QR scan (IP: 192.168.150.25)

---

## 🎯 Next Step

Setelah semua OK di InfinityFree, deploy ke production:  
**Domain**: ctkry.salimagro.com  
**Guide**: Use `.env.production` from original project

---

**Questions?** Refer ke `DEPLOYMENT_INFINITYFREE.md` untuk detail lengkap.
