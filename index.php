<?php
/**
 * PORTAL SALIN ASLIMAS (Sadewo Lintarti ASN Peduli Pekerja Banyumas)
 * Public portal: Beranda + Form Pengajuan + Progres OPD + Cek Kepesertaan
 */

require __DIR__ . '/inc/bootstrap.php';

// --- LOGIKA SIMPAN PENGAJUAN (FORM MANUAL) ---
$msg = isset($_GET['msg']) ? $_GET['msg'] : "";
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : "home";

if ($connected && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_pengajuan'])) {
    $status_kedinasan_raw = strtolower(trim((string)($_POST['status_kedinasan'] ?? 'dinas')));
    $status_kedinasan = ($status_kedinasan_raw === 'swasta') ? 'swasta' : 'dinas';

    // Mapping penyimpanan:
    // - dinas: asn_* seperti sebelumnya
    // - swasta: asn_opd = nama perusahaan, asn_nama = nama PIC, asn_jabatan = jabatan PIC, asn_hp = WA PIC, asn_nip = '-'
    if ($status_kedinasan === 'swasta') {
        $asn_opd = $conn->real_escape_string((string)($_POST['swasta_nama_perusahaan'] ?? ''));
        $asn_nama = $conn->real_escape_string((string)($_POST['swasta_nama'] ?? ''));
        $asn_jabatan = $conn->real_escape_string((string)($_POST['swasta_jabatan'] ?? ''));
        $asn_hp = $conn->real_escape_string((string)($_POST['swasta_hp'] ?? ''));
        $asn_nip = $conn->real_escape_string('-');
    } else {
        $asn_nama = $conn->real_escape_string((string)($_POST['asn_nama'] ?? ''));
        $asn_nip = $conn->real_escape_string((string)($_POST['asn_nip'] ?? ''));
        $asn_opd = $conn->real_escape_string((string)($_POST['asn_opd'] ?? ''));
        $asn_jabatan = $conn->real_escape_string((string)($_POST['asn_jabatan'] ?? ''));
        $asn_hp = $conn->real_escape_string((string)($_POST['asn_hp'] ?? ''));
    }

    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
    if (!is_dir('uploads/ktp')) mkdir('uploads/ktp', 0777, true);
    $worker_names = $_POST['worker_nama'] ?? [];
    $success_count = 0;

    foreach ($worker_names as $key => $val) {
        $w_nama  = $conn->real_escape_string($_POST['worker_nama'][$key] ?? '');
        $w_nik   = $conn->real_escape_string($_POST['worker_nik'][$key] ?? '');
        
        $w_tmpt  = $conn->real_escape_string($_POST['worker_tmpt_lahir'][$key] ?? '');
        $w_tgl   = $conn->real_escape_string($_POST['worker_tgl_lahir'][$key] ?? '');
        $w_ttl   = trim($w_tmpt . ', ' . $w_tgl, ', ');

        $w_jk    = $conn->real_escape_string($_POST['worker_jk'][$key] ?? '');
        $w_job   = $conn->real_escape_string($_POST['worker_job'][$key] ?? '');
        $w_hp    = $conn->real_escape_string($_POST['worker_hp'][$key] ?? '');

        // Upload KTP per pekerja (opsional)
        $w_ktp_filename = '';
        if (!empty($_FILES['worker_ktp']['name'][$key] ?? '') && (int)($_FILES['worker_ktp']['error'][$key] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = $_FILES['worker_ktp']['tmp_name'][$key] ?? '';
            $size = (int)($_FILES['worker_ktp']['size'][$key] ?? 0);

            if ($tmp !== '' && is_uploaded_file($tmp) && $size > 0 && $size <= 3 * 1024 * 1024) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$finfo->file($tmp);
                $ext = '';
                if ($mime === 'image/jpeg') $ext = 'jpg';
                if ($mime === 'image/png') $ext = 'png';

                if ($ext !== '') {
                    $rand = bin2hex(random_bytes(8));
                    $filename = 'ktp_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
                    $dest = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'ktp' . DIRECTORY_SEPARATOR . $filename;
                    if (move_uploaded_file($tmp, $dest)) {
                        $w_ktp_filename = $filename;
                    }
                }
            }
        }
        
        if (!empty($w_nama)) {
            $w_ktp_sql = $w_ktp_filename !== '' ? ("'" . $conn->real_escape_string($w_ktp_filename) . "'") : "NULL";
            $sql = "INSERT INTO salin_aslimas_data (status_kedinasan, asn_nama, asn_nip, asn_opd, asn_jabatan, asn_hp, pekerja_nama, pekerja_nik, pekerja_ttl, pekerja_jk, pekerja_job, pekerja_hp, pekerja_ktp) 
                    VALUES ('$status_kedinasan', '$asn_nama', '$asn_nip', '$asn_opd', '$asn_jabatan', '$asn_hp', '$w_nama', '$w_nik', '$w_ttl', '$w_jk', '$w_job', '$w_hp', $w_ktp_sql)";
            if ($conn->query($sql)) $success_count++;
        }
    }
    header("Location: ?tab=form&msg=Berhasil menyimpan $success_count data.");
    exit();
}

