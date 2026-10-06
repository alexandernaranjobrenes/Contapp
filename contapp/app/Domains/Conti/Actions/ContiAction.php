<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Core\Models\Company;
use App\Models\User;

/**
 * Algo que Conti puede guardar (ContiActionCatalog).
 *
 * Nunca se guarda en el momento: Conti lo prepara (prepare, que valida y
 * resuelve los códigos), la persona lo revisa en CONTAPP y, si lo confirma,
 * recién ahí se ejecuta (execute) —con sus permisos de ese momento y los
 * mismos servicios y reglas que usa la pantalla correspondiente—.
 */
interface ContiAction
{
    /** La clave con la que el agente la pide: «crear_socio». */
    public function key(): string;

    /** «Crear un socio de negocio». */
    public function label(): string;

    /** Qué hace y cuándo usarla, para el agente. */
    public function description(): string;

    /** La pantalla del menú que pide con Lectura y escritura. */
    public function screen(): string;

    /** @return array<string, string> campo => qué acepta */
    public function fields(): array;

    /**
     * Valida y resuelve lo que mandó el agente. Corta con 422 si algo no
     * sirve, diciendo qué.
     */
    public function prepare(array $input, Company $company, User $user): PreparedAction;

    /** Lo guarda. Corre dentro de una transacción. */
    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult;
}
