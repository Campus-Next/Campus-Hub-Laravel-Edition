# Tutorial PoC ClamAV: Image TCP INSTREAM dan Document clamonacc

Dokumen ini merapikan dua flow ClamAV Campus Hub:

- Image/poster upload: Laravel melakukan app-level scan sebelum file masuk `storage/app/public/events`. Laravel connect ke `clamd` host lewat TCP `host.docker.internal:3310` dan mengirim file dengan `INSTREAM`.
- Document/attachment upload: aplikasi menyimpan file ke `storage/app/public/event_attachments`, lalu `clamonacc` melakukan on-access scanning memakai fanotify.

## Arsitektur final

Untuk document/system-level, gunakan dua proses `clamonacc`:

```text
clamav-clamonacc.service
= watch folder upload document
= kalau FOUND, file dipindah ke quarantine dengan --move

campushub-clamonacc-quarantine.service
= watch folder quarantine
= tidak pakai --move
= hanya fanotify blocking agar file quarantine tetap tidak bisa dibaca proses biasa
```

Kenapa dua service?

```text
--move adalah action global untuk satu proses clamonacc.
Kalau satu clamonacc watch upload dan quarantine sekaligus lalu diberi --move,
quarantine juga ikut terkena action move. Itu rancu.
```

Jadi kita pisahkan:

```text
upload folder      -> clamonacc upload      -> --move ke quarantine
quarantine folder  -> clamonacc quarantine  -> fanotify block saja
```

## Target path Campus Hub

Laravel menyimpan attachment document melalui public disk ke path relatif:

```text
event_attachments/<uuid>.<ext>
```

Di host/container deployment Campus Hub, path lengkapnya:

```text
/var/www/html/storage/app/public/event_attachments
```

Quarantine PoC:

```text
/home/devops/hasbi/quarantine/files
```

## Struktur log

```text
/var/log/clamav/clamav.log
```

Maknanya:

- App-level image scan dari Laravel TCP `INSTREAM` masuk ke log ini lewat `clamd`.
- Document upload watcher masuk ke log ini lewat `clamonacc --log`.
- Quarantine watcher masuk ke log ini lewat `clamonacc --log`.

Timestamp dipakai yang gampang: `LogTime yes` di `clamd.conf`. Kalau versi `clamonacc` yang dipakai tidak memberi timestamp di baris log-nya, biarkan default saja untuk PoC.

## Cek fanotify

On-access prevention ClamAV hanya bisa memblokir akses jika kernel support fanotify permission events.

```bash
grep FANOTIFY /boot/config-$(uname -r)
```

Ekspektasi:

```text
CONFIG_FANOTIFY=y
CONFIG_FANOTIFY_ACCESS_PERMISSIONS=y
```

Kalau `CONFIG_FANOTIFY_ACCESS_PERMISSIONS` tidak aktif, ClamAV masih bisa scan dan log, tapi tidak bisa memblokir akses. Mode itu hanya notify-only.

## Setup folder dan permission

Buat folder:

```bash
sudo mkdir -p /var/www/html/storage/app/public/event_attachments
sudo mkdir -p /home/devops/hasbi/quarantine/files
sudo mkdir -p /var/log/clamav
```

Buat file log:

```bash
sudo touch /var/log/clamav/clamav.log
```

Permission log:

```bash
sudo chown root:clamav /var/log/clamav/clamav.log
sudo chmod 660 /var/log/clamav/clamav.log
```

Permission quarantine:

```bash
sudo chown 82:clamav /home/devops/hasbi/quarantine
sudo chown 82:clamav /home/devops/hasbi/quarantine/files
sudo chmod 750 /home/devops/hasbi/quarantine
sudo chmod 2770 /home/devops/hasbi/quarantine/files
```

Kenapa `82:clamav`?

