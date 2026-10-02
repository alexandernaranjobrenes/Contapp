<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cambiar de usuario en medio de un test equivale a salir e ingresar con
     * otra cuenta, y un ingreso real nunca hereda la sesión del anterior: el
     * logout la invalida. En un test la sesión es la misma de principio a
     * fin, así que acá se le quita el hash de contraseña que dejó el usuario
     * previo. Si quedara, el middleware auth.session (bootstrap/app.php) lo
     * compararía con la contraseña del usuario nuevo y le cerraría la sesión.
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        $this->app['session']->forget('password_hash_'.($guard ?? config('auth.defaults.guard')));

        return parent::actingAs($user, $guard);
    }
}
