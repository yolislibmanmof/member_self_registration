<?php
/**
 * MSR v3.1 — Dashboard statistik (grafik SVG tanpa dependensi eksternal)
 */
use SLiMS\DB;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

$schema = $activeSchema->fetchObject();
if (!$schema) {
    echo '<div class="alert alert-warning m-3"><strong>Belum ada skema aktif.</strong> Buat/aktifkan skema dahulu.</div>';
    return;
}

$db      = DB::getInstance();
$table   = msr_schema_table($schema->name);
$option  = msr_option($schema);
$hasLogs = Schema::hasTable('self_registration_logs');
$path    = trim(strtolower(str_replace(' ', '_', $schema->name)));

/* ---- Angka antrean ---- */
$pending  = (int)$db->query('select count(*) from `' . $table . '`')->fetchColumn();
$verified = Schema::hasColumn($table, 'email_verified_at')
    ? (int)$db->query('select count(*) from `' . $table . '` where email_verified_at is not null')->fetchColumn() : 0;
$unverified = $pending - $verified;
$oldest     = $db->query('select min(created_at) from `' . $table . '`')->fetchColumn();

/* ---- Angka dari audit log ---- */
$logCount = function(string $event, string $extra = '') use($db, $hasLogs): int {
    if (!$hasLogs) return 0;
    $q = $db->prepare("select count(*) from self_registration_logs where event = ? $extra");
    $q->execute([$event]);
    return (int)$q->fetchColumn();
};
$approvedTotal = $logCount('approve');
$rejectedTotal = $logCount('reject');
$blockedTotal  = $logCount('submit_failed');
$submitToday   = $logCount('submit', "and date(created_at) = curdate()");
$approveToday  = $logCount('approve', "and date(created_at) = curdate()");

