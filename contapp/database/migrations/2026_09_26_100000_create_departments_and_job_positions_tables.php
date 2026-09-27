<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departamentos y puestos como catálogo, no como texto libre.
 *
 * ── Por qué importa que dejen de ser texto ───────────────────────────────
 *
 * Hoy `employees.department` y `employees.position` son cadenas sueltas.
 * Con veinte empleados eso produce «Contabilidad», «contabilidad»,
 * «Contab.» y «Depto. Contabilidad» como cuatro departamentos distintos, y
 * cualquier reporte agrupado por departamento sale en pedazos. Lo mismo con
 * los puestos: no se puede contestar «cuántos operarios hay» ni comparar
 * salarios del mismo puesto si cada ficha lo escribe distinto.
 *
 * ── El código de ocupación de la CCSS ────────────────────────────────────
 *
 * El puesto lleva además un `ccss_occupation_code`: la Caja tiene su propio
 * catálogo de ocupaciones y lo pide en la planilla. Se guarda en la ficha
 * del PUESTO y no en la del trabajador porque es una propiedad del puesto —
 * todos los que ocupan el mismo puesto reportan la misma ocupación—, y así
 * se llena una vez y no una por persona.
 *
 * El catálogo de la Caja NO viene cargado: son cientos de ocupaciones que
 * cambian, y traerlas de memoria sería inventarlas. El campo queda para que
 * cada empresa ponga el código que le corresponde a cada puesto.
 *
 * ── Por qué se conserva el texto libre ───────────────────────────────────
 *
 * Las columnas viejas no se borran. Hay fichas con su departamento escrito a
 * mano, y borrarlas perdería ese dato antes de que alguien pueda mapearlo al
 * catálogo. La ficha usa el catálogo cuando lo tiene y el texto cuando no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');

            // El centro de costo del departamento: es la respuesta por
            // defecto para quien entra a trabajar ahí, y evita tener que
            // acordarse de ponérselo a cada ficha.
            $table->foreignId('cost_center_id')->nullable()
                ->constrained('cost_centers')->nullOnDelete();

            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');

            $table->foreignId('department_id')->nullable()
                ->constrained('departments')->nullOnDelete();

            // El código de ocupación de la CCSS. Va en el puesto y no en el
            // trabajador: todos los que ocupan el mismo puesto reportan la
            // misma ocupación.
            $table->string('ccss_occupation_code', 20)->nullable();
            $table->string('ccss_occupation_name')->nullable();

            // Rango salarial de referencia. Sirve para que la verificación
            // avise cuando un salario se sale de lo previsto para el puesto:
            // un cero de más en un aumento no lo detecta nadie mirando.
            $table->decimal('min_salary', 18, 2)->nullable();
            $table->decimal('max_salary', 18, 2)->nullable();

            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('employees', function (Blueprint $table) {
            // Nullable y junto a las columnas de texto: las viejas se
            // conservan para no perder lo que ya está escrito a mano.
            $table->foreignId('department_id')->nullable()->after('department')
                ->constrained('departments')->nullOnDelete();
            $table->foreignId('job_position_id')->nullable()->after('position')
                ->constrained('job_positions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_position_id');
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('job_positions');
        Schema::dropIfExists('departments');
    }
};
