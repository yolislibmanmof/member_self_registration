<?php
use SLiMS\DB;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');
if (!utility::havePrivilege('membership', 'w')) { toastr('Hak tulis diperlukan.')->error(); exit; }
if (!msr_csrf_verify()) { toastr('Token CSRF tidak valid.')->error(); exit; }

$db = DB::getInstance();
$q = $db->prepare('select * from self_registration_schemas where id = ?');
$q->execute([(int)($_POST['schema_id'] ?? 0)]);
$s = $q->fetchObject();
if (!$s) { toastr('Skema tidak ditemukan.')->error(); exit; }

$old = json_decode($s->structure, true) ?: [];
$table = msr_schema_table($s->name);
$count = Schema::hasTable($table) ? (int)$db->query('select count(*) from `' . $table . '`')->fetchColumn() : 0;
$lock = $count > 0;
$listTypes = ['enum', 'enum_radio', 'text_multiple'];

$posted = (array)($_POST['c'] ?? []);
$del = array_map('intval', (array)($_POST['del'] ?? []));
$warnings = []; $new = []; $dropped = []; $toAdd = []; $toModify = [];

foreach ($old as $idx => $col) {
    if (in_array($idx, $del, true)) {
        if ($lock) { $warnings[] = 'Ruas "' . $col['name'] . '" tidak dihapus (antrean berisi data).'; $new[] = $col; }
        else $dropped[] = $col;
        continue;
    }
    $p = $posted[$idx] ?? [];
    $col['name'] = trim(strip_tags((string)($p['label'] ?? $col['name']))) ?: ($col['name'] ?? '');
    $col['is_required'] = (bool)($p['required'] ?? $col['is_required']);

    if (($col['field'] ?? '') === 'advance' && in_array($col['advfieldtype'] ?? '', $listTypes, true)) {
        $parts = explode(',', (string)$col['advfield'], 2);
        $oldChoices = array_values(array_filter(array_map('trim', explode('|', $parts[1] ?? ''))));
        $newChoices = array_values(array_filter(array_map('trim', explode('|', (string)($p['choices'] ?? ($parts[1] ?? ''))))));
        if ($newChoices && $newChoices !== $oldChoices) {
            $ok = true;
            if ($lock && Schema::hasColumn($table, $parts[0])) {
                $dq = $db->query('select distinct `' . $parts[0] . '` from `' . $table . '`');
                while ($v = $dq->fetchColumn()) {
                    $vals = ($col['advfieldtype'] === 'text_multiple') ? (json_decode((string)$v, true) ?: []) : [(string)$v];
                    foreach ($vals as $vv) if ($vv !== '' && !in_array($vv, $newChoices, true)) { $ok = false; break 2; }
                }
            }
            if ($ok) { $col['advfield'] = $parts[0] . ',' . implode('|', $newChoices); $toModify[] = $col; }
            else $warnings[] = 'Pilihan "' . $col['name'] . '" tidak diubah (ada data lama di luar pilihan baru).';
        }
    }
    $new[] = $col;
}

/* Ruas mahir baru */
$nf = (array)($_POST['nf'] ?? []); $nl = (array)($_POST['nl'] ?? []);
$nt = (array)($_POST['nt'] ?? []); $nc = (array)($_POST['nc'] ?? []);
$existing = [];
foreach ($new as $c) $existing[] = $c['field'] === 'advance' ? trim(explode(',', $c['advfield'])[0]) : $c['field'];
foreach ($nf as $i => $fname) {
    $fname = trim((string)$fname);
    if ($fname === '') continue;
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,28}$/', $fname)) { $warnings[] = 'Nama kolom baru tidak valid: ' . $fname; continue; }
    $type = in_array($nt[$i] ?? '', ['varchar','int','text','enum','enum_radio','text_multiple'], true) ? $nt[$i] : 'varchar';
    $adv = 'adv_' . $fname;
    if (in_array($type, $listTypes, true)) {
        $choices = array_values(array_filter(array_map('trim', explode('|', (string)($nc[$i] ?? '')))));
        if (!$choices) { $warnings[] = 'Ruas baru "' . $fname . '" butuh daftar pilihan.'; continue; }
        $adv .= ',' . implode('|', $choices);
    }
    if (in_array($adv, $existing, true)) { $warnings[] = 'Kolom ' . $adv . ' sudah ada.'; continue; }
    $col = ['name' => trim(strip_tags((string)($nl[$i] ?? ''))) ?: $fname, 'field' => 'advance', 'advfield' => $adv, 'advfieldtype' => $type, 'is_required' => false];
    $new[] = $col; $toAdd[] = $col; $existing[] = $adv;
}

$hasReq = count(array_filter($new, fn($c) => in_array($c['field'] ?? '', ['member_id', 'member_name', 'gender'], true)));
if ($hasReq < 3) { toastr('Ruas member_id, member_name, gender wajib tetap ada.')->error(); exit; }

/* ALTER tabel antrean (member_custom tidak pernah di-drop) */
try {
    foreach ($dropped as $col) {
        $f = ($col['field'] === 'advance') ? trim(explode(',', $col['advfield'])[0]) : $col['field'];
        if (Schema::hasColumn($table, $f)) $db->exec("alter table `$table` drop column `$f`");
    }
    foreach ($toAdd as $col) {
        $r = msr_resolve_column($col, null); $sql = msr_column_sql($r);
        if (!Schema::hasColumn($table, $r['field'])) $db->exec("alter table `$table` add column `{$r['field']}` $sql null");
        if (Schema::hasTable('member_custom') && !Schema::hasColumn('member_custom', $r['field'])) $db->exec("alter table `member_custom` add column `{$r['field']}` $sql null");
    }
    foreach ($toModify as $col) {
        $r = msr_resolve_column($col, null); $sql = msr_column_sql($r);
        if (Schema::hasColumn($table, $r['field'])) $db->exec("alter table `$table` modify column `{$r['field']}` $sql null");
    }
} catch (Throwable $e) {
    toastr('Gagal mengubah struktur: ' . $e->getMessage())->error();
    exit;
}

$info = [
    'title'    => trim(strip_tags((string)($_POST['info']['title'] ?? ''))),
    'desc'     => strip_tags((string)($_POST['info']['desc'] ?? ''), '<p><a><i><em><strong><h1><h2><h3><ul><ol><li><br>'),
    'position' => ($_POST['info']['position'] ?? 'top') === 'bottom' ? 'bottom' : 'top',
];
$db->prepare('update self_registration_schemas set info = ?, structure = ?, updated_at = now() where id = ?')
   ->execute([json_encode($info), json_encode(array_values($new)), $s->id]);

msr_log('schema_edit', null, 'Skema ' . $s->name . ' diperbarui');
toastr('Skema berhasil diperbarui.')->success();
foreach ($warnings as $w) toastr($w)->warning();
redirect()->simbioAJAX(pluginUrl(['section' => 'list']));
exit;