---
description: Safe deployment workflow - push code to production server without breaking database
---

# 🔒 Aturan Emas: Safe Production Deployment (Kanban PPIC)

// turbo-all

## Prinsip Utama
1. **JANGAN PERNAH** jalankan `migrate:fresh` atau `migrate:rollback` di production
2. **SELALU** backup database sebelum deploy jika ada migration baru
3. **HANYA** jalankan `migrate` (tanpa flag) untuk migration baru di production
4. Perubahan PHP/Blade biasa **TIDAK PERLU** migration

---

## Langkah Deployment

### STEP 1: Commit & Push dari Local (Laragon)

```powershell
# Masuk ke folder project
cd c:\laragon\www\kanban-ppic

# Cek perubahan
git status

# Add semua perubahan
git add -A

# Commit dengan pesan deskriptif
git commit -m "feat: deskripsi singkat perubahan"

# Push ke production remote (Github)
git push origin main
```

### STEP 2: Backup Database di Server (WAJIB jika ada migration baru)

SSH ke server: `ssh peroniks@peroniks-ppicserver`

Lalu jalankan:
```bash
cd /srv/docker/apps/kanban-ppic

# Backup database sebelum pull
docker compose exec db mysqldump -u root -p[PASSWORD] kanban-ppic > ~/backups/kanban_backup_$(date +%Y%m%d_%H%M%S).sql
```

### STEP 3: Pull & Update di Server

```bash
cd /srv/docker/apps/kanban-ppic

# Pull kode terbaru
git pull origin main

sudo docker compose build --no-cache
sudo docker compose up -d

# Clear semua cache
docker compose exec app php artisan config:clear
docker compose exec app php artisan view:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan cache:clear

# HANYA jika ada migration baru (BUKAN migrate:fresh!)
docker compose exec app php artisan migrate

# Re-cache untuk production
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

### STEP 4: Verifikasi

1. Buka aplikasi di browser
2. Cek apakah fitur baru berfungsi
3. Cek apakah data lama masih ada

---

## ⚠️ PERINTAH BERBAHAYA - JANGAN GUNAKAN DI PRODUCTION

```bash
# ❌ JANGAN! Ini menghapus SEMUA data!
php artisan migrate:fresh
php artisan migrate:fresh --seed
php artisan migrate:rollback
php artisan db:wipe
```

---

## Checklist Sebelum Deploy

- [ ] Sudah test di local (Laragon)?
- [ ] Ada migration baru? Jika ya, backup database dulu!
- [ ] Commit message sudah jelas?
- [ ] Push ke remote `origin` (main branch)?

---

## Recovery Jika Terjadi Masalah

```bash
# Restore database dari backup
docker compose exec -T db mysql -u root -p[PASSWORD] kanban-ppic < ~/backups/kanban_backup_YYYYMMDD_HHMMSS.sql

# Rollback ke commit sebelumnya (gunakan dengan hati-hati)
git reset --hard HEAD~1
```