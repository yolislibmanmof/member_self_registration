<?php
/**
 * MSR v3.2 — Rebuild tabel antrean dari struktur skema + guard anti-fatal.
 * Tanpa refleksi privat, PHP 8.2 safe, InnoDB, kolom nullable (aman strict-mode).
 */
use SLiMS\Table\Schema;

if (!function_exists('msr_resolve_column')) {
    function msr_resolve_column(array $column, ?array $detail): ?array
    {
        $map     = msr_type_map();
        $field   = $column['field'] ?? '';
        $advType = $column['advfieldtype'] ?? '';
        $isAdv   = $field === 'advance';

        if ($isAdv) {
            $dataType = $advType ?: 'varchar';
            $field    = trim((string)($column['advfield'] ?? ''));
        } else {
            if (!$detail) return null; // kolom asing dilewati
            $dataType = $detail['DATA_TYPE'];
        }
        if ($field === '') return null;

        $method = array_search($dataType, $map, true);
        if ($method === false) {
            $special = ['enum' => 'enum', 'enum_radio' => 'string', 'text_multiple' => 'text', 'varchar' => 'string', 'int' => 'number'];
            $method  = $special[$dataType] ?? null;
            if ($method === null) return null;
        }

        $choices = null;
        if ($isAdv && in_array($advType, ['enum', 'enum_radio', 'text_multiple'], true)) {
            $parts   = explode(',', $field, 2);
            $field   = trim($parts[0]);
            $choices = array_values(array_filter(array_map('trim', explode('|', $parts[1] ?? ''))));
            $method  = $advType === 'enum' ? 'enum' : ($advType === 'enum_radio' ? 'string' : 'text');
        }

        // enum bawaan tabel member (mis. gender) → ambil nilai asli dari COLUMN_TYPE
        if ($method === 'enum' && $choices === null) {
            if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", (string)($detail['COLUMN_TYPE'] ?? ''), $m)) $choices = $m[1];
            if (!$choices) $method = 'string';
        }

        $length = (int)($detail['CHARACTER_MAXIMUM_LENGTH'] ?? 0) ?: 64;
        if ($method === 'enum') $params = [$field, $choices ?: ['Lainnya']];
        elseif (in_array($method, ['text', 'longtext', 'json', 'date', 'datetime'], true)) $params = [$field];
        else $params = [$field, $length];

        return ['method' => $method, 'field' => $field, 'params' => $params];
    }
}

if (!function_exists('msr_rebuild_table')) {
    function msr_rebuild_table(stdClass $schema): array
    {
        $table     = msr_schema_table($schema->name);
        $structure = json_decode($schema->structure ?? '', true) ?: [];
        if (!$structure) return ['ok' => false, 'msg' => 'Struktur skema kosong/rusak — tidak bisa rebuild.'];
        if (Schema::hasTable($table)) return ['ok' => true, 'created' => false, 'msg' => 'Tabel sudah ada.'];

        $byName = [];
        foreach (Schema::table('member')->columns(true) as $d) $byName[$d['COLUMN_NAME']] = $d;

        try {
            Schema::create($table, function($t) use ($structure, $byName) {
                foreach ($structure as $column) {
                    $r = msr_resolve_column($column, $byName[$column['field'] ?? ''] ?? null);
                    if (!$r) continue;
                    if ($r['field'] === 'member_id')   { $t->index('member_id'); $t->unique('member_id'); }
                    if ($r['field'] === 'member_name') { $t->index('member_name'); }
                    $t->{$r['method']}(...$r['params'])->nullable();
                }
                $t->datetime('email_verified_at')->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->timestamps();
                $t->engine    = 'InnoDB';
                $t->charset   = 'utf8mb4';
                $t->collation = 'utf8mb4_unicode_ci';
            });

            // lengkapi kolom ruas mahir di member_custom bila belum ada
            $adv = [];
            foreach ($structure as $column) {
                if (($column['field'] ?? '') !== 'advance') continue;
                $r = msr_resolve_column($column, null);
                if ($r && !Schema::hasColumn('member_custom', $r['field'])) $adv[] = $r;
            }
            if ($adv && Schema::hasTable('member_custom')) {
                Schema::table('member_custom', function($t) use ($adv) {
                    foreach ($adv as $r) $t->{$r['method']}(...$r['params'])->nullable()->add();
                });
            }
            return ['ok' => true, 'created' => true, 'msg' => 'Tabel `' . $table . '` berhasil dibuat ulang dari struktur skema.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => 'Gagal membuat tabel: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('msr_guard_table')) {
    function msr_guard_table(stdClass $schema, bool $autoRepair = true): array
    {
        if (Schema::hasTable(msr_schema_table($schema->name))) return ['ok' => true];
        if (!$autoRepair) return ['ok' => false, 'msg' => 'Tabel antrean tidak ditemukan.'];
        $r = msr_rebuild_table($schema);
        if ($r['ok']) {
            msr_log('repair', null, $r['msg']);
            return ['ok' => true, 'repaired' => true,
                    'msg' => 'Tabel antrean tidak ditemukan dan telah dibuat ulang otomatis. Antrean kini kosong — data lama hanya dapat dipulihkan dari backup.'];
        }
        return ['ok' => false, 'msg' => $r['msg']];
    }
}