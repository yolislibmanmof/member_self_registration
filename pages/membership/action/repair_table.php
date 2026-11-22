<?php
use SLiMS\DB;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

if (!utility::havePrivilege('membership', 'w')) { toastr('Hak tulis diperlukan.')->error(); exit; }
require_once MSLR . DS . 'msr_repair.php';

$id = (int)($_GET['schema_id'] ?? $_POST['schema_id'] ?? 0);
if ($id > 0) {
    $q = DB::getInstance()->prepare('select * from self_registration_schemas where id = ?');
    $q->execute([$id]);
    $schema = $q->fetchObject();
} else {
    $schema = $activeSchema->fetchObject();
}
if (!$schema) { toastr('Skema tidak ditemukan.')->error(); exit; }

$r = msr_rebuild_table($schema);
if ($r['ok']) { msr_log('repair', null, $r['msg']); toastr($r['msg'])->success(); }
else toastr($r['msg'])->error();

redirect()->simbioAJAX(pluginUrl(reset: true));
exit;