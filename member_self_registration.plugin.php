<?php
/**
 * Plugin Name: member_self_registration
 * Plugin URI: https://github.com/yolislibmanmof/member_self_registration
 * Description: Pendaftaran anggota mandiri via OPAC: wizard multi-langkah, verifikasi email, anti-spam, ACC + notifikasi email, audit log. Kompatibel SLiMS 9.8.x & PHP 8.2.
 * Version: 3.0.0
 * Author: Drajat Hasan (v1-v2) & UNIMOF (v3)
 * Author URI: https://github.com/yolislibmanmof
 */

use SLiMS\DB;
use SLiMS\Plugins;
use SLiMS\Url;
use SLiMS\Table\Schema;

defined('MSLR') or define('MSLR', __DIR__);
defined('MSWB') or define('MSWB', (string)Url::getSlimsBaseUri('plugins/' . basename(MSLR) . '/'));

require_once __DIR__ . DS . 'helper.php';

$plugin = Plugins::getInstance();
// QR opsional: muat vendor plugin bila ada (composer require chillerlan/php-qrcode)
if (file_exists(__DIR__ . '/vendor/autoload.php')) $plugin->registerAutoload();

// Submenu admin: Keanggotaan → Daftar Online
$plugin->registerMenu('membership', 'Daftar Online', __DIR__ . '/pages/membership/index.php');

// Menu OPAC mengikuti nama skema aktif
if (Schema::hasTable('self_registration_schemas')) {
    $activeSchema = DB::getInstance()->query('select id,name from self_registration_schemas where status = 1');
    if ($activeSchema->rowCount()) {
        $data = $activeSchema->fetchObject();
        $plugin->registerMenu('opac', $data->name, __DIR__ . DS . 'pages' . DS . 'opac' . DS . 'index.php');
    }
}

// v3: TIDAK lagi override modul membership (folder pages/customs DIHAPUS).
// Ruas mahir disuntik lewat hook resmi SLiMS 9.7+/9.8.
require_once __DIR__ . DS . 'pages' . DS . 'membership' . DS . 'msr_hooks.php';