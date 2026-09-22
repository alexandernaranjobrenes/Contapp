<?php

use App\Domains\Billing\Models\PriceOverrideAuthorization;
use App\Domains\Billing\Models\SalesDocument;
use App\Domains\Core\Models\Role;
use App\Domains\Core\Models\UserRole;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\PriceListItem;
use App\Models\User;

/**
 * Compañía facturable con lista de precios predeterminada: el artículo vale
 * 2.500 y la factura de referencia lleva 10 unidades.
 *
 * El fixture se arma acá y no se toma prestado de BillingHttpTest: los
 * helpers de Pest viven en un namespace global compartido, así que llamarlo
 * desde otro archivo funciona solo cuando los dos se cargan en la misma
 * corrida — y este archivo tiene que poder correrse solo.
 */
function overrideFixture(): array
{
    $f = salesFixture();

    logInAsCompanyUser($f['company']);

    $f['salesType'] = App\Domains\Core\Models\DocumentType::factory()->create([
        'company_id' => $f['company']->id, 'code' => 'FVE-P', 'origin_module' => 'ventas',
    ]);

    $list = PriceList::factory()->create([
        'company_id' => $f['company']->id,
        'code' => 'PUB',
        'currency_id' => $f['company']->local_currency_id,
        'is_default' => true,
    ]);

    PriceListItem::factory()->create([
        'price_list_id' => $list->id,
        'item_id' => $f['item']->id,
        'unit_price' => 2500,
    ]);

    return [...$f, 'list' => $list];
}

/**
 * Un usuario de esta compañía con el tipo de rol indicado.
 *
 * Lleva acceso a todos los módulos a propósito: lo que estas pruebas
 * ejercitan es el control de PRECIO, no el de módulos. Sin el acceso, el
 * middleware cortaría la request con 403 y el guard ni siquiera correría —
 * la prueba pasaría por el motivo equivocado.
 */
function overrideUser(array $f, string $roleType, string $password = 'clave-buena'): User
{
    $user = User::factory()->create([
        'default_company_id' => $f['company']->id,
        'password' => bcrypt($password),
    ]);

    $f['company']->users()->attach($user->id, ['is_default' => true]);
    grantAllModuleAccess($user, $f['company']);

    $role = Role::create([
        'company_id' => $f['company']->id,
        'name' => ucfirst($roleType),
        'type' => $roleType,
        'can_grant_permissions' => false,
    ]);

    UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'company_id' => $f['company']->id]);

    return $user;
}

/**
 * El payload base de una factura, propio de este archivo por la misma razón
 * que el fixture.
 */
function overridePayload(array $f): array
{
    return [
        'document_type_id' => $f['salesType']->id,
        'fiscal_document_type' => '01',
        'business_partner_id' => $f['customer']->id,
        'currency_id' => $f['company']->local_currency_id,
        'exchange_rate' => 1,
        'sale_condition' => '02',
        'credit_term_days' => 30,
        'document_date' => now()->format('Y-m-d'),
        'posting_date' => now()->format('Y-m-d'),
        'lines' => [[
            'cabys_code' => '2310110000000',
            'description' => 'Producto de prueba',
            'unit_code' => 'Unid',
            'quantity' => 10,
            'unit_price' => 2500,
            'item_id' => $f['item']->id,
            'warehouse_id' => $f['warehouse']->id,
            'item_code' => 'ART-1',
            'is_service' => false,
            'taxes' => [['tax_code' => '01', 'iva_rate_code' => '08']],
        ]],
    ];
}

/**
 * Emite con el precio indicado (y descuento opcional), más lo que se le pase
 * para la autorización.
 */
function emitAt(array $f, $unitPrice, array $extra = [], $discount = 0)
{
    $payload = overridePayload($f);
    $payload['lines'][0]['unit_price'] = $unitPrice;
    $payload['lines'][0]['discount_amount'] = $discount;

    if ($discount > 0) {
        $payload['lines'][0]['discount_code'] = '01';
        $payload['lines'][0]['discount_reason'] = 'Descuento comercial';
    }

    return test()->post(route('sales-documents.store'), array_merge($payload, $extra));
}

// ── El precio de la lista se respeta ─────────────────────────────────────

it('emitir al precio de la lista no pide ninguna autorización', function () {
    $f = overrideFixture();

    emitAt($f, 2500)->assertSessionHasNoErrors();

    expect(SalesDocument::count())->toBe(1)
        ->and(PriceOverrideAuthorization::count())->toBe(0);
});

it('LA PRUEBA CENTRAL: un vendedor no puede bajar el precio sin autorización', function () {
    $f = overrideFixture();

    $this->actingAs(overrideUser($f, 'user'));

    emitAt($f, 2000)->assertSessionHasErrors('price_override');

    // Nada de la factura llegó a existir: el control corre ANTES de emitir.
    expect(SalesDocument::count())->toBe(0);
});

