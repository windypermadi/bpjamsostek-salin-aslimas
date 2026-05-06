<?php
/**
 * Shared bootstrap: DB connect + auto setup tables + reference fetch.
 */

// --- KONFIGURASI DATABASE ---
// $db_host = "localhost";
// $db_user = "root";
// $db_pass = "";
// $db_name = "byfd1777_db_asnpedulibms";

$db_host = "localhost";
$db_user = "root"; 
$db_pass = "";
$db_name = "byfd1777_db_asnpedulibms"; 

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

$connected = !$conn->connect_error;
$db_status = $connected ? "Terhubung" : ("Koneksi Gagal: " . $conn->connect_error);

function salin_aslimas_base_url(): string {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptName === '' || strpos($scriptName, ':') !== false || $scriptName[0] !== '/') {
        $scriptName = (string)($_SERVER['PHP_SELF'] ?? '');
    }
    if ($scriptName === '' || strpos($scriptName, ':') !== false || $scriptName[0] !== '/') {
        $reqPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $scriptName = $reqPath !== '' ? $reqPath : '/';
    }

    // dirname('/salin-aslimas/index.php') => '/salin-aslimas'
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($basePath === '' || $basePath === '.') $basePath = '/';
    if ($basePath[0] !== '/') $basePath = '/' . $basePath;

    return $scheme . '://' . $host . ($basePath === '/' ? '' : $basePath) . '/';
}

function salin_aslimas_to_url(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;

    $base = salin_aslimas_base_url();
    $projectRoot = dirname(__DIR__);

    // Jika sudah absolute path (/uploads/...) jadikan absolute URL.
    if (strncmp($path, '/', 1) === 0) {
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . $path;
    }

    // Jika path lokal tapi file-nya belum ada, biarkan caller pakai fallback.
    $candidate = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/'));
    if (!is_file($candidate)) return '';

    return $base . ltrim($path, '/');
}

if ($connected) {
    // --- AUTO-SETUP TABLES (PUBLIC CORE) ---
    $conn->query("CREATE TABLE IF NOT EXISTS salin_aslimas_data (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asn_nama VARCHAR(100) NOT NULL,
        asn_nip VARCHAR(30) NOT NULL,
        asn_opd VARCHAR(100) NOT NULL,
        asn_jabatan VARCHAR(100) NOT NULL,
        asn_hp VARCHAR(20) NOT NULL,
        pekerja_nama VARCHAR(100) NOT NULL,
        pekerja_nik CHAR(16) NOT NULL,
        pekerja_ttl VARCHAR(100) DEFAULT NULL,
        pekerja_jk ENUM('Laki-laki', 'Perempuan') DEFAULT NULL,
        pekerja_job VARCHAR(100) DEFAULT NULL,
        pekerja_hp VARCHAR(20) DEFAULT NULL,
        pekerja_ktp VARCHAR(255) DEFAULT NULL,
        pekerja_email VARCHAR(100) DEFAULT NULL,
        keterangan_status_data VARCHAR(255) DEFAULT NULL,
        status_kepesertaan ENUM('Aktif', 'Pending', 'Non-Aktif', 'Ditolak', 'Belum-Diinput') DEFAULT 'Pending',
        tgl_input TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Backward-compatible: add missing column if table already exists.
    $colCheck = $conn->query("SHOW COLUMNS FROM salin_aslimas_data LIKE 'pekerja_ktp'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE salin_aslimas_data ADD COLUMN pekerja_ktp VARCHAR(255) DEFAULT NULL");
    }

    $ketCheck = $conn->query("SHOW COLUMNS FROM salin_aslimas_data LIKE 'keterangan_status_data'");
    if ($ketCheck && $ketCheck->num_rows === 0) {
        $conn->query("ALTER TABLE salin_aslimas_data ADD COLUMN keterangan_status_data VARCHAR(255) DEFAULT NULL");
    }

    // Ensure ENUM has 'Ditolak'
    $statusCol = $conn->query("SHOW COLUMNS FROM salin_aslimas_data LIKE 'status_kepesertaan'");
    if ($statusCol) {
        $row = $statusCol->fetch_assoc();
        $type = (string)($row['Type'] ?? '');
        $needDitolak = $type !== '' && stripos($type, "'Ditolak'") === false;
        $needBelum = $type !== '' && stripos($type, "'Belum-Diinput'") === false;
        if ($needDitolak || $needBelum) {
            $conn->query("ALTER TABLE salin_aslimas_data MODIFY status_kepesertaan ENUM('Aktif','Pending','Non-Aktif','Ditolak','Belum-Diinput') DEFAULT 'Pending'");
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS ref_opd (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_opd VARCHAR(100) UNIQUE NOT NULL,
        jumlah_asn INT DEFAULT 0
    )");

    $jAsnCheck = $conn->query("SHOW COLUMNS FROM ref_opd LIKE 'jumlah_asn'");
    if ($jAsnCheck && $jAsnCheck->num_rows === 0) {
        $conn->query("ALTER TABLE ref_opd ADD COLUMN jumlah_asn INT DEFAULT 0");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS ref_pekerjaan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_pekerjaan VARCHAR(100) UNIQUE NOT NULL
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS ref_branding (
        key_name VARCHAR(50) PRIMARY KEY,
        file_path VARCHAR(255) NOT NULL
    )");
}

function salin_aslimas_fetch_branding(mysqli $conn, bool $connected): array {
    if (!$connected) return [];
    $branding = [];
    $res = $conn->query("SELECT * FROM ref_branding");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $branding[$row['key_name']] = $row['file_path'];
        }
    }
    return $branding;
}

function salin_aslimas_fetch_opd_options(mysqli $conn, bool $connected) {
    if (!$connected) return false;
    return $conn->query("SELECT * FROM ref_opd ORDER BY nama_opd ASC");
}

function salin_aslimas_fetch_job_options(mysqli $conn, bool $connected) {
    if (!$connected) return false;
    return $conn->query("SELECT * FROM ref_pekerjaan ORDER BY nama_pekerjaan ASC");
}

