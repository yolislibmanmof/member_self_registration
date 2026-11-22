<?php
use SLiMS\DB;
use SLiMS\Table\Schema;
use SLiMS\Table\Blueprint;
use SLiMS\Migration\Migration;

class UpgradeV3 extends Migration
{
    public function up()
    {
        // Audit log + basis rate-limit per IP
        Schema::create('self_registration_logs', function(Blueprint $table){
            $table->autoIncrement('id');
            $table->string('member_id', 20)->nullable();
            $table->string('event', 32)->notNull();
            $table->string('ip_address', 45)->nullable();
            $table->text('detail')->nullable();
            $table->timestamps();
            $table->index('event');
            $table->index('ip_address');
            $table->index('created_at');
            $table->engine = 'InnoDB';
        });

        // Token verifikasi email
        Schema::create('self_registration_tokens', function(Blueprint $table){
            $table->autoIncrement('id');
            $table->string('member_id', 20)->notNull();
            $table->string('schema_table', 64)->notNull();
            $table->string('token', 64)->notNull();
            $table->datetime('expired_at')->notNull();
            $table->datetime('used_at')->nullable();
            $table->timestamps();
            $table->unique('token');
            $table->index('member_id');
            $table->engine = 'InnoDB';
        });

        // Lengkapi tabel pendaftar lama + konversi engine
        $db = DB::getInstance();
        $schemas = $db->query('select name from self_registration_schemas');
        while ($row = $schemas->fetchObject()) {
            $t = 'self_registration_' . trim(strtolower(str_replace(' ', '_', $row->name)));
            if (!Schema::hasTable($t)) continue;
            if (!Schema::hasColumn($t, 'email_verified_at')) $db->exec("alter table `$t` add column `email_verified_at` datetime null");
            if (!Schema::hasColumn($t, 'ip_address'))        $db->exec("alter table `$t` add column `ip_address` varchar(45) null");
            $db->exec("alter table `$t` engine = InnoDB");
        }
        $db->exec('alter table `self_registration_schemas` engine = InnoDB');
    }

    public function down()
    {
        Schema::drop('self_registration_tokens');
        Schema::drop('self_registration_logs');
    }
}