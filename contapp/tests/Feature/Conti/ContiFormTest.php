<?php

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Conti\Actions\ContiActionCatalog;
use App\Domains\Conti\Models\ContiAction;
use App\Domains\Conti\Services\ContiFormService;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Conti\Support\ContiHistory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Formularios, preguntas con opciones y la ventana para confirmar
|--------------------------------------------------------------------------
|
| Para registrar o editar, Conti muestra el formulario de la acción en el
| chat con lo que ya sabe; al enviarlo se prepara (las mismas validaciones
| de siempre) y CONTAPP abre la ventana para confirmar. Para que la persona
| elija, le hace preguntas con opciones. Ni el formulario ni las preguntas
| las ve el modelo: van directo al chat.
|
*/

const CENTRO_VEN = ['codigo' => 'VEN', 'nombre' => 'Ventas'];

it('Conti muestra el formulario con lo que sabe precargado, y termina su turno', function () {
    configureOpenAi();
    $f = contiLicensed();
    Http::fake([OPENAI_URL => Http::sequence()->push(openAiReply('Te dejo el formulario.', [['formulario', ['accion' => 'crear_centro_costo', 'datos' => CENTRO_VEN]]]))]);

    $response = sendToConti($this, $f, 'Creá el centro de costo VEN, Ventas')->assertOk()
        ->assertJsonPath('respuesta', 'Te dejo el formulario.')
        ->assertJsonPath('interaccion.tipo', 'formulario')
        ->assertJsonPath('interaccion.formulario.accion', 'crear_centro_costo')
        ->assertJsonPath('interaccion.formulario.valores.codigo', 'VEN')
        ->assertJsonPath('interaccion.formulario.valores.vigente_desde', now('America/Costa_Rica')->format('Y-m-d'))
        ->assertJsonPath('interaccion.formulario.valores.activo', true);

    expect(collect($response->json('interaccion.formulario.campos'))->firstWhere('campo', 'activo')['tipo'])->toBe('si_no');
    // El formulario no vuelve al modelo: una sola llamada.
    Http::assertSentCount(1);
    expect(ContiHistory::get($f['user']->id, $f['company']->id, 'abc')[1]['content'])->toContain('(Le mostré el formulario «Crear un centro de costo»');
});

it('Conti hace preguntas con opciones y termina su turno', function () {
    configureOpenAi();
    $f = contiLicensed();
    Http::fake([OPENAI_URL => Http::sequence()->push(openAiReply(null, [['preguntar', ['preguntas' => [
        ['pregunta' => '¿De qué período?', 'encabezado' => 'Período', 'opciones' => [['etiqueta' => 'Octubre', 'descripcion' => 'El mes en curso'], 'Setiembre']],
        ['pregunta' => '¿Qué querés ver?', 'multiple' => true, 'opciones' => ['Ingresos', 'Gastos', 'Utilidad']],
    ]]]]))]);

    sendToConti($this, $f, '¿Cómo vamos?')->assertOk()
        ->assertJsonPath('interaccion.tipo', 'preguntas')
        ->assertJsonPath('interaccion.preguntas.0.opciones.0', ['etiqueta' => 'Octubre', 'descripcion' => 'El mes en curso'])
        ->assertJsonPath('interaccion.preguntas.0.opciones.1.etiqueta', 'Setiembre')
        ->assertJsonPath('interaccion.preguntas.1.multiple', true);

    Http::assertSentCount(1);
    expect(ContiHistory::get($f['user']->id, $f['company']->id, 'abc')[1]['content'])
        ->toContain('(Le pregunté: ¿De qué período? Opciones: Octubre / Setiembre');
});

