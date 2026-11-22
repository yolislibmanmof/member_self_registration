<?php
use SLiMS\DB;

defined('INDEX_AUTH') or die('Direct access is not allowed!');
if (!utility::havePrivilege('membership', 'r')) die('Hak baca diperlukan.');
if (!msr_csrf_verify()) { toastr('Token CSRF tidak valid. Muat ulang halaman.')->error(); exit; }
require_once MSLR . DS . 'msr_tools.php';

$ids = preg_split('/[\s,;]+/', (string)($_POST['member_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$ids = array_slice(array_values(array_unique(array_filter($ids, fn($v) => preg_match('/^[A-Za-z0-9._-]{1,20}$/', $v)))), 0, 50);
if (!$ids) { toastr('Masukkan minimal satu ID anggota valid.')->error(); exit; }

$db = DB::getInstance();
$in = implode(',', array_fill(0, count($ids), '?'));
$q = $db->prepare("select m.member_id, m.member_name, m.member_image, m.expire_date, mt.member_type_name
                   from member m left join mst_member_type mt on mt.member_type_id = m.member_type_id
                   where m.member_id in ($in)");
$q->execute($ids);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { toastr('Anggota tidak ditemukan.')->error(); exit; }

$mpdf = msr_mpdf(['format' => [86, 54], 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0, 'margin_header' => 0, 'margin_footer' => 0]);
if (!$mpdf) { toastr('mPDF tidak tersedia di instalasi ini.')->error(); exit; }
$mpdf->SetTitle('Kartu Anggota');

$tmp = [];
foreach ($rows as $i => $m) {
    if ($i > 0) $mpdf->AddPage();
    $qr = msr_qr_file($m['member_id']);
    if ($qr) $tmp[] = $qr;
    $mpdf->WriteHTML(msr_card_html($m, $qr));
}
msr_log('card_print', null, count($rows) . ' kartu dicetak');
$mpdf->Output('kartu-anggota.pdf', 'I');
foreach ($tmp as $f) @unlink($f);
exit;