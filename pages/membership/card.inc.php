<?php defined('INDEX_AUTH') or die('Direct access is not allowed!'); ?>
<div class="card mx-3 my-3"><div class="card-body">
    <h5 class="font-weight-bold">🪪 Cetak Kartu Anggota (PDF)</h5>
    <p class="text-muted mb-2">Ukuran standar ID-1 (86×54 mm), satu kartu per halaman, foto + masa berlaku.
        QR otomatis bila library QR tersedia; jika tidak, barcode Code128 (dibaca scanner sirkulasi).</p>
    <form method="post" action="<?= pluginUrl(['action' => 'card_pdf']) ?>" target="_blank">
        <?= msr_csrf_field() ?>
        <div class="form-group">
            <label><strong>ID Anggota</strong> <small class="text-muted">(satu, atau banyak dipisah koma / baris baru — maks 50)</small></label>
            <textarea name="member_ids" class="form-control col-6" rows="4" required placeholder="M260001&#10;M260002"></textarea>
        </div>
        <button class="btn btn-primary" type="submit">🖨️ Buat PDF</button>
    </form>
</div></div>