it('unas preguntas mal armadas vuelven al modelo como error, y sigue', function () {
    configureOpenAi();
    $f = contiLicensed();
    Http::fake([OPENAI_URL => Http::sequence()
        ->push(openAiReply(null, [['preguntar', ['preguntas' => [['pregunta' => '¿Cuál?', 'opciones' => ['Una sola']]]]]]))
        ->push(openAiReply('Te respondo sin preguntar.'))]);

    sendToConti($this, $f)->assertOk()
        ->assertJsonPath('respuesta', 'Te respondo sin preguntar.')
        ->assertJsonPath('interaccion', null);

    Http::assertSentCount(2);
});

it('lo que Conti prepara llega al chat para abrir la ventana, y al modelo no le llega ningún enlace', function () {
    configureOpenAi();
    $f = contiLicensed();
    Http::fake([OPENAI_URL => Http::sequence()
        ->push(openAiReply(null, [['preparar_accion', ['accion' => 'crear_centro_costo', 'datos' => CENTRO_VEN]]]))
        ->push(openAiReply('Listo: revisalo en la ventana y confirmalo.'))]);

    $response = sendToConti($this, $f)->assertOk();
    $pending = ContiAction::sole();

    expect($response->json('acciones'))->toBe([['id' => $pending->uuid, 'titulo' => $pending->summary['titulo']]])
        ->and($pending->status)->toBe('pending');

    Http::assertSent(function (Request $request) {
        $last = collect($request['messages'])->last();

        return ($last['role'] ?? null) !== 'tool' || ! str_contains($last['content'], '/conti/acciones/');
    });
});

it('sin permiso de escritura, el formulario vuelve como error al modelo', function () {
    $f = contiLicensed();
    ['user' => $reader] = contiUser(['accounting.cost_centers' => 'read'], $f['company']);

    $result = contiTools($reader, $f['company'])->run('formulario', ['accion' => 'crear_centro_costo', 'datos' => CENTRO_VEN]);

    expect($result['codigo'])->toBe(403)
        ->and($result)->not->toHaveKey('interaccion');
});

