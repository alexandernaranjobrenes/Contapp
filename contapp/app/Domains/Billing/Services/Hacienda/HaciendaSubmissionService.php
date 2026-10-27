<?php

namespace App\Domains\Billing\Services\Hacienda;

use App\Domains\Billing\Contracts\HaciendaTransport;
use App\Domains\Billing\Exceptions\HaciendaApiException;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\HaciendaCredential;
use App\Domains\Billing\Support\HaciendaClock;
use App\Domains\Core\Models\Company;
use App\Jobs\DeliverElectronicDocumentJob;
use Illuminate\Support\Facades\Cache;

/**
 * Envía un comprobante firmado a Hacienda y sigue su estado hasta que lo
 * acepta o lo rechaza.
 *
 * ── Las reglas que no se negocian ────────────────────────────────────────
 *
 * - Una clave que Hacienda ya registró NUNCA se reenvía («el comprobante ya
 *   fue recibido anteriormente»). Ante la duda —tiempo agotado, un 5xx, un
 *   proceso que se cayó a mitad del envío— primero se consulta la clave: si
 *   Hacienda la tiene, se sigue su estado; si contesta 404, recién ahí se
 *   reenvía la MISMA clave. Así una venta nunca termina con dos comprobantes.
 * - Un 400 es que Hacienda no lo recibió, y dice por qué en X-Error-Cause:
 *   eso no se arregla reenviando, sino con un comprobante nuevo.
 * - Un 401 es el token: se pide otro una vez, y si tampoco, son las
 *   credenciales.
 * - «Procesando» se vuelve a consultar con espera creciente
 *   (hacienda.poll_backoff), y al cabo de unas 48 h pasa a «Requiere
 *   atención» en vez de consultarse para siempre.
 *
 * ── Quién llama ──────────────────────────────────────────────────────────
 *
 * La cola (SubmitElectronicDocumentJob, PollElectronicDocumentJob), que lanza
 * el programador cada minuto con lo que está pendiente (pivox:hacienda-sync),
 * y la pantalla del comprobante cuando alguien pide «Consultar estado» o
 * «Reintentar envío». Este servicio no se encola a sí mismo: con la cola en
 * modo sync, cada «procesando» volvería a llamarse en el acto, en cadena.
 *
 * Un candado por comprobante evita que la cola y una persona lo procesen a la
 * vez.
 */
class HaciendaSubmissionService
{
    public function __construct(
        private readonly HaciendaTransport $api,
        private readonly HaciendaTokenProvider $tokens,
        private readonly ElectronicInvoicing $electronic,
        private readonly XmlSignatureVerifier $verifier,
    ) {}

    /**
     * Envía el comprobante (o confirma que ya llegó). Devuelve false si otro
     * proceso lo tiene tomado.
     */
    public function submit(ElectronicDocument $document, ?int $userId = null): bool
    {
        return $this->locked($document, function () use ($document, $userId) {
            $document->refresh();

            if (in_array($document->status, [ElectronicDocument::SUBMIT_UNKNOWN, ElectronicDocument::SUBMITTING, ElectronicDocument::NEEDS_ATTENTION], true)) {
                // ¿Llegó? Antes de reenviar, siempre.
                if ($this->reconcile($document, $userId)) {
                    return;
                }
            } elseif ($document->status !== ElectronicDocument::SIGNED) {
                return;
            }

            $this->send($document, $userId);
        });
    }

    /**
     * Pregunta a Hacienda por el comprobante y aplica lo que conteste.
     * Devuelve false si otro proceso lo tiene tomado.
     */
    public function poll(ElectronicDocument $document, ?int $userId = null): bool
    {
        return $this->locked($document, function () use ($document, $userId) {
            $document->refresh();

            if (! in_array($document->status, [ElectronicDocument::SUBMITTED, ElectronicDocument::SUBMIT_UNKNOWN, ElectronicDocument::NEEDS_ATTENTION, ElectronicDocument::SUBMITTING], true)) {
                return;
            }

            $credential = $this->credentialFor($document);

            try {
                $response = $this->api->status($document->environment, $this->tokens->token($credential), $document->clave);
            } catch (HaciendaApiException $e) {
                $this->handleStatusFailure($document, $credential, $e, $userId);

                return;
            }

            $this->logExchange($document, 'status', $response);

            if ($response->notFound()) {
                $this->handleNotFound($document, $userId);

                return;
            }

            $this->applyStatus($document, $response, $userId);
        });
    }

