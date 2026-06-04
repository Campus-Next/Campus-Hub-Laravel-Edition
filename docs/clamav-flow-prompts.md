# Prompt Diagram Flow PoC ClamAV

## Prompt 1 - Upload/Edit Image dengan app-level scanning

```text
Buat diagram flow teknis berbahasa Indonesia untuk PoC "Upload/Edit Image dengan ClamAV app-level scanning". Buat gambar terpisah hanya untuk flow ini. Tampilkan aktor: Admin Vue Frontend, Laravel API, EventImageService, Clamd Scanner, Public Storage, Quarantine Folder, Log File. Alur: admin memilih file apa pun di form poster/image, frontend mengirim FormData field image tanpa validasi MIME/extension, Laravel menerima request dan tetap cek auth/permission, file temp discan via clamd sebelum disimpan, jika CLEAN maka file disimpan ke storage/app/public/events dan metadata Image dibuat, jika FOUND maka file disalin ke /home/devops/hasbi/quarantine/files, event dicatat ke /home/devops/hasbi/quarantine/clamav-upload.log, lalu API menolak request 400 Malware detected. Tambahkan jalur error scanner unavailable: API menolak 503 dan mencatat log. Gaya diagram: clean security architecture, arrows jelas, warna hijau untuk CLEAN, merah untuk FOUND, kuning untuk scanner error, tanpa dekorasi berlebihan.
```

## Prompt 2 - Upload Document dengan clamonacc

```text
Buat diagram flow teknis berbahasa Indonesia untuk PoC "Upload Document dengan ClamAV clamonacc on-access scanning". Buat gambar terpisah hanya untuk flow ini. Tampilkan aktor: Admin Vue Frontend, Laravel API, EventAttachmentService, Laravel Public Storage, clamonacc, clamd, Quarantine/Log Area. Alur: admin mengupload document/attachment lewat FormData field attachment, aplikasi tidak melakukan app-level scan, Laravel menyimpan file ke storage/app/public/event_attachments, clamonacc memonitor path tersebut dengan fanotify, clamonacc meminta clamd melakukan scanning, jika file CLEAN maka file tetap tersedia melalui public storage symlink, jika FOUND maka on-access prevention memblokir akses/operasi sesuai konfigurasi dan kejadian dicatat ke log ClamAV serta area /home/devops/hasbi/quarantine untuk PoC. Sertakan catatan visual bahwa document flow berbeda dari image flow karena scanning terjadi di layer OS/on-access, bukan di controller/service Laravel. Gaya diagram: clean infrastructure/security flow, pisahkan application layer dan OS/ClamAV layer, gunakan hijau untuk CLEAN dan merah untuk FOUND.
```
