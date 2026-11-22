<?php
/**
 * MSR v3.2 — Impor skema: validasi → tabel dulu → baris skema.
 */
use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');
require_once MSLR . DS . 'msr_repair.php';

$new = json_decode((string)($_POST['import']['raw_json'] ?? ''), true);
if (!is_array($new) || empty($new['name']) || empty($new['structure'])) { toastr('Berkas skema tidak valid.')->error(); exit; }

$structure = json_decode((string)$new['structure'], true) ?: [];
$hasReq = (bool)count(array_filter($structure, fn($c) => in_array($c['field'] ?? '', ['member_id', 'member_name', 'gender'], true)));
if (!$hasReq) { toastr('Ruas member_id, member_name dan gender tidak ditemukan.')->error('Galat'); exit; }

$name = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9\s]/', '', (string)$new['name'])));
if ($name === '' || mb_strlen($name) > 32) { toastr('Nama skema tidak valid (maks 32 karakter).')->error(); exit; }

$db = DB::getInstance();
$cek = $db->prepare('select id from self_registration_schemas where name = ?');
$cek->execute([$name]);
if ($cek->rowCount()) { toastr('Nama skema sudah ada — impor dibatalkan.')->error(); exit; }

$structureJson = json_encode(array_values($structure));
Plugins::getInstance()->execute('member_self_before_create_schema', ['newTable' => msr_schema_table($name), 'structure' => $structure]);

$r = msr_rebuild_table((object)['name' => $name, 'structure' => $structureJson]);
if (!$r['ok']) { toastr($r['msg'])->error('Galat'); exit; }

try {
    $db->prepare('insert into self_registration_schemas (name, info, structure, `option`, status, created_at, updated_at) values (?,?,?,?,0,now(),now())')
       ->execute([$name, (string)($new['info'] ?? '{}'), $structureJson, isset($new['option']) ? (string)$new['option'] : null]);
} catch (Throwable $e) {
    Schema::drop(msr_schema_table($name));
    toastr('Gagal menyimpan skema: ' . $e->getMessage())->error();
    exit;
}

toastr('Berhasil mengimpor skema')->success();
echo '<script>top.jQuery.colorbox.close();</script>';
redirect()->simbioAJAX(pluginUrl([], true));
exit;