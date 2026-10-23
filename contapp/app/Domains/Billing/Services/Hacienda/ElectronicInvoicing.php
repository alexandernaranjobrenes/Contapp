<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\DataTransferObjects\SalesDocumentInput;
use App\Domains\Billing\DataTransferObjects\SalesLineInput;
use App\Domains\Billing\Exceptions\ElectronicInvoicingException;
use App\Domains\Billing\Models\CompanyEconomicActivity;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\HaciendaCredential;
use App\Domains\Billing\Models\HaciendaSetting;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Support\CostaRicaTerritory;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Billing\Support\HaciendaClock;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;

/**
 * ¿Puede esta compañía emitir comprobantes electrónicos, y este comprobante
 * en particular?
 *
 * Dos preguntas distintas:
 *
 * - readiness(): lo que tiene que estar configurado (emisor, credenciales,
 *   llave). Lo ve el Superusuario en su pantalla, como una lista de lo que
 *   falta, y se revisa otra vez al encender la emisión.
 * - assertCanIssue(): lo que exige el comprobante que se está emitiendo
 *   (receptor identificado en una factura, el texto de «Otros» en la
 *   condición 99, la fecha del documento de referencia…). Se revisa ANTES
 *   de tomar el consecutivo: un error acá no quema un número.
 *
 * Con la emisión apagada nada de esto aplica, y facturar sigue como antes.
 */
class ElectronicInvoicing
{
    public function settings(Company $company): ?HaciendaSetting
    {
        return HaciendaSetting::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->first();
    }

    public function credential(Company $company, string $environment): ?HaciendaCredential
    {
        return HaciendaCredential::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('environment', $environment)
            ->first();
    }

    /** Si al emitir hay que firmar y enviar a Hacienda. */
    public function isEnabled(Company $company): bool
    {
        return (bool) $this->settings($company)?->enabled;
    }

    /**
     * Lo que falta para poder emitir en el ambiente dado (el activo, si no se
     * dice). Cada elemento es una frase para mostrar.
     *
     * @return list<string>
     */
    public function missing(Company $company, ?string $environment = null): array
    {
        $settings = $this->settings($company);
        $environment ??= $settings?->environment ?? 'stag';
        $missing = [];

        $taxId = preg_replace('/\D/', '', (string) $company->tax_id);

        if ($taxId === '') {
            $missing[] = 'La cédula de la compañía (Mi cuenta → Mis datos → Tus compañías).';
        }

        if (mb_strlen(trim((string) $company->legal_name)) < 5) {
            $missing[] = 'La razón social de la compañía, de al menos 5 caracteres.';
        }

        if (! $settings?->identification_type) {
            $missing[] = 'El tipo de identificación del emisor.';
        } elseif ($taxId !== '' && ! FiscalCatalogs::identificationMatchesType($settings->identification_type, $taxId)) {
            $missing[] = 'La cédula de la compañía no tiene los dígitos de una '.mb_strtolower(FiscalCatalogs::IDENTIFICATION_TYPES[$settings->identification_type]).'.';
        }

        if (! $settings?->province || ! $settings->canton || ! $settings->district || mb_strlen((string) $settings->address_details) < 5) {
            $missing[] = 'La ubicación del emisor: provincia, cantón, distrito y otras señas.';
        } elseif (! CostaRicaTerritory::exists($settings->province, $settings->canton, $settings->district)) {
            $missing[] = "La ubicación del emisor ({$settings->province}-{$settings->canton}-{$settings->district}) no está en la tabla de Hacienda: elegí de nuevo el cantón y el distrito.";
        }

        if (array_filter($settings?->emailList() ?? [], [FiscalCatalogs::class, 'isValidEmail']) === []) {
            $missing[] = 'Al menos un correo electrónico del emisor.';
        }

        $activity = $this->defaultActivity($company);

        if (! $activity) {
            $missing[] = 'Una actividad económica por defecto (Facturación → Parámetros de facturación).';
        } elseif (! FiscalCatalogs::isValidActivityCode($activity->code)) {
            $missing[] = "La actividad económica {$activity->code} tiene que ir con el formato de la v4.4, por ejemplo 6201.0.";
        }

        $credential = $this->credential($company, $environment);
        $label = config("hacienda.environments.{$environment}.label", $environment);

        if (! $credential?->hasApiCredentials()) {
            $missing[] = "El usuario y la contraseña del API del ATV ({$label}).";
        }

        if (! $credential?->hasCertificate()) {
            $missing[] = "La llave criptográfica y su PIN ({$label}).";
        } elseif ($credential->certificateExpired()) {
            $missing[] = "La llave criptográfica de {$label} venció el ".$credential->certificate_valid_to->format('d/m/Y').'.';
        }

        return $missing;
    }

