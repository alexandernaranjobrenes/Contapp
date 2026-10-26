<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planes, precios y solicitudes de licencia (CLAUDE.md secc. 42).
     *
     * - pricing_features: cada función que se vende, con su precio mensual y
     *   anual en colones y en dólares (el anual vacío = 12 veces el
     *   mensual). Una «por cantidad» (compañías, usuarios) cobra por unidad,
     *   con las primeras included_quantity sin costo. module_key la liga al
     *   módulo de Pivox que da, para cuando la licencia limite módulos: hoy
     *   es solo comercial.
     * - pricing_packages: paquetes armados, que el visitante ajusta. El
     *   descuento del paquete vale mientras no se le quite ninguna función.
     * - license_requests: lo que pide un visitante desde /precios, con la
     *   cotización tal como la vio (quote, JSON): un cambio de precios
     *   después no la altera. El backoffice decide si la responde por
     *   correo (license_request_replies, solo se agregan) o la descarta.
     */
    public function up(): void
    {
        Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('default_currency', 3)->default('CRC');
            $table->decimal('tax_rate', 5, 2)->default(13);
            $table->boolean('prices_include_tax')->default(false);
            $table->string('notification_email')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_features', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->string('icon', 40)->default('sparkles');
            $table->string('group', 60);
            $table->string('kind', 10)->default('toggle');
            $table->string('unit_singular', 40)->nullable();
            $table->string('unit_plural', 40)->nullable();
            $table->unsignedInteger('included_quantity')->default(0);
            $table->unsignedInteger('max_quantity')->nullable();
            $table->decimal('monthly_crc', 14, 2)->default(0);
            $table->decimal('annual_crc', 14, 2)->nullable();
            $table->decimal('monthly_usd', 12, 2)->default(0);
            $table->decimal('annual_usd', 12, 2)->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(false);
            $table->string('module_key', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pricing_feature_requirements', function (Blueprint $table) {
            $table->foreignId('feature_id')->constrained('pricing_features')->cascadeOnDelete();
            $table->foreignId('required_feature_id')->constrained('pricing_features')->cascadeOnDelete();

            $table->primary(['feature_id', 'required_feature_id']);
        });

        Schema::create('pricing_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('slug', 100)->unique();
            $table->string('tagline', 160)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('badge', 40)->nullable();
            $table->boolean('is_highlighted')->default(false);
            $table->string('billing', 10)->default('both');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pricing_package_features', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained('pricing_packages')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('pricing_features')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);

            $table->primary(['package_id', 'feature_id']);
        });

        Schema::create('license_requests', function (Blueprint $table) {
            $table->id();
            $table->string('status', 12)->default('new');
            $table->string('contact_name', 120);
            $table->string('company_name', 160);
            $table->string('identification', 30)->nullable();
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->text('message')->nullable();
            $table->foreignId('package_id')->nullable()->constrained('pricing_packages')->nullOnDelete();
            $table->string('package_name', 80)->nullable();
            $table->string('billing_cycle', 10);
            $table->string('currency', 3);
            $table->json('quote');
            $table->decimal('total', 14, 2);
            $table->timestamp('consented_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->text('internal_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('license_request_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_request_id')->constrained('license_requests')->cascadeOnDelete();
            $table->foreignId('propietario_id')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->string('to_email');
            $table->string('subject', 200);
            $table->text('body');
            $table->boolean('include_quote')->default(true);
            $table->string('status', 10);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_request_replies');
        Schema::dropIfExists('license_requests');
        Schema::dropIfExists('pricing_package_features');
        Schema::dropIfExists('pricing_packages');
        Schema::dropIfExists('pricing_feature_requirements');
        Schema::dropIfExists('pricing_features');
        Schema::dropIfExists('pricing_settings');
    }
};
