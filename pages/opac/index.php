<?php
/**
 * MSR v3.1 — Halaman OPAC: formulir pendaftaran, verifikasi email, cek status publik
 */
use SLiMS\DB;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');
require_once MSLR . DS . 'msr_lib.php';
require_once MSLR . DS . 'msr_tools.php';

$schema = msr_active_schema(true);
if ($schema === null) {
    echo '<div class="alert alert-warning my-3">Mohon maaf, pendaftaran online belum dibuka.</div>';
    return;
}

$path    = trim(strtolower(str_replace(' ', '_', $schema->name)));
$selfUrl = SWB . 'index.php?p=' . $path;
$table   = msr_schema_table($schema->name);
require_once MSLR . DS . 'msr_repair.php';
$msrGuard = msr_guard_table($schema);
if (!$msrGuard['ok']) {
    echo '<div class="alert alert-danger my-3">Pendaftaran sementara tidak tersedia. Silakan hubungi pustakawan.</div>';
    return;
}
if (!empty($msrGuard['repaired'])) {
    echo '<div class="alert alert-info my-3">' . msr_e($msrGuard['msg']) . '</div>';
}
$mode    = isset($_GET['msr_status']) ? 'status' : 'register';

/* ---- Simpan pendaftaran ---- */
if ($mode === 'register' && isset($_POST['form'])) {
    action('save', ['schema' => $schema, 'selfUrl' => $selfUrl, 'table' => $table]);
}

/* ---- Verifikasi email via tautan ---- */
if (isset($_GET['msr_verify']) && Schema::hasTable('self_registration_tokens')) {
    $token = substr(preg_replace('/[^a-f0-9]/', '', (string)$_GET['msr_verify']), 0, 64);
    $q = DB::getInstance()->prepare('select * from self_registration_tokens where token = ? and used_at is null and expired_at > now()');
    $q->execute([$token]);
    if ($row = $q->fetchObject()) {
        if (Schema::hasTable($row->schema_table) && Schema::hasColumn($row->schema_table, 'email_verified_at')) {
            DB::getInstance()->prepare('update `' . $row->schema_table . '` set email_verified_at = now() where member_id = ?')->execute([$row->member_id]);
        }
        DB::getInstance()->prepare('update self_registration_tokens set used_at = now() where id = ?')->execute([$row->id]);
        msr_log('verify', $row->member_id);
        echo '<div class="alert alert-success my-3"><strong>Terima kasih!</strong> Email Anda terverifikasi. Pendaftaran menunggu persetujuan pustakawan.</div>';
    } else {
        echo '<div class="alert alert-danger my-3">Tautan verifikasi tidak valid atau kedaluwarsa.</div>';
    }
}

/* ---- Proses cek status ---- */
$statusResult = $statusError = null;
if ($mode === 'status' && isset($_POST['msr_check'])) {
    try {
        if (!msr_csrf_verify()) throw new Exception('Sesi tidak valid. Muat ulang halaman.');
        if (!empty($_POST['msr_hp'])) throw new Exception('Aktivitas terdeteksi sebagai bot.');
        if (!msr_rate_limit('status_check', 10, 600)) throw new Exception('Terlalu banyak pengecekan. Coba lagi dalam 10 menit.');
        $id = trim((string)($_POST['msr_id'] ?? ''));
        $em = trim((string)($_POST['msr_email'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{1,20}$/', $id)) throw new Exception('ID anggota tidak valid.');
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) throw new Exception('Format email tidak valid.');
        $statusResult = msr_status_lookup($id, $em);
        msr_log('status_check', $id, 'code=' . $statusResult['code']);
    } catch (Throwable $e) {
        $statusError = $e->getMessage();
    }
}

/* ---- Tampilan ---- */
if ($mode === 'status') {
    $map = ['active'=>'success','pending'=>'info','hold'=>'warning','expired'=>'secondary','rejected'=>'danger','not_found'=>'warning','contact'=>'secondary','closed'=>'secondary'];
    echo '<div class="container my-4" style="max-width:640px">';
    echo '<h3 class="mb-1">🔎 Cek Status Pendaftaran</h3><p class="text-muted">Masukkan ID anggota &amp; email yang Anda gunakan saat mendaftar.</p>';
    if ($statusError) echo '<div class="alert alert-danger py-2">' . msr_e($statusError) . '</div>';
    if ($statusResult) {
        $cls = $map[$statusResult['code']] ?? 'info';
        echo '<div class="alert alert-' . $cls . '"><h5 class="alert-heading">' . msr_e($statusResult['title']) . '</h5>';
        if (!empty($statusResult['name'])) echo '<p class="mb-1"><strong>' . msr_e($statusResult['name']) . '</strong> (' . msr_e($statusResult['member_id'] ?? '') . ')</p>';
        echo '<p class="mb-1">' . msr_e($statusResult['detail'] ?? '') . '</p>';
        if (!empty($statusResult['expire'])) echo '<p class="mb-0"><small>Masa berlaku hingga: <strong>' . msr_e($statusResult['expire']) . '</strong></small></p>';
        if (!empty($statusResult['date'])) echo '<p class="mb-0"><small>Waktu: ' . msr_e($statusResult['date']) . '</small></p>';
        echo '</div>';
    }
    echo '<form method="post" action="' . msr_e($selfUrl . '&msr_status=1') . '">';
    echo msr_csrf_field();
    echo '<input type="text" name="msr_hp" class="d-none" tabindex="-1" autocomplete="off">';
    echo '<div class="form-group"><label><strong>ID Anggota</strong></label><input type="text" name="msr_id" class="form-control" required maxlength="20" value="' . msr_e($_POST['msr_id'] ?? '') . '"></div>';
    echo '<div class="form-group"><label><strong>Email</strong></label><input type="email" name="msr_email" class="form-control" required value="' . msr_e($_POST['msr_email'] ?? '') . '"></div>';
    echo '<button class="btn btn-primary" name="msr_check" value="1">Cek Status</button> ';
    echo '<a class="btn btn-outline-secondary" href="' . msr_e($selfUrl) . '">Kembali ke Formulir</a>';
    echo '</form></div>';
} else {
    $opac = $opac ?? ($GLOBALS['opac'] ?? null);
    echo formGenerator($schema, [], $selfUrl, $opac);
    echo '<div class="text-center my-3"><small>Sudah mendaftar? <a href="' . msr_e($selfUrl . '&msr_status=1') . '"><strong>Cek status pendaftaran Anda</strong></a></small></div>';
}