<?php
use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed');

Plugins::getInstance()->execute('member_self_before_drop_schema', ['schemaById' => $schemaById]);

$schemaById->execute([$_POST['schema_id']]);
$detail = $schemaById->fetchObject();
if (!$detail) { toastr('Skema tidak ditemukan.')->error(); exit; }

DB::getInstance()->prepare('delete from `self_registration_schemas` where `id` = ?')->execute([$_POST['schema_id']]);

$table = msr_schema_table($detail->name);
if (Schema::hasTable($table)) Schema::drop($table); // v2: fatal bila tabel sudah hilang

foreach (json_decode($detail->structure, true) ?: [] as $column) {
    if (($column['field'] ?? '') !== 'advance') continue;
    $col = trim(explode(',', (string)($column['advfield'] ?? ''))[0]);
    if ($col !== '' && Schema::hasColumn('member_custom', $col)) Schema::dropColumn('member_custom', $col);
}
exit;