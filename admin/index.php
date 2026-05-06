<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

require __DIR__ . '/../inc/bootstrap.php';

$msg = isset($_GET['msg']) ? $_GET['msg'] : "";
$active_sub = isset($_GET['sub']) ? $_GET['sub'] : "dashboard";

$opd_filter = isset($_GET['opd']) ? trim((string)$_GET['opd']) : '';
$nik_filter = isset($_GET['nik']) ? preg_replace('/\D+/', '', (string)$_GET['nik']) : '';
$opd_sort = isset($_GET['sort_opd']) ? trim((string)$_GET['sort_opd']) : 'default';
if (!in_array($opd_sort, ['default', 'opd_asc', 'opd_desc'], true)) $opd_sort = 'default';

$db_q_extra = [];
if ($opd_filter !== '') $db_q_extra['opd'] = $opd_filter;
if ($nik_filter !== '') $db_q_extra['nik'] = $nik_filter;
if ($opd_sort !== 'default') $db_q_extra['sort_opd'] = $opd_sort;
$db_qs_extra = $db_q_extra ? ('&' . http_build_query($db_q_extra)) : '';

function salin_aslimas_csv_excel_text($value): string {
    $s = (string)$value;
    if ($s === '') return '';
    // Force Excel to treat cell as text (avoid scientific notation on long numbers)
    return "=\"" . str_replace('"', '""', $s) . "\"";
}

function get_csv_separator($line) {
    $delimiters = [";" => 0, "," => 0, "\t" => 0];
    foreach ($delimiters as $delim => $count) {
        $delimiters[$delim] = count(explode($delim, $line));
    }
    return array_search(max($delimiters), $delimiters);
}

function salin_aslimas_xlsx_read_rows(string $xlsxPath): array {
    if (!is_file($xlsxPath)) return [];
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) return [];

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx && isset($sx->si)) {
            foreach ($sx->si as $si) {
                $parts = [];
                if (isset($si->t)) {
                    $parts[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) $parts[] = (string)$r->t;
                }
                $sharedStrings[] = implode('', $parts);
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) $sheetXml = $zip->getFromName('xl/worksheets/sheet.xml');
    $zip->close();
    if ($sheetXml === false) return [];

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData->row)) return [];

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        if (!isset($row->c)) { $rows[] = []; continue; }
        foreach ($row->c as $c) {
            $r = (string)($c['r'] ?? '');
            $col = preg_replace('/\d+/', '', $r);
            $v = isset($c->v) ? (string)$c->v : '';
            $t = (string)($c['t'] ?? '');

            if ($t === 's') {
                $idx = (int)$v;
                $cells[$col] = $sharedStrings[$idx] ?? '';
            } else {
                $cells[$col] = $v;
            }
        }

        $rows[] = [
            trim((string)($cells['A'] ?? '')),
            trim((string)($cells['B'] ?? '')),
            trim((string)($cells['C'] ?? '')),
            trim((string)($cells['D'] ?? '')),
        ];
    }

    return $rows;
}

function salin_aslimas_xlsx_xml_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function salin_aslimas_xlsx_col_name(int $zeroBasedIndex): string {
    $n = $zeroBasedIndex + 1;
    $s = '';
    while ($n > 0) {
        $m = ($n - 1) % 26;
        $s = chr(65 + $m) . $s;
        $n = intdiv($n - 1, 26);
    }
    return $s;
}

function salin_aslimas_xlsx_inline_cell(string $col, int $row1, string $text): string {
    $ref = $col . $row1;
    $t = salin_aslimas_xlsx_xml_esc($text);
    return '<c r="' . $ref . '" t="inlineStr"><is><t>' . $t . '</t></is></c>';
}

function salin_aslimas_download_hasil_xlsx(mysqli $conn, string $export_where): void {
    if (!class_exists('ZipArchive')) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Ekspor XLSX membutuhkan ekstensi PHP ZipArchive.";
        exit();
    }

    $sql = "SELECT id, asn_nama, asn_nip, asn_opd, asn_jabatan, asn_hp, pekerja_nama, pekerja_nik, pekerja_ttl, pekerja_jk, pekerja_job, status_kepesertaan, tgl_input
            FROM salin_aslimas_data $export_where
            ORDER BY tgl_input DESC";
    $q = $conn->query($sql);
    if (!$q) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Gagal mengambil data untuk diekspor.";
        exit();
    }

    $headers = [
        'ID',
        'Nama ASN',
        'NIP',
        'OPD',
        'Jabatan',
        'No HP ASN',
        'Nama Pekerja',
        'NIK Pekerja',
        'TTL',
        'JK',
        'Pekerjaan',
        'Status',
        'Tgl Input',
    ];

    $rowsXml = '';
    $r = 1;

    $headerCells = '';
    foreach ($headers as $i => $h) {
        $col = salin_aslimas_xlsx_col_name($i);
        $headerCells .= salin_aslimas_xlsx_inline_cell($col, $r, $h);
    }
    $rowsXml .= '<row r="' . $r . '">' . $headerCells . '</row>';
    $r++;

    while ($row = $q->fetch_assoc()) {
        $vals = [
            (string)($row['id'] ?? ''),
            (string)($row['asn_nama'] ?? ''),
            (string)($row['asn_nip'] ?? ''),
            (string)($row['asn_opd'] ?? ''),
            (string)($row['asn_jabatan'] ?? ''),
            (string)($row['asn_hp'] ?? ''),
            (string)($row['pekerja_nama'] ?? ''),
            (string)($row['pekerja_nik'] ?? ''),
            (string)($row['pekerja_ttl'] ?? ''),
            (string)($row['pekerja_jk'] ?? ''),
            (string)($row['pekerja_job'] ?? ''),
            (string)($row['status_kepesertaan'] ?? ''),
            (string)($row['tgl_input'] ?? ''),
        ];

        $cells = '';
        foreach ($vals as $i => $v) {
            $col = salin_aslimas_xlsx_col_name($i);
            // Pakai inlineStr agar Excel memperlakukan sebagai teks (hindari scientific notation pada NIK/NIP/HP)
            $cells .= salin_aslimas_xlsx_inline_cell($col, $r, $v);
        }
        $rowsXml .= '<row r="' . $r . '">' . $cells . '</row>';
        $r++;
    }

    $lastRow = $r - 1;
    $lastCol = salin_aslimas_xlsx_col_name(count($headers) - 1);
    $dimension = 'A1:' . $lastCol . max(1, $lastRow);

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="' . salin_aslimas_xlsx_xml_esc($dimension) . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Hasil" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'salinaslimas_xlsx_');
    if ($tmp === false) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Gagal membuat file sementara untuk ekspor.";
        exit();
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Gagal membuat arsip XLSX.";
        exit();
    }

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $fname = 'hasil_aslimas_' . date('Ymd') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . (string)@filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit();
}

