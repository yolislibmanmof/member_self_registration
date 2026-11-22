<?php
use SLiMS\DB;
use SLiMS\Url;
use SLiMS\Table\Schema;
use SLiMS\Captcha\Factory as Captcha;
use SLiMS\Filesystems\Storage;

/* ================= Utilitas v3 ================= */

if (!function_exists('msr_e')) {
    function msr_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('msr_schema_table')) {
    function msr_schema_table(string $name): string {
        return 'self_registration_' . trim(strtolower(str_replace(' ', '_', $name)));
    }
}

if (!function_exists('msr_active_schema')) {
    function msr_active_schema(bool $fresh = false): ?stdClass {
        static $cache = null;
        if ($fresh) $cache = null;
        if ($cache !== null) return $cache;
        if (!Schema::hasTable('self_registration_schemas')) return null;
        $q = DB::getInstance()->query('select * from self_registration_schemas where status = 1');
        return $cache = ($q->rowCount() ? $q->fetchObject() : null);
    }
}

if (!function_exists('msr_option')) {
    function msr_option($schema): stdClass {
        return json_decode($schema->option ?? '') ?: new stdClass;
    }
}

if (!function_exists('msr_client_ip')) {
    function msr_client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }
}

if (!function_exists('msr_log')) {
    function msr_log(string $event, ?string $memberId = null, string $detail = ''): void {
        try {
            if (!Schema::hasTable('self_registration_logs')) return;
            DB::getInstance()->prepare('insert into self_registration_logs (member_id, event, ip_address, detail, created_at) values (?,?,?,?,now())')
                ->execute([$memberId, $event, msr_client_ip(), $detail]);
        } catch (Throwable $e) { /* log tidak boleh menggagalkan alur */ }
    }
}

if (!function_exists('msr_rate_limit')) {
    function msr_rate_limit(string $event = 'submit', int $max = 5, int $window = 600): bool {
        if (!Schema::hasTable('self_registration_logs')) return true;
        $q = DB::getInstance()->prepare('select count(*) from self_registration_logs where ip_address = ? and event = ? and created_at >= ?');
        $q->execute([msr_client_ip(), $event, date('Y-m-d H:i:s', time() - $window)]);
        return (int)$q->fetchColumn() < $max;
    }
}

if (!function_exists('msr_csrf_token')) {
    function msr_csrf_token(): string {
        return $_SESSION['msr_csrf_token'] ??= bin2hex(random_bytes(24));
    }
}

if (!function_exists('msr_csrf_field')) {
    function msr_csrf_field(): string {
        return '<input type="hidden" name="msr_csrf" value="' . msr_e(msr_csrf_token()) . '"/>';
    }
}

if (!function_exists('msr_csrf_verify')) {
    function msr_csrf_verify(): bool {
        return hash_equals($_SESSION['msr_csrf_token'] ?? '', (string)($_POST['msr_csrf'] ?? ''));
    }
}

if (!function_exists('msr_password_error')) {
    function msr_password_error(string $p): ?string {
        global $sysconf;
        $min = (int)($sysconf['password_policy_min_length'] ?? 8);
        if (!empty($sysconf['password_policy_strong'])) {
            if (!class_exists('simbio_security')) {
                $f = SIMBIO . 'simbio_UTILS/simbio_security.inc.php';
                if (file_exists($f)) require_once $f;
            }
            if (class_exists('simbio_security') && method_exists('simbio_security', 'validatePassword')) {
                return simbio_security::validatePassword($p, $min) ? null
                    : 'Kata sandi minimal ' . $min . ' karakter, mengandung huruf besar, angka, dan karakter khusus.';
            }
        }
        return strlen($p) >= $min ? null : 'Kata sandi minimal ' . $min . ' karakter.';
    }
}