// --- API kecil untuk cek kepesertaan berdasarkan NIK (JSON) ---
if ($connected && isset($_GET['action']) && $_GET['action'] === 'cek_nik') {
    header('Content-Type: application/json; charset=utf-8');
    $nik = preg_replace('/\D+/', '', (string)($_GET['nik'] ?? ''));
    if (strlen($nik) < 8) {
        echo json_encode(['ok' => false, 'message' => 'NIK tidak valid.']);
        exit();
    }

    $nik_esc = $conn->real_escape_string($nik);
    $q = $conn->query("SELECT pekerja_nama, pekerja_nik, status_kepesertaan, asn_opd, asn_nama, tgl_input
                       FROM salin_aslimas_data
                       WHERE pekerja_nik = '$nik_esc'
                       ORDER BY tgl_input DESC
                       LIMIT 1");

    if ($q && $q->num_rows > 0) {
        $row = $q->fetch_assoc();
        echo json_encode(['ok' => true, 'found' => true, 'data' => $row]);
        exit();
    }

    echo json_encode(['ok' => true, 'found' => false]);
    exit();
}

// Ambil Data Referensi & Branding
$branding = salin_aslimas_fetch_branding($conn, $connected);

$def_logo_pemda = salin_aslimas_to_url($branding['logo_pemda'] ?? '') ?: "https://upload.wikimedia.org/wikipedia/commons/e/e8/Logo_Kabupaten_Banyumas.png";
$def_logo_bpjs  = salin_aslimas_to_url($branding['logo_bpjs'] ?? '') ?: "https://upload.wikimedia.org/wikipedia/commons/b/b2/Logo_BPJS_Ketenagakerjaan.png";
$def_logo_baznas = salin_aslimas_to_url($branding['logo_baznas'] ?? '') ?: "https://dummyimage.com/200x80/ffffff/065f46&text=Logo+BAZNAS";
$def_foto_bupati = salin_aslimas_to_url($branding['foto_bupati'] ?? '') ?: "https://dummyimage.com/800x1000/065f46/ffffff&text=Bupati+Banyumas";

$opd_options = salin_aslimas_fetch_opd_options($conn, $connected);
$job_options = salin_aslimas_fetch_job_options($conn, $connected);
$rekap_query = $connected ? $conn->query("
    SELECT
        d.asn_opd,
        COALESCE(o.jumlah_asn, 0) as jumlah_asn,
        COUNT(DISTINCT d.asn_nip) as total_asn,
        COUNT(d.pekerja_nik) as total_pekerja,
        COUNT(CASE WHEN d.status_kepesertaan = 'Aktif' THEN 1 END) as pekerja_dari_opd
    FROM salin_aslimas_data d
    LEFT JOIN ref_opd o ON o.nama_opd = d.asn_opd
    GROUP BY d.asn_opd, o.jumlah_asn
    ORDER BY pekerja_dari_opd DESC, total_pekerja DESC, asn_opd ASC
") : false;

$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salin Aslimas - Banyumas Peduli Pekerja</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], heading: ['Plus Jakarta Sans', 'sans-serif'] },
                    colors: { brand: { 50: '#ecfdf5', 100: '#d1fae5', 500: '#10b981', 600: '#059669', 700: '#047857', 900: '#064e3b' } },
                    boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.05)', 'card': '0 4px 20px rgba(0,0,0,0.03)' }
                }
            }
        }
    </script>
    <style>
        body { background-color: #FAFAFA; }
        .tab-content { display: none; opacity: 0; transition: opacity 0.4s ease-in-out; }
        .tab-content.active { display: block; opacity: 1; animation: slideUp 0.5s ease-out forwards; }
        .nav-link { position: relative; transition: color 0.3s ease; }
        .nav-link::after { content: ''; position: absolute; width: 0; height: 2px; bottom: -4px; left: 0; background-color: #059669; transition: width 0.3s ease; }
        .nav-link.active { color: #064e3b; font-weight: 600; }
        .nav-link.active::after { width: 100%; }
        .glass-header { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255,255,255,0.3); }
        .input-premium{
    width:100%;
    background:#f8fafc;
    border:1.5px solid #cbd5e1; /* garis terlihat */
    color:#1e293b;
    border-radius:12px;
    padding:14px 16px;
    outline:none;
    transition:all .25s ease;
    font-size:14px;
}

.input-premium:hover{
    border-color:#94a3b8;
    background:#ffffff;
}

.input-premium:focus{
    background:#ffffff;
    border-color:#10b981;
    box-shadow:0 0 0 4px rgba(16,185,129,0.12);
}

select.input-premium{
    cursor:pointer;
}

textarea.input-premium{
    resize:vertical;
    min-height:120px;
}
        @keyframes slideUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="text-slate-600 antialiased selection:bg-brand-100 selection:text-brand-900">

    <!-- NAVIGATION (GLASSMORPHISM) -->
    <header class="glass-header sticky top-0 z-50 transition-all duration-300">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <!-- Logo & Brand -->
                <div class="flex items-center gap-4 cursor-pointer" onclick="showTab('home')">
                    <img src="<?php echo $def_logo_pemda; ?>" alt="Pemda" class="h-10 md:h-12 object-contain drop-shadow-sm">
                    <div class="border-l-2 border-slate-200 pl-4">
                        <h1 class="font-heading text-lg md:text-xl font-extrabold text-slate-900 leading-none tracking-tight">Salin Aslimas</h1>
                        <p class="text-[10px] md:text-xs text-brand-600 font-semibold tracking-wider uppercase mt-1">Sadewo Lintarti ASN Peduli Pekerja Rentan Banyumas</p>
                    </div>
                </div>

                <!-- Desktop Menu -->
                <nav class="hidden md:flex space-x-8 items-center text-sm font-medium text-slate-500">
                    <button onclick="showTab('home')" class="nav-link" id="tab-home">Beranda</button>
                    <button onclick="showTab('form')" class="nav-link" id="tab-form">Pengajuan</button>
                    <button onclick="showTab('recap')" class="nav-link" id="tab-recap">Rekap Ajuan</button>
                    <button onclick="showTab('check')" class="nav-link" id="tab-check">Cek Kepesertaan</button>
                    <!-- <a href="admin/" class="px-5 py-2 bg-slate-900 hover:bg-slate-800 text-white rounded-full transition-colors font-semibold">Admin Panel</a> -->
                </nav>

                <!-- Mobile Menu Button (Optional integration) -->
                <div class="md:hidden flex items-center">
                    <button class="text-slate-600 hover:text-slate-900 focus:outline-none p-2">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 md:py-12">
        
        <?php if($msg): ?>
        <div id="notif" class="mb-8 p-4 bg-brand-50 border border-brand-100 rounded-2xl flex justify-between items-center animate-fadeIn shadow-sm">
            <div class="flex items-center text-brand-700">
                <div class="bg-white p-2 rounded-full mr-3 shadow-sm"><i class="fas fa-check-circle text-xl text-brand-500"></i></div>
                <span class="font-medium text-sm"><?php echo htmlspecialchars($msg); ?></span>
            </div>
            <button onclick="document.getElementById('notif').remove()" class="text-brand-400 hover:text-brand-600 transition"><i class="fas fa-times"></i></button>
        </div>
        <?php endif; ?>

        <!-- BERANDA (MODERN LANDING PAGE) -->
        <!-- GANTI SELURUH BLOK TAB HOME LAMA DENGAN INI -->
<div id="home" class="tab-content">

    <!-- HERO SECTION -->
    <section class="relative overflow-hidden bg-white rounded-[2rem] shadow-soft border border-slate-100 -mx-4 sm:-mx-6 lg:-mx-8">
        <div class="absolute inset-0 bg-gradient-to-br from-brand-50 via-white to-emerald-50"></div>
        <div class="relative grid lg:grid-cols-[0.8fr_1.2fr] gap-10 items-center px-8 md:px-14 py-14 md:py-20">
            
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-brand-50 border border-brand-100 text-brand-700 text-[11px] font-bold uppercase tracking-widest mb-4">
                    <span class="w-2 h-2 rounded-full bg-brand-500 animate-pulse"></span>
                    Program Perlindungan Sosial Banyumas
                </div>

                <h1 class="font-heading text-2xl md:text-4xl font-extrabold text-slate-900 leading-tight tracking-tight">
                    Satu ASN,<br>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-600 to-emerald-400">
                        Satu Pekerja Rentan Terlindungi
                    </span>
                </h1>

                <p class="mt-4 text-slate-500 text-sm md:text-base leading-relaxed max-w-xl">
                    Program Salin Aslimas (Sadewo Lintarti ASN Peduli Pekerja Banyumas) merupakan wujud nyata kepedulian Aparatur
                    Sipil Negara (ASN) Kabupaten Banyumas dalam memberikan perlindungan sosial ketenagakerjaan bagi pekerja rentan di lingkungan sekitar.
                </p>

                <div class="mt-6 flex flex-wrap gap-3">
                    <button onclick="showTab('form')" class="bg-slate-900 text-white px-6 py-3 rounded-xl font-semibold text-sm hover:bg-slate-800 transition shadow-lg">
                        Ajukan Sekarang
                    </button>

                    <button onclick="showTab('check')" class="border border-slate-200 px-6 py-3 rounded-xl font-semibold text-sm text-slate-700 hover:bg-slate-50 transition">
                        Cek Kepesertaan
                    </button>
                </div>

                <div class="grid grid-cols-3 gap-3 mt-8">
                    <div class="bg-white border border-slate-100 rounded-2xl p-3 shadow-card">
                        <p class="text-2xl font-bold text-brand-600">100%</p>
                        <p class="text-[11px] text-slate-500 mt-1">Komitmen Sosial</p>
                    </div>
                    <div class="bg-white border border-slate-100 rounded-2xl p-3 shadow-card">
                        <p class="text-2xl font-bold text-brand-600">27+</p>
                        <p class="text-[11px] text-slate-500 mt-1">OPD Terlibat</p>
                    </div>
                    <div class="bg-white border border-slate-100 rounded-2xl p-3 shadow-card">
                        <p class="text-2xl font-bold text-brand-600">∞</p>
                        <p class="text-[11px] text-slate-500 mt-1">Manfaat Jangka Panjang</p>
                    </div>
                </div>
            </div>

            <div class="relative">
                <div class="absolute -top-8 -left-8 w-32 h-32 bg-brand-100 rounded-full blur-3xl opacity-70"></div>
                <img src="<?php echo $def_foto_bupati; ?>" class="relative rounded-[2rem] shadow-soft w-full h-auto max-h-[720px] object-contain">
            </div>

        </div>
    </section>


    <!-- PARTNERS -->
    <section class="mt-14 text-center">
        <p class="text-xs font-bold uppercase tracking-[0.3em] text-slate-400 mb-8">
            Kolaborasi Bersama
        </p>

        <div class="flex flex-wrap justify-center items-center gap-12 opacity-70 grayscale hover:grayscale-0 transition">
            <img src="<?php echo $def_logo_pemda; ?>" class="h-14 object-contain">
            <img src="<?php echo $def_logo_bpjs; ?>" class="h-14 object-contain">
            <img src="<?php echo $def_logo_baznas; ?>" class="h-14 object-contain">
        </div>
    </section>


    <!-- ABOUT -->
    <section class="mt-20 grid lg:grid-cols-2 gap-10 items-center">
        <div>
            <h2 class="font-heading text-4xl font-bold text-slate-900 mb-6">
                Tentang Program
            </h2>

            <p class="text-slate-500 leading-relaxed mb-5">
                Banyak pekerja rentan di Banyumas bekerja tanpa perlindungan sosial yang memadai.
                Saat risiko kecelakaan kerja, meninggal dunia, atau kehilangan pendapatan terjadi,
                keluarga mereka menjadi pihak paling terdampak.
            </p>

            <p class="text-slate-500 leading-relaxed mb-5">
                Melalui Salin Aslimas, ASN berperan nyata membantu satu pekerja rentan agar
                memperoleh perlindungan BPJS Ketenagakerjaan secara berkelanjutan.
            </p>

            <p class="text-slate-500 leading-relaxed">
                Ini bukan sekadar program administratif, tetapi gerakan kepedulian sosial
                yang memberi dampak langsung bagi masyarakat.
            </p>
        </div>

        <div class="grid grid-cols-2 gap-5">
            <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-card">
                <i class="fas fa-shield-heart text-brand-500 text-2xl mb-4"></i>
                <h3 class="font-bold text-slate-800 mb-2">Perlindungan</h3>
                <p class="text-sm text-slate-500">Jaminan risiko kerja & kematian.</p>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-card">
                <i class="fas fa-hand-holding-heart text-brand-500 text-2xl mb-4"></i>
                <h3 class="font-bold text-slate-800 mb-2">Kepedulian</h3>
                <p class="text-sm text-slate-500">ASN hadir untuk masyarakat.</p>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-card">
                <i class="fas fa-chart-line text-brand-500 text-2xl mb-4"></i>
                <h3 class="font-bold text-slate-800 mb-2">Dampak</h3>
                <p class="text-sm text-slate-500">Meningkatkan kesejahteraan keluarga.</p>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-card">
                <i class="fas fa-people-group text-brand-500 text-2xl mb-4"></i>
                <h3 class="font-bold text-slate-800 mb-2">Kolaborasi</h3>
                <p class="text-sm text-slate-500">Pemda, BPJS, dan masyarakat.</p>
            </div>
        </div>
    </section>


    <!-- BENEFITS -->
    <section class="mt-24">
        <div class="text-center mb-12">
            <h2 class="font-heading text-4xl font-bold text-slate-900">Manfaat Program</h2>
            <p class="text-slate-500 mt-3">Dampak nyata bagi pekerja rentan dan keluarga.</p>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            <div class="bg-white rounded-2xl p-8 border border-slate-100 shadow-card">
                <h3 class="font-bold text-slate-900 mb-3">Jaminan Kecelakaan Kerja</h3>
                <p class="text-slate-500 text-sm leading-relaxed">
                    Perlindungan saat terjadi risiko kecelakaan selama bekerja.
                </p>
            </div>

            <div class="bg-white rounded-2xl p-8 border border-slate-100 shadow-card">
                <h3 class="font-bold text-slate-900 mb-3">Jaminan Kematian</h3>
                <p class="text-slate-500 text-sm leading-relaxed">
                    Santunan bagi ahli waris ketika peserta meninggal dunia.
                </p>
            </div>

            <div class="bg-white rounded-2xl p-8 border border-slate-100 shadow-card">
                <h3 class="font-bold text-slate-900 mb-3">Ketenangan Keluarga</h3>
                <p class="text-slate-500 text-sm leading-relaxed">
                    Memberi rasa aman dan masa depan lebih baik.
                </p>
            </div>
        </div>
    </section>


    <!-- TIMELINE -->
    <section class="mt-24 bg-white rounded-[2rem] p-10 border border-slate-100 shadow-soft">
        <div class="text-center mb-12">
            <h2 class="font-heading text-4xl font-bold text-slate-900">Cara Kerja Program</h2>
        </div>

        <div class="grid md:grid-cols-4 gap-6 text-center">
            <div>
                <div class="w-14 h-14 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center mx-auto font-bold text-xl">1</div>
                <h3 class="font-bold mt-4 text-slate-800">ASN Mengusulkan</h3>
                <p class="text-sm text-slate-500 mt-2">Input data pekerja rentan.</p>
            </div>

            <div>
                <div class="w-14 h-14 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center mx-auto font-bold text-xl">2</div>
                <h3 class="font-bold mt-4 text-slate-800">Verifikasi</h3>
                <p class="text-sm text-slate-500 mt-2">Data dicek sistem/admin.</p>
            </div>

            <div>
                <div class="w-14 h-14 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center mx-auto font-bold text-xl">3</div>
                <h3 class="font-bold mt-4 text-slate-800">Aktivasi</h3>
                <p class="text-sm text-slate-500 mt-2">Kepesertaan diaktifkan.</p>
            </div>

            <div>
                <div class="w-14 h-14 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center mx-auto font-bold text-xl">4</div>
                <h3 class="font-bold mt-4 text-slate-800">Manfaat Dirasakan</h3>
                <p class="text-sm text-slate-500 mt-2">Perlindungan berjalan.</p>
            </div>
        </div>
    </section>


    <!-- CTA -->
    <section class="mt-24 mb-10">
        <div class="rounded-[2rem] bg-gradient-to-r from-slate-900 to-slate-800 p-10 md:p-14 text-center text-white shadow-soft">
            <h2 class="font-heading text-4xl font-bold mb-4">
                Saatnya ASN Memberi Dampak Nyata
            </h2>

            <p class="text-slate-300 max-w-2xl mx-auto leading-relaxed mb-8">
                Bersama Salin Aslimas, satu langkah kecil dari ASN dapat menjadi
                perlindungan besar bagi pekerja rentan dan keluarganya.
            </p>

            <button onclick="showTab('form')" class="bg-white text-slate-900 px-8 py-4 rounded-xl font-bold hover:scale-105 transition">
                Mulai Pengajuan Sekarang
            </button>
        </div>
    </section>

</div>

        <!-- FORMULIR PENGAJUAN (CLEAN CARD UI) -->
        <div id="form" class="tab-content max-w-4xl mx-auto">
            <div class="text-center mb-10">
                <h2 class="font-heading text-3xl font-bold text-slate-900 mb-3">Formulir Usulan Perlindungan</h2>
                <p class="text-slate-500">Silakan lengkapi data diri ASN dan data pekerja rentan yang akan dilindungi.</p>
            </div>

            <div class="bg-white p-8 md:p-12 rounded-[2rem] shadow-soft border border-slate-100">
                <form method="POST" action="" enctype="multipart/form-data" class="space-y-12">
                    
                    <!-- Section 1 -->
                    <div>
                        <div class="flex items-center gap-4 mb-6">
                            <div class="w-10 h-10 rounded-full bg-brand-50 flex items-center justify-center text-brand-600 font-bold">1</div>
                            <h3 class="font-heading text-xl font-bold text-slate-800">Identitas Pengusul</h3>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pl-0 md:pl-14">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Status Kedinasan</label>
                                <select id="status_kedinasan" name="status_kedinasan" class="input-premium">
                                    <option value="dinas" selected>Dinas</option>
                                    <option value="swasta">Swasta</option>
                                </select>
                                <p class="text-[11px] text-slate-400 mt-1">Pilih <b>Dinas</b> untuk ASN, atau <b>Swasta</b> untuk perusahaan (PT/CV).</p>
                            </div>

                            <!-- DINAS -->
                            <div data-kedinasan="dinas">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Nama Lengkap (ASN)</label>
                                <input type="text" name="asn_nama" required class="input-premium" placeholder="Cth: Budi Santoso">
                            </div>
                            <div data-kedinasan="dinas">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">NIP</label>
                                <input type="text" name="asn_nip" required class="input-premium" placeholder="18 Digit NIP">
                            </div>
                            <div data-kedinasan="dinas">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Unit Kerja (OPD)</label>
                                <select name="asn_opd" required class="input-premium appearance-none bg-[url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%2394a3b8%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E')] bg-no-repeat bg-[position:right_1rem_center] bg-[length:0.6rem_auto]">
                                    <option value="">Pilih Unit Kerja</option>
                                    <?php if($opd_options): while($o = $opd_options->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($o['nama_opd']); ?>"><?php echo htmlspecialchars($o['nama_opd']); ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </div>
                            <div data-kedinasan="dinas">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Jabatan</label>
                                <input type="text" name="asn_jabatan" required class="input-premium" placeholder="Staf / Kasi / Kabid">
                            </div>
                            <div class="md:col-span-2" data-kedinasan="dinas">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Nomor HP/WhatsApp</label>
                                <input type="text" name="asn_hp" required class="input-premium" placeholder="0812...">
                            </div>

                            <!-- SWASTA -->
                            <div class="hidden" data-kedinasan="swasta">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Nama Perusahaan (PT/CV)</label>
                                <input type="text" name="swasta_nama_perusahaan" required class="input-premium" placeholder="Cth: PT Maju Jaya / CV Sejahtera">
                            </div>
                            <div class="hidden" data-kedinasan="swasta">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Nama PIC/Pengusul</label>
                                <input type="text" name="swasta_nama" required class="input-premium" placeholder="Cth: Andi Wijaya">
                            </div>
                            <div class="hidden" data-kedinasan="swasta">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Jabatan</label>
                                <input type="text" name="swasta_jabatan" required class="input-premium" placeholder="Cth: Direktur / HRD / Supervisor">
                            </div>
                            <div class="hidden md:col-span-2" data-kedinasan="swasta">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Nomor WhatsApp</label>
                                <input type="text" name="swasta_hp" required class="input-premium" placeholder="0812...">
                            </div>
                        </div>
                    </div>

                    <hr class="border-slate-100">

                    <!-- Section 2 -->
                    <div>
                        <div class="flex items-center gap-4 mb-6">
                            <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 font-bold">2</div>
                            <div class="flex-1 flex justify-between items-center">
                                <h3 class="font-heading text-xl font-bold text-slate-800">Data Pekerja Rentan</h3>
                            </div>
                        </div>
                        
                        <div id="worker-container" class="space-y-6 pl-0 md:pl-14">
                            <!-- Worker Card 1 -->
                            <div class="bg-white border border-slate-200 p-6 rounded-2xl shadow-card relative group">
                                <span class="absolute -top-3 left-6 bg-white px-2 text-xs font-bold text-brand-600">Pekerja #1</span>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-2 text-sm">
                                    <div class="md:col-span-2"><label class="block text-xs font-semibold text-slate-500 mb-1">Nama Lengkap Sesuai KTP</label><input type="text" name="worker_nama[]" required class="input-premium"></div>
                                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">NIK (16 Digit)</label><input type="text" name="worker_nik[]" class="input-premium"></div>
                                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">Jenis Kelamin</label>
                                        <select name="worker_jk[]" class="input-premium appearance-none bg-[url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22...')]"><option>Laki-laki</option><option>Perempuan</option></select>
                                    </div>
                                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">Tempat Lahir</label><input type="text" name="worker_tmpt_lahir[]" class="input-premium" placeholder="Cth: Banyumas"></div>
                                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">Tanggal Lahir</label><input type="date" name="worker_tgl_lahir[]" class="input-premium"></div>
                                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">Pekerjaan</label>
                                         <select name="worker_job[]" required class="input-premium appearance-none">
                                            <option value="">Pilih Pekerjaan</option>
                                            <?php if($job_options): mysqli_data_seek($job_options, 0); while($j = $job_options->fetch_assoc()): ?>
                                                <option value="<?php echo htmlspecialchars($j['nama_pekerjaan']); ?>"><?php echo htmlspecialchars($j['nama_pekerjaan']); ?></option>
                                            <?php endwhile; endif; ?>
                                         </select>
                                    </div>
                                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">No HP Pekerja (Opsional)</label><input type="text" name="worker_hp[]" class="input-premium"></div>
                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-semibold text-slate-500 mb-1">Upload Foto KTP (JPG/PNG)</label>
                                        <input type="file" name="worker_ktp[]" accept="image/png,image/jpeg" class="input-premium bg-white">
                                        <p class="text-[11px] text-slate-400 mt-1">Opsional. Maks 3MB.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="pl-0 md:pl-14 mt-6">
                            <button type="button" onclick="addWorker()" class="w-full py-4 bg-slate-50 border-2 border-dashed border-slate-200 text-slate-500 rounded-2xl font-semibold hover:bg-slate-100 hover:text-slate-700 hover:border-slate-300 transition flex items-center justify-center gap-2">
                                <i class="fas fa-plus"></i> Tambah Pekerja Lainnya
                            </button>
                        </div>
                    </div>

                    <div class="pt-6">
                        <button type="submit" name="submit_pengajuan" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-bold text-lg hover:bg-slate-800 transition-all shadow-[0_4px_14px_0_rgb(0,0,0,0.2)]">Kirim Usulan Data</button>
                    </div>
                </form>
            </div>
        </div>
        
                    <?php
            // --- TAMBAHKAN BAGIAN INI DI ATAS FILE (SEBELUM HTML) ---
            // Asumsi Anda memiliki variabel koneksi database, misalnya $conn
            // Sesuaikan 'nama_tabel_anda' dengan tabel atau view yang Anda gunakan untuk $rekap_query
            
            $query_summary = "
                SELECT 
                    COUNT(DISTINCT id) as total_opd
                FROM ref_opd
            ";
            $result_summary = $conn->query($query_summary);
            $summary = $result_summary->fetch_assoc();
            
            $queryasn = "SELECT SUM(jumlah_asn) as total_asn FROM ref_opd";
            $result_summary_asn = $conn->query($queryasn);
            $summary_asn = $result_summary_asn->fetch_assoc();

            // Rekap pengajuan (khusus kedinasan = dinas)
            $query_opd_mengajukan = "SELECT COUNT(DISTINCT asn_opd) AS opd_mengajukan FROM salin_aslimas_data WHERE status_kedinasan = 'dinas'";
            $res_opd_mengajukan = $conn->query($query_opd_mengajukan);
            $sum_opd_mengajukan = $res_opd_mengajukan ? $res_opd_mengajukan->fetch_assoc() : [];

            $query_asn_mengajukan = "SELECT COUNT(DISTINCT asn_nip) AS asn_mengajukan FROM salin_aslimas_data WHERE status_kedinasan = 'dinas' AND asn_nip <> '' AND asn_nip <> '-'";
            $res_asn_mengajukan = $conn->query($query_asn_mengajukan);
            $sum_asn_mengajukan = $res_asn_mengajukan ? $res_asn_mengajukan->fetch_assoc() : [];
            
            $querypekerja = "SELECT COUNT(id) as total_pekerja FROM salin_aslimas_data";
            $result_summary_pekerja = $conn->query($querypekerja);
            $summary_pekerja = $result_summary_pekerja->fetch_assoc();
            
            $querypekerjaterlindungi = "SELECT COUNT(id) as terlindungi FROM salin_aslimas_data WHERE status_kepesertaan = 'Aktif'";
            $result_summary_pekerja_terlindungi = $conn->query($querypekerjaterlindungi);
            $summary_pekerja_terlindungi = $result_summary_pekerja_terlindungi->fetch_assoc();
            
            // Fallback jika data kosong
            $tot_opd = $summary['total_opd'] ?? 0;
            $tot_asn = $summary_asn['total_asn'] ?? 0;
            $tot_opd_mengajukan = $sum_opd_mengajukan['opd_mengajukan'] ?? 0;
            $tot_asn_mengajukan = $sum_asn_mengajukan['asn_mengajukan'] ?? 0;
            $tot_dimasukkan = $summary_pekerja['total_pekerja'] ?? 0;
            $tot_terlindungi = $summary_pekerja_terlindungi['terlindungi'] ?? 0;
            
            
            // HAPUS ATAU KOMENTARI NILAI DUMMY DI BAWAH INI JIKA SUDAH MENGGUNAKAN QUERY DI ATAS
            // $tot_opd = 45; 
            // $tot_asn = 12500;
            // $tot_dimasukkan = 8400;
            // $tot_terlindungi = 21050;
            // --------------------------------------------------------
            ?>
        <!-- REKAPITULASI (CLEAN TABLE) -->
        <div id="recap" class="tab-content max-w-5xl mx-auto">
    <div class="bg-white p-8 md:p-10 rounded-[2rem] shadow-soft border border-slate-100">
        
        <div class="mb-8">
            <h3 class="font-heading text-2xl font-bold text-slate-900">Progres Perlindungan per OPD</h3>
            <p class="text-sm text-slate-500 mt-1">Data diperbarui secara real-time berdasarkan usulan yang masuk.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100 flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fas fa-building"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500">OPD Mengajukan</p>
                    <h4 class="text-xl font-bold text-slate-800">
                        <?php echo number_format((int)$tot_opd_mengajukan, 0, ',', '.'); ?>
                        <!-- <span class="text-slate-400 font-semibold">/ <?php echo number_format((int)$tot_opd, 0, ',', '.'); ?></span> -->
                    </h4>
                </div>
            </div>

            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100 flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500">ASN Mengajukan</p>
                    <h4 class="text-xl font-bold text-slate-800">
                        <?php echo number_format((int)$tot_asn_mengajukan, 0, ',', '.'); ?>
                        <!-- <span class="text-slate-400 font-semibold">/ <?php echo number_format((int)$tot_asn, 0, ',', '.'); ?></span> -->
                    </h4>
                </div>
            </div>

            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100 flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fas fa-user-check"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500">Pekerja Diinput</p>
                    <h4 class="text-xl font-bold text-slate-800"><?php echo number_format($tot_dimasukkan, 0, ',', '.'); ?></h4>
                </div>
            </div>

            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100 flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xl shrink-0">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500">Terlindungi</p>
                    <h4 class="text-xl font-bold text-slate-800"><?php echo number_format($tot_terlindungi, 0, ',', '.'); ?></h4>
                </div>
            </div>
        </div>
        <div class="mb-6">
            <div class="relative">
                <input id="searchOpd" type="text" class="input-premium pl-11" placeholder="Cari Unit Kerja (OPD) ...">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
            </div>
            <p id="searchOpdInfo" class="text-xs text-slate-400 mt-2"></p>
        </div>
        
        <div class="overflow-hidden rounded-2xl border border-slate-200">
            <table class="w-full text-left border-collapse bg-white">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="py-4 px-6 text-xs font-bold text-slate-500 uppercase tracking-wider">Unit Kerja (OPD)</th>
                        <th class="py-4 px-6 text-center text-xs font-bold text-slate-500 uppercase tracking-wider">Jumlah ASN</th>
                        <th class="py-4 px-6 text-center text-xs font-bold text-slate-500 uppercase tracking-wider">Partisipasi ASN</th>
                        <th class="py-4 px-6 text-center text-xs font-bold text-slate-500 uppercase tracking-wider">Total Pekerja Terlindungi</th>
                        <th class="py-4 px-6 text-center text-xs font-bold text-slate-500 uppercase tracking-wider">Presentese Partisipasi</th>
                    </tr>
                </thead>
                <tbody id="opdTableBody" class="divide-y divide-slate-100">
                    <?php if($rekap_query): while($row = $rekap_query->fetch_assoc()): ?>
                    <tr class="hover:bg-slate-50/50 transition duration-150">
                        <td class="py-4 px-6 font-medium text-slate-800"><?php echo htmlspecialchars($row['asn_opd']); ?></td>
                        <td class="py-4 px-6 text-center text-slate-600"><span class="bg-slate-100 text-slate-700 px-3 py-1 rounded-full text-xs font-semibold"><?php echo (int)($row['jumlah_asn'] ?? 0); ?> Orang</span></td>
                        <td class="py-4 px-6 text-center text-slate-600"><span class="bg-slate-100 text-slate-700 px-3 py-1 rounded-full text-xs font-semibold"><?php echo $row['total_asn']; ?> Orang</span></td>
                        <td class="py-4 px-6 text-center">
                            <span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-brand-50 text-brand-700 font-bold text-sm">
                                <?php echo $row['total_pekerja']; ?>
                            </span>
                        </td>
                        <td class="py-4 px-6 text-center">
                            <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 px-3 py-1.5 rounded-full text-xs font-bold border border-emerald-100">
                        <i class="fas fa-check-circle text-emerald-500"></i>
                        <?php
                            $jumlah_asn_opd = (int)($row['jumlah_asn'] ?? 0);
                            $partisipasi_asn = (int)($row['total_asn'] ?? 0);
                            $persentase = $jumlah_asn_opd > 0 ? (($partisipasi_asn / $jumlah_asn_opd) * 100) : 0;
                        ?>
                        <?php echo number_format($persentase, 2, ',', '.'); ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

        <!-- CEK NIK (MINIMALIST SEARCH) -->
        <div id="check" class="tab-content max-w-2xl mx-auto">
            <div class="bg-white p-10 md:p-14 rounded-[2rem] shadow-soft border border-slate-100 text-center relative overflow-hidden">
                <!-- Decorative background blur -->
                <div class="absolute top-0 right-0 w-64 h-64 bg-brand-50 rounded-full mix-blend-multiply filter blur-3xl opacity-50 transform translate-x-1/2 -translate-y-1/2"></div>
                
                <div class="relative z-10 space-y-8">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-brand-50 text-brand-600 shadow-sm mb-2">
                        <i class="fas fa-fingerprint text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="font-heading text-3xl font-bold text-slate-900">Verifikasi Kepesertaan</h3>
                        <p class="text-slate-500 mt-2">Masukkan 16 digit NIK untuk memeriksa status perlindungan.</p>
                    </div>
                    
                    <div class="relative max-w-md mx-auto">
                        <input type="text" id="cekNik" placeholder="Contoh: 3302..." maxlength="16" class="w-full bg-slate-50 border border-slate-200 text-slate-900 rounded-2xl py-4 pl-6 pr-32 focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-50 transition-all outline-none font-medium tracking-wide text-lg text-center md:text-left">
                        <button onclick="prosesCek()" class="mt-4 md:mt-0 md:absolute md:right-2 md:top-2 w-full md:w-auto bg-slate-900 hover:bg-slate-800 text-white px-6 py-2 md:py-2 rounded-xl font-semibold transition-colors h-12 md:h-[auto] text-sm">Periksa</button>
                    </div>

                    <div id="resCek" class="hidden text-left animate-fadeIn mt-6"></div>
                </div>
            </div>
        </div>

    </main>

    <!-- FOOTER (MINIMALIST) -->
    <footer class="mt-12 bg-white border-t border-slate-200 pt-10 pb-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
                <div class="flex items-center space-x-3">
                    <img src="<?php echo $def_logo_pemda; ?>" class="h-8 grayscale opacity-50">
                    <div>
                        <p class="text-sm font-bold text-slate-700 font-heading">Salin Aslimas</p>
                        <p class="text-[10px] text-slate-400 font-medium uppercase tracking-widest">Kabupaten Banyumas</p>
                    </div>
                </div>
                <p class="text-xs text-slate-500 text-center max-w-md leading-relaxed">Salin Aslimas (Sadewo Lintarti ASN Peduli Pekerja Rentan Banyumas) bekerja sama dengan BPJS Ketenagakerjaan dan BAZNAS.</p>
                <div class="text-xs text-slate-400 font-medium">&copy; <?php echo date('Y'); ?> BPJS Ketenagakerjaan Purwokerto</div>
            </div>
        </div>
    </footer>

    <script>
        const currentPage = "<?php echo $currentPage; ?>";

        function showTab(id) {
            document.querySelectorAll('.tab-content').forEach(el => { el.classList.remove('active'); });
            document.querySelectorAll('.nav-link').forEach(el => { el.classList.remove('active'); });
            
            const targetContent = document.getElementById(id);
            const targetLink = document.getElementById('tab-' + id);
            
            if(targetContent) { targetContent.classList.add('active'); }
            if(targetLink) { targetLink.classList.add('active'); }
            
            let url = currentPage + '?tab=' + id;
            const urlParams = new URLSearchParams(window.location.search);
            history.replaceState(null, null, url);
        }

        function initKedinasanToggle() {
            const sel = document.getElementById('status_kedinasan');
            if (!sel) return;

            const blocks = Array.from(document.querySelectorAll('[data-kedinasan]'));
            const apply = () => {
                const mode = (sel.value === 'swasta') ? 'swasta' : 'dinas';
                blocks.forEach(b => {
                    const isOn = b.getAttribute('data-kedinasan') === mode;
                    b.classList.toggle('hidden', !isOn);
                    b.querySelectorAll('input, select, textarea').forEach(el => {
                        if (el.hasAttribute('required')) {
                            el.required = isOn;
                        } else {
                            // Jika sebelumnya required tetapi atributnya terhapus oleh browser/DOM, biarkan.
                            // (Kita hanya toggle yang memang bertanda required di HTML)
                        }
                        if (!isOn && (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement)) {
                            el.value = '';
                        }
                        if (!isOn && el instanceof HTMLSelectElement) {
                            el.selectedIndex = 0;
                        }
                    });
                });
            };

            sel.addEventListener('change', apply);
            apply();
        }

        let workerIdx = 1;
        function addWorker() {
            workerIdx++;
            const container = document.getElementById('worker-container');
            const card = document.createElement('div');
            card.className = "bg-white border border-slate-200 p-6 rounded-2xl shadow-card relative group mt-6 animate-fadeIn";
            
            let jobOptions = '<option value="">Pilih Pekerjaan</option>';
            <?php 
            if($job_options) {
                mysqli_data_seek($job_options, 0); 
                while($j = $job_options->fetch_assoc()) {
                    echo "jobOptions += '<option value=\"" . addslashes($j['nama_pekerjaan']) . "\">" . addslashes($j['nama_pekerjaan']) . "</option>';";
                }
            }
            ?>

            card.innerHTML = `
                <button type="button" onclick="this.parentElement.remove()" class="absolute top-4 right-4 w-8 h-8 rounded-full bg-red-50 text-red-500 hover:bg-red-100 hover:text-red-700 transition flex items-center justify-center"><i class="fas fa-times text-sm"></i></button>
                <span class="absolute -top-3 left-6 bg-white px-2 text-xs font-bold text-brand-600">Pekerja #${workerIdx}</span>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-2 text-sm">
                    <div class="md:col-span-2"><label class="block text-xs font-semibold text-slate-500 mb-1">Nama Lengkap Sesuai KTP</label><input type="text" name="worker_nama[]" required class="input-premium"></div>
                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">NIK (16 Digit)</label><input type="text" name="worker_nik[]" class="input-premium"></div>
                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">Jenis Kelamin</label><select name="worker_jk[]" class="input-premium appearance-none bg-[url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22...')]"><option>Laki-laki</option><option>Perempuan</option></select></div>
                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">Tempat Lahir</label><input type="text" name="worker_tmpt_lahir[]" class="input-premium"></div>
                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">Tanggal Lahir</label><input type="date" name="worker_tgl_lahir[]" class="input-premium"></div>
                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">Pekerjaan</label><select name="worker_job[]" required class="input-premium appearance-none">${jobOptions}</select></div>
                    <div><label class="block text-xs font-semibold text-slate-500 mb-1">No HP Pekerja (Opsional)</label><input type="text" name="worker_hp[]" class="input-premium"></div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Upload Foto KTP (JPG/PNG)</label>
                        <input type="file" name="worker_ktp[]" accept="image/png,image/jpeg" class="input-premium bg-white">
                        <p class="text-[11px] text-slate-400 mt-1">Opsional. Maks 3MB.</p>
                    </div>
                </div>`;
            container.appendChild(card);
        }

        async function prosesCek() {
            const nikRaw = document.getElementById('cekNik').value || '';
            const nik = nikRaw.replace(/\D+/g, '').slice(0, 16);
            const res = document.getElementById('resCek');

            if (nik.length < 8) {
                res.classList.remove('hidden');
                res.innerHTML = `<div class="bg-red-50 text-red-600 p-4 rounded-xl text-sm border border-red-100 flex gap-3"><i class="fas fa-exclamation-circle mt-0.5"></i> Masukkan NIK yang valid.</div>`;
                return;
            }

            res.classList.remove('hidden');
            res.innerHTML = `<div class="bg-slate-50 text-slate-600 p-4 rounded-xl text-sm border border-slate-200 flex gap-3"><i class="fas fa-spinner fa-spin mt-0.5"></i> Memeriksa data...</div>`;

            try {
                const r = await fetch(`${currentPage}?action=cek_nik&nik=${encodeURIComponent(nik)}`);
                const j = await r.json();

                if (!j.ok) throw new Error(j.message || 'Gagal memproses permintaan.');

                if (!j.found) {
                    res.innerHTML = `<div class="bg-amber-50 text-amber-800 p-4 rounded-xl text-sm border border-amber-200 flex gap-3"><i class="fas fa-circle-info mt-0.5"></i> NIK <span class="font-mono bg-white px-1.5 py-0.5 rounded border border-amber-200">${nik}</span> belum ditemukan di sistem.</div>`;
                    return;
                }

                const d = j.data;
                const st = (d.status_kepesertaan || 'Pending').toLowerCase();
                const isActive = st === 'aktif';
                const isRed = st === 'ditolak' || st === 'belum-diinput' || st === 'non-aktif';

                const cardCls = isActive
                    ? 'bg-brand-50 border-brand-200'
                    : (isRed ? 'bg-red-50 border-red-200' : 'bg-white border-slate-200');

                const badge = isActive
                    ? 'bg-brand-600 text-white border-brand-600'
                    : (isRed ? 'bg-red-600 text-white border-red-600' : 'bg-slate-100 text-slate-700 border-slate-200');

                const innerBoxCls = isActive
                    ? 'bg-white/70 border-brand-200'
                    : (isRed ? 'bg-white/70 border-red-200' : 'bg-slate-50 border-slate-200');
                const labelCls = isActive ? 'text-brand-800' : (isRed ? 'text-red-800' : 'text-slate-500');
                const valueCls = isActive ? 'text-brand-900' : (isRed ? 'text-red-900' : 'text-slate-800');

                res.innerHTML = `
                    <div class="${cardCls} border p-5 rounded-2xl shadow-card">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div>
                                <p class="font-bold ${valueCls} text-lg">${d.pekerja_nama || 'Data Pekerja'}</p>
                                <p class="text-xs ${labelCls} mt-1">NIK <span class="font-mono bg-white/70 px-2 py-0.5 rounded border border-slate-200">${d.pekerja_nik || nik}</span></p>
                            </div>
                            <span class="px-3 py-1 rounded-full border text-[10px] font-bold uppercase tracking-wider ${badge}">${d.status_kepesertaan || 'Pending'}</span>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                            <div class="${innerBoxCls} border rounded-xl p-3">
                                <p class="text-xs ${labelCls} font-semibold">Unit Kerja</p>
                                <p class="font-medium ${valueCls} mt-0.5">${d.asn_opd || '-'}</p>
                            </div>
                            <div class="${innerBoxCls} border rounded-xl p-3">
                                <p class="text-xs ${labelCls} font-semibold">Pengusul Pekerja</p>
                                <p class="font-medium ${valueCls} mt-0.5">${(d.asn_nama && String(d.asn_nama).trim()) ? String(d.asn_nama).trim() : '-'}</p>
                            </div>
                        </div>
                    </div>`;
            } catch (e) {
                res.innerHTML = `<div class="bg-red-50 text-red-700 p-4 rounded-xl text-sm border border-red-100 flex gap-3"><i class="fas fa-triangle-exclamation mt-0.5"></i> ${e.message || 'Terjadi kesalahan.'}</div>`;
            }
        }

        function initOpdSearch() {
            const input = document.getElementById('searchOpd');
            const tbody = document.getElementById('opdTableBody');
            const info = document.getElementById('searchOpdInfo');
            if (!input || !tbody) return;

            const rows = Array.from(tbody.querySelectorAll('tr'));
            const total = rows.length;
            const normalize = (s) => (s || '').toString().toLowerCase().trim();

            const apply = () => {
                const q = normalize(input.value);
                let shown = 0;
                rows.forEach(r => {
                    const text = normalize(r.querySelector('td')?.innerText || '');
                    const visible = !q || text.includes(q);
                    r.style.display = visible ? '' : 'none';
                    if (visible) shown++;
                });
                if (info) info.textContent = q ? `Menampilkan ${shown} dari ${total} OPD` : '';
            };

            input.addEventListener('input', apply);
            apply();
        }

        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'home';
            showTab(tab);
            initOpdSearch();
            initKedinasanToggle();
        }
    </script>
</body>
</html>
<?php if($connected) $conn->close(); ?>