<?php
/**
 * MSR v3.1 — Mesin bersama: setujui, tolak, cek status publik.
 * Dimuat oleh pages/membership/index.php & pages/opac/index.php
 */
use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Table\Schema;

if (!function_exists('msr_approve_pending')) {
    /** @return array{code:string,msg:string} code: approved|skipped|failed */
    function msr_approve_pending(string $memberId, stdClass $schema, array $overrides = []): array
    {
        $db     = DB::getInstance();
        $table  = msr_schema_table($schema->name);
        $option = msr_option($schema);

        try {
            Plugins::getInstance()->execute('member_self_before_acc', ['member_id' => $memberId, 'activeSchema' => $schema]);

            $stmt = $db->prepare('select * from `' . $table . '` where member_id = ?');
            $stmt->execute([$memberId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$result) return ['code' => 'skipped', 'msg' => 'Data tidak ditemukan di antrean.'];

            if (($option->verify_email ?? false) && empty($result['email_verified_at'])) {
                return ['code' => 'skipped', 'msg' => 'Email belum diverifikasi.'];
            }

            $memberColumns = Schema::table('member')->columns();
            $customColumns = Schema::hasTable('member_custom') ? Schema::table('member_custom')->columns() : [];

            // Koreksi dari form pratinjau (opsional)
            foreach (array_keys($result) as $col) {
                if (in_array($col, ['created_at','updated_at','ip_address','email_verified_at'], true)) continue;
                if (!isset($overrides[$col])) continue;
                $new = $overrides[$col];
                $new = is_array($new) ? json_encode(array_map('strip_tags', $new), JSON_UNESCAPED_UNICODE) : strip_tags(trim((string)$new));
                if ($col === 'mpasswd') {
                    if ($new === '') continue; // kosong = pakai hash lama
                    if ($err = msr_password_error($new)) return ['code' => 'failed', 'msg' => $err];
                    $new = password_hash($new, PASSWORD_BCRYPT);
                }
                if ($new === '') continue;
                $result[$col] = $new;
            }

            $cek = $db->prepare('select member_id from member where member_id = ?');
            $cek->execute([$result['member_id']]);
            if ($cek->rowCount()) return ['code' => 'skipped', 'msg' => 'ID sudah ada di data anggota.'];

            $result['input_date']        = substr((string)($result['created_at'] ?? date('Y-m-d')), 0, 10);
            $result['register_date']     = date('Y-m-d');
            $result['member_since_date'] = date('Y-m-d');
            $result['last_update']       = date('Y-m-d');
            $result['expire_date']       = date('Y-m-d', strtotime('+1 year'));
            $result['is_pending']        = 0;

            if (!empty($result['member_type_id'])) {
                $mt = $db->prepare('select member_periode from mst_member_type where member_type_id = ?');
                $mt->execute([$result['member_type_id']]);
                if ($row = $mt->fetchObject()) $result['expire_date'] = date('Y-m-d', strtotime('+' . (int)$row->member_periode . ' days'));
            }

            $custom = [];
            foreach ($result as $col => $val) {
                if (strpos($col, 'adv_') === 0) {
                    if (in_array($col, $customColumns, true)) $custom[$col] = $val;
                    unset($result[$col]);
                    continue;
                }
                if (!in_array($col, $memberColumns, true)) unset($result[$col]);
            }

            $db->beginTransaction();
            $set = implode(',', array_map(fn($c) => '`' . $c . '` = ?', array_keys($result)));
            $db->prepare('insert into `member` set ' . $set)->execute(array_values($result));

            if ($custom) {
                $cekC = $db->prepare('select member_id from member_custom where member_id = ?');
                $cekC->execute([$result['member_id']]);
                $setC = implode(',', array_map(fn($c) => '`' . $c . '` = ?', array_keys($custom)));
                if ($cekC->rowCount()) {
                    $db->prepare('update `member_custom` set ' . $setC . ' where member_id = ?')->execute([...array_values($custom), $result['member_id']]);
                } else {
                    $custom['member_id'] = $result['member_id'];
                    $setC = implode(',', array_map(fn($c) => '`' . $c . '` = ?', array_keys($custom)));
                    $db->prepare('insert into `member_custom` set ' . $setC)->execute(array_values($custom));
                }
            }

            $db->prepare('delete from `' . $table . '` where member_id = ?')->execute([$memberId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            msr_log('approve_failed', $memberId, $e->getMessage());
            return ['code' => 'failed', 'msg' => 'Gagal menyimpan: ' . $e->getMessage()];
        }

        msr_log('approve', $result['member_id'], 'Disetujui oleh ' . ($_SESSION['realname'] ?? 'sistem'));
        Plugins::getInstance()->execute('member_self_after_acc', ['member' => $result]);

        if (!empty($result['member_email'])) {
            $html = '<p>Halo <b>' . msr_e($result['member_name']) . '</b>,</p>'
                  . '<p>Pendaftaran Anda telah <b>disetujui</b>. Selamat bergabung!</p>'
                  . '<ul><li>ID Anggota: <b>' . msr_e($result['member_id']) . '</b></li>'
                  . '<li>Berlaku hingga: <b>' . msr_e($result['expire_date']) . '</b></li></ul>'
                  . '<p>Login area anggota: <a href="' . msr_e(SWB . 'index.php?p=member') . '">' . msr_e(SWB . 'index.php?p=member') . '</a></p>';
            msr_mail($result['member_email'], $result['member_name'], 'Pendaftaran Anggota Disetujui', $html);
        }

        return ['code' => 'approved', 'msg' => 'Anggota baru: ' . $result['member_name']];
    }
}

if (!function_exists('msr_reject_pending')) {
    function msr_reject_pending(string $memberId, stdClass $schema, string $reason = ''): array
    {
        $db    = DB::getInstance();
        $table = msr_schema_table($schema->name);

        $stmt = $db->prepare('select * from `' . $table . '` where member_id = ?');
        $stmt->execute([$memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['code' => 'skipped', 'msg' => 'Data tidak ditemukan.'];

        $reason = trim(strip_tags($reason)) ?: 'Tidak memenuhi syarat keanggotaan.';

        Plugins::getInstance()->execute('member_self_before_reject', ['baseTable' => $table, 'member_id' => $memberId, 'row' => $row]);

        // Detail JSON dipakai fitur cek status publik (pencocokan email)
        msr_log('reject', $memberId, json_encode([
            'name'   => $row['member_name'] ?? '',
            'email'  => $row['member_email'] ?? '',
            'reason' => $reason,
            'by'     => $_SESSION['realname'] ?? 'petugas',
        ], JSON_UNESCAPED_UNICODE));

        if (!empty($row['member_email'])) {
            $html = '<p>Halo <b>' . msr_e($row['member_name'] ?? '') . '</b>,</p>'
                  . '<p>Mohon maaf, pendaftaran Anda dengan ID <b>' . msr_e($memberId) . '</b> belum dapat disetujui.</p>'
                  . '<p>Alasan: ' . msr_e($reason) . '</p>'
                  . '<p>Silakan hubungi pustakawan untuk informasi lebih lanjut.</p>';
            msr_mail($row['member_email'], $row['member_name'] ?? 'Calon Anggota', 'Informasi Pendaftaran Anggota', $html);
        }

        $db->prepare('delete from `' . $table . '` where member_id = ?')->execute([$memberId]);
        Plugins::getInstance()->execute('member_self_after_reject', ['member_id' => $memberId, 'reason' => $reason]);

        return ['code' => 'rejected', 'msg' => 'Ditolak: ' . $memberId];
    }
}

if (!function_exists('msr_status_contact')) {
    function msr_status_contact(): array {
        return ['code' => 'contact', 'title' => 'Hubungi Pustakawan',
                'detail' => 'Status tidak dapat ditampilkan online. Silakan hubungi pustakawan dengan menyebutkan ID pendaftaran Anda.'];
    }
}

if (!function_exists('msr_status_lookup')) {
    /** Cek status: antrean → anggota → log penolakan. Privasi: wajib cocok ID + email. */
    function msr_status_lookup(string $memberId, string $email): array
    {
        $schema = msr_active_schema();
        if (!$schema) return ['code' => 'closed', 'title' => 'Pendaftaran Ditutup', 'detail' => 'Skema pendaftaran belum aktif.'];

        $db    = DB::getInstance();
        $table = msr_schema_table($schema->name);
        $match = fn($v) => is_string($v) && $v !== '' && strcasecmp(trim($v), $email) === 0;

        // 1) Masih di antrean
        if (Schema::hasTable($table)) {
            $q = $db->prepare('select * from `' . $table . '` where member_id = ?');
            $q->execute([$memberId]);
            if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                if (!$match($row['member_email'] ?? '')) return msr_status_contact();
                $verified = !empty($row['email_verified_at']);
                return ['code' => 'pending', 'title' => 'Menunggu Persetujuan',
                        'name' => $row['member_name'] ?? '', 'member_id' => $memberId,
                        'detail' => $verified
                            ? 'Email terverifikasi. Data Anda sedang ditinjau pustakawan.'
                            : 'Email belum diverifikasi. Silakan klik tautan verifikasi yang dikirim ke email Anda.',
                        'date' => $row['created_at'] ?? ''];
            }
        }

        // 2) Sudah menjadi anggota
        $q = $db->prepare('select member_id,member_name,member_email,register_date,expire_date,is_pending from member where member_id = ?');
        $q->execute([$memberId]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            if (!$match($row['member_email'] ?? '')) return msr_status_contact();
            if (!empty($row['is_pending'])) {
                return ['code' => 'hold', 'title' => 'Disetujui — Aktivasi Tertunda', 'name' => $row['member_name'], 'member_id' => $memberId,
                        'detail' => 'Akun Anda disetujui namun masih berstatus pending. Hubungi pustakawan untuk aktivasi.'];
            }
            $expired = strtotime((string)$row['expire_date']) < strtotime(date('Y-m-d'));
            return ['code' => $expired ? 'expired' : 'active',
                    'title' => $expired ? 'Keanggotaan Kedaluwarsa' : 'Anggota Aktif',
                    'name' => $row['member_name'], 'member_id' => $memberId,
                    'detail' => $expired ? 'Masa berlaku habis. Hubungi pustakawan untuk perpanjangan.' : 'Selamat! Keanggotaan Anda aktif.',
                    'expire' => $row['expire_date']];
        }

        // 3) Pernah ditolak (dicocokkan via email pada detail log)
        if (Schema::hasTable('self_registration_logs')) {
            $q = $db->prepare("select detail,created_at from self_registration_logs where member_id = ? and event = 'reject' order by id desc limit 1");
            $q->execute([$memberId]);
            if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $d = json_decode($row['detail'] ?? '', true) ?: [];
                if ($match($d['email'] ?? '')) {
                    return ['code' => 'rejected', 'title' => 'Pendaftaran Ditolak', 'name' => $d['name'] ?? '', 'member_id' => $memberId,
                            'detail' => 'Alasan: ' . ($d['reason'] ?? '-'), 'date' => $row['created_at']];
                }
            }
        }

        return ['code' => 'not_found', 'title' => 'Data Tidak Ditemukan',
                'detail' => 'Periksa kembali ID anggota dan email yang Anda masukkan, atau hubungi pustakawan.'];
    }
}