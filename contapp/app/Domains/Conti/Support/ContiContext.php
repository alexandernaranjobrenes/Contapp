<?php

namespace App\Domains\Conti\Support;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Services\ScreenAccessService;
use App\Domains\Core\Support\ScreenCatalog;
use App\Models\User;
use LogicException;

/**
 * A nombre de quién y en qué compañía consulta Conti mientras responde un
 * mensaje (ContiChatController lo arma). Es el único lugar que decide si
 * Conti puede ver o guardar algo: con los mismos permisos por pantalla que
 * la persona tiene en el menú (ScreenAccessService), ni uno más.
 */
class ContiContext
{
    private ?User $user = null;

    private ?Company $company = null;

    private bool $grace = false;

    /** @var array<string, string>|null */
    private ?array $levels = null;

    public function set(User $user, Company $company, bool $grace): void
    {
        $this->user = $user;
        $this->company = $company;
        $this->grace = $grace;
        $this->levels = null;
    }

    public function user(): User
    {
        return $this->user ?? throw new LogicException('Conti sin persona: falta armar el ContiContext.');
    }

    public function company(): Company
    {
        return $this->company ?? throw new LogicException('Conti sin compañía: falta armar el ContiContext.');
    }

    public function inGrace(): bool
    {
        return $this->grace;
    }

    public function isSuperAdmin(): bool
    {
        return $this->user()->isSuperAdmin($this->company()->id);
    }

    public function roleLabel(): string
    {
        if ($this->isSuperAdmin()) {
            return 'Superusuario';
        }

        return $this->user()->roleTypeFor($this->company()->id) === 'admin' ? 'Administrador' : 'Usuario';
    }

    /** @return array<string, string> pantalla => 'none'|'read'|'read_write' */
    public function levels(): array
    {
        return $this->levels ??= app(ScreenAccessService::class)->levelsFor($this->user(), $this->company()->id);
    }

    public function allows(string $screen, string $level = 'read'): bool
    {
        return ScreenAccessService::LEVELS[$this->levels()[$screen] ?? 'none'] >= ScreenAccessService::LEVELS[$level];
    }

    /** @param list<string> $screens */
    public function allowsAny(array $screens, string $level = 'read'): bool
    {
        foreach ($screens as $screen) {
            if ($this->allows($screen, $level)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Corta con 403 si la persona no tiene ninguna de esas pantallas a ese
     * nivel, diciendo cuál le falta: Conti se lo explica tal cual.
     *
     * @param  string|list<string>  $screens
     */
    public function authorize(string|array $screens, string $level = 'read'): void
    {
        $screens = (array) $screens;

        if ($this->allowsAny($screens, $level)) {
            return;
        }

        $names = implode(' o ', array_map(fn (string $key) => '«'.self::screenPath($key).'»', $screens));
        $what = $level === 'read_write' ? 'Lectura y escritura' : 'Lectura';

        abort(403, "No tenés permiso para esto: hace falta {$what} en {$names}. Pedíselo a quien administra la compañía.");
    }

    /** «Contabilidad → Registros»: como se ve en el menú. */
    public static function screenPath(string $key): string
    {
        $screen = ScreenCatalog::find($key);

        if ($screen === null) {
            return $key;
        }

        $section = collect(ScreenCatalog::sections())->firstWhere('key', $screen['section']);

        return ($section['label'] ?? $screen['section']).' → '.$screen['label'];
    }
}
