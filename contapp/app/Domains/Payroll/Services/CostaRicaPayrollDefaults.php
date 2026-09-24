<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollContribution;
use App\Domains\Payroll\Models\PayrollProvision;
use App\Domains\Payroll\Models\PayrollTaxBracket;
use App\Domains\Payroll\Models\PayrollTaxCredit;
use Illuminate\Support\Facades\DB;

/**
 * Carga una configuración INICIAL de planilla costarricense en una compañía.
 *
 * ╔═══════════════════════════════════════════════════════════════════════╗
 * ║  LEA ESTO ANTES DE USAR LOS NÚMEROS DE ESTE ARCHIVO                   ║
 * ╠═══════════════════════════════════════════════════════════════════════╣
 * ║                                                                       ║
 * ║  Los porcentajes y montos de acá son una PLANTILLA DE ARRANQUE, no    ║
 * ║  una fuente autorizada. Cambian: las cuotas de la CCSS por acuerdo    ║
 * ║  de Junta Directiva, la escala del impuesto por decreto cada año,     ║
 * ║  los créditos familiares con ella, y la póliza de riesgos del INS     ║
 * ║  según la actividad de CADA empresa.                                  ║
 * ║                                                                       ║
 * ║  Antes de correr la primera planilla en serio hay que verificarlos    ║
 * ║  uno por uno contra el decreto y las publicaciones vigentes, y        ║
 * ║  corregirlos en la pantalla de configuración. El sistema está hecho   ║
 * ║  para eso: ninguna tasa vive en el código del cálculo, todas viven    ║
 * ║  en tablas con vigencia, y una planilla vieja se reproduce con las    ║
 * ║  tasas de su propia fecha.                                            ║
 * ║                                                                       ║
 * ║  Lo que SÍ es estructural y no cambia con un decreto:                 ║
 * ║   · qué componente lo paga el obrero y cuál el patrono                ║
 * ║   · que el impuesto se calcula sobre el bruto MENOS cargas obreras    ║
 * ║   · que la escala es progresiva y mensual                             ║
 * ║   · que los créditos familiares se restan del impuesto, no de la base ║
 * ║   · que el aguinaldo es un doceavo del salario devengado              ║
 * ║   · qué ingresos son salario y cuáles no (viáticos, subsidios)        ║
 * ║                                                                       ║
 * ╚═══════════════════════════════════════════════════════════════════════╝
 *
 * Es idempotente: se puede volver a correr sin duplicar, porque cada fila se
 * identifica por su código y su fecha de vigencia.
 */
class CostaRicaPayrollDefaults
{
    /**
     * Cargas sociales, por componente separado.
     *
     * Van una por una y no como un solo "10,67% obrero" porque la planilla
     * de la Caja se concilia componente por componente: cuando un número no
     * cuadra, hay que poder decir cuál.
     *
     * `base`: 'ccss' usa solo los ingresos que forman salario; 'gross' usa
     * el bruto completo.
     *
     * @return array<int, array<string, mixed>>
     */
    public const CONTRIBUTIONS = [
        // ── Obrero: se le rebaja al trabajador ──────────────────────────
        ['code' => 'SEM-OBR', 'name' => 'CCSS · Enfermedad y Maternidad (obrero)',
            'payer' => 'employee', 'institution' => 'ccss', 'percentage' => '5.50',
            'legal_basis' => 'Reglamento del Seguro de Salud, CCSS — VERIFICAR vigente'],
        ['code' => 'IVM-OBR', 'name' => 'CCSS · Invalidez, Vejez y Muerte (obrero)',
            'payer' => 'employee', 'institution' => 'ccss', 'percentage' => '4.17',
            'legal_basis' => 'Reglamento del Seguro de IVM, CCSS — VERIFICAR vigente'],
        ['code' => 'BPDC-OBR', 'name' => 'Banco Popular · Aporte obrero',
            'payer' => 'employee', 'institution' => 'banco_popular', 'percentage' => '1.00',
            'legal_basis' => 'Ley Orgánica del Banco Popular n.º 4351 — VERIFICAR vigente'],

        // ── Patronal: lo paga la empresa ENCIMA del salario ─────────────
        ['code' => 'SEM-PAT', 'name' => 'CCSS · Enfermedad y Maternidad (patronal)',
            'payer' => 'employer', 'institution' => 'ccss', 'percentage' => '9.25',
            'legal_basis' => 'Reglamento del Seguro de Salud, CCSS — VERIFICAR vigente'],
        ['code' => 'IVM-PAT', 'name' => 'CCSS · Invalidez, Vejez y Muerte (patronal)',
            'payer' => 'employer', 'institution' => 'ccss', 'percentage' => '5.42',
            'legal_basis' => 'Reglamento del Seguro de IVM, CCSS — VERIFICAR vigente'],
        ['code' => 'BPDC-PAT', 'name' => 'Banco Popular · Aporte patronal',
            'payer' => 'employer', 'institution' => 'banco_popular', 'percentage' => '0.50',
            'legal_basis' => 'Ley n.º 4351 — VERIFICAR vigente'],
        ['code' => 'ASIG-FAM', 'name' => 'Asignaciones Familiares (FODESAF)',
            'payer' => 'employer', 'institution' => 'otro', 'percentage' => '5.00',
            'legal_basis' => 'Ley de Desarrollo Social y Asignaciones Familiares — VERIFICAR vigente'],
        ['code' => 'IMAS', 'name' => 'IMAS',
            'payer' => 'employer', 'institution' => 'imas', 'percentage' => '0.50',
            'legal_basis' => 'Ley n.º 4760 — VERIFICAR vigente'],
        ['code' => 'INA', 'name' => 'INA',
            'payer' => 'employer', 'institution' => 'ina', 'percentage' => '1.50',
            'legal_basis' => 'Ley Orgánica del INA n.º 6868 — VERIFICAR vigente'],
        ['code' => 'FCL', 'name' => 'Fondo de Capitalización Laboral',
            'payer' => 'employer', 'institution' => 'fcl', 'percentage' => '1.50',
            'legal_basis' => 'Ley de Protección al Trabajador n.º 7983 — VERIFICAR vigente'],
        ['code' => 'ROP', 'name' => 'Régimen Obligatorio de Pensiones Complementarias',
            'payer' => 'employer', 'institution' => 'rop', 'percentage' => '2.00',
            'legal_basis' => 'Ley de Protección al Trabajador n.º 7983 — VERIFICAR vigente'],
        // La póliza de riesgos NO tiene una tasa nacional: depende de la
        // actividad de cada empresa y la fija el INS en su póliza. Entra en
        // cero a propósito, para que nadie la dé por buena sin ponerla.
        ['code' => 'INS-RT', 'name' => 'INS · Riesgos del Trabajo',
            'payer' => 'employer', 'institution' => 'ins', 'percentage' => '0.00',
            'legal_basis' => 'Código de Trabajo, Título IV — la tasa la fija la póliza de CADA empresa según su actividad'],
    ];