/* ---- Grafik 14 hari ---- */
$dates = [];
for ($i = 13; $i >= 0; $i--) $dates[] = date('Y-m-d', strtotime("-$i days"));
$since = $dates[0] . ' 00:00:00';
$subs = $apps = [];
if ($hasLogs) {
    $q = $db->prepare("select date(created_at) d, count(*) c from self_registration_logs where event = 'submit' and created_at >= ? group by d");
    $q->execute([$since]); $subs = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $q = $db->prepare("select date(created_at) d, count(*) c from self_registration_logs where event = 'approve' and created_at >= ? group by d");
    $q->execute([$since]); $apps = $q->fetchAll(PDO::FETCH_KEY_PAIR);
}
$chart = function(array $dates, array $sub, array $app): string {
    $w = 700; $h = 230; $pad = 34; $bw = 13; $gap = 4;
    $max = max(1, ...array_values($sub ?: [0]), ...array_values($app ?: [0]));
    $slot = ($w - $pad - 10) / max(1, count($dates));
    $grid = $bars = $labels = '';
    for ($g = 0; $g <= 4; $g++) {
        $y = $h - 30 - round($g / 4 * ($h - 60)); $val = (int)round($g / 4 * $max);
        $grid .= '<line x1="' . $pad . '" y1="' . $y . '" x2="' . ($w - 6) . '" y2="' . $y . '" stroke="#e5e7eb"/>'
               . '<text x="' . ($pad - 6) . '" y="' . ($y + 3) . '" font-size="9" text-anchor="end" fill="#888">' . $val . '</text>';
    }
    foreach ($dates as $i => $d) {
        $x  = $pad + $i * $slot + ($slot - 2 * $bw - $gap) / 2;
        $h1 = (int)round(($sub[$d] ?? 0) / $max * ($h - 60));
        $h2 = (int)round(($app[$d] ?? 0) / $max * ($h - 60));
        $bars .= '<rect x="' . $x . '" y="' . ($h - 30 - $h1) . '" width="' . $bw . '" height="' . $h1 . '" fill="#3b82f6" rx="2"><title>' . $d . ' — daftar: ' . ($sub[$d] ?? 0) . '</title></rect>'
               . '<rect x="' . ($x + $bw + $gap) . '" y="' . ($h - 30 - $h2) . '" width="' . $bw . '" height="' . $h2 . '" fill="#22c55e" rx="2"><title>' . $d . ' — disetujui: ' . ($app[$d] ?? 0) . '</title></rect>';
        $labels .= '<text x="' . ($x + $bw) . '" y="' . ($h - 10) . '" font-size="9" text-anchor="middle" fill="#888">' . date('d/m', strtotime($d)) . '</text>';
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto">' . $grid . $bars . $labels . '</svg>';
};

/* ---- Aktivitas terakhir ---- */
$recent = $hasLogs ? $db->query('select * from self_registration_logs order by id desc limit 12')->fetchAll(PDO::FETCH_ASSOC) : [];
$badgeMap = ['submit'=>'primary','approve'=>'success','reject'=>'danger','verify'=>'info','submit_failed'=>'warning','approve_failed'=>'warning','status_check'=>'secondary','mail_error'=>'dark'];

$card = fn(string $icon, string $label, $value, string $color) =>
    '<div class="col-6 col-md-4 col-xl-2 mb-3"><div class="card h-100" style="border-left:4px solid ' . $color . '"><div class="card-body py-3">'
    . '<div style="font-size:1.6rem;font-weight:700">' . $value . '</div>'
    . '<small class="text-muted">' . $icon . ' ' . $label . '</small></div></div></div>';
?>
<div class="mx-3 mt-3">
    <?php if (!$hasLogs): ?>
        <div class="alert alert-danger">Migrasi v3 belum dijalankan — statistik log & grafik tidak tersedia. Jalankan: <strong>Sistem → Plugin → Migrasi</strong>.</div>
    <?php endif; ?>
    <?php if ($oldest && strtotime($oldest) < strtotime('-3 days')): ?>
        <div class="alert alert-warning py-2">⏳ Ada pendaftar yang sudah menunggu <strong><?= (int)floor((time() - strtotime($oldest)) / 86400) ?> hari</strong>. Segera tinjau antrean.</div>
    <?php endif; ?>

    <div class="row">
        <?= $card('📥', 'Menunggu Persetujuan', $pending, '#3b82f6') ?>
        <?= $card('✉️', 'Email Belum Verifikasi', $unverified, '#f59e0b') ?>
        <?= $card('📅', 'Daftar Hari Ini', $submitToday, '#8b5cf6') ?>
        <?= $card('✅', 'Disetujui (total)', $approvedTotal, '#22c55e') ?>
        <?= $card('⛔', 'Ditolak (total)', $rejectedTotal, '#ef4444') ?>
        <?= $card('🛡️', 'Spam/Gagal Diblokir', $blockedTotal, '#64748b') ?>
    </div>

    <div class="row">
        <div class="col-lg-8 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="font-weight-bold mb-0">📈 Tren 14 Hari — <?= msr_e($schema->name) ?></h6>
                        <small><span class="badge badge-primary">■ Daftar</span> <span class="badge badge-success">■ Disetujui</span>
                        <span class="text-muted ml-2">Hari ini ACC: <?= $approveToday ?></span></small>
                    </div>
                    <?= $chart($dates, $subs, $apps) ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="font-weight-bold">🕒 Aktivitas Terakhir</h6>
                    <div style="max-height:260px;overflow:auto">
                    <?php if (!$recent): ?><small class="text-muted">Belum ada aktivitas.</small><?php endif; ?>
                    <?php foreach ($recent as $r):
                        $cls = $badgeMap[$r['event']] ?? 'secondary';
                        $detail = mb_strimwidth((string)($r['detail'] ?? ''), 0, 70, '…'); ?>
                        <div class="border-bottom py-1">
                            <span class="badge badge-<?= $cls ?>"><?= msr_e($r['event']) ?></span>
                            <strong><?= msr_e($r['member_id'] ?? '-') ?></strong>
                            <small class="text-muted d-block"><?= msr_e($detail) ?> · <?= msr_e($r['ip_address'] ?? '') ?> · <?= msr_e($r['created_at'] ?? '') ?></small>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4"><div class="card-body py-2 d-flex flex-wrap align-items-center">
        <strong class="mr-3">⚡ Aksi Cepat:</strong>
        <a href="<?= pluginUrl(reset: true) ?>" class="btn btn-sm btn-primary mr-2">Antrean (<?= $pending ?>)</a>
        <a href="<?= pluginUrl(['section' => 'form_config']) ?>" class="btn btn-sm btn-outline-secondary mr-2">Pengaturan Form</a>
        <a href="<?= pluginUrl(['section' => 'list']) ?>" class="btn btn-sm btn-outline-secondary mr-2">Daftar Skema</a>
        <a target="_blank" class="notAJAX btn btn-sm btn-outline-success mr-2" href="<?= SWB . 'index.php?p=' . $path ?>">Form OPAC</a>
        <a target="_blank" class="notAJAX btn btn-sm btn-outline-info" href="<?= SWB . 'index.php?p=' . $path . '&msr_status=1' ?>">Halaman Cek Status Publik</a>
        <?php if (($option->verify_email ?? false)): ?><span class="badge badge-info ml-auto px-2 py-1">Verifikasi email: AKTIF</span><?php endif; ?>
    </div></div>
</div>