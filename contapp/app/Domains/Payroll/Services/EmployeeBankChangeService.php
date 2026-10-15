<?php

namespace App\Domains\Payroll\Services;

use App\Domains\Core\Models\Company;
use App\Domains\Payroll\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeBankChangeNotification;
use Throwable;

/**
 * El cambio de la cuenta bancaria de un empleado (CLAUDE.md secc. 36).
 *
 * Cambiarle la cuenta a alguien justo antes de generar el archivo de pago es
 * el fraude más común en una planilla. Por eso:
 *
 * - El Superusuario la cambia directamente: es el dueño de la licencia.
 * - Cualquier otra persona deja el cambio pendiente, y al Superusuario le
 *   llega un correo para revisarlo. Mientras no lo apruebe, la planilla y el
 *   archivo de pago siguen con la cuenta confirmada.
 * - Aprobarlo o rechazarlo es solo del Superusuario.
 *
 * Al crear un empleado la cuenta queda tal cual: no hay una anterior que
 * proteger. Todo queda en la Bitácora (con la cuenta enmascarada).
 */
class EmployeeBankChangeService
{
    /**
     * @return 'unchanged'|'applied'|'pending'
     */
    public function request(Employee $employee, User $by, ?string $bankName, ?string $bankAccount): string
    {
        $bankName = filled($bankName) ? trim($bankName) : null;
        $bankAccount = filled($bankAccount) ? trim($bankAccount) : null;

        if ($bankName === $employee->bank_name && $bankAccount === $employee->bank_account) {
            return 'unchanged';
        }

        if ($by->isSuperAdmin($employee->company_id)) {
            $employee->forceFill([
                'bank_name' => $bankName,
                'bank_account' => $bankAccount,
                ...$this->noPending(),
            ])->save();

            return 'applied';
        }

        // El mismo cambio que ya espera: no se vuelve a avisar.
        if ($employee->hasPendingBankChange() && $bankName === $employee->pending_bank_name && $bankAccount === $employee->pending_bank_account) {
            return 'pending';
        }

        $employee->forceFill([
            'pending_bank_name' => $bankName,
            'pending_bank_account' => $bankAccount,
            'bank_change_requested_by' => $by->id,
            'bank_change_requested_at' => now(),
        ])->save();

        $this->notifySuperuser($employee, $by);

        return 'pending';
    }

    public function approve(Employee $employee): void
    {
        if (! $employee->hasPendingBankChange()) {
            return;
        }

        $employee->forceFill([
            'bank_name' => $employee->pending_bank_name,
            'bank_account' => $employee->pending_bank_account,
            ...$this->noPending(),
        ])->save();
    }

    public function reject(Employee $employee): void
    {
        $employee->forceFill($this->noPending())->save();
    }

    /** @return array<string, null> */
    private function noPending(): array
    {
        return ['pending_bank_name' => null, 'pending_bank_account' => null, 'bank_change_requested_by' => null, 'bank_change_requested_at' => null];
    }

    /** El correo al Superusuario. Si no sale, el cambio igual queda pendiente y se ve en la ficha. */
    private function notifySuperuser(Employee $employee, User $by): void
    {
        $company = Company::find($employee->company_id);
        $superuser = $company?->license?->superuser_id ? User::find($company->license->superuser_id) : null;

        if ($superuser === null || $superuser->is($by)) {
            return;
        }

        try {
            $superuser->notify(new EmployeeBankChangeNotification($employee, $by, $company->trade_name ?: $company->legal_name));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
