<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos que no pueden depender de que alguien esté mirando la pantalla.
 *
 * El WebSocket entrega al instante, pero no guarda nada: quien no estaba
 * conectado en ese momento no se entera nunca. Para un beep de venta nueva eso
 * da igual —la venta está en la lista—, pero no para algo que espera una
 * decisión, como una solicitud de cancelación de un cobro de varios miles.
 *
 * Es la tabla estándar de Laravel, así que cualquier `Notification` con canal
 * `database` la usa sin más trabajo. No lleva `tenant_id` a propósito: el
 * aislamiento lo da `notifiable_id`, que es un usuario y ya pertenece a un
 * tenant y a una sucursal. Añadir la columna sería un segundo camino para lo
 * mismo, y dos caminos se contradicen tarde o temprano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // La consulta de la campana: pendientes de un usuario, recientes
            // primero. `morphs()` ya indexa (notifiable_type, notifiable_id).
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
