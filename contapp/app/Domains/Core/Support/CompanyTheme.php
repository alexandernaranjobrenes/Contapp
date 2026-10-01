<?php

namespace App\Domains\Core\Support;

/**
 * Los temas visuales que puede usar una compañía (CLAUDE.md secc. 31).
 *
 * Acá vive el catálogo —la clave, el nombre, la descripción y la tipografía
 * que se muestran al elegirlo—; los colores de cada tema, en claro y en
 * oscuro, están en resources/css/app.scss, en el mapa $company-themes, con
 * estas mismas claves. CompanyAppearanceTest verifica que las dos listas
 * coincidan.
 *
 * Una compañía sin tema elegido usa el predeterminado, Marino: el aspecto
 * original de CONTAPP.
 */
enum CompanyTheme: string
{
    case Marino = 'marino';
    case Grafito = 'grafito';
    case Esmeralda = 'esmeralda';
    case Borgona = 'borgona';
    case Petroleo = 'petroleo';
    case Indigo = 'indigo';
    case Cobalto = 'cobalto';
    case Terracota = 'terracota';
    case Salvia = 'salvia';
    case Onix = 'onix';

    public static function default(): self
    {
        return self::Marino;
    }

    /**
     * El tema de una compañía, tolerante a un valor guardado que ya no
     * exista: cae al predeterminado en vez de romper la página.
     */
    public static function resolve(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    public function label(): string
    {
        return match ($this) {
            self::Marino => 'Marino',
            self::Grafito => 'Grafito',
            self::Esmeralda => 'Esmeralda',
            self::Borgona => 'Borgoña',
            self::Petroleo => 'Petróleo',
            self::Indigo => 'Índigo',
            self::Cobalto => 'Cobalto',
            self::Terracota => 'Terracota',
            self::Salvia => 'Salvia',
            self::Onix => 'Ónix',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Marino => 'Azul marino profundo sobre mármol cálido, con acento ámbar. El aspecto clásico de CONTAPP.',
            self::Grafito => 'Gris carbón neutro con acento turquesa. Minimalista y técnico.',
            self::Esmeralda => 'Verde profundo con detalles dorados. Sereno y distinguido.',
            self::Borgona => 'Vino tinto con cobre rosado. Cálido y ejecutivo.',
            self::Petroleo => 'Azul petróleo con aguamarina. Fresco y confiable.',
            self::Indigo => 'Índigo intenso con lavanda. Contemporáneo y creativo.',
            self::Cobalto => 'Azul cobalto con celeste. Limpio y corporativo.',
            self::Terracota => 'Café tostado con terracota. Orgánico y acogedor.',
            self::Salvia => 'Verde oliva grisáceo con salvia. Natural y calmado.',
            self::Onix => 'Negro profundo con dorado. Sobrio, de alto contraste.',
        };
    }

    /** La tipografía del tema, tal como se llama la familia en la hoja de estilos. */
    public function font(): string
    {
        return match ($this) {
            self::Marino => 'Instrument Sans',
            self::Grafito => 'Inter',
            self::Esmeralda => 'Manrope',
            self::Borgona => 'Plus Jakarta Sans',
            self::Petroleo => 'DM Sans',
            self::Indigo => 'Outfit',
            self::Cobalto => 'IBM Plex Sans',
            self::Terracota => 'Work Sans',
            self::Salvia => 'Nunito Sans',
            self::Onix => 'Sora',
        };
    }

    /**
     * El catálogo para la pantalla de Apariencia.
     *
     * @return list<array{key: string, label: string, description: string, font: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $theme) => [
            'key' => $theme->value,
            'label' => $theme->label(),
            'description' => $theme->description(),
            'font' => $theme->font(),
        ], self::cases());
    }
}
