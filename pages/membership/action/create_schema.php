<?php
/**
 * MSR v3.2 — Buat skema: TABEL DULU, baris skema kemudian (anti skema yatim).
 * Tanpa refleksi privat → aman PHP 8.2 & perubahan core.
 */
use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed');
require_once MSLR . DS . 'msr_repair.php';

if (!isset($_POST['saveData'])) return;

$name = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9\s]/', '', (string)($_POST['name'] ?? ''))));
if ($name === '') { toastr('Nama skema tidak boleh kosong!')->warning('Peringatan'); exit; }
if (mb_strlen($name) > 32) { toastr('Nama skema maksimal 32 karakter.')->warning(); exit; }

$columns = $_POST['column'] ?? [];
if (!is_array($columns) || !$columns) { toastr('Struktur ruas kosong.')->error(); exit; }

$hasReq = (bool)count(array_filter($columns, fn($c) => in_array($c['field'] ?? '', ['member_id', 'member_name', 'gender'], true)));
if (!$hasReq) { toastr('Ruas member_id, member_name dan gender wajib ada.')->error('Galat'); exit; }

foreach ($columns as $k => $c) {
    $columns[$k]['is_required'] = (bool)($c['is_required'] ?? false);
    if (($c['field'] ?? '') === 'advance') {
        $adv = trim((string)($c['advfield'] ?? ''));
        if ($adv === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', explode(',', $adv)[0])) {
            toastr('Nama kolom ruas mahir tidak valid (diawali huruf; huruf/angka/underscore).')->error(); exit;
        }
        $columns[$k]['advfield'] = 'adv_' . $adv;
    }
}

$db = DB::getInstance();
$cek = $db->prepare('select id from self_registration_schemas where name = ?');
$cek->execute([$name]);
if ($cek->rowCount()) { toastr('Nama skema sudah digunakan.')->error(); exit; }

$structureJson = json_encode(array_values($columns));
Plugins::getInstance()->execute('member_self_before_create_schema', ['newTable' => msr_schema_table($name), 'structure' => $columns]);

// 1) Bangun tabel dulu
$r = msr_rebuild_table((object)['name' => $name, 'structure' => $structureJson]);
if (!$r['ok']) { toastr($r['msg'])->error('Galat'); exit; }

// 2) Baru tulis baris skema; gagal → buang tabelnya
try {
    $db->prepare('insert into self_registration_schemas (name, info, structure, status, created_at) values (?,?,?,0,now())')
       ->execute([$name, json_encode($_POST['info'] ?? []), $structureJson]);
} catch (Throwable $e) {
    Schema::drop(msr_schema_table($name));
    toastr('Gagal menyimpan skema: ' . $e->getMessage())->error();
    exit;
}

redirect()->simbioAJAX(pluginUrl(reset: true));
exit;