    /**
     * Para la pantalla de facturación: si está encendida, en qué ambiente, y
     * lo que falta.
     *
     * @return array<string, mixed>
     */
    public function status(Company $company): array
    {
        $settings = $this->settings($company);
        $missing = $this->missing($company);

        return [
            'enabled' => (bool) $settings?->enabled,
            'environment' => $settings?->environment ?? 'stag',
            'environment_label' => config('hacienda.environments.'.($settings?->environment ?? 'stag').'.label'),
            'ready' => $missing === [],
            'missing' => $missing,
            'branch' => $settings?->branch ?? '001',
            'terminal' => $settings?->terminal ?? '00001',
            'electronic_types' => FiscalCatalogs::ELECTRONIC_TYPES,
        ];
    }

    /**
     * Lo que este comprobante tiene que traer para emitirse electrónicamente.
     * Se llama dentro de la transacción, antes de tomar el consecutivo.
     */
    public function assertCanIssue(Company $company, SalesDocumentInput $input, ?BusinessPartner $partner): void
    {
        $missing = $this->missing($company);

        if ($missing !== []) {
            throw new ElectronicInvoicingException(
                'La facturación electrónica está encendida pero incompleta, y el comprobante no se emitió. Falta: '.
                implode(' ', $missing).' Lo completa el Superusuario en Administración → Facturación electrónica.'
            );
        }

        $type = $input->fiscalDocumentType;
        $label = FiscalCatalogs::DOCUMENT_TYPES[$type] ?? $type;
        $errors = [];

        if (! in_array($type, FiscalCatalogs::ELECTRONIC_TYPES, true)) {
            $errors[] = "El tipo «{$label}» no se emite electrónicamente desde Facturación.";
        }

        if (in_array($input->saleCondition, FiscalCatalogs::SALE_CONDITIONS_NOT_IN[$type] ?? [], true)) {
            $errors[] = "La condición de venta «".FiscalCatalogs::SALE_CONDITIONS[$input->saleCondition]."» no existe en el XSD de «{$label}».";
        }

        if ($input->saleCondition === '99' && mb_strlen(trim((string) $input->saleConditionOther)) < 5) {
            $errors[] = 'La condición de venta «Otros» necesita describir cuál es (al menos 5 caracteres).';
        }

        // Un comprobante electrónico se emite en el momento: la fecha de
        // emisión es hoy, y la clave lleva esa misma fecha.
        $today = HaciendaClock::local(HaciendaClock::now())->format('Y-m-d');

        if ($input->documentDate->format('Y-m-d') !== $today) {
            $errors[] = "Un comprobante electrónico se emite con la fecha de hoy ({$today}); la fecha de contabilización sí puede ser otra.";
        }

        $activity = $input->emitterActivityCode ?? $this->defaultActivity($company)?->code;

        if (! FiscalCatalogs::isValidActivityCode($activity)) {
            $errors[] = "La actividad económica del emisor ({$activity}) tiene que ir con el formato 0000.0.";
        }

        $errors = [...$errors, ...$this->receiverErrors($type, $partner), ...$this->lineErrors($type, $input->lines)];

        foreach ($input->payments as $payment) {
            if (($payment['method_code'] ?? null) === '99' && mb_strlen(trim((string) ($payment['method_other'] ?? ''))) < 3) {
                $errors[] = 'El medio de pago «Otros» necesita describir cuál es.';
            }
        }

        foreach ($input->references as $reference) {
            if (empty($reference['issued_at']) && ! $this->knownClave($company, (string) ($reference['number'] ?? ''))) {
                $errors[] = 'Cada documento de referencia necesita su fecha de emisión.';
            }
        }

        if ($input->originalSalesDocumentId !== null) {
            $errors = [...$errors, ...$this->originalErrors($company, $input->originalSalesDocumentId)];
        }

        if ($errors !== []) {
            throw new ElectronicInvoicingException('El comprobante no se puede emitir electrónicamente: '.implode(' ', array_unique($errors)));
        }
    }

