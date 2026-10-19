<?php

use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Services\Hacienda\HaciendaSubmissionService;
use App\Jobs\DeliverElectronicDocumentJob;
use App\Jobs\SubmitElectronicDocumentJob;
use App\Mail\ElectronicDocumentMail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

/*
| El envío a Hacienda y el seguimiento de su estado, contra un Hacienda
| simulado. Ninguna prueba sale a la red (fakeHacienda: preventStrayRequests).
|
| Lo central: una venta nunca termina con dos comprobantes. Ante la duda se
| consulta antes de reenviar, y una clave procesada no se reenvía.
*/

const RECEPCION = 'api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1/recepcion';

beforeEach(function () {
    Queue::fake();
    Mail::fake();

    $this->f = enableHacienda(salesFixture());
    $this->document = electronicSale($this->f);
    $this->electronic = electronicOf($this->document);
    $this->service = app(HaciendaSubmissionService::class);
});

it('al emitir, el envío se encola después del commit', function () {
    Queue::assertPushed(SubmitElectronicDocumentJob::class, fn ($job) => $job->electronicDocumentId === $this->electronic->id);
});

it('envía el comprobante con el cuerpo que pide Hacienda y queda en proceso', function () {
    fakeHacienda([RECEPCION => Http::response(null, 202)]);

    $this->service->submit($this->electronic);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/recepcion')
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer token-de-prueba')
        && $request['clave'] === $this->electronic->clave
        && $request['emisor'] === ['tipoIdentificacion' => '02', 'numeroIdentificacion' => '3101123456']
        && $request['receptor'] === ['tipoIdentificacion' => '02', 'numeroIdentificacion' => '3101654321']
        && str_ends_with($request['fecha'], '-06:00')
        && base64_decode($request['comprobanteXml']) === $this->electronic->signed_xml);

    $electronic = $this->electronic->fresh();

    expect($electronic->status)->toBe(ElectronicDocument::SUBMITTED)
        ->and($electronic->submit_attempts)->toBe(1)
        ->and($electronic->next_attempt_at)->not->toBeNull()
        ->and($electronic->exchanges()->where('operation', 'submit')->value('http_status'))->toBe(202);
});

it('aceptado: guarda la respuesta de Hacienda y le envía al cliente el XML, la respuesta y el PDF', function () {
    $clave = $this->electronic->clave;

    fakeHacienda([
        RECEPCION.'/'.$clave => Http::response(haciendaStatus($clave, 'aceptado', 'Este comprobante fue aceptado')),
        RECEPCION => Http::response(null, 201),
    ]);

    $this->service->submit($this->electronic);
    $this->service->poll($this->electronic->fresh());

    $electronic = $this->electronic->fresh();

    expect($electronic->status)->toBe(ElectronicDocument::ACCEPTED)
        ->and($electronic->hacienda_message)->toBe('Este comprobante fue aceptado')
        ->and($electronic->response_xml)->toContain('<Mensaje>1</Mensaje>')
        ->and($electronic->delivery_status)->toBe('pending');

    Queue::assertPushed(DeliverElectronicDocumentJob::class);

    (new DeliverElectronicDocumentJob($electronic->id))->handle(app(App\Domains\Core\Support\CurrentCompany::class));

    Mail::assertSent(ElectronicDocumentMail::class, fn ($mail) => $mail->hasTo('facturas@cliente.cr'));

    expect($electronic->fresh()->delivery_status)->toBe('sent');
});

it('rechazado: queda rechazado con el detalle de Hacienda, y se puede volver a emitir', function () {
    $clave = $this->electronic->clave;

    fakeHacienda([
        RECEPCION.'/'.$clave => Http::response(haciendaStatus($clave, 'rechazado', 'El código de actividad no está inscrito')),
        RECEPCION => Http::response(null, 202),
    ]);

    $this->service->submit($this->electronic);
    $this->service->poll($this->electronic->fresh());

    $electronic = $this->electronic->fresh();

    expect($electronic->status)->toBe(ElectronicDocument::REJECTED)
        ->and($electronic->hacienda_message)->toBe('El código de actividad no está inscrito')
        ->and($electronic->canBeReissued())->toBeTrue();

    Queue::assertNotPushed(DeliverElectronicDocumentJob::class);
});

it('un 400 se lee de X-Error-Cause y deja el comprobante como no recibido (L18)', function () {
    fakeHacienda([RECEPCION => Http::response('', 400, ['X-Error-Cause' => 'La firma del comprobante electrónico no es válida'])]);

    $this->service->submit($this->electronic);

    $electronic = $this->electronic->fresh();

    expect($electronic->status)->toBe(ElectronicDocument::SUBMIT_FAILED)
        ->and($electronic->hacienda_message)->toBe('La firma del comprobante electrónico no es válida')
        ->and($electronic->exchanges()->value('error_cause'))->toBe('La firma del comprobante electrónico no es válida');
});

it('si Hacienda dice que ya lo había recibido, no lo vuelve a enviar: consulta su estado (L16)', function () {
    fakeHacienda([RECEPCION => Http::response('', 400, ['X-Error-Cause' => 'El comprobante ya fue recibido anteriormente'])]);

    $this->service->submit($this->electronic);

    expect($this->electronic->fresh()->status)->toBe(ElectronicDocument::SUBMIT_UNKNOWN);
});

