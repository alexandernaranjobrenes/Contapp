<?php

namespace App\Domains\Billing\Contracts;

use App\Domains\Billing\Services\Hacienda\HaciendaResponse;

/**
 * El API de recepción de comprobantes de Hacienda: enviar uno y consultar su
 * estado. Interfaz para poder cambiar el cliente HTTP sin tocar la máquina de
 * estados, y para que las pruebas no dependan de la red.
 *
 * El token lo pone quien llama (HaciendaTokenProvider): es de cada compañía.
 */
interface HaciendaTransport
{
    /**
     * POST /recepcion. Una respuesta 2xx es «recibido»; un 4xx o un problema
     * de red lanza HaciendaApiException.
     *
     * @param  array<string, mixed>  $payload
     */
    public function submit(string $environment, string $accessToken, array $payload): HaciendaResponse;

    /**
     * GET /recepcion/{clave}. Un 404 NO lanza: es la respuesta «Hacienda no
     * tiene esa clave», que decide si se puede reenviar.
     */
    public function status(string $environment, string $accessToken, string $clave): HaciendaResponse;
}
