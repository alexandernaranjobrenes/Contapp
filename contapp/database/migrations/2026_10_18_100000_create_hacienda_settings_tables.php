<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturación electrónica por compañía (CLAUDE.md secc. 38). Lo carga solo el
 * Superusuario de cada compañía, y cada una tiene lo suyo: su ambiente, sus
 * datos de emisor, sus credenciales del ATV y su llave criptográfica.
 *
 * - hacienda_settings: una fila por compañía. Datos del emisor que el XML
 *   exige y la compañía no tenía (ubicación, correo, teléfono), el ambiente
 *   activo y si la emisión electrónica está encendida.
 * - hacienda_credentials: una fila por compañía y AMBIENTE. Las de pruebas y
 *   las de producción son distintas en Hacienda (usuario, contraseña y
 *   llave), y tenerlas separadas permite probar en sandbox sin tocar las de
 *   producción. Todo lo secreto va cifrado con la APP_KEY (cast `encrypted`):
 *   la contraseña, la llave .p12, su PIN y los tokens.
 * - fiscal_sequences: el consecutivo fiscal por sucursal, terminal y tipo de
 *   comprobante. Hacienda los numera por separado (la factura y la nota de
 *   crédito llevan cada una su serie), y una fila bloqueada con FOR UPDATE
 *   dentro de la transacción del comprobante es lo que impide que dos ventas
 *   simultáneas tomen el mismo número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hacienda_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('environment', 4)->default('stag')->comment('stag (pruebas) | prod');
            $table->boolean('enabled')->default(false)->comment('emitir y enviar a Hacienda al emitir cada comprobante');
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();

            // Emisor: lo que el XML pide y la compañía no tenía.
            $table->string('identification_type', 2)->nullable()->comment('Nota 4: 01 física, 02 jurídica, 03 DIMEX, 04 NITE');
            $table->string('province', 1)->nullable()->comment('Ubicacion/Provincia, 1 dígito');
            $table->string('canton', 2)->nullable()->comment('Ubicacion/Canton, 2 dígitos');
            $table->string('district', 2)->nullable()->comment('Ubicacion/Distrito, 2 dígitos');
            $table->string('neighborhood', 50)->nullable()->comment('Ubicacion/Barrio, 5 a 50 caracteres');
            $table->string('address_details', 250)->nullable()->comment('Ubicacion/OtrasSenas, 5 a 250 caracteres');
            $table->string('phone', 20)->nullable();
            $table->json('emails')->nullable()->comment('CorreoElectronico del emisor: de 1 a 4');

            $table->string('branch', 3)->default('001')->comment('sucursal por defecto');
            $table->string('terminal', 5)->default('00001')->comment('terminal por defecto');
            $table->boolean('send_to_customer')->default(true)->comment('enviar XML, respuesta y PDF al cliente cuando Hacienda acepta');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hacienda_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 4)->comment('stag | prod');

            $table->string('username', 160)->nullable()->comment('usuario del API del ATV');
            $table->text('password')->nullable()->comment('cifrada');

            // La llave criptográfica (.p12) en base64, cifrada. En la base y no
            // en el disco: un archivo sin cifrar en storage/ es justo lo que
            // no puede pasar con la firma de una empresa.
            $table->longText('certificate')->nullable()->comment('.p12 en base64, cifrado');
            $table->text('certificate_pin')->nullable()->comment('cifrado');
            $table->string('certificate_subject')->nullable();
            $table->string('certificate_issuer')->nullable();
            $table->string('certificate_serial', 80)->nullable();
            $table->string('certificate_holder_id', 20)->nullable()->comment('cédula del titular, leída del certificado');
            $table->timestamp('certificate_valid_from')->nullable();
            $table->timestamp('certificate_valid_to')->nullable();
            $table->string('certificate_fingerprint', 64)->nullable()->comment('SHA-256 del certificado');
            $table->timestamp('certificate_uploaded_at')->nullable();
            $table->unsignedSmallInteger('certificate_warned_days')->nullable()->comment('último aviso de vencimiento enviado');

            $table->text('access_token')->nullable()->comment('cifrado');
            $table->text('refresh_token')->nullable()->comment('cifrado');
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();

            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'environment']);
        });

        Schema::create('fiscal_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('branch', 3);
            $table->string('terminal', 5);
            $table->string('document_type', 2)->comment('Nota 3');
            $table->unsignedBigInteger('last_number')->default(0)->comment('el último que se usó; el próximo es +1');
            $table->timestamps();

            $table->unique(['company_id', 'branch', 'terminal', 'document_type'], 'fiscal_sequences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_sequences');
        Schema::dropIfExists('hacienda_credentials');
        Schema::dropIfExists('hacienda_settings');
    }
};
