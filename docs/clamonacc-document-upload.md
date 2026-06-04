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

Gunakan satu folder quarantine untuk PoC, dengan nama log yang dipisah per flow. Quarantine tetap terpisah dari log. Untuk Laravel container, path quarantine final ditentukan oleh environment `CLAMAV_QUARANTINE_PATH` dari compose.

```text
/home/devops/hasbi/quarantine/files
/var/log/clamav/campushub-clamonupload.log
/var/log/clamav/campushub-clamonacc.log
```

Maknanya:

- `campushub-clamonupload.log`: ditulis native oleh `clamd` host untuk app-level upload scanning.
- `campushub-clamonacc.log`: ditulis native oleh proses `clamonacc` untuk document on-access scanning.
- `files/`: tempat file infected yang dikarantina dari kedua flow PoC.

Catatan pemisahan log: `campushub-clamonupload.log` adalah log scanner `clamd`, bukan log Laravel custom. Untuk PoC ini file tersebut dipakai melihat scan app-level TCP `INSTREAM`. Jika `clamonacc` memakai instance `clamd` yang sama, verdict engine dari document scan bisa tetap muncul di log `clamd`; log yang khusus menunjukkan aktivitas on-access, prevention, dan move system-level adalah `campushub-clamonacc.log`.

Buat folder quarantine dan log:

```bash
QUARANTINE_PATH=${QUARANTINE_PATH:-/home/devops/hasbi/quarantine}
APP_UPLOAD_LOG=${APP_UPLOAD_LOG:-/var/log/clamav/campushub-clamonupload.log}
CLAMONACC_LOG=${CLAMONACC_LOG:-/var/log/clamav/campushub-clamonacc.log}

sudo mkdir -p "$QUARANTINE_PATH/files"
sudo mkdir -p /var/log/clamav
sudo touch "$APP_UPLOAD_LOG"
sudo touch "$CLAMONACC_LOG"
sudo chown -R root:clamav "$QUARANTINE_PATH"
sudo chown root:clamav "$APP_UPLOAD_LOG" "$CLAMONACC_LOG"
sudo chmod 750 "$QUARANTINE_PATH"
sudo chmod 700 "$QUARANTINE_PATH/files"
sudo chmod 660 "$APP_UPLOAD_LOG"
sudo chmod 640 "$CLAMONACC_LOG"
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

# Native clamd log untuk app-level upload scanning via TCP INSTREAM.
# Jika sudah ada LogFile lain, ganti menjadi path ini agar tidak dobel.
LogFile /var/log/clamav/campushub-clamonupload.log
LogTime yes
LogClean yes
ExtendedDetectionInfo yes

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
      CLAMAV_QUARANTINE_PATH: ${CLAMAV_QUARANTINE_PATH:-/home/devops/hasbi/quarantine}
```

`CLAMAV_QUARANTINE_PATH` adalah path yang dilihat dari dalam container Laravel. Tidak wajib mount khusus untuk quarantine. Jika ingin artefak quarantine Laravel tersimpan di host, mount path apa pun yang kamu pilih ke path container yang sama dengan nilai `CLAMAV_QUARANTINE_PATH`.

Container Laravel tidak perlu tahu path log ClamAV. Log app-level ditulis oleh `clamd` host dari konfigurasi `LogFile`, bukan oleh Laravel.

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

Gunakan service bawaan package, biasanya `clamav-clamonacc.service`. Jangan buat service baru untuk PoC ini.

Cek unit bawaan:

```bash
systemctl list-unit-files | grep -i clamonacc
sudo systemctl cat clamav-clamonacc.service
```

Override command service bawaan:

```bash
sudo systemctl edit clamav-clamonacc.service
```

Isi override:

```ini
[Service]
ExecStart=
ExecStart=/usr/sbin/clamonacc -F --config-file=/etc/clamav/clamd.conf --log=/var/log/clamav/campushub-clamonacc.log --move=/home/devops/hasbi/quarantine/files
```

Aktifkan service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now clamav-clamonacc.service
sudo systemctl restart clamav-clamonacc.service
sudo systemctl status clamav-clamonacc.service --no-pager -l
```

`--move` membuat file infected dipindah ke `/home/devops/hasbi/quarantine/files` setelah verdict `FOUND`. `--log` menulis log system-level ke `/var/log/clamav/campushub-clamonacc.log`.

Mode debug manual jika perlu:

```bash
sudo systemctl stop clamav-clamonacc.service
sudo clamonacc \
  --foreground \
  --verbose \
  --config-file=/etc/clamav/clamd.conf \
  --log=/var/log/clamav/campushub-clamonacc.log \
  --move=/home/devops/hasbi/quarantine/files
```

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
sudo tail -n 100 /var/log/clamav/campushub-clamonupload.log
sudo tail -n 100 /var/log/clamav/campushub-clamonacc.log
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

Verifikasi service system-level:

```bash
sudo systemctl is-active clamav-clamonacc.service
sudo systemctl status clamav-clamonacc.service --no-pager -l
sudo systemctl cat clamav-clamonacc.service | grep -E -- '--log=|--move=|OnAccess|ExecStart'
```

Ekspektasi:

```text
- service aktif.
- ExecStart memakai --log=/var/log/clamav/campushub-clamonacc.log.
- ExecStart memakai --move=/home/devops/hasbi/quarantine/files.
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
