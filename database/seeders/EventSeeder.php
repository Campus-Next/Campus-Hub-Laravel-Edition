<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Image;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class EventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if user exist in case UserSeeder failed
        if (User::count() == 0) {
            $this->command->warn('No users found. Please run UserSeeder first.');
            return;
        }

        // If users with role 'admin' are seeded, assign them as organizer_id
        $users = User::role('admin')->get();

        // Check if there is ANY user with role 'admin'
        if ($users->isEmpty()) {
            $this->command->warn('No admin users found. Please run UserSeeder first.');
            return;
        }

        $categories = Category::all();

        if ($categories->isEmpty()) {
            $this->command->warn('No categories found. Please run CategorySeeder first.');
            return;
        }

        $eventSeeds = [
            [
                'title' => 'Data Storytelling Night',
                'category' => 'Seminar',
                'description' => 'Malam berbagi tentang cara mengubah data mentah menjadi cerita yang mudah dipahami. Cocok untuk mahasiswa yang ingin membuat presentasi riset, dashboard, atau laporan organisasi terasa lebih hidup.',
                'location' => 'Auditorium Bima',
                'max_participants' => 180,
            ],
            [
                'title' => 'Pitch Deck Klinik',
                'category' => 'Workshop',
                'description' => 'Sesi bedah pitch deck untuk tim mahasiswa yang sedang menyiapkan ide bisnis, lomba, atau demo day. Peserta akan mendapat masukan langsung tentang alur cerita, market sizing, dan slide yang paling sering membuat juri tertarik.',
                'location' => 'Innovation Lab 2',
                'max_participants' => 40,
            ],
            [
                'title' => 'Hack the Campus Flow',
                'category' => 'Competition',
                'description' => 'Hackathon singkat untuk merancang solusi digital bagi masalah sehari-hari di kampus. Tim mahasiswa akan memilih satu pain point, membuat prototype, lalu mempresentasikan dampaknya di akhir acara.',
                'location' => 'Co-Working Space Lt. 3',
                'max_participants' => 120,
            ],
            [
                'title' => 'Career Sprint: CV to Interview',
                'category' => 'Career',
                'description' => 'Program intensif untuk merapikan CV, LinkedIn, dan cara menjawab interview pertama. Mahasiswa akan berlatih dengan studi kasus rekrutmen nyata dan mendapat checklist personal untuk langkah berikutnya.',
                'location' => 'Ruang Seminar Utama',
                'max_participants' => 150,
            ],
            [
                'title' => 'Urban Sketch Walk',
                'category' => 'Art',
                'description' => 'Jalan santai sambil menggambar sudut-sudut kampus yang sering terlewat. Terbuka untuk pemula, komunitas seni, dan siapa pun yang ingin melatih observasi visual tanpa tekanan.',
                'location' => 'Titik Kumpul Plaza Kampus',
                'max_participants' => 35,
            ],
            [
                'title' => 'Cloud Basics for Student Builders',
                'category' => 'Webinar',
                'description' => 'Webinar pengantar cloud untuk mahasiswa yang ingin deploy aplikasi tanpa bingung memilih layanan. Pembicara akan membahas konsep hosting, database, storage, dan biaya dengan contoh sederhana.',
                'location' => 'Online',
                'max_participants' => 300,
            ],
            [
                'title' => 'Campus Night Run',
                'category' => 'Sport',
                'description' => 'Lari malam keliling area kampus dengan rute ramah pemula. Acara ini dibuat untuk membangun kebiasaan bergerak, bertemu teman baru, dan menikmati kampus dari suasana yang berbeda.',
                'location' => 'Lapangan Utama',
                'max_participants' => 200,
            ],
            [
                'title' => 'Bank Sampah Pop-Up',
                'category' => 'Volunteer',
                'description' => 'Aksi relawan untuk memilah sampah, menimbang hasil donasi, dan mengenalkan kebiasaan daur ulang yang praktis. Peserta akan ikut mengelola pos kecil yang dibuka untuk warga kampus.',
                'location' => 'Taman Tengah Kampus',
                'max_participants' => 80,
            ],
            [
                'title' => 'Ruang Riset Mahasiswa',
                'category' => 'Seminar',
                'description' => 'Forum ringan untuk mahasiswa yang ingin mulai riset tetapi belum tahu harus bertanya ke siapa. Dosen dan asisten riset akan menjelaskan cara memilih topik, membaca paper, dan mencari pembimbing.',
                'location' => 'Ruang 4.12 Gedung Akademik',
                'max_participants' => 90,
            ],
            [
                'title' => 'Design System Jam',
                'category' => 'Workshop',
                'description' => 'Workshop membuat komponen UI yang konsisten untuk aplikasi kampus. Peserta akan belajar token warna, spacing, state tombol, dan cara mendokumentasikan komponen agar mudah dipakai tim.',
                'location' => 'Laboratorium Multimedia',
                'max_participants' => 45,
            ],
            [
                'title' => 'Debat Isu Kota Cerdas',
                'category' => 'Competition',
                'description' => 'Kompetisi debat tentang transportasi, data publik, dan layanan kota cerdas. Formatnya cepat, argumentatif, dan dirancang agar peserta belajar menyusun posisi dengan bukti.',
                'location' => 'Aula Fakultas Sosial',
                'max_participants' => 96,
            ],
            [
                'title' => 'Remote Internship Briefing',
                'category' => 'Career',
                'description' => 'Sesi persiapan untuk mahasiswa yang ingin magang remote di startup atau komunitas global. Bahasannya meliputi portfolio, komunikasi async, dan cara menjaga ritme kerja lintas zona waktu.',
                'location' => 'Online',
                'max_participants' => 250,
            ],
            [
                'title' => 'Mini Concert: After Class Sessions',
                'category' => 'Art',
                'description' => 'Panggung musik kecil setelah jam kuliah untuk band, solois, dan penikmat musik kampus. Nuansanya santai, dekat, dan memberi ruang bagi karya original mahasiswa.',
                'location' => 'Amphitheater Kampus',
                'max_participants' => 220,
            ],
            [
                'title' => 'Futsal Fakultas Cup',
                'category' => 'Sport',
                'description' => 'Turnamen futsal antarfakultas dengan format grup singkat. Selain kompetitif, acara ini juga jadi tempat supporter kampus berkumpul dan merayakan sportivitas.',
                'location' => 'GOR Kampus',
                'max_participants' => 160,
            ],
            [
                'title' => 'Literasi Digital untuk Warga Sekitar',
                'category' => 'Volunteer',
                'description' => 'Program pendampingan dasar penggunaan email, penyimpanan cloud, dan keamanan akun untuk warga sekitar kampus. Mahasiswa akan menjadi fasilitator kecil dalam kelompok belajar.',
                'location' => 'Balai RW Mitra Kampus',
                'max_participants' => 60,
            ],
            [
                'title' => 'AI Ethics Roundtable',
                'category' => 'Seminar',
                'description' => 'Diskusi meja bundar tentang penggunaan AI dalam tugas, riset, dan organisasi mahasiswa. Peserta diajak membahas batas bantuan teknologi, atribusi, serta risiko bias.',
                'location' => 'Ruang Diskusi Perpustakaan',
                'max_participants' => 70,
            ],
            [
                'title' => 'No-Code Automation Lab',
                'category' => 'Workshop',
                'description' => 'Praktik membuat automasi sederhana untuk reminder, form response, dan rekap data organisasi. Cocok untuk bendahara, sekretaris, dan panitia event yang ingin mengurangi kerja manual.',
                'location' => 'Lab Komputer 1',
                'max_participants' => 36,
            ],
            [
                'title' => 'Capture The Flag: Beginner Arena',
                'category' => 'Competition',
                'description' => 'Kompetisi keamanan siber level pemula dengan soal web, crypto ringan, dan forensic sederhana. Peserta boleh datang tanpa pengalaman CTF, selama siap belajar cepat.',
                'location' => 'Cyber Security Lab',
                'max_participants' => 90,
            ],
            [
                'title' => 'LinkedIn Makeover Day',
                'category' => 'Career',
                'description' => 'Hari khusus untuk memperbaiki profil LinkedIn dan headline profesional mahasiswa. Akan ada sesi foto singkat, review profil, dan contoh narasi pengalaman organisasi.',
                'location' => 'Career Center',
                'max_participants' => 100,
            ],
            [
                'title' => 'Short Film Screening: Kampus dalam 5 Menit',
                'category' => 'Art',
                'description' => 'Pemutaran film pendek karya mahasiswa tentang rutinitas, ambisi, dan humor kehidupan kampus. Setelah screening, kreator akan berbagi proses produksi dari ide sampai editing.',
                'location' => 'Mini Theater',
                'max_participants' => 140,
            ],
            [
                'title' => 'Badminton Fun League',
                'category' => 'Sport',
                'description' => 'Liga badminton santai untuk mahasiswa lintas jurusan. Formatnya dibuat fleksibel agar pemula tetap bisa ikut, sementara pemain rutin punya ruang bertanding.',
                'location' => 'Hall Olahraga Indoor',
                'max_participants' => 80,
            ],
            [
                'title' => 'Green Campus Planting Day',
                'category' => 'Volunteer',
                'description' => 'Aksi menanam dan memberi label tanaman di beberapa titik kampus. Peserta akan belajar cara perawatan dasar sekaligus membantu membuat area belajar lebih teduh.',
                'location' => 'Kebun Edukasi',
                'max_participants' => 75,
            ],
            [
                'title' => 'Startup Failure Stories',
                'category' => 'Seminar',
                'description' => 'Talkshow tentang pelajaran dari proyek startup yang tidak berjalan sesuai rencana. Pembicara akan membahas keputusan sulit, validasi pasar, dan cara bangkit tanpa romantisasi kegagalan.',
                'location' => 'Auditorium Cendekia',
                'max_participants' => 180,
            ],
            [
                'title' => 'Product Thinking Bootcamp',
                'category' => 'Workshop',
                'description' => 'Bootcamp singkat untuk memahami masalah pengguna sebelum membuat fitur. Peserta akan menyusun user journey, prioritas backlog, dan eksperimen validasi sederhana.',
                'location' => 'Innovation Lab 1',
                'max_participants' => 50,
            ],
            [
                'title' => 'Business Case Clash',
                'category' => 'Competition',
                'description' => 'Kompetisi menyelesaikan studi kasus bisnis dalam waktu terbatas. Tim mahasiswa akan menganalisis data, memilih strategi, lalu mempertahankan rekomendasi di depan juri.',
                'location' => 'Ruang Sidang Fakultas Ekonomi',
                'max_participants' => 120,
            ],
            [
                'title' => 'Portfolio Review for Creatives',
                'category' => 'Career',
                'description' => 'Sesi review portfolio untuk mahasiswa desain, komunikasi, film, dan bidang kreatif lainnya. Reviewer akan memberi masukan tentang kurasi karya, narasi proses, dan tampilan akhir.',
                'location' => 'Studio Kreatif',
                'max_participants' => 60,
            ],
            [
                'title' => 'Poetry Open Mic: Suara Lorong',
                'category' => 'Art',
                'description' => 'Open mic puisi dan spoken word untuk menampung cerita mahasiswa dari lorong kelas, halte, dan meja kantin. Penampil baru sangat disambut, tanpa audisi dan tanpa tekanan.',
                'location' => 'Kafe Kampus',
                'max_participants' => 85,
            ],
            [
                'title' => 'Basket 3x3 Sunset Match',
                'category' => 'Sport',
                'description' => 'Pertandingan basket 3x3 sore hari dengan format cepat dan seru. Cocok untuk tim kecil yang ingin bertanding tanpa komitmen turnamen panjang.',
                'location' => 'Lapangan Basket Outdoor',
                'max_participants' => 96,
            ],
            [
                'title' => 'Tutor Sebaya Matematika Dasar',
                'category' => 'Volunteer',
                'description' => 'Program tutor sebaya untuk membantu mahasiswa tahun pertama memahami materi matematika dasar. Relawan akan memandu kelompok kecil dengan latihan soal yang ramah pemula.',
                'location' => 'Ruang Belajar Bersama',
                'max_participants' => 65,
            ],
            [
                'title' => 'Sustainable Fashion Talk',
                'category' => 'Seminar',
                'description' => 'Diskusi tentang pilihan berpakaian, konsumsi, dan dampak industri fashion. Mahasiswa akan diajak melihat pakaian bukan hanya sebagai gaya, tetapi juga keputusan lingkungan.',
                'location' => 'Aula Serbaguna',
                'max_participants' => 130,
            ],
            [
                'title' => 'Podcast Production 101',
                'category' => 'Workshop',
                'description' => 'Workshop membuat podcast dari konsep, rekaman, editing, sampai publikasi. Peserta akan mencoba menyusun episode pendek dan memahami cara menjaga kualitas audio.',
                'location' => 'Studio Audio Kampus',
                'max_participants' => 32,
            ],
            [
                'title' => 'Esports Strategy Weekend',
                'category' => 'Competition',
                'description' => 'Kompetisi esports dengan sesi analisis strategi setelah pertandingan. Tim tidak hanya mengejar kemenangan, tetapi juga belajar membaca rotasi, komunikasi, dan evaluasi replay.',
                'location' => 'Gaming Arena Kampus',
                'max_participants' => 128,
            ],
            [
                'title' => 'Mock Interview with Alumni',
                'category' => 'Career',
                'description' => 'Simulasi interview bersama alumni dari berbagai industri. Peserta akan mendapat feedback tentang cara menjawab, bahasa tubuh, dan cara menutup interview dengan percaya diri.',
                'location' => 'Career Center',
                'max_participants' => 70,
            ],
            [
                'title' => 'Mural Kolaborasi: Dinding Baru',
                'category' => 'Art',
                'description' => 'Sesi mural kolaboratif untuk memperbarui salah satu sudut kampus. Mahasiswa akan bekerja dalam kelompok kecil mulai dari sketsa, warna, hingga finishing.',
                'location' => 'Koridor Seni Gedung C',
                'max_participants' => 45,
            ],
            [
                'title' => 'Yoga Pagi di Plaza',
                'category' => 'Sport',
                'description' => 'Kelas yoga pagi dengan gerakan ringan untuk melepas tegang sebelum kuliah. Terbuka untuk semua level, termasuk peserta yang baru pertama kali mencoba.',
                'location' => 'Plaza Kampus',
                'max_participants' => 90,
            ],
            [
                'title' => 'Care Package untuk Mahasiswa Rantau',
                'category' => 'Volunteer',
                'description' => 'Aksi mengemas paket dukungan berisi kebutuhan kecil dan pesan penyemangat untuk mahasiswa rantau. Relawan akan membantu sortir, packing, dan distribusi di area kampus.',
                'location' => 'Sekretariat BEM',
                'max_participants' => 70,
            ],
            [
                'title' => 'Future of Work Forum',
                'category' => 'Webinar',
                'description' => 'Forum online tentang perubahan dunia kerja, skill yang makin dicari, dan cara mahasiswa menyiapkan diri sejak kuliah. Pembahasan dibuat praktis, bukan sekadar tren.',
                'location' => 'Online',
                'max_participants' => 350,
            ],
            [
                'title' => 'Intro to Robotics Playground',
                'category' => 'Workshop',
                'description' => 'Sesi pengenalan robotika melalui eksperimen kecil dengan sensor dan aktuator. Peserta akan melihat bagaimana ide sederhana bisa bergerak lewat kode dan rangkaian dasar.',
                'location' => 'Robotics Lab',
                'max_participants' => 40,
            ],
            [
                'title' => 'Ideathon Aplikasi Kampus',
                'category' => 'Competition',
                'description' => 'Kompetisi ide aplikasi untuk memperbaiki pengalaman mahasiswa, dosen, atau staf kampus. Fokusnya pada masalah yang jelas, solusi yang mungkin dibuat, dan dampak yang terukur.',
                'location' => 'Hall Gedung Teknologi',
                'max_participants' => 110,
            ],
            [
                'title' => 'Personal Branding for Scholarship Hunters',
                'category' => 'Career',
                'description' => 'Sesi membangun narasi diri untuk pendaftar beasiswa. Peserta akan belajar menyusun cerita akademik, pengalaman organisasi, dan tujuan masa depan secara meyakinkan.',
                'location' => 'Ruang Beasiswa',
                'max_participants' => 100,
            ],
            [
                'title' => 'Photography Walk: Light and Shadow',
                'category' => 'Art',
                'description' => 'Eksplorasi fotografi kampus dengan fokus pada cahaya, bayangan, dan komposisi. Peserta boleh memakai kamera profesional atau ponsel, karena latihan utamanya adalah melihat.',
                'location' => 'Titik Kumpul Perpustakaan',
                'max_participants' => 50,
            ],
            [
                'title' => 'Table Tennis Ladder',
                'category' => 'Sport',
                'description' => 'Format ladder untuk pemain tenis meja kampus dari berbagai level. Peserta bisa naik turun peringkat lewat pertandingan singkat yang santai tapi tetap kompetitif.',
                'location' => 'Ruang Tenis Meja',
                'max_participants' => 64,
            ],
            [
                'title' => 'Kelas Relawan Bencana Dasar',
                'category' => 'Volunteer',
                'description' => 'Pelatihan dasar untuk mahasiswa yang ingin memahami peran relawan saat terjadi bencana. Materi meliputi koordinasi, komunikasi, logistik, dan menjaga keselamatan diri.',
                'location' => 'Aula Kemanusiaan',
                'max_participants' => 90,
            ],
            [
                'title' => 'Fintech Literacy Hour',
                'category' => 'Webinar',
                'description' => 'Webinar literasi finansial tentang dompet digital, pinjaman online, investasi pemula, dan keamanan data pribadi. Tujuannya membantu mahasiswa mengambil keputusan finansial yang lebih sadar.',
                'location' => 'Online',
                'max_participants' => 320,
            ],
            [
                'title' => 'Public Speaking Drill Room',
                'category' => 'Workshop',
                'description' => 'Latihan public speaking dengan format singkat, berulang, dan penuh feedback. Peserta akan mencoba opening, storytelling, serta menjawab pertanyaan spontan.',
                'location' => 'Ruang Presentasi 2',
                'max_participants' => 38,
            ],
            [
                'title' => 'Scientific Poster Battle',
                'category' => 'Competition',
                'description' => 'Kompetisi poster ilmiah untuk menyampaikan hasil riset secara visual dan ringkas. Peserta dinilai dari kejelasan pesan, kualitas visual, dan kemampuan menjelaskan poster.',
                'location' => 'Lobi Gedung Riset',
                'max_participants' => 100,
            ],
            [
                'title' => 'Company Visit: Behind the Product',
                'category' => 'Career',
                'description' => 'Kunjungan industri untuk melihat bagaimana sebuah produk digital dirancang, dibangun, dan dioperasikan. Peserta akan berdialog dengan tim product, engineering, dan marketing.',
                'location' => 'Bus Kampus - Titik Kumpul Gerbang Utama',
                'max_participants' => 45,
            ],
            [
                'title' => 'Zine Making Corner',
                'category' => 'Art',
                'description' => 'Workshop kecil membuat zine dengan kolase, tulisan pendek, dan ilustrasi bebas. Cocok untuk mahasiswa yang ingin menuangkan opini atau cerita personal dalam format cetak sederhana.',
                'location' => 'Ruang Kreatif Perpustakaan',
                'max_participants' => 35,
            ],
            [
                'title' => 'Fun Archery Trial',
                'category' => 'Sport',
                'description' => 'Sesi coba panahan untuk pemula dengan pendampingan instruktur. Peserta akan belajar postur dasar, keselamatan, dan mencoba beberapa putaran tembakan ringan.',
                'location' => 'Lapangan Serbaguna',
                'max_participants' => 60,
            ],
            [
                'title' => 'Food Rescue Kantin',
                'category' => 'Volunteer',
                'description' => 'Gerakan mengumpulkan makanan layak konsumsi dari area kantin untuk disalurkan melalui mitra komunitas. Relawan akan belajar standar keamanan pangan sederhana dan alur distribusi.',
                'location' => 'Area Kantin Pusat',
                'max_participants' => 55,
            ],
        ];

        foreach ($eventSeeds as $index => $seed) {
            $category = $categories->firstWhere('name', $seed['category']) ?? $categories->random();

            $event = Event::factory()->raw([
                'title' => $seed['title'],
                'description' => $seed['description'],
                'location' => $seed['location'],
                'max_participants' => $seed['max_participants'],
                'organizer_id' => $users->random()->id,
                'category_id' => $category->id,
            ]);

            $eventModel = Event::updateOrCreate(
                ['title' => $event['title']],
                $event,
            );

            // Seed image assets
            $imageNum = ($index % 50) + 1; // event_1.jpg to event_50.jpg
            $sourcePath = database_path("seeders/images/events/event_{$imageNum}.jpg");
            $destDir = storage_path('app/public/images');
            
            if (!File::exists($destDir)) {
                File::makeDirectory($destDir, 0755, true);
            }
            
            $destPath = "{$destDir}/event_{$imageNum}.jpg";
            if (File::exists($sourcePath)) {
                File::copy($sourcePath, $destPath);
            }

            // Link image to the event
            Image::updateOrCreate(
                ['event_id' => $eventModel->id, 'path' => "images/event_{$imageNum}.jpg"],
                ['event_id' => $eventModel->id, 'path' => "images/event_{$imageNum}.jpg"]
            );
        }
    }
}
