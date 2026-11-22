<?php
/**
 * Member Self Registration v3.0 — Pengaturan Form (FINAL)
 * Path   : pages/membership/form_config.inc.php
 * Target : SLiMS 9.8.x • PHP 8.2 (Laragon)
 */

use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

/* ---- Skema aktif (guard: jangan baca properti pada false) ---- */
$data = $activeSchema->fetchObject();
if (!$data) {
    echo '<div class="alert alert-warning m-3">
            <strong>Belum ada skema aktif.</strong> Aktifkan sebuah skema pada menu <em>Daftar Skema</em> terlebih dahulu.
          </div>';
    return;
}

$option = msr_option($data);

/* ---- Status pendukung (informatif, tidak mengubah alur simpan) ---- */
$mailConf  = config('mail', []);
$mailReady = is_array($mailConf) && !empty($mailConf['server']) && !empty($mailConf['from']);
$captchaOn = (bool)config('captcha', false);
$migrated  = Schema::hasTable('self_registration_tokens') && Schema::hasTable('self_registration_logs');
$opacUrl   = SWB . 'index.php?p=' . trim(strtolower(str_replace(' ', '_', $data->name)));

$badge = fn(bool $ok, string $yes, string $no) =>
    '<span class="badge ' . ($ok ? 'badge-success' : 'badge-warning') . ' px-2 py-1 mr-1">' . ($ok ? $yes : $no) . '</span>';
?>
<div class="card mx-3 mt-3 mb-2">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div class="mb-2">
                <h5 class="mb-1 font-weight-bold">⚙️ Pengaturan Form — <?= msr_e($data->name) ?></h5>
                <?= $badge($migrated, 'Migrasi v3 ✓', 'Migrasi v3 belum dijalankan') ?>
                <?= $badge($captchaOn, 'Captcha siap ✓', 'Captcha belum diatur') ?>
                <?= $badge($mailReady, 'SMTP siap ✓', 'SMTP belum diatur') ?>
                <span id="msrDirty" class="badge badge-danger px-2 py-1 d-none">Perubahan belum disimpan</span>
            </div>
            <div>
                <label class="mb-0"><small class="text-muted">Tautan formulir publik (OPAC)</small></label>
                <div class="input-group input-group-sm" style="max-width:420px">
                    <input type="text" id="msrOpacUrl" class="form-control" readonly value="<?= msr_e($opacUrl) ?>">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-outline-secondary" id="msrCopyUrl">Salin</button>
                        <a href="<?= msr_e($opacUrl) ?>" target="_blank" class="btn btn-outline-success notAJAX">Buka</a>
                    </div>
                </div>
            </div>
        </div>
        <?php if (!$migrated): ?>
            <div class="alert alert-danger mt-2 mb-0 py-2">
                <strong>Wajib:</strong> jalankan migrasi plugin (Sistem → Plugin → <em>Member Self Registration</em> → Migrasi)
                agar verifikasi email, rate-limit, dan audit log berfungsi.
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
/* ---- Form ---- */
$form = new simbio_form_table_AJAX('mainForm', pluginUrl(reset: true), 'post');
$form->submit_button_attr = 'name="saveData" value="' . __('Save') . '" class="s-btn btn btn-primary"';
$form->table_attr         = 'id="dataList" cellpadding="0" cellspacing="0"';
$form->table_header_attr  = 'class="alterCell"';
$form->table_content_attr = 'class="alterCell2"';

$form->addHidden('action', 'form_config');
$form->addHidden('schema_id', (string)$data->id);

$yesNo = [[0, __('Disable')], [1, __('Enable')]];

/* == Seksi 1: Tampilan Formulir == */
$form->addAnything('<strong class="text-primary">🧭 Tampilan Formulir</strong>',
    '<small class="text-muted">Atur pengalaman pendaftar mengisi formulir di OPAC.</small>');

$form->addSelectList('form_config[wizard]', '<strong>Wizard Bertahap?</strong>', $yesNo,
    (int)($option->wizard ?? 1), 'rows="1" class="form-control col-3"');
$form->addAnything('', '<small class="text-muted">Form dipecah menjadi beberapa langkah dengan progress bar &amp; validasi per langkah. Direkomendasikan untuk formulir panjang.</small>');

$form->addSelectList('form_config[with_agreement]', '<strong>Wajib Setujui Syarat?</strong>',
    [[0, __('No')], [1, __('Yes')]], (int)($option->with_agreement ?? 0), 'rows="1" class="form-control col-3"');
$form->addAnything('', '<small class="text-muted">Tombol "Daftar" terkunci sampai pendaftar mencentang persetujuan. Teks syarat diambil dari deskripsi skema.</small>');

/* == Seksi 2: Foto Profil == */
$form->addAnything('<strong class="text-primary">📷 Foto Profil</strong>', '');

$form->addSelectList('form_config[image]', '<strong>Izinkan Unggah Foto?</strong>', $yesNo,
    (int)($option->image ?? 0), 'rows="1" class="imageWarning form-control col-3"');

$form->addAnything('<strong>Batas Foto (KB)</strong>',
    '<input type="number" name="form_config[max_image_kb]" class="form-control col-3" min="50" max="10240" step="1" required value="' . (int)($option->max_image_kb ?? 2048) . '">' .
    '<small class="text-muted">50–10240 KB. Foto divalidasi sebagai gambar asli &amp; EXIF dibersihkan otomatis (hardening SLiMS 9.8).</small>');

