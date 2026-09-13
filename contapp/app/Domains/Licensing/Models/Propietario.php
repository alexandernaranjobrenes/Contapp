<?php

namespace App\Domains\Licensing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * El fabricante/distribuidor de CONTAPP (CLAUDE.md secc. 11/12): un actor
 * completamente aparte de Superusuario/Administrador/Usuario, con su propia
 * tabla y su propio guard ('propietario', ver config/auth.php) — nunca
 * comparte sesión ni fila con App\Models\User. Deliberadamente mínimo: sin
 * compañías, sin roles, sin permisos — el único privilegio es estar
 * autenticado en este guard.
 */
class Propietario extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