    public function defaultActivity(Company $company): ?CompanyEconomicActivity
    {
        return CompanyEconomicActivity::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /** El comprobante nuestro con esa clave, para tomar de ahí su fecha de emisión exacta. */
    public function documentByClave(Company $company, string $clave): ?SalesDocument
    {
        if (! preg_match('/^\d{50}$/', $clave)) {
            return null;
        }

        return SalesDocument::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('clave', $clave)
            ->first();
    }

    private function knownClave(Company $company, string $clave): bool
    {
        return $this->documentByClave($company, $clave) !== null;
    }

    /** @return list<string> */
    private function receiverErrors(string $type, ?BusinessPartner $partner): array
    {
        if ($partner === null) {
            return $type === '01'
                ? ['La factura electrónica necesita un receptor identificado; a consumidor final se emite un tiquete.']
                : [];
        }

        $errors = [];

        if (mb_strlen(trim((string) $partner->name)) < 3) {
            $errors[] = "El nombre del cliente {$partner->code} tiene que tener al menos 3 caracteres.";
        }

        $hasId = filled($partner->identification_type) && filled($partner->tax_id);

        if ($type === '01' && ! $hasId) {
            $errors[] = "El cliente {$partner->code} necesita su tipo de identificación y su número para recibir una factura electrónica (Socios de negocio).";
        }

        if ($hasId && ! FiscalCatalogs::identificationMatchesType($partner->identification_type, (string) $partner->tax_id)) {
            $errors[] = "La identificación del cliente {$partner->code} ({$partner->tax_id}) no tiene los dígitos de una ".
                mb_strtolower(FiscalCatalogs::IDENTIFICATION_TYPES[$partner->identification_type] ?? 'identificación').'.';
        }

        return $errors;
    }

    /**
     * @param  SalesLineInput[]  $lines
     * @return list<string>
     */
    private function lineErrors(string $type, array $lines): array
    {
        $errors = [];

        if (count($lines) > 1000) {
            $errors[] = 'Un comprobante admite como máximo 1000 líneas.';
        }

        foreach (array_values($lines) as $index => $line) {
            $number = $index + 1;

            if ($line->taxes === []) {
                $errors[] = "La línea {$number} no tiene impuesto: cada línea lleva el suyo, también si es exenta (tarifa 10) o 0%.";
            }

            if ($line->discountCode === '99' && mb_strlen(trim((string) $line->discountReason)) < 5) {
                $errors[] = "La línea {$number} tiene un descuento «Otros» que necesita describirse (al menos 5 caracteres).";
            }

            foreach ($line->taxes as $tax) {
                if (in_array($tax->taxCode, FiscalCatalogs::IVA_TAX_CODES, true) && $tax->ivaRateCode === null) {
                    $errors[] = "La línea {$number} tiene IVA sin código de tarifa.";
                }

                if (! $tax->isExonerated()) {
                    continue;
                }

                if ($type === '09') {
                    $errors[] = "La línea {$number} viene exonerada: una factura de exportación no lleva exoneraciones.";

                    continue;
                }

                if ($tax->exonerationInstitution === null || $tax->exonerationDate === null) {
                    $errors[] = "La exoneración de la línea {$number} necesita la institución que la emitió y su fecha.";
                }

                if ($tax->exonerationDocumentType === '99' || $tax->exonerationInstitution === '99') {
                    $errors[] = "La exoneración de la línea {$number} usa el código «Otros», que exige describirlo y Pivox todavía no lo captura.";
                }

                $documentNumber = mb_strlen(trim((string) $tax->exonerationDocumentNumber));

                if ($documentNumber < 3 || $documentNumber > 40) {
                    $errors[] = "El número del documento de exoneración de la línea {$number} tiene que tener entre 3 y 40 caracteres.";
                }
            }
        }

        return $errors;
    }

    /**
     * Una nota sobre un comprobante nuestro que fue electrónico: ese
     * comprobante tiene que estar ACEPTADO. Para Hacienda, uno rechazado o
     * todavía en proceso no existe, y una nota sobre él la rechazaría.
     *
     * @return list<string>
     */
    private function originalErrors(Company $company, int $originalId): array
    {
        $current = ElectronicDocument::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->where('sales_document_id', $originalId)
            ->orderByDesc('id')
            ->first();

        if ($current === null || $current->status === ElectronicDocument::ACCEPTED) {
            return [];
        }

        return ["El comprobante que se corrige está «{$current->label()}» en Hacienda: solo se puede corregir uno aceptado."];
    }
}
