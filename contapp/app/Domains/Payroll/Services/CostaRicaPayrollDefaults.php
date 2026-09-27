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
 * ║  DE DÓNDE SALEN ESTOS NÚMEROS                                         ║
 * ╠═══════════════════════════════════════════════════════════════════════╣
 * ║                                                                       ║
 * ║  Los valores de 2026 los aportó el usuario contador, del Manual de    ║
 * ║  Liquidaciones Laborales en Costa Rica (edición 2026):                ║
 * ║                                                                       ║
 * ║   · IVM según el Acta n.º 9038 de la Junta Directiva de la CCSS:      ║
 * ║     11,16% tripartita del 2026 al 2028 — 5,58 patrono, 4,33 obrero.   ║
 * ║   · Escala del impuesto del Decreto Ejecutivo n.º 45333-H.            ║
 * ║   · Créditos de ₡2.590 por cónyuge y ₡1.710 por hijo menor.           ║
 * ║                                                                       ║
 * ║  Los totales cuadran con los del manual: 10,83% obrero, 26,83%        ║
 * ║  patronal, 14,83% de CCSS patronal, y 6,50% obrero para un            ║
 * ║  pensionado (sin IVM).                                                ║
 * ║                                                                       ║
 * ║  Aun así siguen siendo una PLANTILLA: la póliza del INS depende de    ║
 * ║  la actividad de cada empresa, y las tasas cambian. El sistema está   ║
 * ║  hecho para eso: ninguna tasa vive en el código del cálculo, todas    ║
 * ║  viven en tablas con vigencia, y una planilla vieja se reproduce con  ║
 * ║  las tasas de su propia fecha.                                        ║
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
        // ── Obrero: 10,83% en total ─────────────────────────────────────
        ['code' => 'do001', 'name' => 'CCSS · Enfermedad y Maternidad (SEM)',
            'payer' => 'employee', 'institution' => 'ccss', 'percentage' => '5.50',
            'legal_basis' => 'Reglamento del Seguro de Salud, CCSS'],
        // Los pensionados no cotizan IVM: ya están pensionados por ese
        // régimen. De ahí que su carga obrera baje de 10,83% a 6,50%.
        ['code' => 'do002', 'name' => 'CCSS · Invalidez, Vejez y Muerte (IVM)',
            'payer' => 'employee', 'institution' => 'ccss', 'percentage' => '4.33',
            'exempt_for_pensioner' => true,
            'legal_basis' => 'CCSS Acta n.º 9038 — plan trianual IVM vigente del 2026 al 2028'],
        ['code' => 'do010', 'name' => 'Banco Popular · Aporte obrero',
            'payer' => 'employee', 'institution' => 'banco_popular', 'percentage' => '1.00',
            'legal_basis' => 'Ley Orgánica del Banco Popular n.º 4351'],

        // ── Patronal: 26,83% en total, de los cuales 14,83% son CCSS ────
        ['code' => 'dp001', 'name' => 'CCSS · Enfermedad y Maternidad (SEM)',
            'payer' => 'employer', 'institution' => 'ccss', 'percentage' => '9.25',
            'legal_basis' => 'Reglamento del Seguro de Salud, CCSS'],
        ['code' => 'dp002', 'name' => 'CCSS · Invalidez, Vejez y Muerte (IVM)',
            'payer' => 'employer', 'institution' => 'ccss', 'percentage' => '5.58',
            'exempt_for_pensioner' => true,
            'legal_basis' => 'CCSS Acta n.º 9038 — plan trianual IVM vigente del 2026 al 2028'],
        ['code' => 'dp003', 'name' => 'Banco Popular · Cuota patronal',
            'payer' => 'employer', 'institution' => 'banco_popular', 'percentage' => '0.25',
            'legal_basis' => 'Ley n.º 4351'],
        ['code' => 'dp004', 'name' => 'Asignaciones Familiares (FODESAF)',
            'payer' => 'employer', 'institution' => 'otro', 'percentage' => '5.00',
            'legal_basis' => 'Ley de Desarrollo Social y Asignaciones Familiares'],
        ['code' => 'dp005', 'name' => 'IMAS',
            'payer' => 'employer', 'institution' => 'imas', 'percentage' => '0.50',
            'legal_basis' => 'Ley n.º 4760'],
        ['code' => 'dp006', 'name' => 'INA',
            'payer' => 'employer', 'institution' => 'ina', 'percentage' => '1.50',
            'legal_basis' => 'Ley Orgánica del INA n.º 6868'],
        ['code' => 'dp007', 'name' => 'Banco Popular · Aporte patronal',
            'payer' => 'employer', 'institution' => 'banco_popular', 'percentage' => '0.25',
            'legal_basis' => 'Ley n.º 4351'],
        ['code' => 'dp008', 'name' => 'Fondo de Capitalización Laboral',
            'payer' => 'employer', 'institution' => 'fcl', 'percentage' => '1.50',
            'legal_basis' => 'Ley de Protección al Trabajador n.º 7983'],
        ['code' => 'dp009', 'name' => 'Régimen Obligatorio de Pensiones Complementarias',
            'payer' => 'employer', 'institution' => 'rop', 'percentage' => '2.00',
            'legal_basis' => 'Ley de Protección al Trabajador n.º 7983'],
        // El 1,00% completa el 26,83% del manual, pero la póliza de riesgos
        // NO tiene tasa nacional: la fija el INS según la actividad de cada
        // empresa. Este valor es el de referencia y hay que confirmarlo
        // contra la póliza propia.
        ['code' => 'dp011', 'name' => 'INS · Riesgos del Trabajo',
            'payer' => 'employer', 'institution' => 'ins', 'percentage' => '1.00',
            'legal_basis' => 'Código de Trabajo, Título IV — la tasa depende de la póliza de CADA empresa'],
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
        ['bracket_number' => 1, 'from_amount' => '0', 'to_amount' => '918000', 'percentage' => '0.00'],
        ['bracket_number' => 2, 'from_amount' => '918000', 'to_amount' => '1347000', 'percentage' => '10.00'],
        ['bracket_number' => 3, 'from_amount' => '1347000', 'to_amount' => '2364000', 'percentage' => '15.00'],
        ['bracket_number' => 4, 'from_amount' => '2364000', 'to_amount' => '4727000', 'percentage' => '20.00'],
        // El último tramo no tiene techo: to_amount null.
        ['bracket_number' => 5, 'from_amount' => '4727000', 'to_amount' => null, 'percentage' => '25.00'],
    ];

    /** Créditos familiares mensuales. Se restan DEL IMPUESTO. */
    public const TAX_CREDITS = [
        ['code' => 'spouse', 'name' => 'Crédito por cónyuge', 'monthly_amount' => '2590'],
        ['code' => 'child', 'name' => 'Crédito por hijo menor de edad', 'monthly_amount' => '1710'],
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

        // ── Ingresos que RESTAN del devengado ───────────────────────────
        //
        // El patrono no paga las horas de incapacidad: las cubre el subsidio
        // de la CCSS o del INS. Van como rubro de ingreso con signo negativo
        // y no como deducción, porque tienen que bajar también la BASE DE
        // CARGAS: sobre horas que no se pagaron no se cotiza.
        ['code' => 'HORAS-INC', 'name' => 'Horas por incapacidad (rebajo)', 'type' => 'earning',
            'sign' => -1, 'calculation' => 'hours', 'factor' => '1.0000',
            'affects_ccss' => true, 'affects_income_tax' => true, 'affects_provisions' => true,
            'legal_basis' => 'Las horas no laboradas por incapacidad no las paga el patrono; se rebajan del devengado.'],
        ['code' => 'AUSENCIA', 'name' => 'Ausencias y permisos sin goce (rebajo)', 'type' => 'earning',
            'sign' => -1, 'calculation' => 'hours', 'factor' => '1.0000',
            'affects_ccss' => true, 'affects_income_tax' => true, 'affects_provisions' => true,
            'legal_basis' => 'Tiempo no laborado y no pagado: sale del devengado y de la base de cargas.'],

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
                    // La exención del pensionado va explícita: sin ella,
                    // recargar la plantilla no devolvería a false una carga
                    // que alguien marcó, y la carga dejaría de ser idempotente.
                    $row + [
                        'company_id' => $company->id, 'base' => 'ccss', 'status' => 'active',
                        'exempt_for_pensioner' => false, 'valid_from' => $validFrom,
                    ]
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
                    // El signo va explícito: sin él, recargar la plantilla no
                    // devolvería a +1 un rubro que alguien puso en -1, y la
                    // carga dejaría de ser idempotente.
                    $row + ['company_id' => $company->id, 'status' => 'active', 'is_recurring' => false, 'sign' => 1]
                );
                $counts['concepts']++;
            }

            return $counts;
        });
    }
}
