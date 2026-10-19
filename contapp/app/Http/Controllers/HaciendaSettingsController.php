<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Exceptions\CertificateException;
use App\Domains\Billing\Exceptions\ElectronicInvoicingException;
use App\Domains\Billing\Exceptions\HaciendaApiException;
use App\Domains\Billing\Models\ElectronicDocument;
use App\Domains\Billing\Models\FiscalSequence;
use App\Domains\Billing\Models\HaciendaCredential;
use App\Domains\Billing\Models\HaciendaSetting;
use App\Domains\Billing\Services\Hacienda\CertificateInspector;
use App\Domains\Billing\Services\Hacienda\ElectronicInvoicing;
use App\Domains\Billing\Services\Hacienda\FiscalSequenceAllocator;
use App\Domains\Billing\Services\Hacienda\HaciendaTokenProvider;
use App\Domains\Billing\Support\CostaRicaTerritory;
use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración → Facturación electrónica (CLAUDE.md secc. 38).
 *
 * SOLO el Superusuario de la compañía: las credenciales del ATV y la llave
 * criptográfica son las de la empresa ante Hacienda, y con ellas se firma a
 * su nombre. Ni un Administrador las ve ni las cambia, y cada compañía tiene
 * las suyas (una licencia con tres compañías tiene tres juegos). Se revisa en
 * cada acción, en el servidor: la pantalla no la muestra a nadie más, pero
 * eso no es lo que protege.
 *
 * Nada secreto sale de acá: de la contraseña, el PIN y la llave solo se dice
 * si están cargados (HaciendaCredential::summary()). Para cambiarlos se
 * escriben de nuevo. Cada cambio queda en la Bitácora, sin los valores.
 */
class HaciendaSettingsController extends Controller
{
    public function __construct(
        private readonly ElectronicInvoicing $electronic,
        private readonly FiscalSequenceAllocator $sequences,
    ) {}

    public function index(Request $request, CurrentCompany $currentCompany): Response
    {
        $company = $this->company($request, $currentCompany);
        $settings = $this->electronic->settings($company);

        $credentials = collect(HaciendaSetting::ENVIRONMENTS)->mapWithKeys(fn (string $env) => [
            $env => $this->electronic->credential($company, $env)?->summary(),
        ]);

        $branch = $settings?->branch ?? '001';
        $terminal = $settings?->terminal ?? '00001';

        $series = FiscalSequence::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->orderBy('branch')->orderBy('terminal')->orderBy('document_type')
            ->get()
            ->map(fn (FiscalSequence $s) => $this->seriesRow($s->branch, $s->terminal, $s->document_type, $s->last_number + 1));

        // La sucursal y terminal por defecto, con cada tipo, aunque todavía
        // no tengan fila: es la serie que el Superusuario quiere ajustar
        // antes del primer comprobante.
        foreach (FiscalCatalogs::ELECTRONIC_TYPES as $type) {
            if (! $series->contains(fn (array $row) => $row['branch'] === $branch && $row['terminal'] === $terminal && $row['document_type'] === $type)) {
                $series->push($this->seriesRow($branch, $terminal, $type, $this->sequences->peek($company, $branch, $terminal, $type)));
            }
        }

        $counts = ElectronicDocument::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $activity = $this->electronic->defaultActivity($company);

        return Inertia::render('Hacienda/Settings', [
            'company' => [
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'tax_id' => $company->tax_id,
            ],
            'settings' => [
                'environment' => $settings?->environment ?? 'stag',
                'enabled' => (bool) $settings?->enabled,
                'enabled_at' => $settings?->enabled_at?->setTimezone(config('hacienda.timezone'))->format('d/m/Y H:i'),
                'identification_type' => $settings?->identification_type ?? (strlen(preg_replace('/\D/', '', (string) $company->tax_id)) === 9 ? '01' : '02'),
                'province' => $settings?->province ?? '',
                'canton' => $settings?->canton ?? '',
                'district' => $settings?->district ?? '',
                'neighborhood' => $settings?->neighborhood ?? '',
                'address_details' => $settings?->address_details ?? '',
                'phone' => $settings?->phone ?? '',
                'emails' => $settings?->emailList() ?: [''],
                'branch' => $branch,
                'terminal' => $terminal,
                'send_to_customer' => $settings?->send_to_customer ?? true,
            ],
            'credentials' => $credentials,
            'missing' => collect(HaciendaSetting::ENVIRONMENTS)->mapWithKeys(fn (string $env) => [$env => $this->electronic->missing($company, $env)]),
            'series' => $series->values(),
            'activity' => $activity ? ['code' => $activity->code, 'name' => $activity->name, 'valid' => FiscalCatalogs::isValidActivityCode($activity->code)] : null,
            'systemProvider' => preg_replace('/\D/', '', (string) config('hacienda.system_provider_id')) ?: null,
            'counts' => [
                'pending' => (int) $counts->only([ElectronicDocument::SIGNED, ElectronicDocument::SUBMITTING, ElectronicDocument::SUBMITTED, ElectronicDocument::SUBMIT_UNKNOWN])->sum(),
                'accepted' => (int) ($counts[ElectronicDocument::ACCEPTED] ?? 0),
                'rejected' => (int) $counts->only([ElectronicDocument::REJECTED, ElectronicDocument::SUBMIT_FAILED])->sum(),
                'attention' => (int) ($counts[ElectronicDocument::NEEDS_ATTENTION] ?? 0),
            ],
            'catalogs' => [
                'identificationTypes' => array_intersect_key(FiscalCatalogs::IDENTIFICATION_TYPES, array_flip(FiscalCatalogs::EMITTER_IDENTIFICATION_TYPES)),
                'territory' => CostaRicaTerritory::tree(),
                'documentTypes' => array_intersect_key(FiscalCatalogs::DOCUMENT_TYPES, array_flip(FiscalCatalogs::ELECTRONIC_TYPES)),
                'environments' => collect(config('hacienda.environments'))->map(fn (array $e) => $e['label']),
            ],
        ]);
    }

