<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Support\MediaStorage;
use App\Domains\Core\Support\ProfilePhoto;
use App\Domains\Payroll\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * La fotografía de un empleado, en el disco de imágenes (MediaStorage: el
 * bucket de Cloudflare R2), docs/decisiones.md 2026-10-09.
 *
 * - Una por empleado, con nombre fijo:
 *   «companies/employee_photos/company_{compañía}/{nombre}_{id}.{ext}».
 * - Se guarda como la foto de perfil (ProfilePhoto): derecha, cuadrada, de
 *   512 px y sin los metadatos que trae una foto de teléfono (el GPS de dónde
 *   se tomó), porque la dirección es pública.
 * - En el bucket no queda basura: al reemplazarla, la anterior se pisa (misma
 *   ruta) o se borra (otro formato); al quitarla o al eliminar al empleado,
 *   se borra. Si cambia el nombre, la foto se muda a su ruta nueva.
 * - La anterior se borra recién con la nueva guardada: si la subida falla,
 *   el empleado conserva su foto.
 */
class EmployeePhotoService
{
    public function __construct(private readonly MediaStorage $media) {}

    /** «companies/employee_photos/company_3/ana_mora_rojas_7.jpg» */
    public static function pathFor(Employee $employee, string $extension): string
    {
        $name = Str::limit(Str::slug($employee->fullName(), '_'), 60, '') ?: 'empleado';

        return "companies/employee_photos/company_{$employee->company_id}/{$name}_{$employee->id}.{$extension}";
    }

    /**
     * Guarda la foto nueva y saca la anterior del bucket. Devuelve null si
     * quedó guardada, o el motivo si no (y el empleado sigue con la suya).
     */
    public function replace(Employee $employee, UploadedFile $file): ?string
    {
        $photo = ProfilePhoto::normalize($file);

        if ($photo === null) {
            return 'No pudimos leer esa imagen. Probá con otra en '.ProfilePhoto::formatsLabel().'.';
        }

        $path = self::pathFor($employee, $photo['extension']);

        if (! $this->media->put($path, $photo['contents'])) {
            return 'No se pudo guardar la fotografía. Probá de nuevo en un momento.';
        }

        $previous = $employee->photo_path;

        $employee->forceFill(['photo_path' => $path])->save();
        // Con la misma ruta, la dirección no cambia: la fecha de la ficha va
        // en la dirección (?v=…) para que el navegador no muestre la vieja.
        $employee->touch();

        // Con la misma ruta, la nueva ya pisó a la anterior. Con otra (otro
        // formato, o una ruta de antes) la anterior se borra.
        if ($previous !== null && $previous !== $path) {
            $this->media->delete($previous);
        }

        return null;
    }

    /** Quita la foto: de la ficha y del bucket. */
    public function remove(Employee $employee): void
    {
        $previous = $employee->photo_path;

        if ($previous === null) {
            return;
        }

        $employee->forceFill(['photo_path' => null])->save();
        $this->media->delete($previous);
    }

    /**
     * Si la ruta ya no es la que le toca (cambió el nombre, o es una foto de
     * antes, «employees/…»), la foto se muda. Si no se puede, queda donde
     * estaba: sigue sirviendo.
     */
    public function followName(Employee $employee): void
    {
        $current = $employee->photo_path;

        if ($current === null) {
            return;
        }

        $expected = self::pathFor($employee, strtolower(pathinfo($current, PATHINFO_EXTENSION)) ?: 'jpg');

        if ($expected !== $current && $this->media->move($current, $expected)) {
            $employee->forceFill(['photo_path' => $expected])->save();
        }
    }

    /** El empleado se eliminó: su foto también. */
    public function forget(?string $path): void
    {
        $this->media->delete($path);
    }
}
