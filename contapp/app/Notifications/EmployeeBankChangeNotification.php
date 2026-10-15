<?php

namespace App\Notifications;

use App\Domains\Payroll\Models\Employee;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le avisa al Superusuario que alguien pidió cambiar la cuenta bancaria de
 * un empleado, y que espera su aprobación (EmployeeBankChangeService).
 *
 * Las cuentas van enmascaradas: un correo se reenvía y se guarda en lugares
 * que CONTAPP no controla. El detalle completo está en la ficha.
 *
 * No se encola: no hay worker de colas.
 */
class EmployeeBankChangeNotification extends Notification
{
    public function __construct(
        private readonly Employee $employee,
        private readonly User $requestedBy,
        private readonly string $companyName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Cambio de cuenta bancaria pendiente: {$this->employee->fullName()}")
            ->markdown('mail.employee-bank-change', [
                'name' => $notifiable->name ?? null,
                'employee' => trim($this->employee->code.' — '.$this->employee->fullName(), ' —'),
                'company' => $this->companyName,
                'requestedBy' => $this->requestedBy->name,
                'current' => $this->describe($this->employee->bank_name, $this->employee->bank_account),
                'requested' => $this->describe($this->employee->pending_bank_name, $this->employee->pending_bank_account),
                'url' => route('employees.show', $this->employee->id),
            ]);
    }

    private function describe(?string $bank, ?string $account): string
    {
        if (blank($account)) {
            return 'sin cuenta';
        }

        $masked = strlen($account) > 4 ? '••••'.substr($account, -4) : $account;

        return trim(($bank ? $bank.' ' : '').$masked);
    }
}