```text
82
= UID www-data di container backend kamu.
= perlu bisa menulis app-level quarantine dari Laravel.

clamav
= group untuk user clamav di host.
= perlu bisa membaca file quarantine agar clamd bisa scan.

750 /home/devops/hasbi/quarantine
= user biasa tidak bisa masuk.

2770 /home/devops/hasbi/quarantine/files
= UID 82 bisa menulis, group clamav bisa membaca/scan, user biasa tetap tidak bisa masuk.
= bit 2 di depan membuat file/folder baru cenderung mewarisi group clamav.
```

Kalau UID container berubah, cek lagi:

```bash
docker exec -it campushub-dev-backend-1 sh -lc 'id www-data'
```

Ganti `82` dengan UID yang muncul.

## Konfigurasi clamd TCP dan shared on-access option

Edit:

```bash
sudo nano /etc/clamav/clamd.conf
```

Tambahkan atau sesuaikan:

```conf
TCPSocket 3310
TCPAddr 0.0.0.0

# Log terpusat untuk clamd dan clamonacc PoC.
LogFile /var/log/clamav/clamav.log
LogFileUnlock yes
LogTime yes
LogClean yes
ExtendedDetectionInfo yes

# Jangan scan proses clamd sendiri, supaya tidak looping.
# Ini hanya bypass fanotify, bukan bypass permission Linux.
OnAccessExcludeUname clamav

# Blokir akses file malicious pada level fanotify.
OnAccessPrevention yes

# Scan tambahan saat file/directory dibuat atau dipindahkan.
OnAccessExtraScanning yes

# Opsional untuk PoC fail-closed: jika scan error, akses ditolak.
# Aktifkan hanya jika sudah yakin tidak mengganggu proses lain.
# OnAccessDenyOnError yes
```

Jangan taruh `OnAccessIncludePath` langsung di `clamd.conf` untuk PoC dua service ini. Path yang dipantau akan dipisahkan lewat `--include-list`, supaya action upload dan quarantine tidak campur.

Kalau sudah ada baris lama seperti ini, comment atau hapus:

```conf
OnAccessIncludePath /var/www/html/storage/app/public/event_attachments
OnAccessIncludePath /home/devops/hasbi/quarantine/files
```

Restart `clamd`:

```bash
sudo systemctl disable --now clamav-daemon.socket
sudo systemctl restart clamav-daemon
sudo ss -lntp | grep ':3310'
```

Ekspektasi:

```text
LISTEN ... 0.0.0.0:3310 ...
```

## Include list untuk dua clamonacc

Buat include list upload:

```bash
sudo tee /etc/clamav/campushub-clamonacc-upload.includes >/dev/null <<'EOF'
/var/www/html/storage/app/public/event_attachments
EOF
```

Buat include list quarantine:

```bash
sudo tee /etc/clamav/campushub-clamonacc-quarantine.includes >/dev/null <<'EOF'
/home/devops/hasbi/quarantine/files
EOF
```

Permission:

```bash
sudo chown root:clamav /etc/clamav/campushub-clamonacc-upload.includes
sudo chown root:clamav /etc/clamav/campushub-clamonacc-quarantine.includes
sudo chmod 640 /etc/clamav/campushub-clamonacc-upload.includes
sudo chmod 640 /etc/clamav/campushub-clamonacc-quarantine.includes
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
      CLAMAV_PORT: "3310"
      CLAMAV_QUARANTINE_PATH: /quarantine
    volumes:
      - /var/www/html/storage:/var/www/html/storage
      - /home/devops/hasbi/quarantine:/quarantine
```

`CLAMAV_QUARANTINE_PATH` adalah path yang dilihat dari dalam container Laravel. Dengan contoh di atas, Laravel menulis quarantine ke `/quarantine/files`, lalu Docker menyimpannya di host sebagai `/home/devops/hasbi/quarantine/files`.

Container Laravel tidak perlu tahu path log ClamAV. Log app-level ditulis oleh `clamd` host dari konfigurasi `LogFile`, bukan oleh Laravel.

Laravel flow:

