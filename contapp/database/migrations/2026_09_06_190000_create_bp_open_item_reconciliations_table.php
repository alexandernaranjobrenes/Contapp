<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equivalente de account_reconciliations/account_reconciliation_lines
 * (2026-08-24), pero para partidas abiertas (bp_open_items) de UN mismo
 * socio de negocio en vez de movimientos de una misma cuenta contable — ver
 * OpenItemNettingService. AccountReconciliation excluye a propósito
 * cualquier línea con socio de negocio (docs del modelo: "mezclarlos acá
 * arriesgaría cancelar el saldo de un socio contra el de otro por error");
 * esto cubre exactamente ese hueco, sin tocar el módulo de cuentas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bp_open_item_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_partner_id')->constrained('business_partners');
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at');
            $table->timestamps();

            $table->index('business_partner_id');
        });

        Schema::create('bp_open_item_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bp_open_item_reconciliation_id');
            $table->unsignedBigInteger('bp_open_item_id');
            $table->timestamps();

            // Nombres de constraint explícitos y cortos: el nombre
            // auto-generado por Laravel para estas dos columnas supera el
            // límite de 64 caracteres de MySQL ("Identifier name ... is too long").
            $table->foreign('bp_open_item_reconciliation_id', 'bp_oi_recon_lines_recon_id_fk')
                ->references('id')->on('bp_open_item_reconciliations')->cascadeOnDelete();
            $table->foreign('bp_open_item_id', 'bp_oi_recon_lines_open_item_id_fk')
                ->references('id')->on('bp_open_items');

            // Una partida solo puede formar parte de UNA reconciliación
            // activa a la vez — deshacerla (delete físico, ver
            // OpenItemNettingService::unreconcile()) la libera.
            $table->unique('bp_open_item_id', 'bp_oi_recon_lines_open_item_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bp_open_item_reconciliation_lines');
        Schema::dropIfExists('bp_open_item_reconciliations');
    }
};
