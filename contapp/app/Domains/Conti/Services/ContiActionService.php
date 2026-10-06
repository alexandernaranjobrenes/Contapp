<?php

namespace App\Domains\Conti\Services;

use App\Domains\Conti\Actions\ContiAction;
use App\Domains\Conti\Actions\ContiActionCatalog;
use App\Domains\Conti\Actions\PreparedAction;
use App\Domains\Conti\Models\ContiAction as PendingAction;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Services\ScreenAccessService;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Lo que Conti guarda, en dos tiempos (CLAUDE.md secc. 32):
 *
 * 1. Preparar (la herramienta «preparar_accion» de Conti): valida, resuelve
 *    los códigos y lo prueba en seco —corre la operación de verdad dentro de
 *    una transacción que se deshace—, así un período cerrado o un asiento
 *    que no cuadra se dicen antes de molestar a la persona. Queda pendiente,
 *    sin escribir nada.
 * 2. Confirmar (CONTAPP, con la sesión de la persona): vuelve a revisar sus
 *    permisos y la licencia, lo prepara de nuevo con los datos de ese momento
 *    y, solo si sale exactamente lo mismo que se le mostró, lo guarda.
 *
 * El modelo nunca puede confirmar: no tiene ninguna herramienta para eso.
 * Solo el clic de la persona en la pantalla de confirmación guarda.
 */
class ContiActionService
{
    public const TTL_MINUTES = 30;

    public const GRACE_MESSAGE = 'La licencia de tu compañía está vencida: se puede consultar, pero no guardar nada hasta renovarla.';

    public function prepare(ContiContext $context, string $key, array $input): PendingAction
    {
        $action = ContiActionCatalog::find($key)
            ?? abort(404, "No existe la acción «{$key}». Consultá el catálogo para ver las disponibles.");

        $context->authorize($action->screen(), 'read_write');

        if ($context->inGrace()) {
            abort(403, self::GRACE_MESSAGE);
        }

        $prepared = $action->prepare($input, $context->company(), $context->user());

        $this->dryRun($action, $prepared, $context->company(), $context->user());

        return PendingAction::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $context->company()->id,
            'user_id' => $context->user()->id,
            'action' => $action->key(),
            'input' => $prepared->input,
            'summary' => $prepared->summary(),
            'payload_hash' => $prepared->payloadHash(),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    /**
     * La persona confirmó en CONTAPP. Devuelve la acción con su estado final
     * (guardada o fallida, con el motivo). Corta con 403 si hoy ya no puede.
     */
    public function confirm(PendingAction $pending, User $user): PendingAction
    {
        $this->assertOwnedAndHere($pending, $user);

        if (! $pending->isActionable()) {
            return $pending;
        }

        $action = ContiActionCatalog::find($pending->action) ?? abort(404);
        $company = Company::findOrFail($pending->company_id);

        if (! app(ScreenAccessService::class)->allows($user, $company->id, $action->screen(), 'read_write')) {
            abort(403, 'Ya no tenés permiso para esto: hace falta Lectura y escritura en «'.ContiContext::screenPath($action->screen()).'».');
        }

        if (app(CurrentCompany::class)->isInGracePeriod()) {
            abort(403, self::GRACE_MESSAGE);
        }

        try {
            DB::transaction(function () use ($pending, $action, $company, $user) {
                $locked = PendingAction::whereKey($pending->id)->lockForUpdate()->first();

                // Otro clic llegó primero.
                if (! $locked->isActionable()) {
                    return;
                }

                $prepared = $action->prepare($locked->input, $company, $user);

                if (! hash_equals($locked->payload_hash, $prepared->payloadHash())) {
                    throw new \RuntimeException('Los datos cambiaron desde que Conti lo preparó, así que no se guardó para no hacer algo distinto de lo que revisaste. Pedile a Conti que lo prepare de nuevo.');
                }

                $result = $action->execute($prepared, $company, $user);

                $locked->update([
                    'status' => 'confirmed',
                    'result' => $result->toArray(),
                    'decided_at' => now(),
                ]);
            });
        } catch (ValidationException|HttpExceptionInterface|\RuntimeException|\InvalidArgumentException|\DomainException $e) {
            $pending->update([
                'status' => 'failed',
                'error' => self::messageFor($e),
                'decided_at' => now(),
            ]);
        }

        return $pending->fresh();
    }

    public function discard(PendingAction $pending, User $user): PendingAction
    {
        $this->assertOwnedAndHere($pending, $user);

        if ($pending->isActionable()) {
            $pending->update(['status' => 'discarded', 'decided_at' => now()]);
        }

        return $pending->fresh();
    }

    /** Lo que se le cuenta al agente de una acción. */
    public static function present(PendingAction $pending): array
    {
        $status = $pending->effectiveStatus();

        return [
            'id' => $pending->uuid,
            'accion' => $pending->action,
            'estado' => match ($status) {
                'pending' => 'pendiente',
                'confirmed' => 'guardado',
                'discarded' => 'descartado',
                'failed' => 'no_se_pudo_guardar',
                default => 'vencido',
            },
            'estado_texto' => PendingAction::STATUSES[$status] ?? $status,
            'resumen' => $pending->summary,
            // Una ruta de CONTAPP: el chat la abre ahí mismo, sin recargar.
            'enlace_para_confirmar' => $status === 'pending' ? route('conti.actions.show', $pending->uuid, false) : null,
            'vence' => $pending->localTime($pending->expires_at),
            'resultado' => $pending->result,
            'error' => $pending->error,
        ];
    }

    /** Una persona solo ve y decide lo suyo, y en la compañía en la que está. */
    private function assertOwnedAndHere(PendingAction $pending, User $user): void
    {
        abort_unless($pending->user_id === $user->id, 404);

        if ($pending->company_id !== app(CurrentCompany::class)->id()) {
            $name = $pending->company?->trade_name ?: $pending->company?->legal_name;

            abort(409, "Esto es de {$name}: cambiá a esa compañía para confirmarlo o descartarlo.");
        }
    }

    private function dryRun(ContiAction $action, PreparedAction $prepared, Company $company, User $user): void
    {
        DB::beginTransaction();

        try {
            $action->execute($prepared, $company, $user);
        } catch (ValidationException|HttpExceptionInterface|QueryException $e) {
            throw $e;
        } catch (\RuntimeException|\InvalidArgumentException|\DomainException $e) {
            // Una regla del negocio (período cerrado, no cuadra, sin tipo de
            // cambio…): se le dice al agente, que se lo explica a la persona.
            throw ValidationException::withMessages(['accion' => self::messageFor($e)]);
        } finally {
            DB::rollBack();
        }
    }

    private static function messageFor(Throwable $e): string
    {
        // Un error de la base no se le muestra tal cual a nadie.
        if ($e instanceof QueryException) {
            report($e);

            return 'Ocurrió un error en la base de datos y no se guardó. Si se repite, avisale al equipo de CONTAPP.';
        }

        if ($e instanceof ValidationException) {
            return collect($e->errors())->flatten()->implode(' ');
        }

        return trim($e->getMessage()) !== '' ? $e->getMessage() : 'No se pudo guardar.';
    }
}