    /**
     * Escala del impuesto al salario, MENSUAL y progresiva.
     *
     * Cada tramo grava solo la porción que cae dentro de él. Los montos los
     * actualiza Tributación por decreto, normalmente cada año.
     *
     * @return array<int, array<string, mixed>>
     */
    public const TAX_BRACKETS = [
        ['bracket_number' => 1, 'from_amount' => '0', 'to_amount' => '929000', 'percentage' => '0.00'],
        ['bracket_number' => 2, 'from_amount' => '929000', 'to_amount' => '1363000', 'percentage' => '10.00'],
        ['bracket_number' => 3, 'from_amount' => '1363000', 'to_amount' => '2392000', 'percentage' => '15.00'],
        ['bracket_number' => 4, 'from_amount' => '2392000', 'to_amount' => '4783000', 'percentage' => '20.00'],
        // El último tramo no tiene techo: to_amount null.
        ['bracket_number' => 5, 'from_amount' => '4783000', 'to_amount' => null, 'percentage' => '25.00'],
    ];

    /** Créditos familiares mensuales. Se restan DEL IMPUESTO. */
    public const TAX_CREDITS = [
        ['code' => 'spouse', 'name' => 'Crédito por cónyuge', 'monthly_amount' => '4000'],
        ['code' => 'child', 'name' => 'Crédito por hijo', 'monthly_amount' => '2600'],
    ];

    /**
     * Provisiones. Estas sí son casi todas estructurales.
     *
     * - Aguinaldo: un doceavo (8,3333%) del salario devengado. No es una
     *   tasa que cambie; es la definición misma del derecho.
     * - Vacaciones: dos semanas por cada cincuenta trabajadas (CT art. 156).
     *   Una empresa puede conceder más por convenio, y entonces sube.
     * - Cesantía: el art. 29 la escala según antigüedad y la topa en ocho
     *   años. Provisionar un porcentaje fijo es una aproximación razonable
     *   y prudente, no la liquidación exacta: esa se calcula al terminar la
     *   relación, con la tabla y la antigüedad real.
     * - Preaviso: solo se paga si hay despido sin causa y el patrono no da
     *   el aviso. Entra en cero porque provisionarlo o no es una decisión
     *   de política contable de cada empresa, no una obligación.
     *
     * @return array<int, array<string, mixed>>
     */
    public const PROVISIONS = [
        ['code' => 'aguinaldo', 'name' => 'Provisión de aguinaldo', 'percentage' => '8.3333',
            'legal_basis' => 'Ley n.º 2412 y CT art. 611 — un doceavo del salario devengado'],
        ['code' => 'vacaciones', 'name' => 'Provisión de vacaciones', 'percentage' => '4.1667',
            'legal_basis' => 'Código de Trabajo art. 153 y 156'],
        ['code' => 'cesantia', 'name' => 'Provisión de cesantía', 'percentage' => '5.3333',
            'legal_basis' => 'Código de Trabajo art. 29 — aproximación; la liquidación real usa la tabla por antigüedad'],
        ['code' => 'preaviso', 'name' => 'Provisión de preaviso', 'percentage' => '0.0000',
            'legal_basis' => 'Código de Trabajo art. 28 — solo aplica en despido sin causa sin aviso previo'],
    ];

