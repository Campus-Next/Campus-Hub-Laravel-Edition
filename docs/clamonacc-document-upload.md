# Tutorial PoC clamonacc untuk Upload Document

Dokumen ini khusus untuk flow upload document atau attachment event. Flow image/poster memakai app-level scanning dari Laravel sebelum file disimpan. Flow document di bawah ini memakai `clamonacc`, jadi scanning terjadi di layer OS ketika file masuk ke folder upload.

## Target path Campus Hub

Laravel menyimpan attachment melalui public disk ke path relatif:

```text
event_attachments/<uuid>.<ext>
```

Di dalam container/app root, path lengkapnya:

```text
/var/www/html/storage/app/public/event_attachments
```

Path ini berasal dari `storage/app/public/event_attachments/...`, lalu bisa diakses publik melalui symlink Laravel storage.

## Instalasi ClamAV

Ubuntu/Debian:

```bash
sudo apt update
sudo apt install -y clamav clamav-daemon
sudo systemctl stop clamav-freshclam || true
sudo freshclam
sudo systemctl enable --now clamav-daemon
```

Pastikan `clamd` aktif:

```bash
sudo systemctl status clamav-daemon
clamdscan --version
```

## Konfigurasi clamd untuk on-access scanning

Edit file:

```bash
sudo nano /etc/clamav/clamd.conf
```

Tambahkan atau sesuaikan konfigurasi ini:

```conf
LocalSocket /run/clamav/clamd.sock
OnAccessIncludePath /var/www/html/storage/app/public/event_attachments
OnAccessExcludeUname clamav
OnAccessPrevention yes
OnAccessExtraScanning yes
```

Buat folder quarantine dan log PoC:

```bash
sudo mkdir -p /home/devops/hasbi/quarantine/files
sudo touch /home/devops/hasbi/quarantine/clamav-upload.log
sudo chown -R www-data:www-data /home/devops/hasbi/quarantine
sudo chmod -R 750 /home/devops/hasbi/quarantine
```

Restart `clamd`:

```bash
sudo systemctl restart clamav-daemon
```

## Menjalankan clamonacc

`clamonacc` membutuhkan akses root karena memakai fanotify.

```bash
sudo clamonacc \
  --fdpass \
  --log=/home/devops/hasbi/quarantine/clamonacc-document.log
```

Untuk PoC manual, biarkan command ini berjalan di terminal terpisah. Untuk service systemd, buat service terpisah setelah konfigurasi terbukti bekerja.

## Test dengan EICAR

Buat file EICAR di folder upload document:

```bash
sudo mkdir -p /var/www/html/storage/app/public/event_attachments
printf 'X5O!P%%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' | \
  sudo tee /var/www/html/storage/app/public/event_attachments/eicar.txt >/dev/null
```

Cek log:

```bash
sudo tail -n 100 /home/devops/hasbi/quarantine/clamonacc-document.log
sudo tail -n 100 /var/log/clamav/clamav.log
```

Jika `OnAccessPrevention yes` aktif, akses atau operasi pada file infected akan diblokir oleh clamonacc/clamd. Untuk PoC quarantine manual, pindahkan file yang terdeteksi ke:

```text
/home/devops/hasbi/quarantine/files
```

## Catatan Docker/deployment

Jika aplikasi berjalan di container, pastikan path yang dimonitor adalah path yang terlihat oleh proses `clamonacc`. Untuk deployment Campus Hub yang bind-mount storage ke `/var/www/html/storage`, monitor:

```text
/var/www/html/storage/app/public/event_attachments
```

Jangan monitor hanya `/var/www/html/storage` jika ingin PoC fokus ke document upload. Path yang terlalu luas membuat log noise dan bisa memblokir file framework/cache yang bukan bagian dari skenario.

## Troubleshooting

- `clamonacc: fanotify not available`: jalankan di host Linux atau container dengan privilege/capability yang mendukung fanotify.
- File tidak terdeteksi: pastikan `clamd` aktif, database virus sudah update, dan `OnAccessIncludePath` sama persis dengan path upload document.
- Permission denied pada socket: gunakan `--fdpass`, cek owner `/run/clamav/clamd.sock`, dan pastikan user yang menjalankan `clamonacc` bisa mengakses socket.
- Folder upload belum ada: upload satu attachment lewat aplikasi atau buat manual dengan `sudo mkdir -p /var/www/html/storage/app/public/event_attachments`.
