<?php
defined('INDEX_AUTH') or die('Direct access is not allowed!');
header('Content-Type: application/json');

if (!utility::havePrivilege('membership', 'w')) { http_response_code(403); echo json_encode(['ok' => false, 'msg' => 'Hak tulis diperlukan.']); exit; }
if (!msr_csrf_verify()) { echo json_encode(['ok' => false, 'msg' => 'Token CSRF tidak valid. Muat ulang halaman.']); exit; }

$schema = $activeSchema->fetchObject();
if (!$schema) { echo json_encode(['ok' => false, 'msg' => 'Skema aktif tidak ada.']); exit; }

$ids = $_POST['member_ids'] ?? [];
if (!is_array($ids)) $ids = [];
$ids = array_map(fn($v) => trim((string)$v), $ids);
$ids = array_values(array_unique(array_filter($ids, fn($v) => preg_match('/^[A-Za-z0-9._-]{1,20}$/', $v))));
$ids = array_slice($ids, 0, 50);
if (!$ids) { echo json_encode(['ok' => false, 'msg' => 'Tidak ada ID valid.']); exit; }

$ok = $skip = $fail = 0; $details = [];
foreach ($ids as $id) {
    $r = msr_approve_pending($id, $schema);
    if ($r['code'] === 'approved') $ok++;
    elseif ($r['code'] === 'skipped') { $skip++; $details[] = $id . ': ' . $r['msg']; }
    else { $fail++; $details[] = $id . ': ' . $r['msg']; }
}
echo json_encode(['ok' => true, 'success' => $ok, 'skipped' => $skip, 'failed' => $fail, 'details' => $details]);
exit;