<?php
/**
 * MSR v3.1 — Antrean pendaftar + ACC/Tolak massal
 */
use SLiMS\Plugins;
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

$data = $activeSchema->fetchObject();
if (!$data) { echo '<div class="alert alert-warning m-3">Belum ada skema aktif.</div>'; return; }

$table     = msr_schema_table($data->name);
$hasEmail  = Schema::hasColumn($table, 'member_email');
$hasVerify = Schema::hasColumn($table, 'email_verified_at');

if (!function_exists('msrRegButton')) {
    function msrRegButton($dbs, $data) {
        return '<a title="Tinjau ' . msr_e($data[2] ?? '') . '" href="' . pluginUrl(['section' => 'view_detail', 'member_id' => $data[0], 'headless' => 'yes']) . '" class="notAJAX openPopUp btn btn-primary btn-sm"><i class="fa fa-eye"></i> Tinjau</a>';
    }
}
if (!function_exists('msrEmailBadge')) {
    function msrEmailBadge($dbs, $data) {
        return !empty($data[3]) ? '<span class="badge badge-success">Terverifikasi</span>' : '<span class="badge badge-warning">Belum</span>';
    }
}

$datagrid = new simbio_datagrid();
$columns = ['`member_id` AS `Aksi`', '`member_id` AS `ID Anggota`', '`member_name` AS `Nama`'];
if ($hasVerify) $columns[] = '`email_verified_at` AS `Status Email`'; // selalu indeks 3
if ($hasEmail)  $columns[] = '`member_email` AS `Email`';
$columns[] = '`created_at` AS `Tanggal Daftar`';

$datagrid->setSQLColumn(...$columns);
$datagrid->setSQLorder('created_at DESC');
$datagrid->modifyColumnContent(0, 'callback{msrRegButton}');
if ($hasVerify) $datagrid->modifyColumnContent(3, 'callback{msrEmailBadge}');

$datagrid->table_attr = 'id="dataList" class="s-table table"';
$datagrid->table_header_attr = 'class="dataListHeader thead-dark" style="font-weight:bold;"';
$datagrid->chbox_form_URL = pluginUrl(reset: true);

if (isset($_GET['keywords'])) {
    $keywords = $dbs->escape_string($_GET['keywords']);
    $datagrid->setSQLCriteria('(member_id like \'%' . $keywords . '%\' or member_name like \'%' . $keywords . '%\')');
}

Plugins::getInstance()->execute('member_self_before_datagrid', ['datagrid' => $datagrid, 'table_spec' => $table]);

if ($can_write) {
?>
<div class="d-flex flex-wrap align-items-center mx-3 my-2" id="msrBulkBar">
    <label class="mb-0 mr-3" style="cursor:pointer"><input type="checkbox" id="msrCheckAll"> Pilih semua (halaman ini)</label>
    <span id="msrSelCount" class="badge badge-secondary px-2 py-1 mr-3">0 dipilih</span>
    <button type="button" id="msrBulkAcc" class="btn btn-success btn-sm mr-2" disabled>✓ Setujui Terpilih</button>
    <button type="button" id="msrBulkReject" class="btn btn-danger btn-sm mr-2" disabled>✗ Tolak Terpilih</button>
    <small class="text-muted">Maks 50 per aksi · notifikasi email otomatis · verifikasi email dihormati</small>
</div>
<div id="msrRejectBox" class="card mx-3 my-2 d-none"><div class="card-body py-2">
    <label class="mb-1"><strong>Alasan penolakan</strong> <small class="text-muted">(dikirim ke email pendaftar & tampil di cek status)</small></label>
    <textarea id="msrRejectReason" class="form-control" rows="2" maxlength="300">Tidak memenuhi syarat keanggotaan.</textarea>
    <div class="mt-2">
        <button type="button" id="msrRejectGo" class="btn btn-danger btn-sm">Kirim Penolakan</button>
        <button type="button" id="msrRejectCancel" class="btn btn-secondary btn-sm">Batal</button>
    </div>
</div></div>
<?php
}

echo $datagrid->createDataGrid($dbs, $table, 20, $can_write);

if ($can_write) {
    $bulkUrl = pluginUrl(reset: true);
    $csrf    = msr_csrf_token();
    echo <<<HTML
<script>
$(document).ready(function() {
    const bulkUrl = '{$bulkUrl}', csrf = '{$csrf}';
    const ids = () => $('#dataList input[name="itemID[]"]:checked').map((_, el) => el.value).get();
    function upd() {
        const n = ids().length;
        $('#msrSelCount').text(n + ' dipilih');
        $('#msrBulkAcc,#msrBulkReject').prop('disabled', n === 0);
    }
    $('#msrCheckAll').change(function() { $('#dataList input[name="itemID[]"]').prop('checked', this.checked); upd(); });
    $('#dataList').on('change', 'input[name="itemID[]"]', upd);

    function done(res) {
        if (!res || !res.ok) { alert(res && res.msg ? res.msg : 'Terjadi kesalahan.'); return; }
        let m = 'Selesai. Berhasil: ' + res.success + ' · Dilewati: ' + res.skipped + ' · Gagal: ' + res.failed;
        if (res.details && res.details.length) m += '\\n' + res.details.slice(0, 8).join('\\n');
        if (window.top.toastr) top.toastr.success(m, 'Aksi Massal'); else alert(m);
        $('#mainContent').simbioAJAX(bulkUrl);
    }
    function guard(list) {
        if (!list.length) return false;
        if (list.length > 50) { alert('Maksimal 50 pendaftar per aksi.'); return false; }
        return true;
    }
    $('#msrBulkAcc').click(function() {
        const list = ids(); if (!guard(list)) return;
        if (!confirm('Setujui ' + list.length + ' pendaftar? Email notifikasi akan dikirim.')) return;
        $.post(bulkUrl, {action: 'bulk_acc', member_ids: list, msr_csrf: csrf}, done, 'json')
            .fail(() => alert('Gagal terhubung ke server.'));
    });
    $('#msrBulkReject').click(() => $('#msrRejectBox').removeClass('d-none'));
    $('#msrRejectCancel').click(() => $('#msrRejectBox').addClass('d-none'));
    $('#msrRejectGo').click(function() {
        const list = ids(); if (!guard(list)) return;
        if (!confirm('Tolak ' + list.length + ' pendaftar? Data dihapus dari antrean & email penolakan dikirim.')) return;
        $.post(bulkUrl, {action: 'bulk_reject', member_ids: list, reason: $('#msrRejectReason').val(), msr_csrf: csrf}, done, 'json')
            .fail(() => alert('Gagal terhubung ke server.'));
    });
});
</script>
HTML;
}