it('tampoco puede SUBIRLO sin autorización', function () {
    $f = overrideFixture();

    $this->actingAs(overrideUser($f, 'user'));

    // Facturar por encima de lo pactado también es un error contra el
    // precio acordado, y lo descubre el cliente.
    emitAt($f, 3000)->assertSessionHasErrors('price_override');

    expect(SalesDocument::count())->toBe(0);
});

it('LA PRUEBA DE LA PUERTA DE ATRÁS: el descuento no puede saltarse el control', function () {
    $f = overrideFixture();

    $this->actingAs(overrideUser($f, 'user'));

    // Precio de lista intacto, pero ₡5.000 de descuento sobre 10 unidades
    // bajan el precio neto a 2.000. Si el control mirara solo el unitario,
    // esto pasaría sin que nadie se enterara.
    emitAt($f, 2500, discount: 5000)->assertSessionHasErrors('price_override');

    expect(SalesDocument::count())->toBe(0);
});

// ── Quién puede autorizar ────────────────────────────────────────────────

it('un administrador emite el cambio directamente, sin pedirse la clave a sí mismo', function () {
    $f = overrideFixture();

    $this->actingAs(overrideUser($f, 'admin'));

    emitAt($f, 2000)->assertSessionHasNoErrors();

    // No hubo autorización de nadie más, y el comprobante ya dice quién lo
    // emitió: registrar una fila sería ruido.
    expect(SalesDocument::count())->toBe(1)
        ->and(PriceOverrideAuthorization::count())->toBe(0);
});

it('LA PRUEBA DEL MOSTRADOR: el vendedor emite con la credencial del administrador', function () {
    $f = overrideFixture();

    $vendedor = overrideUser($f, 'user');
    $admin = overrideUser($f, 'admin', 'clave-admin');

    $this->actingAs($vendedor);

    emitAt($f, 2000, [
        'price_override_email' => $admin->email,
        'price_override_password' => 'clave-admin',
        'price_override_reason' => 'Cierre de mes',
    ])->assertSessionHasNoErrors();

    $registro = PriceOverrideAuthorization::sole();

    expect(SalesDocument::count())->toBe(1)
        ->and($registro->requested_by)->toBe($vendedor->id)
        ->and($registro->authorized_by)->toBe($admin->id)
        ->and((float) $registro->list_unit_price)->toBe(2500.0)
        ->and((float) $registro->invoiced_unit_price)->toBe(2000.0)
        ->and((float) $registro->difference)->toBe(-500.0)
        ->and($registro->price_list_code)->toBe('PUB')
        ->and($registro->reason)->toBe('Cierre de mes');
});

it('otro vendedor NO puede autorizar, aunque su clave sea correcta', function () {
    $f = overrideFixture();

    $this->actingAs(overrideUser($f, 'user'));
    $companero = overrideUser($f, 'user', 'clave-companero');

    emitAt($f, 2000, [
        'price_override_email' => $companero->email,
        'price_override_password' => 'clave-companero',
    ])->assertSessionHasErrors('price_override');

    expect(SalesDocument::count())->toBe(0);
});

it('rechaza la contraseña incorrecta de un administrador real', function () {
    $f = overrideFixture();

    $this->actingAs(overrideUser($f, 'user'));
    $admin = overrideUser($f, 'admin', 'clave-admin');

    emitAt($f, 2000, [
        'price_override_email' => $admin->email,
        'price_override_password' => 'la-que-no-es',
    ])->assertSessionHasErrors('price_override');

    expect(SalesDocument::count())->toBe(0);
});

it('un administrador de OTRA compañía no puede autorizar acá', function () {
    $f = overrideFixture();

    $this->actingAs(overrideUser($f, 'user'));

    $otra = App\Domains\Core\Models\Company::factory()->create();
    $ajeno = overrideUser(['company' => $otra], 'admin', 'clave-ajena');

    emitAt($f, 2000, [
        'price_override_email' => $ajeno->email,
        'price_override_password' => 'clave-ajena',
    ])->assertSessionHasErrors('price_override');

    expect(SalesDocument::count())->toBe(0);
});

it('el mensaje de error no revela si el usuario existe ni si es administrador', function () {
    $f = overrideFixture();

    $this->actingAs(overrideUser($f, 'user'));
    $admin = overrideUser($f, 'admin', 'clave-admin');

    // Tres fallos distintos —usuario inexistente, clave mala, usuario sin
    // permiso— tienen que responder lo mismo. Mensajes distintos le dirían
    // a un vendedor curioso quiénes son administradores.
    $generico = 'No se pudo autorizar el cambio de precio: verificá el usuario y la contraseña, '.
        'y que esa persona sea administradora de esta compañía.';

    foreach ([
        ['no-existe@ejemplo.com', 'cualquiera'],
        [$admin->email, 'la-que-no-es'],
        [overrideUser($f, 'user', 'x')->email, 'x'],
    ] as [$email, $password]) {
        emitAt($f, 2000, [
            'price_override_email' => $email,
            'price_override_password' => $password,
        ])->assertSessionHasErrors(['price_override' => $generico]);
    }

    expect(SalesDocument::count())->toBe(0);
});

