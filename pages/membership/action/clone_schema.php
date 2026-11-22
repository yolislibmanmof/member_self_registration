<?php
use SLiMS\DB;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');
if (!utility::havePrivilege('membership', 'w')) { toastr('Hak tulis diperlukan.')->error(); exit; }
require_once MSLR . DS . 'msr_repair.php';

$schemaById->execute([$_GET['schema_id'] ?? 0]);
$src = $schemaById->fetchObject();
if (!$src) { toastr('Skema sumber tidak ditemukan.')->error(); exit; }

$db = DB::getInstance();
$exists = function($n) use ($db) {
    $q = $db->prepare('select id from self_registration_schemas where name = ?');
    $q->execute([$n]);
    return (bool)$q->rowCount();
};
$base = mb_substr($src->name, 0, 22);
$name = $base . ' Salinan'; $i = 2;
while ($exists($name) && $i < 99) { $name = mb_substr($base . ' Salinan ' . $i, 0, 32); $i++; }
if ($exists($name)) { toastr('Gagal membuat nama salinan unik.')->error(); exit; }

$r = msr_rebuild_table((object)['name' => $name, 'structure' => $src->structure]);
if (!$r['ok']) { toastr($r['msg'])->error(); exit; }

try {
    $db->prepare('insert into self_registration_schemas (name, info, structure, `option`, status, created_at) values (?,?,?,?,0,now())')
       ->execute([$name, $src->info, $src->structure, $src->option]);
} catch (Throwable $e) {
    Schema::drop(msr_schema_table($name));
    toastr('Gagal mengklon: ' . $e->getMessage())->error();
    exit;
}
msr_log('schema_clone', null, $src->name . ' → ' . $name);
toastr('Skema diklon: ' . $name . ' (antrean kosong, status nonaktif).')->success();
redirect()->simbioAJAX(pluginUrl(['section' => 'list']));
exit;