/* == Seksi 3: Keamanan & Anti-Spam == */
$form->addAnything('<strong class="text-primary">🛡️ Keamanan &amp; Anti-Spam</strong>',
    '<small class="text-muted">Honeypot, time-trap, dan CSRF <strong>selalu aktif</strong> tanpa perlu diatur.</small>');

if ($captchaOn) {
    $form->addSelectList('form_config[captcha]', '<strong>Gunakan Captcha?</strong>', $yesNo,
        (int)($option->captcha ?? 0), 'rows="1" class="form-control col-3"');
} else {
    $form->addAnything('<strong>Gunakan Captcha?</strong>', <<<HTML
    <p class="mb-1">Captcha belum diatur. Aktifkan dahulu:</p>
    <ol class="mb-1 pl-3">
        <li>Buka modul <strong>Sistem</strong></li>
        <li>Pilih menu <strong>Captcha Setting / Pengaturan Captcha</strong></li>
        <li>Atur sesuai kebutuhan — panduan: <a href="https://youtu.be/VLkdSRb7hE4" target="_blank" class="notAJAX">video tutorial</a></li>
    </ol>
    HTML);
}

$form->addAnything('<strong>Batas Kirim per IP / 10 menit</strong>',
    '<input type="number" name="form_config[rate_limit]" class="form-control col-3" min="1" max="100" step="1" required value="' . (int)($option->rate_limit ?? 5) . '">' .
    '<small class="text-muted">1–100 percobaan. Berbasis audit log server (bukan sesi), sehingga tidak bisa di-reset oleh bot.</small>');

/* == Seksi 4: Verifikasi & Pesan == */
$form->addAnything('<strong class="text-primary">✉️ Verifikasi &amp; Pesan</strong>', '');

$form->addSelectList('form_config[verify_email]', '<strong>Verifikasi Email Pendaftar?</strong>', $yesNo,
    (int)($option->verify_email ?? 0), 'rows="1" class="verifyMail form-control col-3"');

$mailHint = $mailReady
    ? '<small class="text-success">SMTP terkonfigurasi ✓ — tautan verifikasi berlaku 48 jam. Tombol ACC terkunci sampai email pendaftar diverifikasi.</small>'
    : '<small class="text-danger">SMTP belum diatur! Isi pengaturan mail SLiMS dahulu, atau biarkan opsi ini nonaktif agar proses ACC tidak terkunci.</small>';
$form->addAnything('', '<div id="msrMailHint">' . $mailHint . '</div>');

$form->addTextField('textarea', 'form_config[message_after_save]', '<strong>Pesan Setelah Registrasi</strong>',
    (string)($option->message_after_save ?? ''), 'rows="3" style="height:90px" class="form-control" maxlength="500"');
$form->addAnything('', '<small class="text-muted"><span id="msrMsgCount">0</span>/500 karakter. Ditampilkan setelah pendaftaran berhasil.</small>');

echo $form->printOut();
?>
<script>
$(document).ready(function() {
    const mailReady = <?= $mailReady ? 'true' : 'false' ?>;
    const $kb  = $('input[name="form_config[max_image_kb]"]');
    const $msg = $('textarea[name="form_config[message_after_save]"]');

    // Batas foto hanya relevan saat unggah foto aktif (readonly, bukan disabled, agar nilai tetap tersimpan)
    function msrToggleKb() {
        const on = $('.imageWarning').val() == 1;
        $kb.prop('readonly', !on).toggleClass('text-muted', !on);
    }
    $('.imageWarning').change(function() {
        if ($(this).val() == 1) {
            const ask = confirm('Izinkan pendaftar mengunggah foto?\n\nv3 sudah memvalidasi file sebagai gambar asli, membatasi ukuran, dan membersihkan EXIF.\nTetap pastikan folder images terlindungi. Lanjutkan?');
            if (!ask) $(this).val(0);
        }
        msrToggleKb();
    });
    msrToggleKb();

    // Verifikasi email tanpa SMTP = pendaftar terkunci
    $('.verifyMail').change(function() {
        if ($(this).val() == 1 && !mailReady) {
            const ask = confirm('SMTP/email SLiMS belum terkonfigurasi.\nTanpa SMTP, tautan verifikasi tidak terkirim dan proses ACC akan terkunci.\nTetap aktifkan?');
            if (!ask) $(this).val(0);
        }
    });

    // Clamp angka sesuai min/max
    $('input[type="number"]').on('change', function() {
        let v = parseInt(this.value || '0', 10);
        const min = parseInt(this.min || '0', 10), max = parseInt(this.max || '99999', 10);
        if (isNaN(v) || v < min) v = min;
        if (v > max) v = max;
        this.value = v;
    });

    // Counter karakter pesan
    function msrCount() { $('#msrMsgCount').text(($msg.val() || '').length); }
    $msg.on('input', msrCount); msrCount();

    // Indikator perubahan belum disimpan
    $('#mainForm').on('change input', 'input,select,textarea', function() {
        $('#msrDirty').removeClass('d-none');
    });
    $('#mainForm').submit(function() {
        $('#msrDirty').addClass('d-none');
        if (window.top.toastr) top.toastr.info('Menyimpan pengaturan…', 'Info');
    });

    // Salin tautan OPAC
    $('#msrCopyUrl').click(function() {
        const btn = $(this), el = document.getElementById('msrOpacUrl');
        const done = () => { btn.text('Tersalin ✓'); setTimeout(() => btn.text('Salin'), 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(el.value).then(done).catch(() => {
                el.select(); document.execCommand('copy'); done();
            });
        } else {
            el.select(); document.execCommand('copy'); done();
        }
    });
});
</script>