```text
upload image -> PHP temp file -> Laravel kirim bytes via INSTREAM ke clamd TCP -> CLEAN baru storeAs events -> FOUND ditolak dan dikarantina
```

Test koneksi dari container backend:

```bash
docker exec -it campushub-dev-backend-1 sh -lc \
  'php -r '\''$s=@stream_socket_client("tcp://host.docker.internal:3310",$e,$m,3); var_dump($s ? "OK" : $m);'\'''
```

## Service 1: upload document watcher

Service ini memantau folder upload document dan memindahkan file infected ke quarantine.

Gunakan service bawaan package:

```bash
systemctl list-unit-files | grep -i clamonacc
sudo systemctl cat clamav-clamonacc.service
```

Override service bawaan:

```bash
sudo systemctl edit clamav-clamonacc.service
```

Isi override:

```ini
[Service]
ExecStart=
ExecStart=/usr/sbin/clamonacc -F --verbose --config-file=/etc/clamav/clamd.conf --include-list=/etc/clamav/campushub-clamonacc-upload.includes --log=/var/log/clamav/clamav.log --move=/home/devops/hasbi/quarantine/files
```

Aktifkan:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now clamav-clamonacc.service
sudo systemctl restart clamav-clamonacc.service
sudo systemctl status clamav-clamonacc.service --no-pager -l
```

Verifikasi:

```bash
sudo systemctl cat clamav-clamonacc.service | grep -E -- 'ExecStart|--include-list|--move|--log'
```

Ekspektasi:

```text
--include-list=/etc/clamav/campushub-clamonacc-upload.includes
--move=/home/devops/hasbi/quarantine/files
--log=/var/log/clamav/clamav.log
```

## Service 2: quarantine fanotify watcher

Service ini memantau folder quarantine dan tidak memakai `--move`.

Tujuannya:

```text
Kalau ada proses biasa mencoba membaca file malware di quarantine,
fanotify tetap memblokir aksesnya.
```

Buat unit baru:

```bash
sudo tee /etc/systemd/system/campushub-clamonacc-quarantine.service >/dev/null <<'EOF'
[Unit]
Description=CampusHub ClamAV quarantine fanotify watcher
After=clamav-daemon.service
Requires=clamav-daemon.service

[Service]
Type=simple
ExecStart=/usr/sbin/clamonacc -F --verbose --config-file=/etc/clamav/clamd.conf --include-list=/etc/clamav/campushub-clamonacc-quarantine.includes --log=/var/log/clamav/clamav.log
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
```

Aktifkan:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now campushub-clamonacc-quarantine.service
sudo systemctl restart campushub-clamonacc-quarantine.service
sudo systemctl status campushub-clamonacc-quarantine.service --no-pager -l
```

Verifikasi:

```bash
sudo systemctl cat campushub-clamonacc-quarantine.service | grep -E -- 'ExecStart|--include-list|--move|--log'
```

Ekspektasi:

```text
--include-list=/etc/clamav/campushub-clamonacc-quarantine.includes
--log=/var/log/clamav/clamav.log
tidak ada --move
```

## Test 1: upload document masuk quarantine

Buat EICAR:

```bash
mkdir -p /tmp/campushub-upload-staging
printf 'X5O!P%%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' \
  > /tmp/campushub-upload-staging/eicar.txt
```

Pindahkan ke folder upload document:

```bash
sudo mv /tmp/campushub-upload-staging/eicar.txt \
  /var/www/html/storage/app/public/event_attachments/eicar.txt
```

Cek upload folder:

```bash
ls -lah /var/www/html/storage/app/public/event_attachments/eicar.txt
```

Ekspektasi:

```text
No such file or directory
```

Cek quarantine:

```bash
sudo ls -lah /home/devops/hasbi/quarantine/files
```

Ekspektasi:

```text
file EICAR ada di quarantine
```

Cek log:

```bash
sudo tail -n 150 /var/log/clamav/clamav.log
```

## Test 2: bukti quarantine tidak bisa diakses user biasa