it('ante un tiempo agotado, consulta antes de reenviar: si Hacienda lo tiene, no hay un segundo POST (A5)', function () {
    $clave = $this->electronic->clave;
    $posts = 0;

    fakeHacienda([
        RECEPCION.'/'.$clave => Http::response(haciendaStatus($clave, 'procesando')),
        RECEPCION => function () use (&$posts) {
            $posts++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        },
    ]);

    $this->service->submit($this->electronic);
    expect($this->electronic->fresh()->status)->toBe(ElectronicDocument::SUBMIT_UNKNOWN);

    $this->service->submit($this->electronic->fresh());

    expect($posts)->toBe(1)
        ->and($this->electronic->fresh()->status)->toBe(ElectronicDocument::SUBMITTED);
});

it('ante un tiempo agotado, si Hacienda no tiene la clave, reenvía la MISMA clave', function () {
    $clave = $this->electronic->clave;
    $sent = [];

    fakeHacienda([
        RECEPCION.'/'.$clave => Http::response(null, 404),
        RECEPCION => function (Request $request) use (&$sent) {
            $sent[] = $request['clave'];

            return count($sent) === 1
                ? throw new ConnectionException('cURL error 28: Operation timed out')
                : Http::response(null, 202);
        },
    ]);

    $this->service->submit($this->electronic);
    $this->service->submit($this->electronic->fresh());

    expect($sent)->toBe([$clave, $clave])
        ->and($this->electronic->fresh()->status)->toBe(ElectronicDocument::SUBMITTED);
});

it('un 401 pide un token nuevo una sola vez y reenvía', function () {
    $responses = [Http::response(null, 401), Http::response(null, 202)];

    fakeHacienda([RECEPCION => function () use (&$responses) {
        return array_shift($responses);
    }]);

    $this->service->submit($this->electronic);

    expect($this->electronic->fresh()->status)->toBe(ElectronicDocument::SUBMITTED);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'idp.') && $request['grant_type'] === 'password');
});

it('credenciales rechazadas por el IDP: requiere atención, sin haber enviado nada', function () {
    Http::preventStrayRequests();
    Http::fake([
        'idp.comprobanteselectronicos.go.cr/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid user credentials'], 401),
    ]);

    $this->service->submit($this->electronic);

    $electronic = $this->electronic->fresh();

    expect($electronic->status)->toBe(ElectronicDocument::NEEDS_ATTENTION)
        ->and($electronic->hacienda_message)->toContain('usuario o la contraseña');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/recepcion'));
});

it('reutiliza el token mientras está vigente y lo guarda cifrado', function () {
    fakeHacienda([RECEPCION.'/*' => Http::response(haciendaStatus($this->electronic->clave, 'procesando')), RECEPCION => Http::response(null, 202)]);

    $this->service->submit($this->electronic);
    $this->service->poll($this->electronic->fresh());

    $tokenRequests = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'idp.'))->count();
    $raw = DB::table('hacienda_credentials')->where('id', $this->f['credential']->id)->value('access_token');

    expect($tokenRequests)->toBe(1)
        ->and($raw)->not->toBeNull()
        ->and($raw)->not->toContain('token-de-prueba');
});

it('«procesando» se consulta con espera creciente y, agotada, pasa a requerir atención (A7)', function () {
    // Al segundo exacto: la base guarda next_attempt_at sin microsegundos.
    $this->freezeSecond();

    fakeHacienda([RECEPCION.'/*' => Http::response(haciendaStatus($this->electronic->clave, 'procesando')), RECEPCION => Http::response(null, 202)]);

    $this->service->submit($this->electronic);

    $delays = [];

    foreach (range(1, count(config('hacienda.poll_backoff')) + 2) as $ignored) {
        $before = now();
        $this->service->poll($this->electronic->fresh());
        $electronic = $this->electronic->fresh();

        if ($electronic->status !== ElectronicDocument::SUBMITTED) {
            break;
        }

        $delays[] = (int) round($before->diffInSeconds($electronic->next_attempt_at));
    }

    expect($this->electronic->fresh()->status)->toBe(ElectronicDocument::NEEDS_ATTENTION)
        ->and($delays)->toBe(array_slice(config('hacienda.poll_backoff'), 1));
});

it('una transición imposible lanza en vez de ignorarse (A6)', function () {
    $this->electronic->update(['status' => ElectronicDocument::ACCEPTED]);

    $this->electronic->fresh()->transitionTo(ElectronicDocument::SUBMITTING);
})->throws(App\Domains\Billing\Exceptions\InvalidElectronicTransitionException::class);

it('cada cambio de estado queda en la historia del comprobante', function () {
    fakeHacienda([RECEPCION => Http::response(null, 202)]);

    $this->service->submit($this->electronic);

    expect($this->electronic->events()->pluck('to_status')->all())
        ->toBe([ElectronicDocument::SIGNED, ElectronicDocument::SUBMITTING, ElectronicDocument::SUBMITTED]);
});

it('el programador despacha lo que toca: los firmados y los que tienen consulta vencida', function () {
    Queue::fake();

    $this->artisan('contapp:hacienda-sync')->assertSuccessful();

    Queue::assertPushed(SubmitElectronicDocumentJob::class, fn ($job) => $job->electronicDocumentId === $this->electronic->id);
});