    public function updateEmitter(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $this->company($request, $currentCompany);

        $validated = $request->validate([
            'identification_type' => ['required', Rule::in(FiscalCatalogs::EMITTER_IDENTIFICATION_TYPES)],
            // Códigos de la tabla oficial de Hacienda: un cantón que no es de
            // la provincia pasa el XSD pero Hacienda lo rechaza.
            ...CostaRicaTerritory::rules($request->all(), required: true),
            'neighborhood' => ['nullable', 'string', 'min:5', 'max:50'],
            'address_details' => ['required', 'string', 'min:5', 'max:250'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[\d\s\-+()]{8,20}$/'],
            'emails' => ['required', 'array', 'min:1', 'max:4'],
            'emails.*' => ['required', 'email', 'max:160'],
            'branch' => ['required', 'regex:/^\d{3}$/'],
            'terminal' => ['required', 'regex:/^\d{5}$/'],
            'send_to_customer' => ['boolean'],
        ], [
            'canton.regex' => 'El cantón es el código de dos dígitos (por ejemplo 01).',
            'district.regex' => 'El distrito es el código de dos dígitos (por ejemplo 03).',
            'branch.regex' => 'La sucursal son tres dígitos (001).',
            'terminal.regex' => 'La terminal son cinco dígitos (00001).',
        ]);

        // El patrón del XSD es más estricto que el de Laravel.
        foreach ($validated['emails'] as $index => $email) {
            if (! FiscalCatalogs::isValidEmail($email)) {
                return back()->withErrors(["emails.{$index}" => 'Hacienda no acepta este correo tal como está escrito.']);
            }
        }

        if (! FiscalCatalogs::identificationMatchesType($validated['identification_type'], (string) $company->tax_id)) {
            return back()->withErrors([
                'identification_type' => 'La cédula de la compañía ('.$company->tax_id.') no tiene los dígitos de una '.
                    mb_strtolower(FiscalCatalogs::IDENTIFICATION_TYPES[$validated['identification_type']]).'.',
            ]);
        }

        $settings = $this->settingsFor($company);
        $before = $settings->only(array_keys($validated));

        $settings->fill([
            ...$validated,
            'emails' => array_values(array_unique(array_map('trim', $validated['emails']))),
            'neighborhood' => $validated['neighborhood'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'send_to_customer' => $request->boolean('send_to_customer'),
            'updated_by' => $request->user()->id,
        ])->save();

        $this->audit($request, $company, 'company.hacienda_settings_updated', $before, $settings->only(array_keys($validated)));

        return back()->with('success', 'Datos del emisor guardados.');
    }

    public function updateCredentials(Request $request, CurrentCompany $currentCompany, string $environment): RedirectResponse
    {
        $company = $this->company($request, $currentCompany);
        $this->assertEnvironment($environment);

        $credential = $this->credentialFor($company, $environment);

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:160'],
            // Vacía: se conserva la que estaba. No se puede «ver» la anterior.
            'password' => [$credential->hasApiCredentials() ? 'nullable' : 'required', 'string', 'max:255'],
        ]);

        $changed = $credential->username !== $validated['username'] || filled($validated['password'] ?? null);

        $credential->username = $validated['username'];

        if (filled($validated['password'] ?? null)) {
            $credential->password = $validated['password'];
        }

        if ($changed) {
            // Otro usuario u otra contraseña: el token de antes ya no corresponde.
            $credential->forceFill([
                'access_token' => null, 'refresh_token' => null,
                'token_expires_at' => null, 'refresh_expires_at' => null,
                'last_tested_at' => null, 'last_test_ok' => null, 'last_test_message' => null,
            ]);
        }

        $credential->updated_by = $request->user()->id;
        $credential->save();

        $this->audit($request, $company, 'company.hacienda_credentials_updated', null, [
            'ambiente' => $environment,
            'usuario' => $validated['username'],
            'contraseña' => filled($validated['password'] ?? null) ? 'cambiada' : 'sin cambio',
        ]);

        return back()->with('success', 'Credenciales del ATV guardadas ('.config("hacienda.environments.{$environment}.label").').');
    }

