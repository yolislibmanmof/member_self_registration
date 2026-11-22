<?php
defined('INDEX_AUTH') or die('Direct access is not allowed!');

if (!utility::havePrivilege('membership', 'w')) { toastr('Anda tidak memiliki hak untuk menyetujui pendaftaran')->error(); exit; }

$member_id = trim((string)($_POST['form']['member_id'] ?? ''));
$schema = $activeSchema->fetchObject();
if (!$schema || $member_id === '') { redirect()->back(); exit; }

$r = msr_approve_pending($member_id, $schema, $_POST['form'] ?? []);

if ($r['code'] === 'approved') {
    toastr('Pendaftaran disetujui. ' . $r['msg'])->success();
    echo '<script>top.jQuery.colorbox.close();</script>';
    redirect()->simbioAJAX(pluginUrl(reset: true));
} else {
    toastr($r['msg'])->error(); // tetap di popup agar petugas bisa mengoreksi
}
exit;