Tanpa `sudo`:

```bash
ls -lah /home/devops/hasbi/quarantine
```

Ekspektasi:

```text
Permission denied
```

Ini bukti permission Linux quarantine bekerja.

## Test 3: bukti fanotify di quarantine

Test ini khusus untuk membuktikan fanotify, jadi permission quarantine perlu dibuka sementara. Jika permission tetap ketat, hasilnya akan `Permission denied` dari Linux permission duluan, bukan dari fanotify.

Buka sementara:

```bash
sudo chmod 755 /home/devops/hasbi/quarantine
sudo chmod 755 /home/devops/hasbi/quarantine/files
sudo chmod 644 /home/devops/hasbi/quarantine/files/NAMA_FILE_EICAR
```

Coba baca tanpa `sudo`:

```bash
cat /home/devops/hasbi/quarantine/files/NAMA_FILE_EICAR
```

Ekspektasi fanotify:

```text
Operation not permitted
```

Kalau ingin bukti lebih teknis:

```bash
strace -e openat,read cat /home/devops/hasbi/quarantine/files/NAMA_FILE_EICAR
```

Ekspektasi ada error:

```text
EACCES
```

atau:

```text
EPERM
```

Balikkan permission setelah test:

```bash
sudo chmod 750 /home/devops/hasbi/quarantine
sudo chmod 2770 /home/devops/hasbi/quarantine/files
```

## Akses forensik

Kalau file quarantine diproteksi fanotify, user biasa akan diblokir. Untuk forensik, jangan matikan semua proteksi. Buat user khusus:

```bash
sudo useradd -r -s /usr/sbin/nologin forensic
```

Tambahkan ke `clamd.conf` jika memang ingin user ini bypass fanotify:

```conf
OnAccessExcludeUname forensic
```

Lalu restart:

```bash
sudo systemctl restart clamav-daemon
sudo systemctl restart clamav-clamonacc.service
sudo systemctl restart campushub-clamonacc-quarantine.service
```

Berikan permission sesuai kebutuhan forensik, lalu akses memakai user tersebut:

```bash
sudo -u forensic sha256sum /home/devops/hasbi/quarantine/files/NAMA_FILE_EICAR
```

Catatan:

```text
OnAccessExcludeUname forensic hanya bypass fanotify.
Permission Linux tetap harus mengizinkan user forensic masuk dan membaca file.
```

## Troubleshooting

- `ss` tidak menampilkan `3310`: cek `clamd.conf`, restart `clamav-daemon`, dan disable `clamav-daemon.socket` jika socket activation mengambil alih.
- Laravel menampilkan scanner unavailable: pastikan `CLAMAV_HOST=host.docker.internal`, `CLAMAV_PORT=3310`, `extra_hosts` sudah ada, lalu jalankan `php artisan optimize:clear`.
- Upload image EICAR tidak ditolak: pastikan Laravel sudah rebuild dengan kode `INSTREAM`, bukan path scan lama.
- `clamonacc: fanotify not available`: jalankan di host Linux atau container privileged/capability yang mendukung fanotify.
- File document tidak terdeteksi: pastikan `clamav-clamonacc.service` memakai include list upload.
- File infected document tidak pindah quarantine: pastikan service upload memakai `--move=/home/devops/hasbi/quarantine/files`.
- File quarantine tidak diblok fanotify: pastikan `campushub-clamonacc-quarantine.service` aktif dan include list quarantine benar.
- `cat` ke quarantine menghasilkan `Permission denied`: itu permission Linux. Untuk bukti fanotify, lakukan Test 3 dengan permission sementara.

Referensi:

- ClamAV On-Access docs: https://docs.clamav.net/manual/OnAccess.html
- Linux fanotify manual: https://man7.org/linux/man-pages/man7/fanotify.7.html
- `clamd.conf(5)` manpage: https://manpages.debian.org/testing/clamav-daemon/clamd.conf.5.en.html