    public function uploadCertificate(Request $request, CurrentCompany $currentCompany, CertificateInspector $inspector, string $environment): RedirectResponse
    {
        $company = $this->company($request, $currentCompany);
        $this->assertEnvironment($environment);

        $request->validate([
            'certificate' => ['required', 'file', 'max:1024'],
            'pin' => ['required', 'string', 'max:64'],
        ], [
            'certificate.max' => 'Una llave criptográfica pesa unos pocos KB: este archivo es demasiado grande.',
        ]);

        $file = $request->file('certificate');

        if (! in_array(strtolower($file->getClientOriginalExtension()), ['p12', 'pfx'], true)) {
            return back()->withErrors(['certificate' => 'La llave criptográfica del ATV es un archivo .p12.']);
        }

        try {
            // En memoria: el archivo no se guarda en el disco en ningún momento.
            $result = $inspector->inspect($file->get(), (string) $request->input('pin'), (string) $company->tax_id);
        } catch (CertificateException $e) {
            return back()->withErrors(['certificate' => $e->getMessage()]);
        }

        $credential = $this->credentialFor($company, $environment);
        $credential->certificate = base64_encode($result['p12']);
        $credential->certificate_pin = (string) $request->input('pin');
        $credential->forceFill([
            ...$result['metadata'],
            'certificate_uploaded_at' => now(),
            'certificate_warned_days' => null,
            'updated_by' => $request->user()->id,
        ])->save();

        $this->audit($request, $company, 'company.hacienda_certificate_uploaded', null, [
            'ambiente' => $environment,
            'titular' => $result['metadata']['certificate_subject'],
            'vence' => $result['metadata']['certificate_valid_to']->format('Y-m-d'),
            'huella' => $result['metadata']['certificate_fingerprint'],
        ]);

        $message = 'Llave criptográfica cargada: vence el '.
            $result['metadata']['certificate_valid_to']->setTimezone(new \DateTimeZone(config('hacienda.timezone')))->format('d/m/Y').'.';

        return back()->with('success', trim($message.' '.implode(' ', $result['warnings'])));
    }

    /**
     * Pide un token real al IDP de Hacienda con el usuario y la contraseña
     * cargados. No envía ningún comprobante.
     */
    public function testConnection(Request $request, CurrentCompany $currentCompany, HaciendaTokenProvider $tokens, string $environment): RedirectResponse
    {
        $company = $this->company($request, $currentCompany);
        $this->assertEnvironment($environment);

        $credential = $this->electronic->credential($company, $environment);

        if (! $credential?->hasApiCredentials()) {
            return back()->with('error', 'Primero cargá el usuario y la contraseña del API del ATV.');
        }

        try {
            $tokens->token($credential, forceNew: true);
            $ok = true;
            $message = 'Hacienda entregó un token: el usuario y la contraseña son correctos.';
        } catch (HaciendaApiException $e) {
            $ok = false;
            $message = $e->getMessage();
        }

        $credential->forceFill([
            'last_tested_at' => now(),
            'last_test_ok' => $ok,
            'last_test_message' => mb_substr($message, 0, 255),
        ])->save();

        $this->audit($request, $company, 'company.hacienda_connection_tested', null, ['ambiente' => $environment, 'resultado' => $ok ? 'correcto' : 'falló']);

        return back()->with($ok ? 'success' : 'error', $message);
    }

