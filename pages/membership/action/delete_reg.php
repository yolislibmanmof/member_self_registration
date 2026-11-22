<?php
defined('INDEX_AUTH') or die('Direct access is not allowed!');

if (!utility::havePrivilege('membership', 'w')) { toastr('Hak tulis diperlukan.')->error(); exit; }

$schema = $activeSchema->fetchObject();
if ($schema) {
    msr_reject_pending((string)($_GET['member_id'] ?? ''), $schema, 'Dihapus oleh petugas melalui pratinjau.');
}
echo '<script>top.jQuery.colorbox.close();</script>';
redirect()->simbioAJAX(pluginUrl(reset: true));
exit;