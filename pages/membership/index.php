<?php
/**
 * MSR v3.2 — Router admin + guard tabel antrean (anti "Table doesn't exist")
 */
use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') OR die('Direct access not allowed!');

require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-membership');
require SB . 'admin/default/session.inc.php';
require SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO . 'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO . 'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO . 'simbio_DB/datagrid/simbio_dbgrid.inc.php';

require_once MSLR . DS . 'msr_lib.php';
require_once MSLR . DS . 'msr_repair.php';
require_once MSLR . DS . 'msr_tools.php';

$can_read  = utility::havePrivilege('membership', 'r');
$can_write = utility::havePrivilege('membership', 'w');
if (!$can_read) die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');

$section      = $_GET['section'] ?? null;
if (!is_string($section)) $section = null;
$schemas      = DB::getInstance()->query('select * from self_registration_schemas');
$schemaById   = DB::getInstance()->prepare('select * from self_registration_schemas where id = ?');
$activeSchema = DB::getInstance()->query('select * from self_registration_schemas where status = 1');

/* ---- GUARD: tabel antrean wajib ada (auto-rebuild bila hilang) ---- */
$msrGuard     = ['ok' => true];
$msrActiveObj = null;
if ($activeSchema->rowCount() > 0) {
    $msrActiveObj = msr_active_schema(true);
    if ($msrActiveObj) $msrGuard = msr_guard_table($msrActiveObj);
}

/* ---- Aksi ---- */
$action = $_POST['action'] ?? $_GET['action'] ?? null;
if (!is_string($action)) $action = null;
if (!$msrGuard['ok'] && in_array($action, ['acc', 'bulk_acc', 'bulk_reject', 'delete_reg'], true)) {
    toastr('Tabel antrean tidak tersedia. Jalankan perbaikan terlebih dahulu.')->error();
    exit;
}
$routes = [
    'import_schema' => [],
    'export_schema' => ['schemaById' => $schemaById],
    'create_schema' => [],
    'active_schema' => [],
    'form_config'   => [],
    'drop_schema'   => ['schemaById' => $schemaById],
    'repair_table'  => ['activeSchema' => $activeSchema],
    'clone_schema'  => ['schemaById' => $schemaById],
    'update_schema' => [],
    'card_pdf'      => [],
    'acc'           => ['activeSchema' => $activeSchema],
    'delete_reg'    => ['activeSchema' => $activeSchema],
    'bulk_acc'      => ['activeSchema' => $activeSchema],
    'bulk_reject'   => ['activeSchema' => $activeSchema],
];
$params = $routes[$action] ?? null;
if ($params !== null) action($action, $params);

/* ---- Kepala halaman ---- */
$page_title = 'Daftar Online';
if (!isset($_GET['headless'])) {
?>
<div class="menuBox">
    <div class="menuBoxInner memberIcon">
        <div class="per_title"><h2><?= $page_title ?></h2></div>
    </div>
</div>
<div class="msr-toolbar px-3 py-2" style="display:<?= $schemas->rowCount() > 0 ? 'flex' : 'none' ?>;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between">
    <div class="btn-group flex-wrap" role="group" style="position:static !important;float:none !important;margin:0 !important">
        <a href="<?= pluginUrl(['section' => 'dashboard']) ?>" class="btn btn-info"><i class="fa fa-dashboard"></i> Dashboard</a>
        <a href="<?= pluginUrl(['section' => 'card']) ?>" class="btn btn-outline-dark"><i class="fa fa-id-card"></i> Kartu PDF</a>
        <?php if ($activeSchema->rowCount() > 0 && $msrActiveObj): ?>
            <?php $path = trim(strtolower(str_replace(' ', '_', $msrActiveObj->name))); ?>
            <a href="<?= pluginUrl(reset: true) ?>" class="btn btn-primary"><i class="fa fa-list"></i> Antrean Pendaftar</a>
            <a target="_blank" href="<?= SWB . '?p=' . $path ?>" class="notAJAX btn btn-success"><i class="fa fa-link"></i> Form OPAC</a>
            <a href="<?= pluginUrl(['section' => 'form_config']) ?>" class="btn btn-outline-secondary"><i class="fa fa-cog"></i> Pengaturan Form</a>
            <a href="<?= pluginUrl(['section' => 'list']) ?>" class="btn btn-outline-secondary"><i class="fa fa-file"></i> Daftar Skema</a>
            <?php if ($section === 'list'): ?>
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-toggle="dropdown">Skema Baru</button>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="<?= pluginUrl(['section' => 'add_schema']) ?>"><i class="fa fa-plus"></i> Tambah</a>
                    <a class="dropdown-item openPopUp notAJAX" href="<?= pluginUrl(['section' => 'import_schema', 'headless' => 'yes']) ?>"><i class="fa fa-download"></i> Impor</a>
                </div>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-toggle="dropdown"><i class="fa fa-file"></i> Skema Baru</button>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="<?= pluginUrl(['section' => 'add_schema']) ?>"><i class="fa fa-plus"></i> Tambah</a>
                    <a class="dropdown-item openPopUp notAJAX" href="<?= pluginUrl(['section' => 'import_schema', 'headless' => 'yes']) ?>"><i class="fa fa-download"></i> Impor</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <form name="search" action="<?= pluginUrl(reset: true) ?>" id="search" method="get" class="form-inline" style="position:static !important;float:none !important;margin:0 !important;display:flex;align-items:center;gap:6px;flex:0 0 auto">
        <input type="text" name="keywords" class="form-control" style="width:210px" placeholder="<?= __('Search') ?> ID / nama…" />
        <input type="submit" id="doSearch" value="<?= __('Search') ?>" class="s-btn btn btn-default" />
    </form>
</div>
<?php
    if (!empty($msrGuard['repaired'])) echo '<div class="alert alert-info mx-3 py-2">🔧 ' . msr_e($msrGuard['msg']) . '</div>';
}

/* ---- Halaman ---- */
if (!$msrGuard['ok'] && (!$section || $section === 'dashboard')) {
    $tableName = msr_schema_table($msrActiveObj->name);
    echo '<div class="card mx-3 my-3 border-danger"><div class="card-body">
            <h5 class="text-danger font-weight-bold">⚠️ Tabel antrean tidak ditemukan</h5>
            <p>Skema <strong>' . msr_e($msrActiveObj->name) . '</strong> aktif, tetapi tabel <code>' . msr_e($tableName) . '</code> tidak ada di database.</p>
            <p class="text-muted mb-2">' . msr_e($msrGuard['msg']) . '</p>
            <p class="text-muted"><small>Penyebab umum: pembuatan/impor skema v2 gagal di tengah (baris skema tertulis sebelum tabel dibuat), atau database pindah/restor sebagian.</small></p>
            <a href="' . pluginUrl(['action' => 'repair_table']) . '" class="btn btn-danger mr-2">🔧 Perbaiki Tabel Sekarang</a>
            <a href="' . pluginUrl(['section' => 'list']) . '" class="btn btn-outline-secondary">Kelola Daftar Skema</a>
          </div></div>';
} elseif (!$section) {
    if ($activeSchema->rowCount() < 1) include __DIR__ . DS . 'list.inc.php';
    else include __DIR__ . DS . 'active_list.inc.php';
} else if (file_exists($filepath = __DIR__ . DS . basename($section) . '.inc.php')) {
    include $filepath;
}