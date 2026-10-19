<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Contracts\HaciendaSigner;
use App\Domains\Billing\Exceptions\CertificateException;
use App\Domains\Billing\Exceptions\ElectronicInvoicingException;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\HaciendaSetting;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Billing\Services\SalesDocumentXmlBuilder;
use App\Domains\Core\Models\Company;

/**
 * Convierte un comprobante ya registrado en su versión fiscal: el XML firmado,
 * listo para enviar.
 *
 *   1. arma el XML (SalesDocumentXmlBuilder)
 *   2. revisa que el resumen cuadre (las mismas cuentas que hace Hacienda)
 *   3. lo valida contra el XSD oficial
 *   4. lo firma con la llave de la compañía
 *   5. verifica la firma desde el texto ya serializado, y el XSD otra vez
 *   6. lo guarda: desde acá no cambia
 *
 * Corre DENTRO de la transacción de la venta. Si cualquier paso falla, no
 * queda la venta, ni el asiento, ni la salida de inventario, ni el
 * consecutivo: es preferible no emitir a emitir algo que Hacienda va a
 * rechazar sin remedio.
 *
 * El envío NO ocurre acá: lo hace la cola después del commit
 * (SubmitElectronicDocumentJob). Hablar con Hacienda dentro de una
 * transacción la dejaría abierta lo que tarde la red.
 */
class ElectronicDocumentIssuer
{
    public function __construct(
        private readonly SalesDocumentXmlBuilder $builder,
        private readonly XsdValidator $validator,
        private readonly HaciendaSigner $signer,
        private readonly XmlSignatureVerifier $verifier,
        private readonly CertificateInspector $inspector,
        private readonly ElectronicInvoicing $electronic,
    ) {}

    public function issue(Company $company, SalesDocument $document, HaciendaSetting $settings, ?int $userId = null): ElectronicDocument
    {
        $credential = $this->electronic->credential($company, $settings->environment);

        if ($credential === null) {
            throw new ElectronicInvoicingException('No hay credenciales de Hacienda para el ambiente '.$settings->environment.'.');
        }

        $document->unsetRelation('lines')->unsetRelation('payments')->unsetRelation('references');

        $problems = $this->builder->invariantProblems($document);

        if ($problems !== []) {
            throw new ElectronicInvoicingException('El resumen del comprobante no cuadra, y no se emitió: '.implode(' ', $problems));
        }

        $xml = $this->builder->build($document, $company, $settings);
        $this->validator->assertValid($xml, $document->fiscal_document_type, signed: false);

        try {
            $signed = $this->signer->sign($xml, $this->inspector->signingKey($credential));
        } catch (CertificateException $e) {
            throw new ElectronicInvoicingException('No se pudo firmar el comprobante: '.$e->getMessage(), previous: $e);
        }

        $signatureProblems = $this->verifier->problems($signed);

        if ($signatureProblems !== []) {
            throw new ElectronicInvoicingException('La firma del comprobante no se pudo verificar: '.implode(' ', $signatureProblems));
        }

        $this->validator->assertValid($signed, $document->fiscal_document_type, signed: true);

        $emitter = SalesDocumentXmlBuilder::emitterIdentification($company, $settings);
        $receiver = SalesDocumentXmlBuilder::receiverIdentification($document);

        $electronic = ElectronicDocument::create([
            'company_id' => $company->id,
            'sales_document_id' => $document->id,
            'environment' => $settings->environment,
            'document_type' => $document->fiscal_document_type,
            'clave' => $document->clave,
            'consecutive' => $document->consecutive,
            'issued_at' => $document->issued_at,
            'status' => ElectronicDocument::SIGNED,
            'signed_xml' => $signed,
            'signed_xml_sha256' => hash('sha256', $signed),
            'emitter_id_type' => $emitter['type'],
            'emitter_id_number' => $emitter['number'],
            'receiver_id_type' => $receiver['type'] ?? null,
            'receiver_id_number' => $receiver['number'] ?? null,
            'next_attempt_at' => now(),
        ]);

        $electronic->events()->create([
            'from_status' => null,
            'to_status' => ElectronicDocument::SIGNED,
            'message' => 'Firmado y validado contra el XSD v4.4 ('.config("hacienda.environments.{$settings->environment}.label").').',
            'user_id' => $userId,
            'created_at' => now(),
        ]);

        return $electronic;
    }
}