    /** @param  callable(): void  $work */
    private function locked(ElectronicDocument $document, callable $work): bool
    {
        $lock = Cache::lock('hacienda:document:'.$document->id, 120);

        if (! $lock->get()) {
            return false;
        }

        try {
            $work();
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * Si Hacienda ya tiene la clave. true: ya se resolvió (estaba, o no se
     * pudo saber y queda para después); false: no la tiene, hay que enviarla.
     */
    private function reconcile(ElectronicDocument $document, ?int $userId): bool
    {
        $credential = $this->credentialFor($document);

        try {
            $response = $this->api->status($document->environment, $this->tokens->token($credential), $document->clave);
        } catch (HaciendaApiException $e) {
            $this->handleStatusFailure($document, $credential, $e, $userId);

            return true;
        }

        $this->logExchange($document, 'status', $response);

        if ($response->notFound()) {
            return false;
        }

        $this->applyStatus($document, $response, $userId);

        return true;
    }

    private function send(ElectronicDocument $document, ?int $userId, bool $retriedToken = false): void
    {
        $credential = $this->credentialFor($document);

        try {
            $token = $this->tokens->token($credential, forceNew: $retriedToken);
        } catch (HaciendaApiException $e) {
            // Sin token no salió nada: el comprobante sigue sin enviar. Cuenta
            // como intento, para que una caída larga del IDP termine en
            // «Requiere atención» y no en un reintento por minuto para siempre.
            $document->increment('submit_attempts');

            if ($e->transient) {
                $this->scheduleSubmitRetry($document, 'Sin conexión con la autenticación de Hacienda: '.$e->getMessage(), $userId, sent: false);
            } else {
                $document->transitionTo(ElectronicDocument::NEEDS_ATTENTION, $e->getMessage(), $userId, [
                    'hacienda_message' => $e->getMessage(),
                    'next_attempt_at' => null,
                ]);
            }

            return;
        }

        if ($document->status !== ElectronicDocument::SUBMITTING) {
            $document->transitionTo(ElectronicDocument::SUBMITTING, null, $userId);
        }

        $document->increment('submit_attempts');

        try {
            $response = $this->api->submit($document->environment, $token, $this->payload($document));
        } catch (HaciendaApiException $e) {
            $this->logFailure($document, 'submit', $e);

            if ($e->isUnauthorized() && ! $retriedToken) {
                $this->tokens->forget($credential);
                $this->send($document, $userId, retriedToken: true);

                return;
            }

            if ($e->transient) {
                $this->scheduleSubmitRetry($document, $e->getMessage(), $userId, sent: true);

                return;
            }

            if ($e->status === 400 && $this->looksDuplicated($e->errorCause)) {
                // Hacienda dice que ya lo tiene: se sigue su estado, no se reenvía.
                $document->transitionTo(ElectronicDocument::SUBMIT_UNKNOWN, 'Hacienda indica que ya lo había recibido: se consulta su estado.', $userId, [
                    'next_attempt_at' => now(),
                ]);

                return;
            }

            if ($e->status === 400) {
                $document->transitionTo(ElectronicDocument::SUBMIT_FAILED, $e->errorCause ?? $e->getMessage(), $userId, [
                    'hacienda_message' => $e->errorCause ?? $e->getMessage(),
                    'next_attempt_at' => null,
                ]);

                return;
            }

            $document->transitionTo(ElectronicDocument::NEEDS_ATTENTION, $e->getMessage(), $userId, [
                'hacienda_message' => $e->getMessage(),
                'next_attempt_at' => null,
            ]);

            return;
        }

        $this->logExchange($document, 'submit', $response);

        $document->transitionTo(ElectronicDocument::SUBMITTED, 'Recibido por Hacienda.', $userId, [
            'submitted_at' => now(),
            'poll_attempts' => 0,
            'hacienda_status' => 'recibido',
            'hacienda_message' => null,
            'next_attempt_at' => now()->addSeconds($this->pollDelay(0)),
        ]);
    }

    private function applyStatus(ElectronicDocument $document, HaciendaResponse $response, ?int $userId): void
    {
        $indicator = $response->indicator();
        $responseXml = $response->responseXml();
        $message = MensajeHacienda::parse($responseXml);
        $detail = $message?->detail;

        $common = [
            'last_checked_at' => now(),
            'hacienda_status' => $indicator,
        ];

        if ($responseXml !== null) {
            $common['response_xml'] = $responseXml;
            $common['response_signature_valid'] = $this->verifier->isValid($responseXml);
        }

        if ($indicator === 'aceptado') {
            $document->transitionTo(ElectronicDocument::ACCEPTED, $detail ?: 'Aceptado por Hacienda.', $userId, [
                ...$common,
                'accepted_at' => now(),
                'hacienda_message' => $detail,
                'next_attempt_at' => null,
            ]);

            $this->queueDelivery($document);

            return;
        }

        if ($indicator === 'rechazado') {
            $document->transitionTo(ElectronicDocument::REJECTED, $detail ?: 'Rechazado por Hacienda.', $userId, [
                ...$common,
                'rejected_at' => now(),
                'hacienda_message' => $detail ?: 'Rechazado por Hacienda, sin detalle.',
                'next_attempt_at' => null,
            ]);

            return;
        }

        if ($indicator === 'error') {
            $document->transitionTo(ElectronicDocument::NEEDS_ATTENTION, 'Hacienda reportó un error al procesarlo: '.($detail ?: 'sin detalle'), $userId, [
                ...$common,
                'hacienda_message' => $detail ?: 'Hacienda reportó un error al procesarlo.',
                'next_attempt_at' => null,
            ]);

            return;
        }

        // recibido, procesando o algo que no se conoce: seguir esperando.
        $attempts = $document->status === ElectronicDocument::SUBMITTED ? $document->poll_attempts + 1 : 0;
        $delay = $this->pollDelay($attempts);

        if ($delay === null) {
            $document->transitionTo(ElectronicDocument::NEEDS_ATTENTION, 'Hacienda lo tiene en proceso desde hace más de 48 horas.', $userId, [
                ...$common,
                'hacienda_message' => 'Hacienda lo tiene en proceso desde hace más de 48 horas: consultá su estado más tarde o comunicate con Hacienda.',
                'next_attempt_at' => null,
            ]);

            return;
        }

        $document->transitionTo(ElectronicDocument::SUBMITTED, $document->status === ElectronicDocument::SUBMITTED ? null : 'Hacienda lo tiene: '.($indicator ?? 'en proceso').'.', $userId, [
            ...$common,
            'submitted_at' => $document->submitted_at ?? now(),
            'poll_attempts' => $attempts,
            'next_attempt_at' => now()->addSeconds($delay),
        ]);
    }

    /**
     * Hacienda no tiene la clave. Si el envío había salido bien, es que
     * todavía no la indexó: se espera un par de consultas. Después, hay
     * certeza de que no llegó y se reenvía la misma clave.
     */
    private function handleNotFound(ElectronicDocument $document, ?int $userId): void
    {
        if ($document->status === ElectronicDocument::SUBMITTED && $document->poll_attempts < 3) {
            $document->update([
                'poll_attempts' => $document->poll_attempts + 1,
                'last_checked_at' => now(),
                'next_attempt_at' => now()->addSeconds($this->pollDelay($document->poll_attempts + 1) ?? 300),
            ]);

            return;
        }

        $document->transitionTo(ElectronicDocument::SUBMIT_UNKNOWN, 'Hacienda no tiene la clave: se vuelve a enviar la misma.', $userId, [
            'last_checked_at' => now(),
            'next_attempt_at' => now(),
        ]);
    }

    private function handleStatusFailure(ElectronicDocument $document, HaciendaCredential $credential, HaciendaApiException $e, ?int $userId): void
    {
        $this->logFailure($document, 'status', $e);

        if ($e->isUnauthorized()) {
            $this->tokens->forget($credential);
        }

        if (! $e->transient && ! $e->isUnauthorized()) {
            $document->transitionTo(ElectronicDocument::NEEDS_ATTENTION, $e->getMessage(), $userId, [
                'hacienda_message' => $e->getMessage(),
                'next_attempt_at' => null,
            ]);

            return;
        }

        $attempts = $document->poll_attempts + 1;
        $delay = $this->pollDelay($attempts);

        $document->update([
            'poll_attempts' => $attempts,
            'last_checked_at' => now(),
            'hacienda_message' => $e->getMessage(),
            'next_attempt_at' => $delay === null ? null : now()->addSeconds($delay),
        ]);

        if ($delay === null) {
            $document->transitionTo(ElectronicDocument::NEEDS_ATTENTION, 'No se pudo consultar a Hacienda en 48 horas: '.$e->getMessage(), $userId);
        }
    }

    /**
     * @param  bool  $sent  si el POST llegó a salir (con un tiempo agotado no
     *                      se sabe si Hacienda lo recibió)
     */
    private function scheduleSubmitRetry(ElectronicDocument $document, string $message, ?int $userId, bool $sent): void
    {
        $backoff = (array) config('hacienda.submit_backoff');
        $attempt = max(0, $document->submit_attempts - 1);
        $delay = $backoff[$attempt] ?? null;

        if ($delay === null) {
            $document->transitionTo(ElectronicDocument::NEEDS_ATTENTION, 'No se pudo enviar después de '.$document->submit_attempts.' intentos: '.$message, $userId, [
                'hacienda_message' => $message,
                'next_attempt_at' => null,
            ]);

            return;
        }

        $attributes = ['hacienda_message' => $message, 'next_attempt_at' => now()->addSeconds($delay)];

        if ($sent) {
            $document->transitionTo(ElectronicDocument::SUBMIT_UNKNOWN, $message, $userId, $attributes);
        } else {
            $document->update($attributes);
        }
    }

    /** @return array<string, mixed> */
    public function payload(ElectronicDocument $document): array
    {
        $payload = [
            'clave' => $document->clave,
            'fecha' => HaciendaClock::format($document->issued_at),
            'emisor' => [
                'tipoIdentificacion' => $document->emitter_id_type,
                'numeroIdentificacion' => $document->emitter_id_number,
            ],
        ];

        if ($document->receiver_id_type && $document->receiver_id_number) {
            $payload['receptor'] = [
                'tipoIdentificacion' => $document->receiver_id_type,
                'numeroIdentificacion' => $document->receiver_id_number,
            ];
        }

        $payload['comprobanteXml'] = base64_encode($document->signed_xml);

        return $payload;
    }

    private function queueDelivery(ElectronicDocument $document): void
    {
        $settings = $this->electronic->settings(Company::findOrFail($document->company_id));

        if (! $document->sales_document_id || ! $settings?->send_to_customer) {
            $document->update(['delivery_status' => 'skipped']);

            return;
        }

        $document->update(['delivery_status' => 'pending']);

        DeliverElectronicDocumentJob::dispatch($document->id);
    }

    private function pollDelay(int $attempt): ?int
    {
        $backoff = (array) config('hacienda.poll_backoff');

        return $backoff[$attempt] ?? null;
    }

    private function looksDuplicated(?string $cause): bool
    {
        return $cause !== null && preg_match('/ya (fue |ha sido )?recibid|ya existe|duplicad/iu', $cause) === 1;
    }

    private function credentialFor(ElectronicDocument $document): HaciendaCredential
    {
        $credential = $this->electronic->credential(Company::findOrFail($document->company_id), $document->environment);

        if ($credential === null) {
            throw new HaciendaApiException('La compañía ya no tiene credenciales de Hacienda para '.$document->environment.'.');
        }

        return $credential;
    }

    private function logExchange(ElectronicDocument $document, string $operation, HaciendaResponse $response): void
    {
        $document->exchanges()->create([
            'operation' => $operation,
            'http_status' => $response->status,
            'ind_estado' => $response->indicator(),
            'error_cause' => $response->errorCause,
            'response_headers' => $response->headers,
            // El cuerpo de una consulta trae el MensajeHacienda en base64: va
            // aparte, decodificado, y no se guarda dos veces.
            'response_body' => $response->responseXml() === null ? mb_substr($response->body, 0, 4000) : null,
            'response_xml' => $response->responseXml(),
            'duration_ms' => $response->durationMs,
            'created_at' => now(),
        ]);
    }

    private function logFailure(ElectronicDocument $document, string $operation, HaciendaApiException $e): void
    {
        $document->exchanges()->create([
            'operation' => $operation,
            'http_status' => $e->status,
            'error_cause' => mb_substr($e->errorCause ?? $e->getMessage(), 0, 4000),
            'response_headers' => $e->headers,
            'response_body' => $e->body === null ? null : mb_substr($e->body, 0, 4000),
            'created_at' => now(),
        ]);
    }
}