    /**
     * Conceptos de planilla.
     *
     * ── Las tres banderas son lo más importante del módulo ───────────────
     *
     * `affects_ccss`, `affects_income_tax` y `affects_provisions` deciden
     * qué ingresos son salario y cuáles no, y de ahí sale TODO lo demás.
     * Marcarlas mal no produce un error visible: produce una planilla que
     * cuadra consigo misma y no cuadra con la Caja.
     *
     * Los casos que más se equivocan:
     *
     *  · Viáticos y reembolsos NO son salario. Son reintegro de un gasto
     *    que el trabajador hizo por la empresa. Tratarlos como salario le
     *    cobra cargas e impuesto sobre dinero que no ganó.
     *  · El subsidio por incapacidad lo paga la CCSS o el INS, no el
     *    patrono: no es salario. Pero el COMPLEMENTO que la empresa
     *    decida pagar encima sí lo es, y por eso son dos conceptos.
     *  · El aguinaldo no está afecto a cargas sociales ni a impuesto
     *    (dentro del límite de ley).
     *  · Las horas extra y las comisiones SÍ son salario, completo.
     *
     * @return array<int, array<string, mixed>>
     */
    public const CONCEPTS = [
        // ── Ingresos que SÍ son salario ─────────────────────────────────
        ['code' => 'HE-SIMPLE', 'name' => 'Horas extra', 'type' => 'earning',
            'calculation' => 'hours', 'factor' => '1.5000',
            'affects_ccss' => true, 'affects_income_tax' => true, 'affects_provisions' => true,
            'legal_basis' => 'CT art. 139: la hora extra se paga con un cincuenta por ciento más sobre la ordinaria.'],
        ['code' => 'HE-DOBLE', 'name' => 'Horas extra dobles', 'type' => 'earning',
            'calculation' => 'hours', 'factor' => '2.0000',
            'affects_ccss' => true, 'affects_income_tax' => true, 'affects_provisions' => true,
            'legal_basis' => 'Para jornada extraordinaria en día feriado o de descanso, según la política de la empresa.'],
        ['code' => 'FERIADO', 'name' => 'Feriado laborado', 'type' => 'earning',
            'calculation' => 'amount',
            'affects_ccss' => true, 'affects_income_tax' => true, 'affects_provisions' => true,
            'legal_basis' => 'CT art. 152: el feriado trabajado se paga doble.'],
        ['code' => 'COMISION', 'name' => 'Comisiones', 'type' => 'earning',
            'calculation' => 'amount',
            'affects_ccss' => true, 'affects_income_tax' => true, 'affects_provisions' => true,
            'legal_basis' => 'Es salario en especie de naturaleza variable: entra completo a la base de todo.'],
        ['code' => 'BONO', 'name' => 'Bonificación', 'type' => 'earning',
            'calculation' => 'amount',
            'affects_ccss' => true, 'affects_income_tax' => true, 'affects_provisions' => true],
        ['code' => 'COMPL-INC', 'name' => 'Complemento de incapacidad', 'type' => 'earning',
            'calculation' => 'amount',
            'affects_ccss' => true, 'affects_income_tax' => true, 'affects_provisions' => true,
            'legal_basis' => 'Lo que la empresa paga POR ENCIMA del subsidio: eso sí es salario.'],

        // ── Ingresos que NO son salario ─────────────────────────────────
        ['code' => 'VIATICO', 'name' => 'Viáticos y reembolsos', 'type' => 'earning',
            'calculation' => 'amount',
            'affects_ccss' => false, 'affects_income_tax' => false, 'affects_provisions' => false,
            'legal_basis' => 'Reintegro de un gasto hecho por cuenta de la empresa; no es remuneración.'],
        ['code' => 'SUBSIDIO', 'name' => 'Subsidio por incapacidad', 'type' => 'earning',
            'calculation' => 'amount',
            'affects_ccss' => false, 'affects_income_tax' => false, 'affects_provisions' => false,
            'legal_basis' => 'Lo paga la CCSS o el INS, no el patrono: no es salario.'],
        ['code' => 'AGUINALDO', 'name' => 'Aguinaldo', 'type' => 'earning',
            'calculation' => 'amount',
            'affects_ccss' => false, 'affects_income_tax' => false, 'affects_provisions' => false,
            'legal_basis' => 'No afecto a cargas sociales ni al impuesto al salario dentro del límite de ley.'],

        // ── Deducciones ─────────────────────────────────────────────────
        ['code' => 'ADELANTO', 'name' => 'Adelanto de salario', 'type' => 'deduction',
            'calculation' => 'amount',
            'affects_ccss' => false, 'affects_income_tax' => false, 'affects_provisions' => false],
        ['code' => 'PRESTAMO', 'name' => 'Cuota de préstamo', 'type' => 'deduction',
            'calculation' => 'amount',
            'affects_ccss' => false, 'affects_income_tax' => false, 'affects_provisions' => false],
        ['code' => 'ASO-AHORRO', 'name' => 'Ahorro asociación solidarista', 'type' => 'deduction',
            'calculation' => 'percentage',
            'affects_ccss' => false, 'affects_income_tax' => false, 'affects_provisions' => false],
        ['code' => 'ASO-CUOTA', 'name' => 'Cuota asociación solidarista', 'type' => 'deduction',
            'calculation' => 'percentage',
            'affects_ccss' => false, 'affects_income_tax' => false, 'affects_provisions' => false],
        ['code' => 'PENSION', 'name' => 'Pensión alimentaria', 'type' => 'deduction',
            'calculation' => 'amount',
            'affects_ccss' => false, 'affects_income_tax' => false, 'affects_provisions' => false,
            'legal_basis' => 'Tiene preferencia sobre cualquier otro rebajo voluntario.'],
        ['code' => 'EMBARGO', 'name' => 'Embargo judicial', 'type' => 'deduction',
            'calculation' => 'amount',
            'affects_ccss' => false, 'affects_income_tax' => false, 'affects_provisions' => false],
        ['code' => 'OTRA-DED', 'name' => 'Otra deducción', 'type' => 'deduction',
            'calculation' => 'amount',
            'affects_ccss' => false, 'affects_income_tax' => false, 'affects_provisions' => false],
    ];

