<?php
use SLiMS\Table\Schema;

defined('INDEX_AUTH') or die('Direct access is not allowed!');

if ($schemas->rowCount() < 1) {
?>
    <div class="w-full d-flex flex-column align-items-center justify-content-center p-5" style="background-color:whitesmoke">
        <img src="<?= MSWB ?>static/images/notfound.png" style="width:250px">
        <h4 class="font-weight-bold mt-2">Yah</h4>
        <p>Belum ada skema</p>
        <div class="d-flex flex-row">
            <a href="<?= pluginUrl(['section' => 'add_schema']) ?>" class="btn btn-outline-primary">Buat Yuk</a>&nbsp;
            <a href="<?= pluginUrl(['section' => 'import_schema', 'headless' => 'yes']) ?>" class="btn btn-outline-secondary notAJAX openPopUp">Impor Aja</a>
        </div>
    </div>
<?php
} else {
    $activeId = $activeSchema->rowCount() ? $activeSchema->fetchObject()->id : 0;
    echo '<div id="schemas" class="my-4 mx-3 d-flex flex-wrap" schema-active="' . $activeId . '">';
    while ($result = $schemas->fetchObject()) {
        $bg = substr(md5($result->name), 0, 6);
        $fn = textColor($bg);
        $info = json_decode($result->info);
        $desc = mb_substr(strip_tags($info->desc ?? ''), 0, 100);
        $checked = $result->status == 1 ? 'checked' : '';
        $label = $result->status == 1 ? 'Non-Aktifkan' : 'Aktifkan';
        $tableOk = Schema::hasTable(msr_schema_table($result->name));

        $previewUrl = pluginUrl(['headless' => 'yes', 'schema_id' => $result->id, 'section' => 'form_preview']);
        $exportUrl  = pluginUrl(['action' => 'export_schema', 'schema_id' => $result->id]);
        $cloneUrl   = pluginUrl(['action' => 'clone_schema', 'schema_id' => $result->id]);
        $editUrl    = pluginUrl(['section' => 'edit_schema', 'schema_id' => $result->id]);
        $repairUrl  = pluginUrl(['action' => 'repair_table', 'schema_id' => $result->id]);
        $deleteUrl  = pluginUrl(['headless' => 'yes', 'section' => 'list']);

        $tableBadge = $tableOk
            ? '<span class="badge badge-success">tabel ✓</span>'
            : '<span class="badge badge-danger">tabel hilang</span> <a href="' . $repairUrl . '" class="badge badge-warning">perbaiki</a>';

        echo <<<HTML
        <div class="card col-4 mb-3">
            <div class="card-img-top rounded-lg" style="background-color:#{$bg};color:#{$fn};height:20px"></div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <h5 class="card-title font-weight-bold mb-1">{$result->name}</h5>
                    {$tableBadge}
                </div>
                <p class="card-text d-flex flex-column mb-2">
                    <label class="mb-0"><strong>Judul Form</strong></label>
                    {$info->title}
                    <label class="mb-0"><strong>Deskripsi</strong></label>
                    {$desc}
                </p>
                <div class="d-flex flex-row justify-content-between align-items-center flex-wrap">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input checkbox" data-uid="{$result->id}" id="checkbox{$result->id}" {$checked}>
                        <label class="custom-control-label" for="checkbox{$result->id}">{$label}</label>
                    </div>
                    <div class="btn-group flex-wrap">
                        <a href="{$editUrl}" class="btn btn-sm btn-outline-primary" title="Edit skema"><i class="fa fa-pencil"></i> Edit</a>
                        <a href="{$cloneUrl}" class="btn btn-sm btn-outline-info" title="Klon skema"><i class="fa fa-copy"></i> Klon</a>
                        <a href="{$exportUrl}" target="blindSubmit" class="btn btn-sm btn-outline-secondary" title="Ekspor"><i class="fa fa-download"></i></a>
                        <a href="{$previewUrl}" class="btn btn-sm btn-outline-success notAJAX openPopUp" height="500px" title="Pratinjau"><i class="fa fa-eye"></i></a>
                        <a href="{$deleteUrl}" data-uid="{$result->id}" class="schemaDelete btn btn-sm btn-outline-danger" title="Hapus"><i class="fa fa-trash"></i></a>
                    </div>
                </div>
            </div>
        </div>
        HTML;
    }
    echo '</div>';
    $actionUrl = pluginUrl(reset: true);
    $url = pluginUrl(['section' => 'list']);
    echo <<<HTML
    <script>
        $('input.checkbox').change(function(){
            let activeSchema = $('#schemas').attr('schema-active');
            if (activeSchema != 0 && activeSchema != $(this).data('uid')) {
                $(`#checkbox\${activeSchema}`).trigger('click');
            }
            let uid = $(this).data('uid');
            if (this.checked === false) uid = 0;
            $.post('{$actionUrl}', {schema_id: uid, action: 'active_schema'}, function(){
                setTimeout(() => { $('#mainContent').simbioAJAX('{$url}'); }, 800);
            });
        });
        $('.schemaDelete').click(function(e) {
            e.preventDefault();
            if (!confirm('Menghapus skema juga menghapus data pendaftarannya. Yakin?')) return;
            $.post('{$actionUrl}', {schema_id: $(this).data('uid'), action: 'drop_schema'}, function() {
                $('#mainContent').simbioAJAX('{$url}');
            });
        });
    </script>
    HTML;
}