    public function updateEnvironment(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $this->company($request, $currentCompany);

        $validated = $request->validate([
            'environment' => ['required', Rule::in(HaciendaSetting::ENVIRONMENTS)],
        ]);

        $settings = $this->settingsFor($company);

        if ($settings->environment === $validated['environment']) {
            return back();
        }

        // Encendida, el cambio rige para el próximo comprobante: tiene que
        // estar todo listo en el ambiente nuevo antes de cambiar.
        if ($settings->enabled && ($missing = $this->electronic->missing($company, $validated['environment'])) !== []) {
            return back()->with('error', 'Antes de pasar a '.config("hacienda.environments.{$validated['environment']}.label").' falta: '.implode(' ', $missing));
        }

        $before = $settings->environment;
        $settings->update(['environment' => $validated['environment'], 'updated_by' => $request->user()->id]);

        $this->audit($request, $company, 'company.hacienda_environment_changed', ['ambiente' => $before], ['ambiente' => $validated['environment']]);

        return back()->with('success', 'Ambiente: '.config("hacienda.environments.{$validated['environment']}.label").'. Rige desde el próximo comprobante.');
    }

    public function enable(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $this->company($request, $currentCompany);
        $settings = $this->settingsFor($company);

        $missing = $this->electronic->missing($company, $settings->environment);

        if ($missing !== []) {
            return back()->with('error', 'Todavía no se puede encender. Falta: '.implode(' ', $missing));
        }

        $settings->update([
            'enabled' => true,
            'enabled_at' => now(),
            'enabled_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $this->audit($request, $company, 'company.hacienda_enabled', null, ['ambiente' => $settings->environment]);

        return back()->with('success', 'Facturación electrónica encendida ('.config("hacienda.environments.{$settings->environment}.label").'): '.
            'desde ahora cada comprobante se firma y se envía a Hacienda.');
    }

    public function disable(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $this->company($request, $currentCompany);
        $settings = $this->settingsFor($company);

        $settings->update(['enabled' => false, 'updated_by' => $request->user()->id]);

        $this->audit($request, $company, 'company.hacienda_disabled', null, ['ambiente' => $settings->environment]);

        // Lo ya firmado sigue su curso: apagar no deja comprobantes a medias.
        return back()->with('success', 'Facturación electrónica apagada. Los comprobantes que ya estaban en camino siguen hasta que Hacienda responda.');
    }

    public function updateSequence(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $this->company($request, $currentCompany);

        $validated = $request->validate([
            'branch' => ['required', 'regex:/^\d{3}$/'],
            'terminal' => ['required', 'regex:/^\d{5}$/'],
            'document_type' => ['required', Rule::in(FiscalCatalogs::ELECTRONIC_TYPES)],
            'next_number' => ['required', 'integer', 'min:1', 'max:9999999999'],
        ]);

        try {
            $this->sequences->setNext($company, $validated['branch'], $validated['terminal'], $validated['document_type'], (int) $validated['next_number']);
        } catch (ElectronicInvoicingException $e) {
            return back()->withErrors(['next_number' => $e->getMessage()]);
        }

        $this->audit($request, $company, 'company.hacienda_sequence_changed', null, $validated);

        return back()->with('success', 'El próximo '.mb_strtolower(FiscalCatalogs::DOCUMENT_TYPES[$validated['document_type']]).
            " de la sucursal {$validated['branch']}, terminal {$validated['terminal']}, será el número {$validated['next_number']}.");
    }

    /** La compañía activa, si quien pide es su Superusuario. */
    private function company(Request $request, CurrentCompany $currentCompany): Company
    {
        $company = Company::findOrFail($currentCompany->id());

        abort_unless(
            $request->user()->isSuperAdmin($company->id),
            403,
            'La facturación electrónica la configura solo el Superusuario de la compañía.'
        );

        return $company;
    }

    private function settingsFor(Company $company): HaciendaSetting
    {
        return $this->electronic->settings($company)
            ?? HaciendaSetting::withoutGlobalScope(CompanyScope::class)->create(['company_id' => $company->id]);
    }

    private function credentialFor(Company $company, string $environment): HaciendaCredential
    {
        return $this->electronic->credential($company, $environment)
            ?? HaciendaCredential::withoutGlobalScope(CompanyScope::class)->create(['company_id' => $company->id, 'environment' => $environment]);
    }

    private function assertEnvironment(string $environment): void
    {
        abort_unless(in_array($environment, HaciendaSetting::ENVIRONMENTS, true), 404);
    }

    /** @return array<string, mixed> */
    private function seriesRow(string $branch, string $terminal, string $type, int $next): array
    {
        return [
            'branch' => $branch,
            'terminal' => $terminal,
            'document_type' => $type,
            'label' => FiscalCatalogs::DOCUMENT_TYPES[$type] ?? $type,
            'next_number' => $next,
        ];
    }

    private function audit(Request $request, Company $company, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            // De la compañía, como su logo o su tema: es su configuración.
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
