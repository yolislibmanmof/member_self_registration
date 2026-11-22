<?php
use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Table\Schema;
use SLiMS\Filesystems\Storage;
use SLiMS\Captcha\Factory as Captcha;

defined('INDEX_AUTH') or die('Direct access is not allowed!');
global $sysconf;
require_once MSLR . DS . 'msr_tools.php';

if (!isset($_POST['form'])) return;

try {
    $structure = json_decode($schema->structure, true) ?: [];
    $option    = msr_option($schema);
    $db        = DB::getInstance();
    $autoId    = (bool)($option->auto_id ?? false);

    /* --- Keamanan --- */
    if (!msr_csrf_verify()) throw new Exception('Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.');
    if (!empty($_POST['msr_hp'])) throw new Exception('Aktivitas terdeteksi sebagai bot.');
    $ts = (int)($_POST['msr_ts'] ?? 0);
    if ($ts < 1 || (time() - $ts) < 3) throw new Exception('Formulir dikirim terlalu cepat. Coba lagi.');
    if (!msr_rate_limit('submit', (int)($option->rate_limit ?? 5), 600)) throw new Exception('Terlalu banyak percobaan dari IP Anda. Coba lagi dalam 10 menit.');

    if (($option->captcha ?? false) && class_exists(Captcha::class) && config('captcha', false)) {
        $captcha = Captcha::section('memberarea');
        if ($captcha->isSectionActive() && !$captcha->isValid()) throw new Exception('Kode captcha salah. Silakan ulangi.');
    }

    /* --- Validasi server-side --- */
    $imageFieldId = null;
    foreach ($structure as $k => $c) if ($c['field'] === 'member_image') $imageFieldId = $k;

    $sqlSet = $sqlParams = [];
    $memberId = $memberName = $memberEmail = '';

    foreach ($structure as $order => $detail) {
        $field   = $detail['field'];
        $advType = $detail['advfieldtype'] ?? '';
        if ($field === 'member_image') continue;
        if ($field === 'member_id' && $autoId) continue; // ID dibuat sistem

        if (!array_key_exists($order, $_POST['form'])) {
            if (!empty($detail['is_required'])) throw new Exception('Ruas "' . $detail['name'] . '" wajib diisi.');
            continue;
        }
        $value = $_POST['form'][$order];

        if ($field === 'advance') {
            $field = in_array($advType, ['enum','enum_radio','text_multiple']) ? trim(explode(',', $detail['advfield'])[0]) : trim($detail['advfield']);
        }
        if ($field === '') continue;

        if (is_array($value)) {
            $value = array_map(fn($v) => strip_tags(trim((string)$v)), $value);
            if ($advType === 'text_multiple') {
                $allowed = array_map('trim', explode('|', trim(explode(',', $detail['advfield'])[1] ?? '')));
                $value = array_values(array_intersect($value, $allowed));
            }
            $value = json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
        } else {
            $value = strip_tags(trim((string)$value));
        }

        if (!empty($detail['is_required']) && $value === '' && $field !== 'mpasswd') throw new Exception('Ruas "' . $detail['name'] . '" wajib diisi.');
        if ($value === '' && $field !== 'mpasswd') continue;

        if ($field === 'member_email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new Exception('Format email tidak valid.');
        if ($field === 'birth_date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new Exception('Format tanggal lahir tidak valid.');
        if ($field === 'gender' && !in_array($value, ['0','1'], true)) throw new Exception('Pilih jenis kelamin.');
        if ($field === 'member_type_id' && !ctype_digit($value)) throw new Exception('Tipe keanggotaan tidak valid.');
        if ($detail['field'] === 'advance' && $advType === 'int' && !ctype_digit($value)) throw new Exception('Ruas "' . $detail['name'] . '" harus angka.');
        if ($detail['field'] === 'advance' && in_array($advType, ['enum','enum_radio'], true)) {
            $allowed = array_map('trim', explode('|', trim(explode(',', $detail['advfield'])[1] ?? '')));
            if (!in_array($value, $allowed, true)) throw new Exception('Pilihan ruas "' . $detail['name'] . '" tidak valid.');
        }
        if ($field === 'member_id') {
            if (!preg_match('/^[A-Za-z0-9._-]{3,20}$/', $value)) throw new Exception('ID anggota 3-20 karakter, hanya huruf/angka/._-');
            $memberId = $value;
        }
        if ($field === 'member_name')  $memberName  = $value;
        if ($field === 'member_email') $memberEmail = $value;
        if ($field === 'mpasswd') {
            if ($value !== (string)($_POST['confirm_password'] ?? '')) throw new Exception('Konfirmasi kata sandi tidak cocok.');
            if ($err = msr_password_error($value)) throw new Exception($err);
            $value = password_hash($value, PASSWORD_BCRYPT);
        }

        $sqlSet[] = '`' . $field . '` = ?';
        $sqlParams[] = $value;
    }

    /* --- Anti duplikat (mode manual) --- */
    if (!$autoId && $memberId !== '') {
        $cek = $db->prepare('select member_id from member where member_id = ?');
        $cek->execute([$memberId]);
        if ($cek->rowCount()) throw new Exception('ID anggota sudah terdaftar. Gunakan ID lain atau hubungi pustakawan.');
        $cek = $db->prepare('select member_id from `' . $table . '` where member_id = ?');
        $cek->execute([$memberId]);
        if ($cek->rowCount()) throw new Exception('ID anggota sudah ada dalam antrean pendaftaran.');
    }
    if ($memberEmail !== '') {
        $cek = $db->prepare('select member_id from member where member_email = ?');
        $cek->execute([$memberEmail]);
        if ($cek->rowCount()) throw new Exception('Email sudah digunakan anggota lain.');
    }

    /* --- Foto profil --- */
    if (($option->image ?? false) && $imageFieldId !== null) {
        $file = $_FILES['member_image'] ?? null;
        $hasFile = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $file['size'] > 0;
        if (!$hasFile) {
            if (!empty($structure[$imageFieldId]['is_required'])) throw new Exception('Foto profil wajib diunggah.');
        } else {
            $maxKb = (int)($option->max_image_kb ?? 2048);
            if ($file['size'] > $maxKb * 1024) throw new Exception('Ukuran foto melebihi ' . $maxKb . ' KB.');
            $newFilename = 'msr_' . bin2hex(random_bytes(8));
            $upload = Storage::images()->upload('member_image', function($img) use($sysconf, $maxKb) {
                $img->isExtensionAllowed($sysconf['allowed_images']);
                $img->isLimitExceeded($maxKb * 1024);
                if (method_exists($img, 'isImageFile')) $img->isImageFile();
                if (!empty($img->getError())) $img->destroyIfFailed();
                if (empty($img->getError()) && method_exists($img, 'cleanExifInfo')) $img->cleanExifInfo();
            })->as('persons' . DS . $newFilename);
            if (!$upload->getUploadStatus()) throw new Exception('Gagal mengunggah foto: ' . $upload->getError());
            $sqlSet[] = '`member_image` = ?';
            $sqlParams[] = $upload->getUploadedFileName();
        }
    }

    $sqlSet[] = '`ip_address` = ?'; $sqlParams[] = msr_client_ip();
    $sqlSet[] = '`created_at` = now()';

    /* --- ID otomatis + lock anti-balapan --- */
    $locked = false;
    if ($autoId) {
        $locked = (bool)$db->query("select get_lock('msr_auto_id', 5)")->fetchColumn();
        $memberId = msr_generate_member_id($option, $table);
        $sqlSet[] = '`member_id` = ?'; $sqlParams[] = $memberId;
    }

    $query = 'insert into `' . $table . '` set ' . implode(',', $sqlSet);
    Plugins::getInstance()->execute('member_self_before_save', ['query' => $query, 'sqlParams' => $sqlParams]);

    if (!defined('MSRPLUS_BYPASS_INSERT')) {
        $insert = $db->prepare($query);
        $insert->execute($sqlParams);
        if ($locked) $db->query("select release_lock('msr_auto_id')");
        if ($insert->rowCount() < 1) throw new Exception('Data gagal disimpan. Silakan coba lagi.');
    } elseif ($locked) {
        $db->query("select release_lock('msr_auto_id')");
    }
    msr_log('submit', $memberId, 'Pendaftaran baru via OPAC' . ($autoId ? ' (ID otomatis)' : ''));

    /* --- Verifikasi email --- */
    $msg = ($option->message_after_save ?? '') ?: 'Pendaftaran berhasil! Data Anda menunggu persetujuan pustakawan.';
    if ($autoId) $msg .= ' ID anggota Anda: ' . $memberId . '.';
    if (($option->verify_email ?? false) && $memberEmail !== '' && Schema::hasTable('self_registration_tokens')) {
        $token = bin2hex(random_bytes(24));
        $db->prepare('insert into self_registration_tokens (member_id, schema_table, token, expired_at, created_at) values (?,?,?,?,now())')
           ->execute([$memberId, $table, $token, date('Y-m-d H:i:s', time() + 48 * 3600)]);
        $link = $selfUrl . '&msr_verify=' . $token;
        $html = '<p>Halo <b>' . msr_e($memberName) . '</b>,</p>'
              . '<p>Terima kasih telah mendaftar di ' . msr_e($sysconf['library_name'] ?? 'perpustakaan') . '.</p>'
              . '<p>ID pendaftaran Anda: <b>' . msr_e($memberId) . '</b></p>'
              . '<p>Klik tautan berikut untuk memverifikasi email (berlaku 48 jam):</p>'
              . '<p><a href="' . msr_e($link) . '">' . msr_e($link) . '</a></p>';
        $msg .= msr_mail($memberEmail, $memberName, 'Verifikasi Pendaftaran Anggota', $html)
              ? ' Tautan verifikasi telah dikirim ke email Anda.'
              : ' (Pengiriman email verifikasi gagal — hubungi pustakawan.)';
    }

    redirect()->withMessage('self_regis_success', $msg)->back();
} catch (PDOException $e) {
    msr_log('submit_failed', null, $e->getMessage());
    redirect()->withMessage('self_regis_error', 'Data gagal disimpan (kemungkinan duplikat). Periksa kembali isian Anda.')->back();
} catch (Throwable $e) {
    msr_log('submit_failed', null, $e->getMessage());
    redirect()->withMessage('self_regis_error', $e->getMessage())->back();
}
exit;