/** Template .xlsx untuk Import Pekerja Sistem (kolom A–D, Sheet1). */
function salin_aslimas_download_template_import_sistem_xlsx(): void {
    $headers = [
        'Status (Y / T)',
        'Keterangan Status Data',
        'Nomor Identitas (NIK)',
        'Nama Lengkap',
    ];
    $exampleRows = [
        ['Y', '', '3302100101010001', 'Contoh Pekerja — Aktif (ganti data asli)'],
        ['T', 'Contoh: tidak lolos verifikasi', '3302100202020002', 'Contoh Pekerja — Ditolak'],
    ];

    $rowsXml = '';
    $r = 1;
    $headerCells = '';
    foreach ($headers as $i => $h) {
        $col = salin_aslimas_xlsx_col_name($i);
        $headerCells .= salin_aslimas_xlsx_inline_cell($col, $r, $h);
    }
    $rowsXml .= '<row r="' . $r . '">' . $headerCells . '</row>';
    $r++;

    foreach ($exampleRows as $vals) {
        $cells = '';
        foreach ($vals as $i => $v) {
            $col = salin_aslimas_xlsx_col_name($i);
            $cells .= salin_aslimas_xlsx_inline_cell($col, $r, (string)$v);
        }
        $rowsXml .= '<row r="' . $r . '">' . $cells . '</row>';
        $r++;
    }

    $lastRow = $r - 1;
    $lastCol = salin_aslimas_xlsx_col_name(count($headers) - 1);
    $dimension = 'A1:' . $lastCol . max(1, $lastRow);

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="' . salin_aslimas_xlsx_xml_esc($dimension) . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'salinaslimas_tpl_');
    if ($tmp === false) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Gagal membuat file sementara untuk template.';
        exit();
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Gagal membuat arsip XLSX.';
        exit();
    }

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $fname = 'template_import_pekerja_sistem_' . date('Ymd') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . (string)@filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit();
}

// --- DOWNLOAD TEMPLATE CSV ---
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="template_aslimas.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array('ASN_Nama', 'ASN_NIP', 'ASN_OPD', 'ASN_Jabatan', 'ASN_NoHP', 'Pekerja_Nama', 'Pekerja_NIK', 'Pekerja_TempatLahir', 'Pekerja_TanggalLahir(YYYY-MM-DD)', 'Pekerja_JK', 'Pekerja_Pekerjaan', 'Pekerja_NoHP'));
    fputcsv($output, array(
        'Budi Santoso',
        salin_aslimas_csv_excel_text('19800101001'),
        'Dinas Kesehatan',
        'Staff',
        salin_aslimas_csv_excel_text('08123'),
        'Supriyadi',
        salin_aslimas_csv_excel_text('3302100...'),
        'Banyumas',
        '1975-10-10',
        'Laki-laki',
        salin_aslimas_csv_excel_text('Buruh Tani'),
        salin_aslimas_csv_excel_text('0857'),
    ));
    fclose($output);
    exit();
}

// --- DOWNLOAD TEMPLATE XLSX (IMPORT PEKERJA SISTEM) ---
if (isset($_GET['action']) && $_GET['action'] === 'download_template_import_sistem') {
    if (!class_exists('ZipArchive')) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Template XLSX membutuhkan ekstensi PHP ZipArchive.';
        exit();
    }
    salin_aslimas_download_template_import_sistem_xlsx();
}

// --- EXPORT XLSX ---
if ($connected && isset($_GET['action']) && $_GET['action'] === 'download_xlsx') {
    $export_where = '';
    if ($opd_filter !== '') {
        $opd_esc = $conn->real_escape_string($opd_filter);
        $export_where = "WHERE asn_opd = '$opd_esc'";
    }
    salin_aslimas_download_hasil_xlsx($conn, $export_where);
}

