<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Core\Support\PasswordPolicy;
use App\Domains\Core\Support\ProfilePhoto;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * «Mi cuenta»: lo que cada persona puede cambiar de sí misma, sea cual sea su
 * rol. Nunca recibe un id de usuario: siempre actúa sobre quien tiene la
 * sesión, así que no hay forma de editar la cuenta de otro desde acá.
 *
 * La cuenta es de la persona, no de una compañía (la misma entra a las de
 * varias licencias, CLAUDE.md secc. 12): por eso estas rutas no llevan
 * middleware de rol ni de módulo, y quedan exentas del modo de gracia (ver
 * EnforceLicenseGracePeriod).
 *
 * ── Qué pide la contraseña actual, y qué no ──────────────────────────────
 *
 * El nombre y la foto se cambian sin más. La contraseña y el correo son las llaves de
 * la cuenta: piden la contraseña actual, para que una sesión que quedó
 * abierta en una computadora ajena no alcance para quedarse con la cuenta.
 */
class ProfileController extends Controller
{
    /** Minutos que dura el enlace que confirma un correo nuevo. */
    private const EMAIL_LINK_MINUTES = 60;

    /** Intentos por minuto de las acciones que piden la contraseña actual. */
    private const MAX_ATTEMPTS = 5;

    public function edit(Request $request, MediaStorage $media): Response
    {
        $user = $request->user();
        $currentCompanyId = app(CurrentCompany::class)->id();

        return Inertia::render('Profile/Edit', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'photo_url' => $media->versionedUrl($user->photo_path, $user->photo_updated_at),
            ],
            // Qué se puede subir, dicho por el servidor para que la pantalla
            // no prometa un formato que después la validación rechaza.
            'photoRules' => [
                'accept' => implode(',', ProfilePhoto::mimeTypes()),
                'formats' => ProfilePhoto::formatsLabel(),
                'max_megabytes' => intdiv(ProfilePhoto::MAX_KILOBYTES, 1024),
            ],
            // Dónde entra esta cuenta y con qué rol. Solo para verlo: los
            // roles los da quien administra cada compañía.
            'memberships' => $user->companies()
                ->wherePivot('status', 'active')
                ->orderBy('companies.legal_name')
                ->get()
                ->map(function ($company) use ($user, $currentCompanyId) {
                    $isSuperAdmin = $user->isSuperAdmin($company->id);

                    return [
                        'id' => $company->id,
                        'name' => $company->trade_name ?: $company->legal_name,
                        'role' => $isSuperAdmin ? 'super_admin' : ($user->roleTypeFor($company->id) ?? 'user'),
                        'is_current' => $company->id === $currentCompanyId,
                        'is_default' => $company->id === $user->default_company_id,
                        // Sus datos los cambia solo su Superusuario
                        // (CompanyDetailsController).
                        'can_edit' => $isSuperAdmin,
                        'legal_name' => $company->legal_name,
                        // El nombre comercial se muestra vacío si es el
                        // mismo que la razón social: así lo cargó quien no
                        // puso uno al crearla.
                        'trade_name' => $company->trade_name === $company->legal_name ? '' : $company->trade_name,
                        'tax_id' => $company->tax_id,
                    ];
                })
                ->values(),
            'passwordRequirements' => PasswordPolicy::requirements(),
            'emailLinkMinutes' => self::EMAIL_LINK_MINUTES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ], [], ['name' => 'nombre']);

        $name = trim($validated['name']);

        if ($name !== $user->name) {
            DB::transaction(function () use ($user, $name, $request) {
                $previous = $user->name;
                $user->update(['name' => $name]);

                $this->audit($request, 'user.profile_updated', ['name' => $previous], ['name' => $name]);
            });
        }

        return back()->with('success', 'Tus datos quedaron guardados.');
    }

    /**
     * La foto de perfil. Va al disco de imágenes (MediaStorage: el bucket de
     * Cloudflare R2) como «users/profile_{id}.{ext}»: una por cuenta, con
     * nombre fijo, así que subir otra reemplaza la anterior.
     *
     * No se guarda el archivo tal como llega: ProfilePhoto lo endereza, lo
     * recorta a un cuadrado y le quita los metadatos (una foto de teléfono
     * trae el GPS adentro, y esta dirección es pública).
     */
    public function updatePhoto(Request $request, MediaStorage $media): RedirectResponse
    {
        $request->validate([
            'photo' => [
                'required',
                'image',
                'mimes:'.implode(',', ProfilePhoto::extensions()),
                'max:'.ProfilePhoto::MAX_KILOBYTES,
                // El tope de arriba es por memoria: para recortarla hay que
                // abrirla entera, y una imagen de 6000 px de lado ya ocupa
                // más de 100 MB descomprimida.
                'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000',
            ],
        ], [
            'photo.image' => 'El archivo tiene que ser una imagen en '.ProfilePhoto::formatsLabel().'.',
            'photo.mimes' => 'La foto tiene que estar en '.ProfilePhoto::formatsLabel().'.',
            'photo.max' => 'La foto no puede pesar más de '.intdiv(ProfilePhoto::MAX_KILOBYTES, 1024).' MB.',
            'photo.dimensions' => 'La foto tiene que medir entre 100 y 6000 píxeles de lado.',
            'photo.uploaded' => 'No se pudo subir la foto. Probá con un archivo más liviano.',
        ], ['photo' => 'foto']);

        $user = $request->user();
        $photo = ProfilePhoto::normalize($request->file('photo'));

        if ($photo === null) {
            return back()->withErrors(['photo' => 'No pudimos leer esa imagen. Probá con otra en '.ProfilePhoto::formatsLabel().'.']);
        }

        $path = "users/profile_{$user->id}.{$photo['extension']}";

        if (! $media->put($path, $photo['contents'])) {
            return back()->withErrors(['photo' => 'No se pudo guardar la foto. Probá de nuevo en un momento.']);
        }

        $previous = $user->photo_path;

        DB::transaction(function () use ($user, $path, $request) {
            $user->forceFill(['photo_path' => $path, 'photo_updated_at' => now()])->save();

            $this->audit($request, 'user.photo_updated', null, ['photo_path' => $path]);
        });

        // Con la misma extensión, la nueva ya pisó a la anterior. Con otra
        // (de .jpg a .png) la anterior quedó con su nombre: se borra, para
        // que cada cuenta tenga un solo archivo en el bucket.
        if ($previous !== null && $previous !== $path) {
            $media->delete($previous);
        }

        return back()->with('success', 'Tu foto de perfil quedó actualizada.');
    }

    public function destroyPhoto(Request $request, MediaStorage $media): RedirectResponse
    {
        $user = $request->user();
        $previous = $user->photo_path;

        if ($previous === null) {
            return back();
        }

        DB::transaction(function () use ($user, $previous, $request) {
            $user->forceFill(['photo_path' => null, 'photo_updated_at' => null])->save();

            $this->audit($request, 'user.photo_removed', ['photo_path' => $previous]);
        });

        // Después de soltarla en la base: si el bucket no responde, el
        // archivo queda huérfano, pero la cuenta ya no muestra la foto.
        $media->delete($previous);

        return back()->with('success', 'Quitamos tu foto de perfil.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $this->throttle($request);

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', PasswordPolicy::rule()],
        ], [
            'password.different' => 'La contraseña nueva tiene que ser distinta de la actual.',
        ], ['current_password' => 'contraseña actual']);

        $user = $request->user();

        DB::transaction(function () use ($user, $validated, $request) {
            // No toca password_chosen_at: conocer la contraseña actual no
            // prueba que el correo sea de quien la cambia (la conoce también
            // quien creó la cuenta). Esa prueba es el enlace por correo, ver
            // NewPasswordController y LicenseActivationService.
            //
            // El hash nuevo cierra las sesiones abiertas en otros dispositivos
            // (middleware auth.session). Esta sigue: al terminar la request el
            // middleware guarda en ella el hash nuevo.
            $user->forceFill(['password' => $validated['password']])->save();

            $this->audit($request, 'user.password_changed');
        });

        RateLimiter::clear($this->throttleKey($request));

        return back()->with('success', 'Tu contraseña quedó cambiada. Las sesiones abiertas en otros dispositivos se cierran.');
    }

    /**
     * Primer paso del cambio de correo: manda el enlace a la casilla nueva.
     * El correo de la cuenta NO cambia acá.
     *
     * Hay que confirmar la casilla porque en CONTAPP el correo es la
     * identidad: es adonde llega el enlace para recuperar la contraseña, y es
     * por correo que un Superusuario invita a una cuenta existente a su
     * compañía. Sin confirmación, alguien podría ponerse el correo de otra
     * persona y recibir los accesos que le dieran a ella.
     */
    public function requestEmailChange(Request $request): RedirectResponse
    {
        $this->throttle($request);

        $user = $request->user();

        $validated = $request->validate([
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::notIn([$user->email]),
                Rule::unique('users', 'email'),
            ],
            'current_password' => ['required', 'string', 'current_password:web'],
        ], [
            'email.not_in' => 'Ese ya es tu correo.',
            'email.unique' => 'Ese correo ya lo usa otra cuenta de CONTAPP.',
        ], ['current_password' => 'contraseña actual']);

        $newEmail = $validated['email'];

        // `from` ata el enlace al correo que la cuenta tiene hoy: si cambia
        // por otro lado antes de abrirlo, el enlace deja de servir.
        // Firma relativa (solo la ruta y sus parámetros): así el enlace sigue
        // siendo válido aunque la aplicación quede detrás de un proxy que
        // cambie el esquema o el host con que llega la request.
        $url = url(URL::temporarySignedRoute('profile.email.confirm', now()->addMinutes(self::EMAIL_LINK_MINUTES), [
            'user' => $user->id,
            'email' => $newEmail,
            'from' => $this->emailFingerprint($user->email),
        ], absolute: false));

        try {
            Notification::route('mail', $newEmail)
                ->notify(new ConfirmEmailChangeNotification($url, $user->name, self::EMAIL_LINK_MINUTES));
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'No pudimos enviar el correo de confirmación. Probá de nuevo en unos minutos.');
        }

        $this->audit($request, 'user.email_change_requested', ['email' => $user->email], ['email' => $newEmail]);

        RateLimiter::clear($this->throttleKey($request));

        return back()->with('success', "Te enviamos un enlace a {$newEmail}. Tu correo cambia cuando lo abras; mientras tanto seguís entrando con el actual.");
    }

    /**
     * Segundo paso: el enlace que llegó a la casilla nueva. No exige sesión,
     * porque el correo se puede abrir en otro dispositivo: lo que autoriza el
     * cambio es la firma del enlace.
     */
    public function confirmEmailChange(Request $request, User $user): RedirectResponse
    {
        $newEmail = (string) $request->query('email');
        $sameSession = $request->user('web')?->is($user) ?? false;
        $destination = $sameSession ? 'profile.edit' : 'login';

        // La firma se revisa acá y no con el middleware `signed`, que
        // contestaría con una página de error 403 a secas.
        if (! $request->hasValidRelativeSignature()) {
            return redirect()->route($destination)->with('error', 'Ese enlace no es válido o ya venció. Pedí otro desde Mi cuenta.');
        }

        // El enlace se armó para el correo que la cuenta tenía entonces. Si
        // ya es otro —este mismo enlace ya se usó, o se confirmó uno pedido
        // después—, no se aplica.
        if (! hash_equals($this->emailFingerprint($user->email), (string) $request->query('from'))) {
            return redirect()->route($destination)->with('error', 'Ese enlace ya no sirve: el correo de la cuenta cambió después de pedirlo.');
        }

        // Entre el pedido y la confirmación otra cuenta pudo tomar ese correo.
        if (User::where('email', $newEmail)->whereKeyNot($user->id)->exists()) {
            return redirect()->route($destination)->with('error', 'Ese correo ya lo usa otra cuenta de CONTAPP.');
        }

        DB::transaction(function () use ($user, $newEmail, $request) {
            $previous = $user->email;

            $user->forceFill(['email' => $newEmail, 'email_verified_at' => now()])->save();

            // Un enlace de «Olvidé mi contraseña» pedido para el correo
            // anterior queda sin efecto.
            DB::table(config('auth.passwords.users.table'))->where('email', $previous)->delete();

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'user.email_changed',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'old_values' => ['email' => $previous],
                'new_values' => ['email' => $newEmail],
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return redirect()->route($destination)->with('success', $sameSession
            ? "Tu correo ahora es {$newEmail}."
            : "Tu correo ahora es {$newEmail}. Ingresá con él.");
    }

    /**
     * Sin compañía: los datos son de la persona, que puede pertenecer a
     * compañías de varias licencias.
     */
    private function audit(Request $request, string $action, ?array $old = null, ?array $new = null): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $request->user()->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Frena la prueba repetida de la contraseña actual desde una sesión
     * abierta. Solo cuentan las contraseñas equivocadas (equivocarse en la
     * nueva no gasta intentos), y el contador se limpia cuando la acción
     * sale bien. El mensaje de «contraseña incorrecta» lo da después la
     * validación.
     */
    private function throttle(Request $request): void
    {
        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'current_password' => 'Demasiados intentos. Esperá '.RateLimiter::availableIn($key).' segundos y volvé a probar.',
            ]);
        }

        $given = $request->input('current_password');

        if (is_string($given) && $given !== '' && ! Hash::check($given, $request->user()->password)) {
            RateLimiter::hit($key, 60);
        }
    }

    private function throttleKey(Request $request): string
    {
        return 'profile-sensitive:'.Auth::guard('web')->id();
    }

    private function emailFingerprint(string $email): string
    {
        return hash('sha256', mb_strtolower($email));
    }
}
