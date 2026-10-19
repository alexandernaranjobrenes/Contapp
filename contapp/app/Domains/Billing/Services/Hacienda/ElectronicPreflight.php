<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\DataTransferObjects\SalesDocumentInput;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Core\Models\Company;

/**
 * Lo que se puede revisar con los datos públicos de Hacienda ANTES de emitir
 * un comprobante electrónico, para no gastar un consecutivo en un rechazo
 * seguro (docs/einvoice/rejections.md):
 *
 * - -400: cada CAByS de las líneas está en el catálogo.
 * - -408: la actividad económica del comprobante está inscrita y activa para
 *   la cédula del emisor.
 *
 * Solo cuenta lo que Hacienda contestó: si su API no responde, no hay
 * problemas que reportar y el comprobante se emite (Hacienda dirá).
 */
class ElectronicPreflight
{
    public function __construct(
        private readonly HaciendaPublicData $hacienda,
        private readonly ElectronicInvoicing $electronic,
    ) {}

    /**
     * Un comprobante por emitir.
     *
     * @return list<string>
     */
    public function problems(Company $company, SalesDocumentInput $input): array
    {
        return $this->check(
            $company,
            array_map(fn ($line) => $line->cabysCode, array_values($input->lines)),
            $input->emitterActivityCode ?? $this->electronic->defaultActivity($company)?->code,
            'Corregilo en la ficha del artículo o en el detalle de la línea.',
            'Se corrige en Facturación → Parámetros de facturación.',
        );
    }

    /**
     * Uno ya registrado que se va a emitir de nuevo («Emitir de nuevo»): la
     * reemisión usa el CAByS de cada línea y la actividad tal como quedaron
     * guardados en la venta, así que si alguno es lo que Hacienda rechazó, se
     * rechazaría otra vez con un consecutivo más.
     *
     * @return list<string>
     */
    public function problemsForDocument(Company $company, SalesDocument $document): array
    {
        return $this->check(
            $company,
            $document->lines()->pluck('cabys_code')->all(),
            $document->emitter_activity_code,
            'Quedó guardado en la venta: emitirla de nuevo no lo cambia.',
            'Quedó guardada en la venta: emitirla de nuevo no la cambia.',
        );
    }

    /**
     * @param  list<string>  $cabysByLine
     * @return list<string>
     */
    private function check(Company $company, array $cabysByLine, ?string $activity, string $cabysHint, string $activityHint): array
    {
        $problems = [];

        $codes = [];
        foreach ($cabysByLine as $index => $code) {
            $codes[(string) $code][] = $index + 1;
        }

        foreach ($codes as $code => $lineNumbers) {
            if (($this->hacienda->cabys((string) $code)['exists'] ?? true) === false) {
                $problems[] = (count($lineNumbers) > 1 ? 'Las líneas '.implode(', ', $lineNumbers).' llevan' : "La línea {$lineNumbers[0]} lleva")
                    ." el CAByS {$code}, que no está en el catálogo de Hacienda (lo rechaza con -400). {$cabysHint}";
            }
        }

        $taxId = preg_replace('/\D/', '', (string) $company->tax_id);

        if ($activity !== null && $taxId !== '') {
            $taxpayer = $this->hacienda->taxpayer($taxId);
            $registered = collect($taxpayer['activities'] ?? [])->filter(fn (array $a) => $a['active']);

            if (($taxpayer['found'] ?? false) && ! $registered->contains(fn (array $a) => $a['code'] === $activity)) {
                $problems[] = "La actividad económica {$activity} no está inscrita a la cédula {$taxId} en Hacienda (lo rechaza con -408). "
                    .($registered->isEmpty()
                        ? 'Hacienda no le tiene ninguna actividad activa.'
                        : 'Las inscritas son: '.$registered->map(fn (array $a) => "{$a['code']} {$a['description']}")->implode('; ').'.')
                    ." {$activityHint}";
            }
        }

        return $problems;
    }
}
