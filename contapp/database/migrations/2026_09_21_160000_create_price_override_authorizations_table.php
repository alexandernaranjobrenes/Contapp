<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorizaciones de cambio de precio: quién liberó una factura que se apartó
 * de la lista, cuándo, y cuánto se apartó.
 *
 * ── Por qué una tabla y no un par de columnas en la línea ────────────────
 *
 * Porque el valor del control no está en bloquear —eso lo hace el guard— sino
 * en poder preguntar después: cuántos cambios hubo este mes, quién los pide,
 * quién los autoriza, sobre qué artículos y por cuánto. Eso es una consulta
 * sobre un registro propio, no un campo escondido dentro de cada comprobante.
 *
 * ── Se guarda el precio de lista congelado ───────────────────────────────
 *
 * `list_unit_price` es el precio que la lista decía EN EL MOMENTO de emitir,
 * no una referencia a la lista. Si mañana la lista sube, la autorización de
 * ayer tiene que seguir diciendo de qué se apartó — igual que el comprobante
 * congela su tipo de cambio y sus datos fiscales.
 *
 * ── Lo que NO se guarda ──────────────────────────────────────────────────
 *
 * La contraseña del autorizador no se registra en ningún lado, ni siquiera
 * cifrada: se verifica contra el hash y se descarta. Lo único que queda es
 * QUIÉN autorizó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_override_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_document_line_id')->nullable()
                ->constrained('sales_document_lines')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();

            // De qué lista se apartó y cuánto decía esa lista. price_list_id
            // es nullable porque la lista puede borrarse después; el precio
            // congelado no depende de que siga existiendo.
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->string('price_list_code', 20)->nullable();
            $table->decimal('list_unit_price', 18, 5);

            // El precio NETO que se facturó: precio unitario menos el
            // descuento prorrateado. Se compara el neto y no el bruto porque
            // si no, bajar el precio por la vía del descuento saltaría el
            // control sin tocar una sola validación.
            $table->decimal('invoiced_unit_price', 18, 5);
            $table->decimal('difference', 18, 5)->comment('facturado - lista; negativo = se vendió más barato');

            // Quién pidió el cambio y quién lo liberó. Son distintos por
            // definición: si quien emite ya puede autorizar, no se pide
            // autorización y no se registra nada.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('authorized_by')->constrained('users');
            $table->string('reason')->nullable();
            $table->timestamps();

            // La consulta que justifica la tabla: qué se autorizó en un
            // período, y quién autoriza.
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'authorized_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_override_authorizations');
    }
};
