<?php

namespace App\Domains\Core\Backup;

use Illuminate\Support\Facades\Schema;

/**
 * La forma de la base, leída de la base misma (Schema::getForeignKeys y
 * getColumns: igual en MySQL y en SQLite). Así el respaldo sigue a las
 * migraciones sin que nadie tenga que listar cada llave foránea a mano.
 */
final class BackupSchema
{
    /** @var array<string, array<string, string>> tabla => [columna => tabla a la que apunta] */
    private array $references = [];

    /** @var array<string, array<string, bool>> tabla => [columna => admite null] */
    private array $nullable = [];

    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @return array<string, string> columna => tabla a la que apunta */
    public function references(string $table): array
    {
        if (! isset($this->references[$table])) {
            $this->references[$table] = [];

            foreach (Schema::getForeignKeys($table) as $key) {
                if (count($key['columns']) === 1) {
                    $this->references[$table][$key['columns'][0]] = $key['foreign_table'];
                }
            }
        }

        return $this->references[$table];
    }

    public function isNullable(string $table, string $column): bool
    {
        $this->loadColumns($table);

        return $this->nullable[$table][$column] ?? true;
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        $this->loadColumns($table);

        return $this->columns[$table];
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    /**
     * Las tablas en un orden en el que cada una va después de las que
     * necesita: al importar, una fila siempre encuentra a sus padres ya
     * creados. Una referencia a la misma tabla (la cuenta madre) o un ciclo
     * por una columna que admite null se resuelve después, al terminar.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function order(array $tables): array
    {
        $last = array_values(array_intersect(BackupCatalog::LAST, $tables));
        $tables = array_values(array_diff($tables, $last));
        $set = array_flip($tables);
        $needs = [];

        foreach ($tables as $table) {
            $needs[$table] = [];

            foreach ($this->references($table) as $column => $target) {
                if ($target !== $table && isset($set[$target])) {
                    $needs[$table][$target] = $this->isNullable($table, $column);
                }
            }

            // Lo que no es una llave foránea pero hay que encontrar ya importado.
            foreach (BackupCatalog::AFTER[$table] ?? [] as $target) {
                if (isset($set[$target])) {
                    $needs[$table][$target] = false;
                }
            }
        }

        $ordered = [];
        $pending = $needs;

        while ($pending !== []) {
            $ready = array_keys(array_filter($pending, fn (array $deps) => array_diff_key($deps, array_flip($ordered)) === []));

            if ($ready === []) {
                // Un ciclo: se suelta la dependencia de una columna que
                // admite null (se completa al final de la importación).
                $table = array_key_first(array_filter($pending, fn (array $deps) => in_array(true, $deps, true)));

                if ($table === null) {
                    throw new \LogicException('Hay un ciclo de referencias obligatorias entre: '.implode(', ', array_keys($pending)));
                }

                $pending[$table] = array_filter($pending[$table], fn (bool $nullable) => ! $nullable);

                continue;
            }

            sort($ready);

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($pending[$table]);
            }
        }

        return [...$ordered, ...$last];
    }

    private function loadColumns(string $table): void
    {
        if (isset($this->columns[$table])) {
            return;
        }

        $this->columns[$table] = [];
        $this->nullable[$table] = [];

        foreach (Schema::getColumns($table) as $column) {
            $this->columns[$table][] = $column['name'];
            $this->nullable[$table][$column['name']] = (bool) $column['nullable'];
        }
    }
}
