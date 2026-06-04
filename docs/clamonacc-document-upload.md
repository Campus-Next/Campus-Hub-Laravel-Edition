# Tutorial PoC ClamAV: Image TCP INSTREAM dan Document clamonacc

Dokumen ini merapikan dua flow ClamAV Campus Hub:

- Image/poster upload: Laravel melakukan app-level scan sebelum file masuk `storage/app/public/events`. Laravel connect ke `clamd` host lewat TCP `host.docker.internal:3310` dan mengirim file dengan `INSTREAM`.
- Document/attachment upload: aplikasi menyimpan file ke `storage/app/public/event_attachments`, lalu `clamonacc` melakukan on-access scanning dengan `OnAccessExtraScanning yes`.

## Target path Campus Hub

Laravel menyimpan attachment document melalui public disk ke path relatif:

```text
event_attachments/<uuid>.<ext>
```

Di host/container deployment Campus Hub, path lengkapnya:

```text
/var/www/html/storage/app/public/event_attachments
```

Path ini berasal dari `storage/app/public/event_attachments/...`, lalu bisa diakses publik melalui symlink Laravel storage.

## Struktur quarantine dan log

Gunakan satu folder quarantine untuk PoC, dengan nama log yang dipisah per flow:

```text
/home/devops/hasbi/quarantine/files
/home/devops/hasbi/quarantine/clamav-upload.log
/home/devops/hasbi/quarantine/clamonacc-document.log
```

Maknanya:

- `clamav-upload.log`: ditulis Laravel untuk image/poster app-level scanning.
- `clamonacc-document.log`: ditulis proses `clamonacc` untuk document on-access scanning.
- `files/`: tempat file infected yang dikarantina dari kedua flow PoC.

Buat folder dan log:

```bash
sudo mkdir -p /home/devops/hasbi/quarantine/files
sudo touch /home/devops/hasbi/quarantine/clamav-upload.log
sudo touch /home/devops/hasbi/quarantine/clamonacc-document.log
sudo chown -R root:clamav /home/devops/hasbi/quarantine
sudo chmod 750 /home/devops/hasbi/quarantine
sudo chmod 700 /home/devops/hasbi/quarantine/files
sudo chmod 640 /home/devops/hasbi/quarantine/clamav-upload.log
sudo chmod 640 /home/devops/hasbi/quarantine/clamonacc-document.log
```

## Konfigurasi clamd TCP

Untuk PoC ini, `clamd` host diekspos lewat TCP. Laravel tidak memakai Unix socket dan tidak melakukan path scan.

Edit:

```bash
sudo nano /etc/clamav/clamd.conf
```

Tambahkan atau sesuaikan:

```conf
TCPSocket 3310
TCPAddr 0.0.0.0

# Folder document/attachment yang dimonitor oleh clamonacc.
OnAccessIncludePath /var/www/html/storage/app/public/event_attachments

# Jangan scan proses clamd sendiri, supaya tidak looping.
OnAccessExcludeUname clamav

# Blokir akses file malicious pada level fanotify.
OnAccessPrevention yes

# Initial scan tambahan saat file/directory dibuat atau dipindahkan.
OnAccessExtraScanning yes

# Opsional untuk PoC fail-closed: jika scan error, akses ditolak.
# Aktifkan hanya jika sudah yakin tidak mengganggu proses lain.
# OnAccessDenyOnError yes
```

Jika `clamav-daemon.socket` masih aktif dan port `3310` tidak muncul, matikan socket activation agar `clamd` memakai `TCPSocket` dari `clamd.conf`:

```bash
sudo systemctl disable --now clamav-daemon.socket
sudo systemctl restart clamav-daemon
sudo ss -lntp | grep ':3310'
```

Ekspektasi:

```text
LISTEN ... 0.0.0.0:3310 ...
```

## Konfigurasi Laravel image app-level scanning

Backend container perlu bisa memanggil ClamAV host lewat `host.docker.internal`.

Contoh `docker-compose.yml`:

```yaml
services:
  backend:
    extra_hosts:
      - "host.docker.internal:host-gateway"
    environment:
      CLAMAV_HOST: host.docker.internal
      CLAMAV_PORT: 3310
      CLAMAV_QUARANTINE_PATH: /home/devops/hasbi/quarantine
```