// --- IMPORT PEKERJA SISTEM (XLSX) ---
if ($connected && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import_sistem'])) {
    $updated = 0;
    $notFound = 0;
    $skipped = 0;

    if (!isset($_FILES['xlsx_file']) || (int)$_FILES['xlsx_file']['error'] !== 0) {
        header("Location: ?sub=import_sistem&msg=" . urlencode("Gagal unggah file Excel (.xlsx)."));
        exit();
    }

    $ext = strtolower(pathinfo((string)($_FILES['xlsx_file']['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        header("Location: ?sub=import_sistem&msg=" . urlencode("Format file harus .xlsx"));
        exit();
    }

    $rows = salin_aslimas_xlsx_read_rows((string)$_FILES['xlsx_file']['tmp_name']);
    if (!$rows) {
        header("Location: ?sub=import_sistem&msg=" . urlencode("File .xlsx tidak dapat dibaca. Pastikan Sheet1 berisi data."));
        exit();
    }

    foreach ($rows as $i => $r) {
        // Skip header row if looks like header
        if ($i === 0) {
            $maybeHeader = strtolower(implode('|', $r));
            if (strpos($maybeHeader, 'status') !== false || strpos($maybeHeader, 'nomor') !== false || strpos($maybeHeader, 'identitas') !== false) {
                continue;
            }
        }

        [$statusRaw, $ketRaw, $identRaw, $namaRaw] = $r + ['', '', '', ''];
        $statusFlag = strtoupper(trim($statusRaw));
        $ident = preg_replace('/\D+/', '', (string)$identRaw);
        if ($statusFlag === '' && $ident === '' && trim((string)$namaRaw) === '') { $skipped++; continue; }
        if ($ident === '') { $skipped++; continue; }

        $nikEsc = $conn->real_escape_string($ident);
        $q = $conn->query("SELECT id FROM salin_aslimas_data WHERE pekerja_nik = '$nikEsc' ORDER BY tgl_input DESC LIMIT 1");
        $id = 0;
        if ($q && $q->num_rows > 0) {
            $id = (int)($q->fetch_assoc()['id'] ?? 0);
        }

        // Jika tidak ditemukan: tetap insert data baru dengan status "Belum-Diinput"
        if ($id <= 0) {
            $namaEsc = $conn->real_escape_string(trim((string)$namaRaw));
            if ($namaEsc === '') $namaEsc = $conn->real_escape_string('Pekerja (Import Sistem)');

            $ketEsc = $conn->real_escape_string(trim((string)$ketRaw));
            $ketSql = $ketEsc !== '' ? "'$ketEsc'" : "NULL";

            // Kolom ASN wajib NOT NULL → isi placeholder.
            $conn->query("INSERT INTO salin_aslimas_data
                (asn_nama, asn_nip, asn_opd, asn_jabatan, asn_hp, pekerja_nama, pekerja_nik, status_kepesertaan, keterangan_status_data)
                VALUES
                ('Import Sistem', '-', '-', '-', '-', '$namaEsc', '$nikEsc', 'Belum-Diinput', $ketSql)");

            $notFound++;
            continue;
        }

        if ($statusFlag === 'T') {
            $ketEsc = $conn->real_escape_string(trim((string)$ketRaw));
            $conn->query("UPDATE salin_aslimas_data SET status_kepesertaan = 'Ditolak', keterangan_status_data = " . ($ketEsc !== '' ? "'$ketEsc'" : "NULL") . " WHERE id = $id");
            $updated++;
        } elseif ($statusFlag === 'Y') {
            $conn->query("UPDATE salin_aslimas_data SET status_kepesertaan = 'Aktif' WHERE id = $id");
            $updated++;
        } else {
            $skipped++;
        }
    }

    $msgText = "Selesai. Updated: $updated, Ditambahkan (Belum-Diinput): $notFound, Dilewati: $skipped.";
    header("Location: ?sub=import_sistem&msg=" . urlencode($msgText));
    exit();
}

// --- MASTER DATA ---
if ($connected && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manage_master'])) {
    if (!empty($_POST['bulk_opd'])) {
        $list = explode("\n", str_replace("\r", "", $_POST['bulk_opd']));
        foreach ($list as $item) {
            $item = trim($conn->real_escape_string($item));
            if (!empty($item)) $conn->query("INSERT IGNORE INTO ref_opd (nama_opd) VALUES ('$item')");
        }
    }
    if (!empty($_POST['bulk_job'])) {
        $list = explode("\n", str_replace("\r", "", $_POST['bulk_job']));
        foreach ($list as $item) {
            $item = trim($conn->real_escape_string($item));
            if (!empty($item)) $conn->query("INSERT IGNORE INTO ref_pekerjaan (nama_pekerjaan) VALUES ('$item')");
        }
    }
    header("Location: ?sub=master&msg=" . urlencode("Master data diperbarui"));
    exit();
}

// --- UPDATE JUMLAH ASN (REF OPD) ---
if ($connected && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_jumlah_asn'])) {
    $items = $_POST['jumlah_asn'] ?? [];
    if (is_array($items)) {
        foreach ($items as $id => $val) {
            $idInt = (int)$id;
            if ($idInt <= 0) continue;
            $num = (int)preg_replace('/\D+/', '', (string)$val);
            if ($num < 0) $num = 0;
            $conn->query("UPDATE ref_opd SET jumlah_asn = $num WHERE id = $idInt");
        }
    }
    header("Location: ?sub=master&msg=" . urlencode("Jumlah ASN diperbarui"));
    exit();
}

// --- BRANDING ---
if ($connected && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_branding'])) {
    $keys = ['logo_pemda', 'logo_bpjs', 'logo_baznas', 'foto_bupati'];
    $brandingDir = __DIR__ . '/../uploads/branding';
    if (!is_dir($brandingDir)) mkdir($brandingDir, 0777, true);
    foreach ($keys as $key) {
        if (isset($_FILES[$key]) && $_FILES[$key]['error'] == 0) {
            $ext = pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION);
            $filename = $key . "." . $ext;
            $targetRel = "uploads/branding/" . $filename;
            $targetAbs = __DIR__ . '/../' . $targetRel;
            if (move_uploaded_file($_FILES[$key]['tmp_name'], $targetAbs)) {
                $conn->query("INSERT INTO ref_branding (key_name, file_path) VALUES ('$key', '$targetRel') ON DUPLICATE KEY UPDATE file_path = '$targetRel'");
            }
        }
    }
    header("Location: ?sub=branding&msg=" . urlencode("Branding diperbarui"));
    exit();
}

// --- IMPORT (CSV / PASTE) ---
if ($connected && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    $imported = 0;
    $data_rows = [];

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $handle = fopen($_FILES['csv_file']['tmp_name'], "r");
        $bom = fread($handle, 3);
        if ($bom != "\xEF\xBB\xBF") rewind($handle);
        $firstLine = fgets($handle);
        $separator = get_csv_separator($firstLine);
        rewind($handle);
        fgetcsv($handle, 4000, $separator);
        while (($row = fgetcsv($handle, 4000, $separator)) !== FALSE) {
            $data_rows[] = $row;
        }
        fclose($handle);
    } elseif (!empty($_POST['paste_data'])) {
        $lines = explode("\n", trim($_POST['paste_data']));
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $sep = (strpos($line, "\t") !== false) ? "\t" : ((strpos($line, ";") !== false) ? ";" : ",");
            $data_rows[] = explode($sep, trim($line));
        }
    }

    foreach ($data_rows as $column) {
        $c = array_map(function($val) {
            return trim(str_replace(['"', "'"], '', $val));
        }, $column);

        $asn_n   = $conn->real_escape_string($c[0] ?? '');
        $asn_nip = $conn->real_escape_string($c[1] ?? '');
        $asn_o   = $conn->real_escape_string($c[2] ?? '');
        $asn_j   = $conn->real_escape_string($c[3] ?? '');
        $asn_h   = $conn->real_escape_string($c[4] ?? '');
        $w_n     = $conn->real_escape_string($c[5] ?? '');
        $w_nik   = $conn->real_escape_string($c[6] ?? '');

        $w_tmpt  = $conn->real_escape_string($c[7] ?? '');
        $w_tgl   = $conn->real_escape_string($c[8] ?? '');
        $w_ttl   = trim($w_tmpt . ', ' . $w_tgl, ', ');

        $w_jk    = $conn->real_escape_string($c[9] ?? '');
        $w_job   = $conn->real_escape_string($c[10] ?? '');
        $w_hp    = $conn->real_escape_string($c[11] ?? '');

        if (!empty($w_n)) {
            $exists = false;
            if (!empty($w_nik)) {
                $check = $conn->query("SELECT id FROM salin_aslimas_data WHERE pekerja_nik = '$w_nik' AND pekerja_nama = '$w_n'");
                if ($check && $check->num_rows > 0) $exists = true;
            }

            if (!$exists) {
                $sql = "INSERT INTO salin_aslimas_data
                        (asn_nama, asn_nip, asn_opd, asn_jabatan, asn_hp, pekerja_nama, pekerja_nik, pekerja_ttl, pekerja_jk, pekerja_job, pekerja_hp)
                        VALUES
                        ('$asn_n', '$asn_nip', '$asn_o', '$asn_j', '$asn_h', '$w_n', '$w_nik', '$w_ttl', '$w_jk', '$w_job', '$w_hp')";
                if ($conn->query($sql)) $imported++;
            }
        }
    }

    $msg_text = ($imported > 0) ? "Berhasil mengimpor $imported data baru." : "Tidak ada data baru yang masuk. Cek kembali format kolom Anda.";
    header("Location: ?sub=import&msg=" . urlencode($msg_text));
    exit();
}

// --- UPDATE STATUS (TABEL HASIL) ---
if ($connected && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status_kepesertaan'] ?? '');
    $allowed = ['Pending', 'Aktif', 'Non-Aktif', 'Ditolak', 'Belum-Diinput'];

    $redir_p = max(1, (int)($_POST['p'] ?? ($_GET['p'] ?? 1)));
    $redir_opd = trim((string)($_POST['opd'] ?? $opd_filter));
    $redir_nik = preg_replace('/\D+/', '', (string)($_POST['nik'] ?? $nik_filter));
    $redir_sort = trim((string)($_POST['sort_opd'] ?? $opd_sort));
    if (!in_array($redir_sort, ['default', 'opd_asc', 'opd_desc'], true)) $redir_sort = 'default';

    $redir_q_extra = [];
    if ($redir_opd !== '') $redir_q_extra['opd'] = $redir_opd;
    if ($redir_nik !== '') $redir_q_extra['nik'] = $redir_nik;
    if ($redir_sort !== 'default') $redir_q_extra['sort_opd'] = $redir_sort;
    $redir_qs_extra = $redir_q_extra ? ('&' . http_build_query($redir_q_extra)) : '';

    if ($id > 0 && in_array($status, $allowed, true)) {
        $st = $conn->real_escape_string($status);
        $conn->query("UPDATE salin_aslimas_data SET status_kepesertaan = '$st' WHERE id = $id");
        header("Location: ?sub=database&p=" . urlencode((string)$redir_p) . $redir_qs_extra . "&msg=" . urlencode("Status diperbarui"));
        exit();
    }

    header("Location: ?sub=database&p=" . urlencode((string)$redir_p) . $redir_qs_extra . "&msg=" . urlencode("Gagal memperbarui status"));
    exit();
}

// --- DATA UNTUK DASHBOARD ---
$job_options = salin_aslimas_fetch_job_options($conn, $connected);
$opd_options = salin_aslimas_fetch_opd_options($conn, $connected);

// --- FILTER + SORT (TABEL HASIL) ---
$where_parts = [];
if ($opd_filter !== '') {
    $opd_esc = $conn->real_escape_string($opd_filter);
    $where_parts[] = "asn_opd = '$opd_esc'";
}
if ($nik_filter !== '') {
    $nik_esc = $conn->real_escape_string($nik_filter);
    // Allow partial search (mis. ketik 5-8 digit pertama) dan full 16 digit
    $where_parts[] = "pekerja_nik LIKE '%$nik_esc%'";
}
$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

$order_sql = "ORDER BY tgl_input DESC";
if ($opd_sort === 'opd_asc') $order_sql = "ORDER BY asn_opd ASC, tgl_input DESC";
if ($opd_sort === 'opd_desc') $order_sql = "ORDER BY asn_opd DESC, tgl_input DESC";

$total_rows = 0;
$total_rows_q = $connected ? $conn->query("SELECT COUNT(id) as total FROM salin_aslimas_data $where_sql") : false;
if ($total_rows_q) $total_rows = (int)($total_rows_q->fetch_assoc()['total'] ?? 0);

// Pagination untuk tabel hasil
$limit_per_page = 20;
$current_page_number = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($current_page_number - 1) * $limit_per_page;
$total_pages = $limit_per_page > 0 ? (int)ceil($total_rows / $limit_per_page) : 1;
$all_data_query = $connected ? $conn->query("SELECT * FROM salin_aslimas_data $where_sql $order_sql LIMIT $limit_per_page OFFSET $offset") : false;

$opd_ref_list = [];
if ($connected) {
    $opd_ref_res = $conn->query("SELECT nama_opd FROM ref_opd ORDER BY nama_opd ASC");
    if ($opd_ref_res) {
        while ($r = $opd_ref_res->fetch_assoc()) $opd_ref_list[] = (string)$r['nama_opd'];
    }
}

$master_limit = 25;
$opd_page = isset($_GET['opd_page']) ? max(1, (int)$_GET['opd_page']) : 1;
$job_page = isset($_GET['job_page']) ? max(1, (int)$_GET['job_page']) : 1;
$opd_offset = ($opd_page - 1) * $master_limit;
$job_offset = ($job_page - 1) * $master_limit;

$total_opd = 0;
$total_job = 0;
if ($connected) {
    $q1 = $conn->query("SELECT COUNT(id) AS total FROM ref_opd");
    if ($q1) $total_opd = (int)($q1->fetch_assoc()['total'] ?? 0);
    $q2 = $conn->query("SELECT COUNT(id) AS total FROM ref_pekerjaan");
    if ($q2) $total_job = (int)($q2->fetch_assoc()['total'] ?? 0);
}
$opd_pages = $master_limit > 0 ? max(1, (int)ceil($total_opd / $master_limit)) : 1;
$job_pages = $master_limit > 0 ? max(1, (int)ceil($total_job / $master_limit)) : 1;

$ref_opd_rows = $connected ? $conn->query("SELECT id, nama_opd, COALESCE(jumlah_asn, 0) AS jumlah_asn FROM ref_opd ORDER BY nama_opd ASC LIMIT $master_limit OFFSET $opd_offset") : false;
$ref_job_rows = $connected ? $conn->query("SELECT id, nama_pekerjaan FROM ref_pekerjaan ORDER BY nama_pekerjaan ASC LIMIT $master_limit OFFSET $job_offset") : false;

// Ambil Data Referensi & Branding
$branding = salin_aslimas_fetch_branding($conn, $connected);
$def_logo_pemda = salin_aslimas_to_url($branding['logo_pemda'] ?? '') ?: "https://upload.wikimedia.org/wikipedia/commons/e/e8/Logo_Kabupaten_Banyumas.png";
?>

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Salin Aslimas</title>
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
                    boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.06)', 'card': '0 4px 20px rgba(0,0,0,0.03)' }
                }
            }
        }
    </script>
    <style>
        body { background: radial-gradient(1200px 600px at 10% 0%, rgba(16,185,129,0.10), transparent 55%),
               radial-gradient(900px 500px at 90% 10%, rgba(59,130,246,0.10), transparent 55%),
               #f8fafc; }
        .input-premium{
            width:100%;
            background:#f8fafc;
            border:1.5px solid #cbd5e1;
            color:#1e293b;
            border-radius:12px;
            padding:14px 16px;
            outline:none;
            transition:all .25s ease;
            font-size:14px;
        }
        .input-premium:hover{ border-color:#94a3b8; background:#ffffff; }
        .input-premium:focus{ background:#ffffff; border-color:#10b981; box-shadow:0 0 0 4px rgba(16,185,129,0.12); }
        textarea.input-premium{ resize:vertical; min-height:120px; }
        select.input-premium{ cursor:pointer; }
        .admin-sidebar-link.active { background: rgba(16,185,129,0.10); color: #064e3b; font-weight: 800; }
    </style>
</head>
<body class="text-slate-600 antialiased">
    <header class="sticky top-0 z-40 bg-white/80 backdrop-blur border-b border-slate-200">
        <div class="w-full mx-auto px-4 sm:px-6 lg:px-10">
            <div class="h-16 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <!-- <img src="<?php echo $def_logo_pemda; ?>" alt="Pemda" class="h-10 md:h-12 object-contain drop-shadow-sm"> -->
                    <div class="border-l-2 border-slate-200 pl-3">
                        <p class="font-heading text-lg font-extrabold text-slate-900 leading-none">Admin Panel</p>
                        <p class="text-[10px] text-brand-600 font-bold tracking-wider uppercase mt-1">Salin Aslimas</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a href="../index.php" class="text-sm font-semibold text-slate-600 hover:text-slate-900">Portal</a>
                    <a href="logout.php" class="text-sm font-semibold text-red-600 hover:text-red-700">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <main class="w-full mx-auto px-4 sm:px-6 lg:px-10 py-8">
        <?php if($msg): ?>
            <div class="mb-6 p-4 bg-brand-50 border border-brand-100 rounded-2xl flex justify-between items-center shadow-sm">
                <div class="flex items-center text-brand-700">
                    <div class="bg-white p-2 rounded-full mr-3 shadow-sm"><i class="fas fa-check-circle text-xl text-brand-500"></i></div>
                    <span class="font-medium text-sm"><?php echo htmlspecialchars($msg); ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-brand-400 hover:text-brand-600 transition"><i class="fas fa-times"></i></button>
            </div>
        <?php endif; ?>

        <div class="bg-white/80 backdrop-blur rounded-[2rem] shadow-soft border border-slate-200/60 overflow-hidden flex flex-col lg:flex-row min-h-[calc(100vh-10.5rem)]">
            <aside class="w-full lg:w-80 bg-white/70 border-r border-slate-200/60 flex-shrink-0">
                <div class="p-6 md:p-8 lg:sticky lg:top-20">
                    <p class="text-[10px] font-extrabold text-slate-400 uppercase tracking-[0.2em] mb-5">Menu Panel</p>
                    <nav class="space-y-2">
                        <button onclick="showSub('dashboard')" class="admin-sidebar-link w-full text-left px-4 py-3 rounded-2xl text-sm text-slate-600 hover:bg-slate-100/70 transition flex items-center gap-3 border border-transparent hover:border-slate-200" id="sub-dashboard"><i class="fas fa-chart-pie w-4 opacity-70"></i> Ikhtisar</button>
                        <button onclick="showSub('master')" class="admin-sidebar-link w-full text-left px-4 py-3 rounded-2xl text-sm text-slate-600 hover:bg-slate-100/70 transition flex items-center gap-3 border border-transparent hover:border-slate-200" id="sub-master"><i class="fas fa-database w-4 opacity-70"></i> Data Master</button>
                        <button onclick="showSub('import')" class="admin-sidebar-link w-full text-left px-4 py-3 rounded-2xl text-sm text-slate-600 hover:bg-slate-100/70 transition flex items-center gap-3 border border-transparent hover:border-slate-200" id="sub-import"><i class="fas fa-cloud-upload-alt w-4 opacity-70"></i> Import Massal</button>
                        <button onclick="showSub('import_sistem')" class="admin-sidebar-link w-full text-left px-4 py-3 rounded-2xl text-sm text-slate-600 hover:bg-slate-100/70 transition flex items-center gap-3 border border-transparent hover:border-slate-200" id="sub-import_sistem"><i class="fas fa-file-arrow-up w-4 opacity-70"></i> Import Pekerja Sistem</button>
                        <button onclick="showSub('database')" class="admin-sidebar-link w-full text-left px-4 py-3 rounded-2xl text-sm text-slate-600 hover:bg-slate-100/70 transition flex items-center gap-3 border border-transparent hover:border-slate-200" id="sub-database"><i class="fas fa-table w-4 opacity-70"></i> Tabel Hasil Pengajuan</button>
                        <button onclick="showSub('branding')" class="admin-sidebar-link w-full text-left px-4 py-3 rounded-2xl text-sm text-slate-600 hover:bg-slate-100/70 transition flex items-center gap-3 border border-transparent hover:border-slate-200" id="sub-branding"><i class="fas fa-paint-roller w-4 opacity-70"></i> Identitas Visual</button>
                    </nav>
                    <div class="mt-6 pt-6 border-t border-slate-200/60">
                        <div class="flex items-center justify-between text-xs text-slate-500">
                            <span>Login sebagai</span>
                            <span class="font-semibold text-slate-700"><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'admin'); ?></span>
                        </div>
                    </div>
                </div>
            </aside>

            <section class="flex-1 p-6 md:p-10 lg:p-12 bg-white/70">
                <div id="sub-content-dashboard" class="admin-sub-content space-y-8">
                    <div>
                        <h3 class="font-heading text-2xl font-bold text-slate-900">Ikhtisar Sistem</h3>
                        <p class="text-slate-500 text-sm mt-1">Status dan ringkasan data saat ini.</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                        <div class="bg-white border border-slate-100 p-6 rounded-2xl shadow-card">
                            <div class="flex items-center justify-between mb-4">
                                <p class="text-sm font-semibold text-slate-500">Total Data (Pekerja)</p>
                                <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center"><i class="fas fa-users text-xs"></i></div>
                            </div>
                            <p class="font-heading text-4xl font-extrabold text-slate-900"><?php echo (int)$total_rows; ?></p>
                        </div>
                        <div class="bg-white border border-slate-100 p-6 rounded-2xl shadow-card">
                            <div class="flex items-center justify-between mb-4">
                                <p class="text-sm font-semibold text-slate-500">Status Database</p>
                                <div class="w-8 h-8 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fas fa-server text-xs"></i></div>
                            </div>
                            <p class="font-heading text-xl font-bold text-slate-900 flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full <?php echo $connected ? 'bg-green-500' : 'bg-red-500'; ?>"></span> <?php echo htmlspecialchars($db_status); ?>
                            </p>
                        </div>
                        <div class="bg-white border border-slate-100 p-6 rounded-2xl shadow-card">
                            <div class="flex items-center justify-between mb-4">
                                <p class="text-sm font-semibold text-slate-500">Aksi Cepat</p>
                                <div class="w-8 h-8 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center"><i class="fas fa-bolt text-xs"></i></div>
                            </div>
                            <div class="flex flex-col gap-2">
                                <a href="?sub=database" class="text-sm font-semibold text-slate-700 hover:text-slate-900">Lihat Tabel Hasil</a>
                                <a href="?sub=import" class="text-sm font-semibold text-brand-700 hover:text-brand-900">Import Massal</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="sub-content-master" class="admin-sub-content space-y-8 hidden">
                    <div>
                        <h3 class="font-heading text-2xl font-bold text-slate-900">Data Master</h3>
                        <p class="text-slate-500 text-sm mt-1">Kelola referensi dropdown untuk formulir (satu item per baris).</p>
                    </div>
                    <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <input type="hidden" name="manage_master" value="1">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2">Master Unit Kerja (OPD)</label>
                            <textarea name="bulk_opd" class="input-premium h-64 font-mono text-sm leading-relaxed" placeholder="Dinas A&#10;Badan B&#10;Kecamatan C"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2">Master Pekerjaan</label>
                            <textarea name="bulk_job" class="input-premium h-64 font-mono text-sm leading-relaxed" placeholder="Petani&#10;Buruh Harian&#10;Pedagang"></textarea>
                        </div>
                        <div class="md:col-span-2 flex justify-end">
                            <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-xl font-semibold shadow-md hover:bg-slate-800 transition">Simpan Referensi</button>
                        </div>
                    </form>

                    <div class="space-y-6">
                        <div class="border border-slate-200/60 rounded-2xl bg-white/80 shadow-card overflow-hidden">
                            <div class="p-5 border-b border-slate-200/60 flex items-center justify-between">
                                <div>
                                    <p class="font-bold text-slate-900">Master OPD</p>
                                    <p class="text-xs text-slate-500 mt-1">Sumber: <span class="font-mono">ref_opd</span>. Edit <span class="font-semibold">Jumlah ASN</span> sesuai data terbaru.</p>
                                </div>
                            </div>
                            <form method="POST" action="" class="p-5">
                                <input type="hidden" name="update_jumlah_asn" value="1">
                                <input type="hidden" name="opd_page" value="<?php echo (int)$opd_page; ?>">
                                <input type="hidden" name="job_page" value="<?php echo (int)$job_page; ?>">
                                <div class="overflow-x-auto rounded-xl border border-slate-200">
                                    <table class="w-full text-sm whitespace-nowrap">
                                        <thead class="bg-slate-50 border-b border-slate-200 text-xs text-slate-500 uppercase tracking-wider">
                                            <tr>
                                                <th class="p-3 text-left font-bold">OPD</th>
                                                <th class="p-3 text-center font-bold">Jumlah ASN</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php if($ref_opd_rows && $ref_opd_rows->num_rows > 0): while($o = $ref_opd_rows->fetch_assoc()): ?>
                                                <tr class="hover:bg-slate-50/60">
                                                    <td class="p-3 font-medium text-slate-800"><?php echo htmlspecialchars($o['nama_opd']); ?></td>
                                                    <td class="p-3 text-center">
                                                        <input type="number" min="0" name="jumlah_asn[<?php echo (int)$o['id']; ?>]" value="<?php echo (int)$o['jumlah_asn']; ?>" class="w-32 text-center input-premium bg-white py-2 px-3 rounded-xl">
                                                    </td>
                                                </tr>
                                            <?php endwhile; else: ?>
                                                <tr><td colspan="2" class="p-6 text-center text-slate-400">Belum ada data OPD.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ($opd_pages > 1): ?>
                                    <div class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-3">
                                        <p class="text-xs text-slate-500 font-medium">
                                            Menampilkan <?php echo ($opd_offset + 1); ?> - <?php echo min($opd_offset + $master_limit, $total_opd); ?> dari <?php echo $total_opd; ?> OPD
                                        </p>
                                        <div class="flex gap-1.5">
                                            <?php if($opd_page > 1): ?>
                                                <a href="?sub=master&opd_page=<?php echo $opd_page - 1; ?>&job_page=<?php echo $job_page; ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 text-xs font-semibold transition"><i class="fas fa-chevron-left"></i></a>
                                            <?php endif; ?>
                                            <?php
                                            $start = max(1, $opd_page - 2);
                                            $end = min($opd_pages, $opd_page + 2);
                                            for ($p = $start; $p <= $end; $p++):
                                            ?>
                                                <a href="?sub=master&opd_page=<?php echo $p; ?>&job_page=<?php echo $job_page; ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 <?php echo $p == $opd_page ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 hover:bg-slate-100'; ?> text-xs font-semibold transition"><?php echo $p; ?></a>
                                            <?php endfor; ?>
                                            <?php if($opd_page < $opd_pages): ?>
                                                <a href="?sub=master&opd_page=<?php echo $opd_page + 1; ?>&job_page=<?php echo $job_page; ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 text-xs font-semibold transition"><i class="fas fa-chevron-right"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="pt-4 flex justify-end">
                                    <button type="submit" class="bg-brand-600 text-white px-6 py-3 rounded-xl font-semibold shadow-md hover:bg-brand-700 transition">Simpan Jumlah ASN</button>
                                </div>
                            </form>
                        </div>

                        <div class="border border-slate-200/60 rounded-2xl bg-white/80 shadow-card overflow-hidden">
                            <div class="p-5 border-b border-slate-200/60">
                                <p class="font-bold text-slate-900">Master Pekerjaan</p>
                                <p class="text-xs text-slate-500 mt-1">Sumber: <span class="font-mono">ref_pekerjaan</span>.</p>
                            </div>
                            <div class="p-5">
                                <div class="overflow-x-auto rounded-xl border border-slate-200">
                                    <table class="w-full text-sm whitespace-nowrap">
                                        <thead class="bg-slate-50 border-b border-slate-200 text-xs text-slate-500 uppercase tracking-wider">
                                            <tr>
                                                <th class="p-3 text-left font-bold">Pekerjaan</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php if($ref_job_rows && $ref_job_rows->num_rows > 0): while($j = $ref_job_rows->fetch_assoc()): ?>
                                                <tr class="hover:bg-slate-50/60">
                                                    <td class="p-3 font-medium text-slate-800"><?php echo htmlspecialchars($j['nama_pekerjaan']); ?></td>
                                                </tr>
                                            <?php endwhile; else: ?>
                                                <tr><td class="p-6 text-center text-slate-400">Belum ada data pekerjaan.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ($job_pages > 1): ?>
                                    <div class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-3">
                                        <p class="text-xs text-slate-500 font-medium">
                                            Menampilkan <?php echo ($job_offset + 1); ?> - <?php echo min($job_offset + $master_limit, $total_job); ?> dari <?php echo $total_job; ?> pekerjaan
                                        </p>
                                        <div class="flex gap-1.5">
                                            <?php if($job_page > 1): ?>
                                                <a href="?sub=master&opd_page=<?php echo $opd_page; ?>&job_page=<?php echo $job_page - 1; ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 text-xs font-semibold transition"><i class="fas fa-chevron-left"></i></a>
                                            <?php endif; ?>
                                            <?php
                                            $start = max(1, $job_page - 2);
                                            $end = min($job_pages, $job_page + 2);
                                            for ($p = $start; $p <= $end; $p++):
                                            ?>
                                                <a href="?sub=master&opd_page=<?php echo $opd_page; ?>&job_page=<?php echo $p; ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 <?php echo $p == $job_page ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 hover:bg-slate-100'; ?> text-xs font-semibold transition"><?php echo $p; ?></a>
                                            <?php endfor; ?>
                                            <?php if($job_page < $job_pages): ?>
                                                <a href="?sub=master&opd_page=<?php echo $opd_page; ?>&job_page=<?php echo $job_page + 1; ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 text-xs font-semibold transition"><i class="fas fa-chevron-right"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="sub-content-import" class="admin-sub-content space-y-8 hidden">
                    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                        <div>
                            <h3 class="font-heading text-2xl font-bold text-slate-900">Import Massal</h3>
                            <p class="text-slate-500 text-sm mt-1">Tambahkan ratusan data pekerja rentan sekaligus.</p>
                        </div>
                        <a href="?action=download_template" class="text-sm font-semibold text-brand-600 bg-brand-50 px-4 py-2 rounded-lg hover:bg-brand-100 transition flex items-center gap-2 w-max"><i class="fas fa-download"></i> Unduh Format Excel</a>
                    </div>

                    <div class="bg-amber-50 border border-amber-200 text-amber-800 p-4 rounded-xl text-sm flex gap-3 shadow-sm">
                        <i class="fas fa-lightbulb mt-0.5 text-amber-500"></i>
                        <p><b>Tips Anti-Gagal:</b> Jika unggah CSV error, gunakan fitur "Tempel Data" (copy-paste dari Excel tanpa judul kolom).</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="border border-slate-200 p-6 rounded-2xl shadow-card bg-white">
                            <h4 class="font-bold text-slate-800 mb-1 flex items-center gap-2"><i class="fas fa-file-csv text-slate-400"></i> Unggah Berkas (.CSV)</h4>
                            <p class="text-xs text-slate-500 mb-5">Metode standar untuk file format comma-separated.</p>
                            <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                                <input type="hidden" name="do_import" value="1">
                                <input type="file" name="csv_file" accept=".csv" class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer border border-slate-200 rounded-full p-1 bg-slate-50">
                                <button type="submit" class="w-full bg-slate-900 text-white py-3 rounded-xl font-semibold hover:bg-slate-800 transition shadow-md text-sm mt-2">Jalankan Import File</button>
                            </form>
                        </div>

                        <div class="border border-brand-200 p-6 rounded-2xl shadow-card bg-brand-50/30 relative overflow-hidden">
                            <div class="absolute top-0 right-0 bg-brand-500 text-white text-[10px] font-bold px-3 py-1 rounded-bl-xl uppercase tracking-wider">Direkomendasikan</div>
                            <h4 class="font-bold text-slate-800 mb-1 flex items-center gap-2"><i class="fas fa-clipboard text-brand-500"></i> Tempel Data Langsung</h4>
                            <p class="text-xs text-slate-500 mb-5">Metode paling aman (Copy-paste dari Excel).</p>
                            <form method="POST" action="" class="space-y-4">
                                <input type="hidden" name="do_import" value="1">
                                <textarea name="paste_data" placeholder="Paste data Excel Anda di sini..." class="w-full h-24 input-premium text-xs font-mono"></textarea>
                                <button type="submit" class="w-full bg-brand-600 text-white py-3 rounded-xl font-semibold hover:bg-brand-700 transition shadow-md text-sm">Jalankan Import Teks</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div id="sub-content-import_sistem" class="admin-sub-content space-y-8 hidden">
                    <div>
                        <h3 class="font-heading text-2xl font-bold text-slate-900">Import Pekerja Sistem</h3>
                        <p class="text-slate-500 text-sm mt-1">Update status pekerja berdasarkan hasil verifikasi dari sistem BPJS Ketenagakerjaan (file Excel .xlsx).</p>
                    </div>

                    <div class="bg-white/70 border border-slate-200/60 rounded-2xl p-6 shadow-sm text-sm text-slate-700">
                        <p class="font-semibold text-slate-900 mb-2">Format kolom (Sheet1, mulai baris 1):</p>
                        <div class="text-xs text-slate-600 leading-relaxed">
                            <div><span class="font-mono">A</span> = Status (<span class="font-semibold">Y</span> / <span class="font-semibold">T</span>)</div>
                            <div><span class="font-mono">B</span> = Keterangan Status Data</div>
                            <div><span class="font-mono">C</span> = Nomor Identitas (dipakai untuk cari <span class="font-mono">pekerja_nik</span>)</div>
                            <div><span class="font-mono">D</span> = Nama Lengkap</div>
                        </div>
                        <div class="mt-4 text-xs text-slate-500">
                            Aturan: jika Status = <b>Y</b> → <b>Aktif</b>. Jika Status = <b>T</b> → <b>Ditolak</b> dan isi <b>keterangan_status_data</b>.
                        </div>
                        <div class="mt-4">
                            <a href="?action=download_template_import_sistem" class="inline-flex items-center gap-2 bg-emerald-600 text-white px-4 py-2.5 rounded-xl font-semibold text-sm hover:bg-emerald-700 transition shadow-md"><i class="fas fa-download"></i> Unduh template Excel (.xlsx)</a>
                            <p class="text-[11px] text-slate-500 mt-2">Berisi header kolom A–D dan baris contoh; hapus contoh lalu isi data hasil verifikasi.</p>
                        </div>
                    </div>

                    <div class="border border-slate-200/60 p-6 rounded-2xl shadow-card bg-white/80">
                        <h4 class="font-bold text-slate-800 mb-1 flex items-center gap-2"><i class="fas fa-file-excel text-green-600"></i> Unggah Berkas Excel (.xlsx)</h4>
                        <p class="text-xs text-slate-500 mb-5">Pastikan data berada di Sheet1 dan kolom A-D sesuai format.</p>
                        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                            <input type="hidden" name="do_import_sistem" value="1">
                            <input type="file" name="xlsx_file" accept=".xlsx" class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer border border-slate-200 rounded-full p-1 bg-slate-50">
                            <button type="submit" class="w-full bg-slate-900 text-white py-3 rounded-xl font-semibold hover:bg-slate-800 transition shadow-md text-sm mt-2">Jalankan Import Sistem</button>
                        </form>
                    </div>
                </div>

                <div id="sub-content-database" class="admin-sub-content space-y-6 hidden">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4">
                        <div>
                            <h3 class="font-heading text-2xl font-bold text-slate-900">Tabel Hasil Pengajuan</h3>
                            <p class="text-slate-500 text-sm mt-1">Total keseluruhan: <span class="font-bold text-slate-800"><?php echo (int)$total_rows; ?> data</span>.</p>
                        </div>
                        <a href="?action=download_xlsx<?php echo $db_qs_extra; ?>" class="bg-slate-900 text-white px-5 py-2.5 rounded-xl font-semibold text-sm flex items-center gap-2 hover:bg-slate-800 transition shadow-md whitespace-nowrap"><i class="fas fa-file-excel"></i> Ekspor ke Excel (.xlsx)</a>
                    </div>

                    <form method="GET" action="" class="bg-white/70 border border-slate-200/60 rounded-2xl p-5 shadow-sm">
    <input type="hidden" name="sub" value="database">
    <input type="hidden" name="p" value="1">
    
    <div class="flex flex-col xl:flex-row xl:items-end gap-3">
        <div class="flex-1 min-w-0">
            <label class="block text-xs font-bold text-slate-700 mb-2">Cari NIK</label>
            <div class="relative">
                <input type="text" name="nik" value="<?php echo htmlspecialchars($_GET['nik'] ?? ''); ?>" class="input-premium w-full bg-white pl-4" placeholder="Masukkan NIK 16 digit...">
            </div>
        </div>

        <div class="flex-1 min-w-0">
            <label class="block text-xs font-bold text-slate-700 mb-2">Filter Unit Kerja (OPD)</label>
            <select name="opd" class="input-premium appearance-none bg-white w-full">
                <option value="">Semua OPD</option>
                <?php foreach ($opd_ref_list as $opdName): ?>
                    <option value="<?php echo htmlspecialchars($opdName); ?>" <?php echo ($opd_filter === $opdName) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($opdName); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="flex-1 min-w-0">
            <label class="block text-xs font-bold text-slate-700 mb-2">Urutkan</label>
            <select name="sort_opd" class="input-premium appearance-none bg-white w-full">
                <option value="default" <?php echo $opd_sort === 'default' ? 'selected' : ''; ?>>Terbaru (default)</option>
                <option value="opd_asc" <?php echo $opd_sort === 'opd_asc' ? 'selected' : ''; ?>>OPD A → Z</option>
                <option value="opd_desc" <?php echo $opd_sort === 'opd_desc' ? 'selected' : ''; ?>>OPD Z → A</option>
            </select>
        </div>
        
        <div class="flex flex-col sm:flex-row xl:flex-col gap-2 xl:w-44 shrink-0">
            <button type="submit" class="w-full bg-slate-900 text-white py-3 rounded-xl font-semibold text-sm hover:bg-slate-800 transition shadow-md">Terapkan</button>
            <a href="?sub=database" class="w-full text-center bg-white border border-slate-200 text-slate-700 py-3 rounded-xl font-semibold text-sm hover:bg-slate-50 transition">Reset</a>
        </div>
    </div>
    
    <p class="text-[11px] text-slate-400 mt-3">Daftar OPD diambil dari <span class="font-mono">ref_opd.nama_opd</span>. Pencarian NIK mendukung pencarian parsial atau penuh.</p>
</form>

                    <div class="bg-white/70 border border-slate-200/60 rounded-2xl px-5 py-4 text-xs text-slate-600 leading-relaxed shadow-sm">
                        Ubah status langsung dari dropdown pada kolom <span class="font-semibold">Status</span>. Perubahan tersimpan otomatis.
                    </div>

                    <div class="border border-slate-200/60 rounded-2xl overflow-hidden shadow-card bg-white/80 flex flex-col">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm whitespace-nowrap">
                                <thead class="bg-slate-50 border-b border-slate-200 text-xs text-slate-500 uppercase tracking-wider">
                                    <tr>
                                        <th class="p-4 font-semibold">Tgl Input</th>
                                        <th class="p-4 font-semibold">ASN Pengusul</th>
                                        <th class="p-4 font-semibold">Nama Pekerja</th>
                                        <th class="p-4 font-semibold text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if($all_data_query && $all_data_query->num_rows > 0): while($row = $all_data_query->fetch_assoc()): ?>
                                    <tr class="hover:bg-slate-50/50 transition">
                                        <td class="p-4 text-slate-500 text-xs"><?php echo date('d M Y', strtotime($row['tgl_input'])); ?></td>
                                        <td class="p-4">
                                            <p class="font-medium text-slate-800"><?php echo htmlspecialchars($row['asn_nama']); ?></p>
                                            <p class="text-[10px] text-slate-400 mt-0.5"><?php echo htmlspecialchars($row['asn_opd']); ?></p>
                                        </td>
                                        <td class="p-4">
                                            <p class="font-semibold text-slate-800"><?php echo htmlspecialchars($row['pekerja_nama']); ?></p>
                                            <p class="text-[10px] text-slate-400 mt-0.5 font-mono"><?php echo htmlspecialchars($row['pekerja_nik']); ?></p>
                                        </td>
                                        <td class="p-4 text-center">
                                            <form method="POST" action="" class="inline-flex items-center justify-center gap-2">
                                                <input type="hidden" name="update_status" value="1">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="p" value="<?php echo (int)$current_page_number; ?>">
                                                <?php if ($nik_filter !== ''): ?>
                                                    <input type="hidden" name="nik" value="<?php echo htmlspecialchars($nik_filter); ?>">
                                                <?php endif; ?>
                                                <?php if ($opd_filter !== ''): ?>
                                                    <input type="hidden" name="opd" value="<?php echo htmlspecialchars($opd_filter); ?>">
                                                <?php endif; ?>
                                                <?php if ($opd_sort !== 'default'): ?>
                                                    <input type="hidden" name="sort_opd" value="<?php echo htmlspecialchars($opd_sort); ?>">
                                                <?php endif; ?>
                                                <select name="status_kepesertaan" class="text-[11px] px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-700 font-semibold tracking-wide shadow-sm hover:bg-slate-50 transition" onchange="this.form.submit()">
                                                    <option value="Pending" <?php echo ($row['status_kepesertaan'] ?? '') === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="Aktif" <?php echo ($row['status_kepesertaan'] ?? '') === 'Aktif' ? 'selected' : ''; ?>>Aktif</option>
                                                    <option value="Non-Aktif" <?php echo ($row['status_kepesertaan'] ?? '') === 'Non-Aktif' ? 'selected' : ''; ?>>Non-Aktif</option>
                                                    <option value="Ditolak" <?php echo ($row['status_kepesertaan'] ?? '') === 'Ditolak' ? 'selected' : ''; ?>>Ditolak</option>
                                                    <option value="Belum-Diinput" <?php echo ($row['status_kepesertaan'] ?? '') === 'Belum-Diinput' ? 'selected' : ''; ?>>Belum-Diinput</option>
                                                </select>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="4" class="p-8 text-center text-slate-400 text-sm">Belum ada data pengajuan.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <div class="border-t border-slate-200 p-4 bg-slate-50 flex flex-col md:flex-row items-center justify-between gap-4">
                                <p class="text-xs text-slate-500 font-medium">Menampilkan <?php echo ($offset + 1); ?> - <?php echo min($offset + $limit_per_page, $total_rows); ?> dari <?php echo $total_rows; ?> data</p>
                                <div class="flex gap-1.5">
                                    <?php if($current_page_number > 1): ?>
                                        <a href="?sub=database&p=<?php echo $current_page_number - 1; ?><?php echo $db_qs_extra; ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 text-xs font-semibold transition"><i class="fas fa-chevron-left"></i></a>
                                    <?php endif; ?>

                                    <?php
                                    $start_page = max(1, $current_page_number - 2);
                                    $end_page = min($total_pages, $current_page_number + 2);
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                    ?>
                                        <a href="?sub=database&p=<?php echo $i; ?><?php echo $db_qs_extra; ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 <?php echo $i == $current_page_number ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 hover:bg-slate-100'; ?> text-xs font-semibold transition"><?php echo $i; ?></a>
                                    <?php endfor; ?>

                                    <?php if($current_page_number < $total_pages): ?>
                                        <a href="?sub=database&p=<?php echo $current_page_number + 1; ?><?php echo $db_qs_extra; ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 text-xs font-semibold transition"><i class="fas fa-chevron-right"></i></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="sub-content-branding" class="admin-sub-content space-y-8 hidden">
                    <div>
                        <h3 class="font-heading text-2xl font-bold text-slate-900">Identitas Visual</h3>
                        <p class="text-slate-500 text-sm mt-1">Sesuaikan logo dan gambar pendukung pada portal.</p>
                    </div>
                    <form method="POST" action="" enctype="multipart/form-data" class="bg-white border border-slate-100 p-8 rounded-2xl shadow-card space-y-6">
                        <input type="hidden" name="update_branding" value="1">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div><label class="block text-xs font-bold text-slate-700 mb-2">Logo Pemerintah Daerah</label><input type="file" name="logo_pemda" class="input-premium p-2 text-sm bg-white"></div>
                            <div><label class="block text-xs font-bold text-slate-700 mb-2">Logo BPJS Ketenagakerjaan</label><input type="file" name="logo_bpjs" class="input-premium p-2 text-sm bg-white"></div>
                            <div><label class="block text-xs font-bold text-slate-700 mb-2">Logo BAZNAS</label><input type="file" name="logo_baznas" class="input-premium p-2 text-sm bg-white"></div>
                            <div><label class="block text-xs font-bold text-slate-700 mb-2">Foto / Ilustrasi Utama (Hero)</label><input type="file" name="foto_bupati" class="input-premium p-2 text-sm bg-white"></div>
                        </div>
                        <div class="pt-4 border-t border-slate-100">
                            <button type="submit" class="bg-slate-900 text-white px-8 py-3 rounded-xl font-semibold shadow-md hover:bg-slate-800 transition">Simpan Perubahan Visual</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </main>

    <script>
        const initialSub = "<?php echo htmlspecialchars($active_sub); ?>";
        function showSub(subId) {
            document.querySelectorAll('.admin-sub-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.admin-sidebar-link').forEach(el => el.classList.remove('active'));

            const target = document.getElementById('sub-content-' + subId);
            const link = document.getElementById('sub-' + subId);
            if (target) target.classList.remove('hidden');
            if (link) link.classList.add('active');

            const url = new URL(window.location.href);
            url.searchParams.set('sub', subId);
            if (subId !== 'database') url.searchParams.delete('p');
            window.history.replaceState(null, '', url.toString());
        }
        window.onload = () => showSub(initialSub || 'dashboard');
    </script>
</body>
</html>
<?php if($connected) $conn->close(); ?>