it('enviar el formulario prepara la acción, con las mismas validaciones, y lo deja en el hilo', function () {
    configureOpenAi();
    $f = contiLicensed();

    $response = $this->actingAs($f['user'])->postJson(route('conti.forms.submit'), [
        'accion' => 'crear_centro_costo',
        'datos' => [...CENTRO_VEN, 'vigente_desde' => '2026-10-01', 'vigente_hasta' => '', 'activo' => false],
        'sesion' => 'abc',
    ])->assertCreated();

    $pending = ContiAction::sole();
    expect($response->json('accion'))->toBe(['id' => $pending->uuid, 'titulo' => $pending->summary['titulo']])
        ->and($pending->status)->toBe('pending')
        ->and($pending->input['activo'])->toBe('no')
        ->and($pending->input)->not->toHaveKey('vigente_hasta');

    $history = ContiHistory::get($f['user']->id, $f['company']->id, 'abc');
    expect($history[0]['content'])->toContain('(Completé el formulario')
        ->and($history[1]['content'])->toContain($pending->uuid);

    // Lo que no sirve vuelve campo por campo, y no se prepara nada.
    $this->actingAs($f['user'])->postJson(route('conti.forms.submit'), ['accion' => 'crear_centro_costo', 'datos' => ['nombre' => 'Sin código']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['codigo']);

    expect(ContiAction::count())->toBe(1);
});

it('el formulario no se envía sin permiso, sin Conti o con la licencia vencida', function () {
    configureOpenAi();
    $f = contiLicensed();
    ['user' => $reader] = contiUser(['accounting.cost_centers' => 'read'], $f['company']);
    $send = fn ($user) => $this->actingAs($user)->postJson(route('conti.forms.submit'), ['accion' => 'crear_centro_costo', 'datos' => CENTRO_VEN]);

    $send($reader)->assertForbidden();

    $f['license']->update(['ai_enabled' => false]);
    $send($f['user'])->assertForbidden();

    // Vencida: modo de gracia, se consulta pero no se guarda.
    $f['license']->update(['ai_enabled' => true, 'expires_at' => now()->subDay()->format('Y-m-d')]);
    $send($f['user'])->assertForbidden();

    expect(ContiAction::count())->toBe(0);
});

it('las sugerencias de un campo salen de las consultas de Conti, con los permisos de la persona', function () {
    configureOpenAi();
    $f = contiLicensed();
    ChartOfAccount::factory()->create(['company_id' => $f['company']->id, 'code' => '1-01-01-001', 'description_es' => 'Caja general', 'account_type' => 'asset', 'accepts_posting' => true]);
    ChartOfAccount::factory()->create(['company_id' => $f['company']->id, 'code' => '1-01', 'description_es' => 'Caja y bancos', 'account_type' => 'asset', 'accepts_posting' => false]);

    $response = $this->actingAs($f['user'])
        ->getJson(route('conti.forms.options', ['fuente' => 'cuentas', 'q' => 'Caja', 'filtros' => ['acepta_movimientos' => 'sí']]))
        ->assertOk();

    expect($response->json('opciones'))->toBe([['valor' => '1-01-01-001', 'etiqueta' => 'Caja general · Activos']]);

    ['user' => $stranger] = contiUser(['business_partners.partners' => 'read'], $f['company']);
    $this->actingAs($stranger)->getJson(route('conti.forms.options', ['fuente' => 'cuentas', 'q' => 'Caja']))->assertForbidden();
    $this->actingAs($f['user'])->getJson(route('conti.forms.options', ['fuente' => 'usuarios']))->assertStatus(422);
});

it('«Corregir» descarta lo preparado y lo devuelve como formulario con los mismos datos', function () {
    configureOpenAi();
    $f = contiLicensed();
    $uuid = contiPrepare($f, 'crear_centro_costo', CENTRO_VEN)->json('id');

    $this->actingAs($f['user'])->postJson(route('conti.actions.revise', $uuid))->assertOk()
        ->assertJsonPath('formulario.accion', 'crear_centro_costo')
        ->assertJsonPath('formulario.valores.codigo', 'VEN')
        ->assertJsonPath('formulario.valores.nombre', 'Ventas');

    expect(ContiAction::sole()->status)->toBe('discarded');

    $this->actingAs($f['user'])->postJson(route('conti.actions.revise', $uuid))->assertStatus(409);

    ['user' => $other] = contiUser(['accounting.cost_centers' => 'read_write'], $f['company']);
    $this->actingAs($other)->postJson(route('conti.actions.revise', $uuid))->assertNotFound();
});

it('cada acción tiene su formulario, con los mismos campos que pide', function () {
    $f = contiLicensed();
    $context = app(ContiContext::class);
    contiTools($f['user'], $f['company']);

    foreach (ContiActionCatalog::all() as $action) {
        $form = app(ContiFormService::class)->build($context, $action->key());

        expect(array_column($form['campos'], 'campo'))->toEqualCanonicalizing(array_keys($action->fields()), $action->key());

        foreach ($form['campos'] as $field) {
            if ($field['tipo'] === 'buscar') {
                expect(ContiFormService::SOURCES)->toHaveKey($field['fuente']);
            }
            if ($field['tipo'] === 'lineas') {
                expect($field['columnas'])->not->toBeEmpty()
                    ->and(count($form['valores'][$field['campo']]))->toBe((int) $field['minimo']);
            }
        }
    }
});

it('lo enviado llega como lo espera la acción: sí/no como texto, sin vacíos ni renglones vacíos', function () {
    $f = contiLicensed();

    $data = app(ContiFormService::class)->submission('crear_orden_compra', [
        'proveedor' => 'P-001',
        'fecha' => '2026-10-07',
        'fecha_esperada' => '',
        'campo_inventado' => 'x',
        'lineas' => [
            ['articulo' => 'A-1', 'cantidad' => 2, 'costo' => '', 'almacen' => ''],
            ['articulo' => '', 'cantidad' => '', 'costo' => '', 'almacen' => ''],
        ],
    ], $f['company']);

    expect($data)->toBe(['proveedor' => 'P-001', 'fecha' => '2026-10-07', 'lineas' => [['articulo' => 'A-1', 'cantidad' => '2']]]);

    expect(app(ContiFormService::class)->submission('crear_cuenta', ['activa' => false, 'monetaria' => true, 'exige_socio' => ''], $f['company']))
        ->toBe(['monetaria' => 'sí', 'activa' => 'no']);
});

it('si piden registrar algo, en la primera vuelta el modelo tiene que usar una herramienta', function () {
    configureOpenAi();
    $f = contiLicensed();
    Http::fake([OPENAI_URL => Http::response(openAiReply('Hola.'))]);

    sendToConti($this, $f, 'Registrá una cuenta contable')->assertOk();
    sendToConti($this, $f, 'Hola, ¿cómo estás?')->assertOk();

    $choices = Http::recorded()->map(fn ($pair) => $pair[0]['tool_choice'])->all();
    expect($choices)->toBe(['required', 'auto']);

    // Quien no puede registrar nada: sin obligarlo.
    ['user' => $reader] = contiUser(['accounting.cost_centers' => 'read'], $f['company']);
    sendToConti($this, ['user' => $reader], 'Registrá un centro de costo')->assertOk();
    expect(Http::recorded()->last()[0]['tool_choice'])->toBe('auto');
});

it('el formulario llega con sugerencias según cómo se viene trabajando, y se recalculan', function () {
    configureOpenAi();
    $f = contiLicensed();
    $receivables = ChartOfAccount::factory()->create(['company_id' => $f['company']->id, 'code' => '1-01-02-001', 'description_es' => 'Clientes locales', 'account_type' => 'asset', 'accepts_posting' => true]);
    ChartOfAccount::factory()->create(['company_id' => $f['company']->id, 'code' => '6-01-007', 'description_es' => 'Alquileres', 'account_type' => 'expense', 'accepts_posting' => true]);
    foreach (['CLI-001', 'CLI-002'] as $code) {
        BusinessPartner::factory()->create(['company_id' => $f['company']->id, 'code' => $code, 'type' => 'client', 'gl_account_id' => $receivables->id, 'payment_terms_days' => 30]);
    }
    contiTools($f['user'], $f['company']);
    $forms = app(ContiFormService::class);

    $partner = $forms->build(app(ContiContext::class), 'crear_socio', ['tipo' => 'cliente', 'nombre' => 'Ferretería Central']);
    // «cliente», como lo dice la persona, es la opción «client» de la lista.
    expect($partner['valores']['tipo'])->toBe('client')
        ->and($partner['valores']['codigo'])->toBe('CLI-003')
        ->and($partner['valores']['cuenta_control'])->toBe('1-01-02-001')
        ->and($partner['valores']['nombre'])->toBe('Ferretería Central')
        ->and($partner['sugeridos']['cuenta_control']['motivo'])->toBe('La usan 2 de los 2 clientes: 1-01-02-001 Clientes locales.')
        ->and($partner['sugeridos'])->not->toHaveKey('nombre');

    // Lo que dijo la persona no se pisa.
    $given = $forms->build(app(ContiContext::class), 'crear_socio', ['tipo' => 'cliente', 'codigo' => 'C-900']);
    expect($given['valores']['codigo'])->toBe('C-900')
        ->and($given['sugeridos'])->not->toHaveKey('codigo');

    // Al elegir la clase de una cuenta, el código que sigue en esa clase.
    $this->actingAs($f['user'])->postJson(route('conti.forms.suggest'), ['accion' => 'crear_cuenta', 'valores' => ['clase' => 'expense']])
        ->assertOk()
        ->assertJsonPath('sugeridos.codigo.valor', '6-01-008');

    $this->actingAs($f['user'])->postJson(route('conti.forms.suggest'), ['accion' => 'crear_cuenta', 'valores' => []])
        ->assertOk()
        ->assertExactJson(['sugeridos' => []]);
});
