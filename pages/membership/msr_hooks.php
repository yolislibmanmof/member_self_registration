<?php
use SLiMS\Plugins;
use SLiMS\Table\Schema;

// Render ruas mahir skema aktif pada form anggota (hook resmi SLiMS 9.7+/9.8)
Plugins::getInstance()->register(Plugins::MEMBERSHIP_CUSTOM_FIELD_FORM, function($form, &$js, $data) {
    $schema = msr_active_schema();
    if (!$schema) return;
    $structure = json_decode($schema->structure, true) ?: [];
    $data = is_array($data) ? $data : [];

    foreach ($structure as $item) {
        if (($item['field'] ?? '') !== 'advance') continue;
        $parts = explode(',', $item['advfield'] ?? '');
        $dbfield = trim($parts[0] ?? '');
        if ($dbfield === '' || !Schema::hasColumn('member_custom', $dbfield)) continue;

        $label = msr_e($item['name'] ?? $dbfield) . ' <small class="text-muted">(daftar online)</small>';
        $value = (string)($data[$dbfield] ?? '');
        $options = array_values(array_filter(array_map('trim', explode('|', $parts[1] ?? ''))));

        switch ($item['advfieldtype'] ?? 'varchar') {
            case 'enum':
                $list = array_map(fn($o) => [$o, $o], $options);
                array_unshift($list, ['', 'Pilih']);
                $form->addSelectList('advfield[' . $dbfield . ']', $label, $list, $value, 'class="form-control col-4"');
                break;
            case 'enum_radio':
                $form->addRadio('advfield[' . $dbfield . ']', $label, array_map(fn($o) => [$o, $o], $options), $value);
                break;
            case 'text_multiple':
                $form->addCheckBox('advfield[' . $dbfield . ']', $label, array_map(fn($o) => [$o, $o], $options), json_decode($value, true) ?: [], 'class="form-control"');
                break;
            case 'text':
                $form->addTextField('textarea', 'advfield[' . $dbfield . ']', $label, $value, 'rows="2" class="form-control"');
                break;
            default:
                $form->addTextField('text', 'advfield[' . $dbfield . ']', $label, $value, 'class="form-control"');
        }
    }
});

// Simpan ruas mahir saat anggota ditambah/diubah dari modul Keanggotaan
Plugins::getInstance()->register(Plugins::MEMBERSHIP_CUSTOM_FIELD_DATA, function(&$custom_data) {
    if (!isset($_POST['advfield']) || !is_array($_POST['advfield'])) return;
    $schema = msr_active_schema();
    if (!$schema) return;
    if (!is_array($custom_data)) $custom_data = [];

    $allowed = [];
    foreach (json_decode($schema->structure, true) ?: [] as $item) {
        if (($item['field'] ?? '') === 'advance') {
            $allowed[trim(explode(',', $item['advfield'] ?? '')[0])] = $item['advfieldtype'] ?? 'varchar';
        }
    }

    foreach ($_POST['advfield'] as $col => $val) {
        if (!isset($allowed[$col]) || !Schema::hasColumn('member_custom', $col)) continue;
        if (is_array($val)) {
            $val = json_encode(array_map(fn($v) => strip_tags(trim((string)$v)), $val), JSON_UNESCAPED_UNICODE);
        } else {
            $val = strip_tags(trim((string)$val));
            if ($allowed[$col] === 'int' && $val !== '' && !ctype_digit($val)) continue;
        }
        $custom_data[$col] = $val;
    }
});