Laravel flow:

```text
upload image -> PHP temp file -> Laravel kirim bytes via INSTREAM ke clamd TCP -> CLEAN baru storeAs events -> FOUND ditolak dan dikarantina
```

Karena memakai `INSTREAM`, host `clamd` tidak perlu akses path temp upload container.

Test koneksi dari container backend:

```bash
docker exec -it campushub-dev-backend-1 sh -lc \
  'php -r '\''$s=@stream_socket_client("tcp://host.docker.internal:3310",$e,$m,3); var_dump($s ? "OK" : $m);'\'''
```

## Menjalankan clamonacc document flow

`clamonacc` membutuhkan akses root karena memakai fanotify.

```bash
sudo clamonacc \
  --fdpass \
  --log=/home/devops/hasbi/quarantine/clamonacc-document.log \
  --move=/home/devops/hasbi/quarantine/files
```

Mode debug yang lebih kelihatan:

```bash
sudo clamonacc \
  --foreground \
  --verbose \
  --fdpass \
  --log=/home/devops/hasbi/quarantine/clamonacc-document.log \
  --move=/home/devops/hasbi/quarantine/files
```

Untuk PoC manual, biarkan command ini berjalan di terminal terpisah. `--move` membuat file infected dipindah ke `/home/devops/hasbi/quarantine/files` setelah verdict `FOUND`.

## Test dengan EICAR untuk document

Buat file EICAR di staging, lalu pindahkan ke folder upload document. Ini mensimulasikan upload selesai ditulis lalu masuk ke storage final.

```bash
sudo mkdir -p /var/www/html/storage/app/public/event_attachments
mkdir -p /tmp/campushub-upload-staging
printf 'X5O!P%%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' | \
  tee /tmp/campushub-upload-staging/eicar.txt >/dev/null
sudo mv /tmp/campushub-upload-staging/eicar.txt /var/www/html/storage/app/public/event_attachments/eicar.txt
```

Cek log:

```bash
sudo tail -n 100 /home/devops/hasbi/quarantine/clamonacc-document.log
sudo tail -n 100 /var/log/clamav/clamav.log
sudo ls -la /home/devops/hasbi/quarantine/files
```

Ekspektasi:

```text
- Event create/move file ditangkap oleh OnAccessExtraScanning.
- clamd memberikan verdict FOUND untuk EICAR.
- clamonacc memindahkan file ke /home/devops/hasbi/quarantine/files karena memakai --move.
- File infected tidak lagi tersedia di /var/www/html/storage/app/public/event_attachments.
- Jika ada proses mencoba mengakses file malicious sebelum dipindah, OnAccessPrevention yes memblokir aksesnya.
```

## Troubleshooting

- `ss` tidak menampilkan `3310`: cek `clamd.conf`, restart `clamav-daemon`, dan disable `clamav-daemon.socket` jika socket activation mengambil alih.
- Laravel menampilkan scanner unavailable: pastikan `CLAMAV_HOST=host.docker.internal`, `CLAMAV_PORT=3310`, `extra_hosts` sudah ada, lalu jalankan `php artisan optimize:clear`.
- Upload image EICAR tidak ditolak: pastikan Laravel sudah rebuild dengan kode `INSTREAM`, bukan path scan lama.
- `clamonacc: fanotify not available`: jalankan di host Linux atau container privileged/capability yang mendukung fanotify.
- File document tidak terdeteksi: pastikan `OnAccessIncludePath` sama persis dengan `/var/www/html/storage/app/public/event_attachments`, `OnAccessExtraScanning yes` aktif, dan `clamonacc` masih berjalan.
- File infected document tidak pindah quarantine: pastikan `clamonacc` dijalankan dengan `--move=/home/devops/hasbi/quarantine/files` dan proses punya permission menulis ke folder quarantine.

Referensi:

- ClamAV On-Access docs: https://docs.clamav.net/manual/OnAccess.html
- `clamd.conf(5)` manpage: https://manpages.debian.org/testing/clamav-daemon/clamd.conf.5.en.html