if (!function_exists('msr_mail')) {
    function msr_mail(string $to, string $toName, string $subject, string $html): bool {
        try {
            if (!class_exists(\SLiMS\Mail::class) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
            $mail = \SLiMS\Mail::to($to, $toName ?: 'Member')->subject($subject);
            $mail->isHTML(true);
            $mail->msgHTML($html);
            $mail->AltBody = strip_tags($html);
            $sent = $mail->send();
            $mail->clearAllRecipients();
            return (bool)$sent;
        } catch (Throwable $e) {
            msr_log('mail_error', null, $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('msr_type_map')) {
    // Peta resmi SLiMS\Table\Grammar\Mysql v9.8 — pengganti refleksi properti privat
    function msr_type_map(): array {
        return [
            'string' => 'varchar', 'fixstring' => 'char', 'text' => 'text',
            'json' => 'json', 'longtext' => 'longtext', 'number' => 'int',
            'smallnumber' => 'smallint', 'tinynumber' => 'tinyint', 'bignumber' => 'bigint',
            'decimal' => 'decimal', 'float' => 'float', 'double' => 'double',
            'date' => 'date', 'datetime' => 'datetime',
        ];
    }
}

/* ================= Fungsi lama (BC) ================= */

if (!function_exists('getActiveSchemaData')) {
    function getActiveSchemaData() { return msr_active_schema(true); }
}

if (!function_exists('action')) {
    function action(string $actionName, array $attribute = []) {
        extract($attribute);
        $trace = debug_backtrace(limit: 1);
        $info = pathinfo(array_pop($trace)['file']);
        if (file_exists($path = $info['dirname'] . DS . 'action' . DS . basename($actionName) . '.php')) {
            include $path;
        } else {
            throw new Exception('Action ' . $actionName . ' is not found!', 404);
        }
    }
}

if (!function_exists('pluginUrl')) {
    function pluginUrl(array $data = [], bool $reset = false): string {
        if ($reset) return Url::getSelf(fn($self) => $self . '?mod=' . $_GET['mod'] . '&id=' . $_GET['id']);
        return Url::getSelf(fn($self) => $self . '?' . http_build_query(array_merge($_GET, $data)));
    }
}

if (!function_exists('textColor')) {
    function textColor($hexCode) {
        $r = hexdec(substr($hexCode, 0, 2)) / 255;
        $g = hexdec(substr($hexCode, 2, 2)) / 255;
        $b = hexdec(substr($hexCode, 4, 2)) / 255;
        return (($r * 299) + ($g * 587) + ($b * 114)) / 1000 > .6 ? '000000' : 'ffffff';
    }
}

/* ================= Form Generator v3 ================= */

if (!function_exists('formGenerator')) {
    function formGenerator($data, $record = [], $actionUrl = '', $opac = null)
    {
        $structure = json_decode($data->structure, true) ?: [];
        $option    = msr_option($data);
        $info      = json_decode($data->info ?? '') ?: (object)['title' => 'Pendaftaran Anggota', 'desc' => '', 'position' => 'top'];

        $isAdmin  = $actionUrl !== '' && strpos($actionUrl, 'admin') !== false;
        $isPublic = $actionUrl !== '' && !$isAdmin;
        $wizard   = $isPublic && (bool)($option->wizard ?? true);
        $maxKb    = (int)($option->max_image_kb ?? 2048);

        ob_start();

        if ($key = flash()->includes('self_regis_success')) echo '<div class="alert alert-success my-2">' . msr_e($key) . '</div>';
        if ($key = flash()->includes('self_regis_error'))   echo '<div class="alert alert-danger my-2">' . msr_e($key) . '</div>';

        $withUpload = ($option->image ?? false) ? ' enctype="multipart/form-data"' : '';
        echo '<form id="self_member" method="POST" action="' . msr_e($actionUrl) . '"' . $withUpload . '>';

        if ($actionUrl === '') {
            echo '<h3>Pratinjau</h3><h5>Skema ' . msr_e($data->name) . '</h5>';
        } elseif ($isAdmin) {
            echo '<h3>Pratinjau Data</h3><h5>Calon anggota ' . msr_e($record['member_name'] ?? '') . '</h5>';
            if (array_key_exists('email_verified_at', $record)) {
                echo empty($record['email_verified_at'])
                    ? '<span class="badge badge-warning">Email belum diverifikasi</span>'
                    : '<span class="badge badge-success">Email terverifikasi ✓</span>';
            }
        } else {
            if ($opac !== null) $opac->page_title = $info->title;
            $descInfo = '<div class="alert alert-info p-3">' . strip_tags($info->desc ?? '', '<p><a><i><em><strong><h1><h2><h3><ul><ol><li><br>') . '</div>';
        }
        if (($info->position ?? 'top') === 'top' && isset($descInfo)) echo $descInfo;

        $fields = [];
        $jsChecks = '';

        foreach ($structure as $key => $column) {
            $fieldKey = $key;
            if ($isAdmin) $fieldKey = empty($column['advfield']) ? $column['field'] : explode(',', $column['advfield'])[0];

            $required = (bool)($column['is_required'] ?? false);
            $reqAttr  = ($required && !($isAdmin && $column['field'] === 'mpasswd')) ? ' required' : '';
            $mark     = $reqAttr ? ' <em class="text-danger">*</em>' : '';
            $label    = msr_e($column['name']);

            $default = $record[$column['field']] ?? '';
            if ($column['field'] === 'advance') {
                $advName = trim(explode(',', $column['advfield'] ?? '')[0]);
                $default = $record[$advName] ?? '';
            }
            $default = (string)$default;
            $defaultE = msr_e($default);

            $html = '<div class="my-3 msr-field"><label class="form-label"><strong>' . $label . $mark . '</strong></label>';

            switch ($column['field']) {
                case 'mpasswd':
                    $html .= '<input type="password" name="form[' . $fieldKey . ']" id="pass1" class="form-control" placeholder="Masukkan ' . $label . '" minlength="8" autocomplete="new-password"' . $reqAttr . '>';
                    if ($isPublic) {
                        $html .= '<div class="progress mt-1" style="height:6px"><div id="msrStrength" class="progress-bar bg-danger" style="width:10%"></div></div>';
                    }
                    $html .= '<small>Konfirmasi ulang kata sandi</small>
                              <input type="password" name="confirm_password" id="pass2" class="form-control" placeholder="Ulangi kata sandi" autocomplete="new-password"' . $reqAttr . '>
                              <small id="msrMatch"></small>';
                    break;

                case 'gender':
                    $sel1 = $default === '1' ? ' selected' : '';
                    $sel0 = ($default === '0' && $default !== '') ? ' selected' : '';
                    $html .= '<select name="form[' . $fieldKey . ']" class="form-control"' . $reqAttr . '>
                                <option value="">Pilih</option>
                                <option value="1"' . $sel1 . '>Laki-Laki</option>
                                <option value="0"' . $sel0 . '>Perempuan</option>
                              </select>';
                    break;

                case 'member_address':
                    $html .= '<textarea name="form[' . $fieldKey . ']" class="form-control" placeholder="Masukkan ' . $label . '"' . $reqAttr . '>' . $defaultE . '</textarea>';
                    break;

                case 'member_type_id':
                    $mt = DB::getInstance()->query('select member_type_id, member_type_name from mst_member_type');
                    $html .= '<select name="form[' . $fieldKey . ']" class="form-control"' . $reqAttr . '><option value="">Pilih</option>';
                    while ($r = $mt->fetch(PDO::FETCH_NUM)) {
                        $sel = ((string)$default === (string)$r[0] && $default !== '') ? ' selected' : '';
                        $html .= '<option value="' . msr_e($r[0]) . '"' . $sel . '>' . msr_e($r[1]) . '</option>';
                    }
                    $html .= '</select>';
                    break;

                case 'member_image':
                    if (!($option->image ?? false)) {
                        $html .= '<div class="alert alert-info">Foto belum diaktifkan pada "Pengaturan Form".</div>';
                    } elseif ($isAdmin && !empty($record['member_image'])) {
                        $img = Storage::images()->isExists('persons/' . $record['member_image']) ? $record['member_image'] : 'avatar.jpg';
                        $html .= '<img class="d-block rounded" src="' . SWB . 'lib/minigalnano/createthumb.php?filename=images/persons/' . urlencode($img) . '&width=120"/>';
                    } else {
                        $html .= '<input type="file" name="member_image" accept=".jpg,.jpeg,.png" class="form-control d-block" data-max-kb="' . $maxKb . '"' . $reqAttr . '>
                                  <small>Maksimal ' . $maxKb . ' KB (JPG/PNG)</small>
                                  <img id="msrPhotoPreview" class="d-none rounded mt-2" style="max-height:140px"/>';
                    }
                    break;

                case 'advance':
                    $advType = $column['advfieldtype'] ?? 'varchar';
                    $parts   = explode(',', $column['advfield'] ?? '');
                    $choices = array_values(array_filter(array_map('trim', explode('|', $parts[1] ?? ''))));
                    $uid     = md5($parts[0] ?? $label);
                    if (in_array($advType, ['enum', 'enum_radio', 'text_multiple'])) $default = (string)($record[trim($parts[0] ?? '')] ?? '');

                    if ($advType === 'text') {
                        $html .= '<textarea name="form[' . $fieldKey . ']" class="form-control"' . $reqAttr . '>' . msr_e($default) . '</textarea>';
                    } elseif ($advType === 'enum') {
                        $html .= '<select name="form[' . $fieldKey . ']" class="form-control"' . $reqAttr . '><option value="">Pilih</option>';
                        foreach ($choices as $c) $html .= '<option value="' . msr_e($c) . '"' . ($default === $c ? ' selected' : '') . '>' . msr_e($c) . '</option>';
                        $html .= '</select>';
                    } elseif ($advType === 'enum_radio') {
                        if ($reqAttr && $isPublic) $jsChecks .= "if ($('.radio{$uid}:checked').length < 1) { evt.preventDefault(); alert('Pilih salah satu: {$label}'); return; }\n";
                        foreach ($choices as $i => $c) {
                            $html .= '<div><input class="radio' . $uid . '" id="r' . $uid . $i . '" type="radio" name="form[' . $fieldKey . ']" value="' . msr_e($c) . '"' . ($default === $c ? ' checked' : '') . '> <label for="r' . $uid . $i . '" style="cursor:pointer">' . msr_e($c) . '</label></div>';
                        }
                    } elseif ($advType === 'text_multiple') {
                        $sel = json_decode($default, true) ?: [];
                        if ($reqAttr && $isPublic) $jsChecks .= "if ($('.checkbox{$uid}:checked').length < 1) { evt.preventDefault(); alert('Pilih salah satu: {$label}'); return; }\n";
                        foreach ($choices as $i => $c) {
                            $html .= '<div><input class="checkbox' . $uid . '" id="c' . $uid . $i . '" type="checkbox" name="form[' . $fieldKey . '][]" value="' . msr_e($c) . '"' . (in_array($c, $sel) ? ' checked' : '') . '> <label for="c' . $uid . $i . '" style="cursor:pointer">' . msr_e($c) . '</label></div>';
                        }
                    } else {
                        $type = $advType === 'int' ? 'number' : 'text';
                        $html .= '<input type="' . $type . '" name="form[' . $fieldKey . ']" value="' . msr_e($default) . '" class="form-control" placeholder="Masukkan ' . $label . '"' . $reqAttr . '>';
                    }
                    break;

                default:
                    if ($column['field'] === 'member_id' && $isPublic && ($option->auto_id ?? false)) {
                        $html .= '<div class="alert alert-secondary py-2 mb-0"><strong>ID otomatis:</strong> dibuat sistem dengan pola <code>' . msr_e(msr_id_example($option)) . '</code> — Anda tidak perlu mengisi ID.</div>';
                        break;
                    }
                    $types = ['birth_date' => 'date', 'member_email' => 'email'];
                    $type  = $types[$column['field']] ?? 'text';
                    $extra = $column['field'] === 'member_id' ? ' maxlength="20" pattern="[A-Za-z0-9._\-]{3,20}" title="3-20 karakter: huruf/angka/._-"' : '';
                    $html .= '<input type="' . $type . '" name="form[' . $fieldKey . ']" value="' . $defaultE . '" class="form-control" placeholder="Masukkan ' . $label . '"' . $extra . $reqAttr . '>';
            }

            $fields[$key] = $html . '</div>';
        }

        /* ---- ekor form (info bawah, persetujuan, captcha, tombol) ---- */
        $tail = '';
        if (($info->position ?? '') === 'bottom' && isset($descInfo)) $tail .= $descInfo;

        if ($isPublic) {
            if (($option->with_agreement ?? false)) {
                $tail .= '<div class="my-2"><input type="checkbox" id="iAgree"/> <label for="iAgree" style="cursor:pointer">Saya menyetujui prasyarat di atas</label></div>';
            }
            if (($option->captcha ?? false) && class_exists(Captcha::class) && config('captcha', false)) {
                $captcha = Captcha::section('memberarea');
                if ($captcha->isSectionActive()) $tail .= '<div class="captchaMember my-2">' . $captcha->getCaptcha() . '</div>';
            }
            $tail .= msr_csrf_field();
            $tail .= '<input type="text" name="msr_hp" value="" class="d-none" tabindex="-1" autocomplete="off">';
            $tail .= '<input type="hidden" name="msr_ts" value="' . time() . '">';
            $dis = ($option->with_agreement ?? false) ? ' disabled title="Centang persetujuan terlebih dahulu"' : '';
            $tail .= '<div class="form-group my-3">
                        <input type="hidden" name="action" value="save"/>
                        <button class="btn btn-primary" type="submit" name="save"' . $dis . '>Daftar</button>
                        <button class="btn btn-outline-secondary" type="reset">Batal</button>
                      </div><strong><em class="text-danger">*</em> ) wajib diisi</strong>';
        } elseif ($isAdmin) {
            $tail .= '<div class="form-group my-3">
                        <input type="hidden" name="action" value="acc"/>
                        <button class="btn btn-success" type="submit" name="acc">Setujui</button>
                        <a class="btn btn-danger" href="' . pluginUrl(['section' => 'view_detail', 'member_id' => $_GET['member_id'] ?? 0, 'headless' => 'yes', 'action' => 'delete_reg']) . '">Hapus</a>
                      </div>';
        }

        /* ---- wizard / langkah ---- */
        $chunks = ($wizard && count($fields) > 4) ? array_chunk($fields, 4, true) : [$fields];
        $total  = count($chunks);
        echo '<div id="msrWizard" data-total="' . $total . '">';
        if ($total > 1) echo '<div class="progress my-3" style="height:8px"><div id="msrProgress" class="progress-bar bg-success" style="width:' . round(100 / $total) . '%"></div></div>';
        foreach ($chunks as $i => $chunk) {
            echo '<div class="msr-step' . ($i > 0 ? ' d-none' : '') . '" data-step="' . $i . '">';
            if ($total > 1) echo '<h5 class="text-muted">Langkah ' . ($i + 1) . ' dari ' . $total . '</h5>';
            echo implode('', $chunk);
            if ($total > 1) {
                echo '<div class="d-flex justify-content-between my-3">';
                echo $i > 0 ? '<button type="button" class="btn btn-outline-secondary msr-prev">&larr; Kembali</button>' : '<span></span>';
                if ($i < $total - 1) echo '<button type="button" class="btn btn-primary msr-next">Lanjut &rarr;</button>';
                echo '</div>';
            }
            if ($i === $total - 1) echo $tail;
            echo '</div>';
        }
        echo '</div></form>';

        /* ---- JS ---- */
        if ($isPublic) {
            $agreeJs = ($option->with_agreement ?? false) ? "
                $('#iAgree').click(function(){
                    let ok = $('#iAgree:checked').length > 0;
                    \$('button[name=\"save\"]').prop('disabled', !ok);
                });" : '';
            echo <<<HTML
<script>
$(document).ready(function() {
    function msrProgress() {
        let total = parseInt($('#msrWizard').attr('data-total'));
        let idx = parseInt($('.msr-step:not(.d-none)').attr('data-step') || 0);
        $('#msrProgress').css('width', Math.round((idx + 1) / total * 100) + '%');
    }
    $('#msrWizard').on('click', '.msr-next', function() {
        let step = $(this).closest('.msr-step'), ok = true;
        step.find('input,select,textarea').each(function() {
            if (!this.checkValidity()) { this.reportValidity(); ok = false; return false; }
        });
        if (!ok) return;
        step.addClass('d-none').next('.msr-step').removeClass('d-none');
        msrProgress();
    });
    $('#msrWizard').on('click', '.msr-prev', function() {
        let step = $(this).closest('.msr-step');
        step.addClass('d-none').prev('.msr-step').removeClass('d-none');
        msrProgress();
    });
    $('#pass1').on('input', function() {
        let v = $(this).val(), s = 0;
        if (v.length >= 8) s++; if (/[A-Z]/.test(v)) s++; if (/[0-9]/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
        let w = [10, 35, 60, 85, 100][s], c = ['bg-danger','bg-danger','bg-warning','bg-info','bg-success'][s];
        $('#msrStrength').removeClass('bg-danger bg-warning bg-info bg-success').addClass(c).css('width', w + '%');
    });
    $('#pass2').on('input', function() {
        let ok = $(this).val() !== '' && $(this).val() === $('#pass1').val();
        $('#msrMatch').html(ok ? '&#10003; Kata sandi cocok' : '&#10007; Belum cocok').css('color', ok ? 'green' : 'red');
    });
    $('input[name="member_image"]').on('change', function() {
        let f = this.files[0]; if (!f) return;
        let maxKb = parseInt($(this).data('max-kb') || '2048', 10);
        if (f.size > maxKb * 1024) { alert('Ukuran foto melebihi ' + maxKb + ' KB'); this.value = ''; return; }
        let r = new FileReader();
        r.onload = function(e) { $('#msrPhotoPreview').attr('src', e.target.result).removeClass('d-none'); };
        r.readAsDataURL(f);
    });
    {$agreeJs}
    $('#self_member').submit(function(evt) {
        {$jsChecks}
        let bad = null;
        $('#msrWizard .msr-step').each(function() {
            let inv = $(this).find(':input').filter(function() { return !this.checkValidity(); });
            if (inv.length) { bad = { step: $(this), el: inv.first() }; return false; }
        });
        if (bad) {
            evt.preventDefault();
            $('.msr-step').addClass('d-none'); bad.step.removeClass('d-none');
            bad.el[0].reportValidity(); msrProgress(); return;
        }
        $('button[name="save"]').prop('disabled', true).text('Mengirim…');
    });
});
</script>
HTML;
        }
        return ob_get_clean();
    }
}