    /**
     * @param  string  $validFrom  desde cuándo rigen estos valores
     * @return array{contributions: int, brackets: int, credits: int, provisions: int, concepts: int}
     */
    public function load(Company $company, string $validFrom): array
    {
        return DB::transaction(function () use ($company, $validFrom) {
            $counts = ['contributions' => 0, 'brackets' => 0, 'credits' => 0, 'provisions' => 0, 'concepts' => 0];

            foreach (self::CONTRIBUTIONS as $row) {
                PayrollContribution::withoutGlobalScope(CompanyScope::class)->updateOrCreate(
                    ['company_id' => $company->id, 'code' => $row['code'], 'valid_from' => $validFrom],
                    $row + ['company_id' => $company->id, 'base' => 'ccss', 'status' => 'active', 'valid_from' => $validFrom]
                );
                $counts['contributions']++;
            }

            foreach (self::TAX_BRACKETS as $row) {
                PayrollTaxBracket::withoutGlobalScope(CompanyScope::class)->updateOrCreate(
                    ['company_id' => $company->id, 'bracket_number' => $row['bracket_number'], 'valid_from' => $validFrom],
                    $row + ['company_id' => $company->id, 'valid_from' => $validFrom]
                );
                $counts['brackets']++;
            }

            foreach (self::TAX_CREDITS as $row) {
                PayrollTaxCredit::withoutGlobalScope(CompanyScope::class)->updateOrCreate(
                    ['company_id' => $company->id, 'code' => $row['code'], 'valid_from' => $validFrom],
                    $row + ['company_id' => $company->id, 'valid_from' => $validFrom]
                );
                $counts['credits']++;
            }

            foreach (self::PROVISIONS as $row) {
                PayrollProvision::withoutGlobalScope(CompanyScope::class)->updateOrCreate(
                    ['company_id' => $company->id, 'code' => $row['code'], 'valid_from' => $validFrom],
                    $row + ['company_id' => $company->id, 'valid_from' => $validFrom]
                );
                $counts['provisions']++;
            }

            // Los conceptos no llevan vigencia: son el catálogo de qué se
            // puede digitar, no una tasa. Lo que sí cambia con el tiempo es
            // el monto que se les digita en cada período.
            foreach (self::CONCEPTS as $row) {
                PayrollConcept::withoutGlobalScope(CompanyScope::class)->updateOrCreate(
                    ['company_id' => $company->id, 'code' => $row['code']],
                    $row + ['company_id' => $company->id, 'status' => 'active', 'is_recurring' => false]
                );
                $counts['concepts']++;
            }

            return $counts;
        });
    }
}
