<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La marca que evita repetir el aviso guardaba el número que lo disparó (20 o
 * 10). Con el umbral configurable ese número deja de ser comparable: si una
 * sucursal lo mueve a media tarde, la marca ya no significa nada. Pasa a decir
 * qué severidad se avisó.
 *
 * Se hace con SQL directo porque PostgreSQL necesita el USING para convertir
 * los valores existentes en el mismo paso que el tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE devices
            ALTER COLUMN battery_alert_level TYPE varchar(10)
            USING CASE battery_alert_level
                WHEN 10 THEN 'critical'
                WHEN 20 THEN 'warn'
                ELSE NULL
            END
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE devices
            ALTER COLUMN battery_alert_level TYPE smallint
            USING CASE battery_alert_level
                WHEN 'critical' THEN 10
                WHEN 'warn' THEN 20
                ELSE NULL
            END
        SQL);
    }
};