// ── Lo que NO se controla, y es deliberado ───────────────────────────────

it('un artículo sin precio en la lista se digita libremente', function () {
    $f = overrideFixture();

    PriceListItem::where('price_list_id', $f['list']->id)->delete();

    $this->actingAs(overrideUser($f, 'user'));

    // No hay de qué apartarse. Exigir autorización acá obligaría a tener el
    // catálogo entero con precio antes de poder facturar.
    emitAt($f, 1234)->assertSessionHasNoErrors();

    expect(SalesDocument::count())->toBe(1);
});

it('sin lista aplicable, facturar funciona como antes de que existieran', function () {
    $f = overrideFixture();

    $f['list']->update(['is_default' => false]);

    $this->actingAs(overrideUser($f, 'user'));

    emitAt($f, 999)->assertSessionHasNoErrors();

    expect(SalesDocument::count())->toBe(1);
});

it('una línea sin artículo no tiene lista contra la cual compararse', function () {
    $f = overrideFixture();

    $this->actingAs(overrideUser($f, 'user'));

    $payload = overridePayload($f);
    $payload['lines'][0]['item_id'] = null;
    $payload['lines'][0]['warehouse_id'] = null;
    $payload['lines'][0]['is_service'] = true;
    $payload['lines'][0]['unit_price'] = 777;

    $this->post(route('sales-documents.store'), $payload)->assertSessionHasNoErrors();

    expect(SalesDocument::count())->toBe(1);
});

// ── El registro ──────────────────────────────────────────────────────────

it('el precio de lista queda congelado: si la lista sube después, la autorización no cambia', function () {
    $f = overrideFixture();

    $vendedor = overrideUser($f, 'user');
    $admin = overrideUser($f, 'admin', 'clave-admin');
    $this->actingAs($vendedor);

    emitAt($f, 2000, [
        'price_override_email' => $admin->email,
        'price_override_password' => 'clave-admin',
    ]);

    // La lista sube a 3.000 al mes siguiente.
    PriceListItem::where('price_list_id', $f['list']->id)->update(['unit_price' => 3000]);

    // La autorización tiene que seguir diciendo de qué se apartó ese día.
    expect((float) PriceOverrideAuthorization::sole()->list_unit_price)->toBe(2500.0);
});

it('el registro queda enlazado a la línea del comprobante, no solo al comprobante', function () {
    $f = overrideFixture();

    $admin = overrideUser($f, 'admin', 'clave-admin');
    $this->actingAs(overrideUser($f, 'user'));

    emitAt($f, 2000, [
        'price_override_email' => $admin->email,
        'price_override_password' => 'clave-admin',
    ]);

    $registro = PriceOverrideAuthorization::sole();
    $document = SalesDocument::sole();

    expect($registro->sales_document_id)->toBe($document->id)
        ->and($registro->sales_document_line_id)->toBe($document->lines()->first()->id)
        ->and($registro->item_id)->toBe($f['item']->id);
});
// ── El registro consultable, que es el punto del control ─────────────────

it('LA PRUEBA DEL REGISTRO: la pantalla lista lo autorizado y suma la diferencia', function () {
    $f = overrideFixture();

    $vendedor = overrideUser($f, 'user');
    $admin = overrideUser($f, 'admin', 'clave-admin');
    $this->actingAs($vendedor);

    emitAt($f, 2000, [
        'price_override_email' => $admin->email,
        'price_override_password' => 'clave-admin',
        'price_override_reason' => 'Liquidación',
    ])->assertSessionHasNoErrors();

    // Un administrador revisa lo que se autorizó.
    $this->actingAs($admin);

    $this->get(route('price-overrides.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Billing/PriceOverrides/Index')
            ->has('overrides.data', 1)
            ->where('summary.count', 1)
            ->where('summary.total_difference', fn ($v) => (float) $v === -500.0)
            ->where('overrides.data.0.reason', 'Liquidación')
            ->where('overrides.data.0.authorized_by.name', $admin->name)
            ->where('overrides.data.0.requested_by.name', $vendedor->name)
        );
});

it('el registro no cruza compañías', function () {
    $f = overrideFixture();

    $admin = overrideUser($f, 'admin', 'clave-admin');
    $this->actingAs(overrideUser($f, 'user'));

    emitAt($f, 2000, [
        'price_override_email' => $admin->email,
        'price_override_password' => 'clave-admin',
    ]);

    // Otra compañía con su propio administrador no ve nada de esto.
    $otro = overrideFixture();
    $this->actingAs(overrideUser($otro, 'admin'));

    $this->get(route('price-overrides.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('overrides.data', 0)->where('summary.count', 0));
});
