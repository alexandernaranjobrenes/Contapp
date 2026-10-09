<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La Bitácora de la compañía (Administración → Bitácora, CLAUDE.md secc.
     * 35): cada pedido que cambia datos queda como una fila, con todo lo que
     * tocó (ActivityRecorder).
     *
     * - route: la acción que se hizo (journal-entries.store), para decirla en
     *   palabras al mostrarla.
     * - screen: la pantalla de ScreenCatalog; un Administrador ve solo las
     *   filas de las pantallas a las que tiene acceso.
     * - subject: cómo se llamaba el registro principal («AS-0005»), porque
     *   puede haberse borrado después.
     * - changes: los registros que tocó, con lo de antes y lo de después.
     *
     * El índice (company_id, id) es el de «Ver más»: de a 15, de la más nueva
     * a la más vieja.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('route', 150)->nullable()->after('action');
            $table->string('screen', 100)->nullable()->after('route');
            $table->string('subject')->nullable()->after('auditable_id');
            $table->json('changes')->nullable()->after('new_values');

            $table->index(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'id']);
            $table->dropColumn(['route', 'screen', 'subject', 'changes']);
        });
    }
};
