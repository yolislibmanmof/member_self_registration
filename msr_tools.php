<?php
/**
 * MSR v3.3 — Batch 3: ID anggota otomatis, kartu PDF+QR, util edit skema.
 */
use SLiMS\DB;
use SLiMS\Table\Schema;

/* ================= ID OTOMATIS ================= */
if (!function_exists('msr_id_base')) {
    function msr_id_base(stdClass $option): string {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($option->id_prefix ?? 'M')));
        $prefix = substr($prefix ?: 'M', 0, 8);
        return $prefix . (($option->id_year ?? true) ? date('y') : '');
    }
}
if (!function_exists('msr_id_digits')) {
    function msr_id_digits(stdClass $option): int {
        $d = max(3, min(8, (int)($option->id_digits ?? 4)));
        return max(1, min($d, 20 - strlen(msr_id_base($option))));
    }
}
if (!function_exists('msr_id_example')) {
    function msr_id_example(stdClass $option): string {
        return msr_id_base($option) . str_pad('1', msr_id_digits($option), '0', STR_PAD_LEFT);
    }
}
if (!function_exists('msr_id_taken')) {
    function msr_id_taken(string $id, string $pendingTable): bool {
        $db = DB::getInstance();
        foreach (['member', $pendingTable] as $t) {
            if (!Schema::hasTable($t)) continue;
            $q = $db->prepare("select member_id from `$t` where member_id = ?");
            $q->execute([$id]);
            if ($q->rowCount()) return true;
        }
        return false;
    }
}
if (!function_exists('msr_generate_member_id')) {
    function msr_generate_member_id(stdClass $option, string $pendingTable): string {
        $base = msr_id_base($option); $digits = msr_id_digits($option);
        $db = DB::getInstance(); $max = 0;
        foreach (['member', $pendingTable] as $t) {
            if (!Schema::hasTable($t)) continue;
            $q = $db->prepare("select member_id from `$t` where member_id like ?");
            $q->execute([$base . '%']);
            while ($v = (string)$q->fetchColumn()) {
                if (preg_match('/^' . preg_quote($base, '/') . '(\d+)$/', $v, $m)) $max = max($max, (int)$m[1]);
            }
        }
        $n = $max + 1;
        do {
            $id = $base . str_pad((string)$n, $digits, '0', STR_PAD_LEFT);
            $n++;
        } while (msr_id_taken($id, $pendingTable) && $n <= $max + 100000);
        return $id;
    }
}

/* ================= mPDF + QR ================= */
if (!function_exists('msr_mpdf')) {
    function msr_mpdf(array $config = []): ?\Mpdf\Mpdf {
        if (!class_exists('\Mpdf\Mpdf')) {
            foreach ([SB . 'lib/mpdf/mpdf/autoload.php', SB . 'lib/mpdf/autoload.php', SB . 'vendor/autoload.php'] as $f) {
                if (file_exists($f)) { require_once $f; break; }
            }
        }
        if (!class_exists('\Mpdf\Mpdf')) return null;
        try { return new \Mpdf\Mpdf($config); } catch (Throwable $e) { return null; }
    }
}
if (!function_exists('msr_qr_file')) {
    /** Path file PNG QR, atau null bila library QR tak tersedia (fallback Code128). */
    function msr_qr_file(string $text): ?string {
        try {
            if (!class_exists('\chillerlan\QRCode\QRCode')) return null;
            $qr = new \chillerlan\QRCode\QRCode;
            if (class_exists('\chillerlan\QRCode\QROptions')) {
                $opt = new \chillerlan\QRCode\QROptions;
                if (defined('\chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG')) $opt->outputType = \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG;
                if (property_exists($opt, 'scale')) $opt->scale = 4;
                if (property_exists($opt, 'quietzoneSize')) $opt->quietzoneSize = 2;
                if (property_exists($opt, 'outputBase64')) $opt->outputBase64 = true;
                $qr = new \chillerlan\QRCode\QRCode($opt);
            }
            $uri = $qr->render($text);
            if (!is_string($uri) || strpos($uri, 'base64,') === false) return null;
            $bin = base64_decode(substr($uri, strpos($uri, 'base64,') + 7), true);
            if (!$bin) return null;
            $path = tempnam(sys_get_temp_dir(), 'msrqr');
            file_put_contents($path, $bin);
            return $path;
        } catch (Throwable $e) { return null; }
    }
}
if (!function_exists('msr_card_html')) {
    function msr_card_html(array $m, ?string $qrPath = null): string {
        global $sysconf;
        $lib = msr_e($sysconf['library_name'] ?? 'Perpustakaan');
        $sub = msr_e($sysconf['library_subname'] ?? '');
        $photo = SB . 'images/persons/' . ($m['member_image'] ?? '');
        if (empty($m['member_image']) || !file_exists($photo)) $photo = SB . 'images/persons/person.png';
        if (!file_exists($photo)) $photo = '';
        $img  = $photo ? '<img src="' . $photo . '" style="width:17mm;height:22mm">' : '';
        $code = $qrPath
            ? '<img src="' . $qrPath . '" style="width:18mm;height:18mm">'
            : '<barcode code="' . msr_e($m['member_id']) . '" type="C128B" height="0.5" size="0.7" />';
        return '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-family:sans-serif">
            <tr><td colspan="2" style="background:#1e3a5f;color:#ffffff;padding:2.5mm 3mm;font-size:9pt;font-weight:bold">' . $lib .
                ($sub ? '<br><span style="font-size:6.5pt;font-weight:normal">' . $sub . '</span>' : '') . '</td></tr>
            <tr>
                <td style="width:22mm;padding:2mm 1mm 1mm 3mm;vertical-align:top">' . $img . '</td>
                <td style="padding:2mm 3mm 1mm 0;vertical-align:top;font-size:8pt">
                    <div style="font-size:10pt;font-weight:bold">' . msr_e($m['member_name']) . '</div>
                    <div>ID: <b>' . msr_e($m['member_id']) . '</b></div>
                    <div>Tipe: ' . msr_e($m['member_type_name'] ?? '-') . '</div>
                    <div>Berlaku s.d: <b>' . msr_e($m['expire_date'] ?? '-') . '</b></div>
                </td>
            </tr>
            <tr><td colspan="2" align="center" style="padding:0 3mm 2mm">' . $code . '</td></tr>
        </table>';
    }
}

/* ================= SQL kolom (edit skema) ================= */
if (!function_exists('msr_column_sql')) {
    function msr_column_sql(array $r): string {
        switch ($r['method']) {
            case 'enum':     return "enum('" . implode("','", str_replace("'", "''", $r['params'][1])) . "')";
            case 'text':
            case 'longtext': return 'text';
            case 'number':   return 'int';
            case 'date':     return 'date';
            case 'datetime': return 'datetime';
            default:         return 'varchar(' . max(1, min(255, (int)($r['params'][1] ?? 64))) . ')';
        }
    }
}