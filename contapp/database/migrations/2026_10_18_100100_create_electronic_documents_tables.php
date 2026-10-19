<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El ciclo del comprobante ante Hacienda, aparte del comprobante del ERP.
 *
 * sales_documents es la venta (asiento, inventario, partida en CxC); esto es
 * su vida fiscal: el XML firmado tal como se envió, en qué estado lo tiene
 * Hacienda y qué respondió. Separados porque se mueven distinto: la venta
 * queda cerrada al emitirse, y su estado ante Hacienda cambia durante minutos
 * u horas, desde la cola.
 *
 * - electronic_documents: una fila por comprobante firmado. Desde que se
 *   firma, el XML no cambia (cualquier corrección es otro comprobante).
 * - electronic_document_events: cada cambio de estado, con quién y por qué.
 *   Solo se agregan filas.
 * - electronic_document_exchanges: cada envío y cada consulta a Hacienda, con
 *   el código HTTP, la causa que manda en X-Error-Cause y el MensajeHacienda
 *   cuando llega. Solo se agregan filas: es la evidencia de lo que pasó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Sin unique: un comprobante que Hacienda rechazó se vuelve a
            // emitir con consecutivo y clave nuevos (una clave ya recibida no
            // se puede reenviar), y el intento anterior queda como historia.
            $table->foreignId('sales_document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('electronic_documents')->nullOnDelete()
                ->comment('el comprobante que reemplazó a este, con clave nueva');

            $table->string('environment', 4)->comment('stag | prod: a qué Hacienda se envía');
            $table->string('document_type', 2)->comment('Nota 3');
            $table->string('clave', 50);
            $table->string('consecutive', 20);
            $table->dateTime('issued_at')->comment('FechaEmision: la misma fecha que lleva la clave');

            $table->string('status', 20)->default('signed')
                ->comment('signed|submitting|submitted|submit_unknown|submit_failed|accepted|rejected|needs_attention|superseded');

            $table->longText('signed_xml');
            $table->string('signed_xml_sha256', 64);

            // Lo que va en el cuerpo del POST /recepcion, además del XML.
            $table->string('emitter_id_type', 2);
            $table->string('emitter_id_number', 20);
            $table->string('receiver_id_type', 2)->nullable();
            $table->string('receiver_id_number', 20)->nullable();

            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->unsignedSmallInteger('poll_attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->comment('cuándo vuelve a enviarlo o consultarlo la cola');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->string('hacienda_status', 20)->nullable()->comment('último ind-estado: recibido, procesando, aceptado, rechazado, error');
            $table->text('hacienda_message')->nullable()->comment('DetalleMensaje o X-Error-Cause, para mostrar');
            $table->longText('response_xml')->nullable()->comment('el MensajeHacienda de la respuesta final');
            $table->boolean('response_signature_valid')->nullable()->comment('si la firma de Hacienda en su respuesta verificó');

            $table->string('delivery_status', 10)->nullable()->comment('pending|sent|failed|skipped: el correo al cliente');
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_error')->nullable();
            $table->json('delivery_recipients')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'environment', 'clave'], 'electronic_documents_clave_unique');
            $table->index(['status', 'next_attempt_at']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('electronic_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('electronic_document_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->comment('null: la cola');
            $table->timestamp('created_at')->nullable();

            $table->index(['electronic_document_id', 'id']);
        });

        Schema::create('electronic_document_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('electronic_document_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 10)->comment('submit | status');
            $table->unsignedSmallInteger('http_status')->nullable()->comment('null: no hubo respuesta (red, tiempo agotado)');
            $table->string('ind_estado', 20)->nullable();
            $table->text('error_cause')->nullable()->comment('X-Error-Cause, o el error de red');
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable()->comment('recortado; nunca lleva el XML enviado ni el token');
            $table->longText('response_xml')->nullable()->comment('MensajeHacienda decodificado');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['electronic_document_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_document_exchanges');
        Schema::dropIfExists('electronic_document_events');
        Schema::dropIfExists('electronic_documents');
    }
};
