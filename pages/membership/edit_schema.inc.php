<?php
use SLiMS\DB;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');
if (!$can_write) die('<div class="errorBox">Hak tulis diperlukan.</div>');

$schemaById->execute([$_GET['schema_id'] ?? 0]);
$s = $schemaById->fetchObject();
if (!$s) { echo '<div class="alert alert-warning m-3">Skema tidak ditemukan.</div>'; return; }

$structure = json_decode($s->structure, true) ?: [];
$info = json_decode($s->info) ?: (object)['title' => '', 'desc' => '', 'position' => 'top'];
$table = msr_schema_table($s->name);
$count = Schema::hasTable($table) ? (int)DB::getInstance()->query('select count(*) from `' . $table . '`')->fetchColumn() : 0;
$lock = $count > 0;
$listTypes = ['enum', 'enum_radio', 'text_multiple'];
?>
<div class="card mx-3 my-3"><div class="card-body">
    <h5 class="font-weight-bold">🧬 Edit Skema — <?= msr_e($s->name) ?></h5>
    <p class="text-muted mb-2">Nama skema terkunci (menentukan nama tabel). Antrean: <strong><?= $count ?></strong> pendaftar.
        <?= $lock
            ? '<span class="badge badge-warning">Terkunci: hapus ruas & ubah pilihan dibatasi selama antrean berisi data.</span>'
            : '<span class="badge badge-success">Antrean kosong — perubahan struktur bebas.</span>' ?>
    </p>
    <form method="post" action="<?= pluginUrl(['section' => 'edit_schema', 'schema_id' => $s->id]) ?>">
        <input type="hidden" name="action" value="update_schema">
        <input type="hidden" name="schema_id" value="<?= (int)$s->id ?>">
        <?= msr_csrf_field() ?>
        <div class="row">
            <div class="col-md-4 form-group"><label><strong>Judul Formulir</strong></label>
                <input type="text" name="info[title]" class="form-control" value="<?= msr_e($info->title ?? '') ?>"></div>
            <div class="col-md-2 form-group"><label><strong>Letak Info</strong></label>
                <select name="info[position]" class="form-control">
                    <option value="top" <?= ($info->position ?? 'top') === 'top' ? 'selected' : '' ?>>Atas</option>
                    <option value="bottom" <?= ($info->position ?? '') === 'bottom' ? 'selected' : '' ?>>Bawah</option>
                </select></div>
            <div class="col-md-6 form-group"><label><strong>Deskripsi / Syarat</strong> <small>(HTML dasar)</small></label>
                <textarea name="info[desc]" class="form-control" rows="2"><?= msr_e($info->desc ?? '') ?></textarea></div>
        </div>
        <table class="table table-sm table-bordered">
            <thead class="thead-light"><tr><th>Label</th><th style="width:110px">Wajib</th><th>Ruas / Tipe</th><th>Pilihan (pisah |)</th><th style="width:60px">Hapus</th></tr></thead>
            <tbody>
            <?php foreach ($structure as $idx => $col):
                $isAdv = ($col['field'] ?? '') === 'advance';
                $parts = explode(',', (string)($col['advfield'] ?? ''), 2);
                $fieldName = $isAdv ? ($parts[0] ?? '') : ($col['field'] ?? '');
                $type = $isAdv ? ($col['advfieldtype'] ?? 'varchar') : 'kolom member';
                $isList = $isAdv && in_array($type, $listTypes, true);
            ?>
                <tr>
                    <td><input type="text" name="c[<?= $idx ?>][label]" class="form-control form-control-sm" value="<?= msr_e($col['name'] ?? '') ?>"></td>
                    <td><select name="c[<?= $idx ?>][required]" class="form-control form-control-sm">
                        <option value="1" <?= !empty($col['is_required']) ? 'selected' : '' ?>>Wajib</option>
                        <option value="0" <?= empty($col['is_required']) ? 'selected' : '' ?>>Opsional</option>
                    </select></td>
                    <td><code><?= msr_e($fieldName) ?></code><br><small class="text-muted"><?= msr_e($type) ?></small></td>
                    <td><?php if ($isList): ?><input type="text" name="c[<?= $idx ?>][choices]" class="form-control form-control-sm" value="<?= msr_e($parts[1] ?? '') ?>"><?php else: ?><small class="text-muted">—</small><?php endif; ?></td>
                    <td class="text-center"><input type="checkbox" name="del[]" value="<?= $idx ?>" <?= $lock ? 'disabled title="Antrean berisi data"' : '' ?>></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <h6 class="font-weight-bold mt-3">➕ Tambah Ruas Mahir Baru</h6>
        <table class="table table-sm" id="msrNewRows"><tbody></tbody></table>
        <button type="button" class="btn btn-sm btn-outline-success" id="msrAddRow">+ Tambah Ruas</button>
        <hr>
        <button type="submit" class="btn btn-primary">💾 Simpan Perubahan</button>
        <a href="<?= pluginUrl(['section' => 'list']) ?>" class="btn btn-outline-secondary">Kembali</a>
    </form>
</div></div>
<script>
$(function(){
    $('#msrAddRow').click(function(){
        $('#msrNewRows tbody').append('<tr>'
            + '<td><input name="nl[]" class="form-control form-control-sm" placeholder="Label"></td>'
            + '<td><input name="nf[]" class="form-control form-control-sm" placeholder="nama_kolom (tanpa adv_)"></td>'
            + '<td><select name="nt[]" class="form-control form-control-sm"><option value="varchar">Teks Singkat</option><option value="int">Angka</option><option value="text">Paragraf</option><option value="enum">Daftar</option><option value="enum_radio">Radio</option><option value="text_multiple">Pilihan Ganda</option></select></td>'
            + '<td><input name="nc[]" class="form-control form-control-sm" placeholder="Opsi A|Opsi B (untuk daftar)"></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="$(this).closest(\'tr\').remove()">✕</button></td></tr>');
    });
});
</script>