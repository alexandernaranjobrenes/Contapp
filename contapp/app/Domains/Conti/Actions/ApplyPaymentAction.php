<?php

namespace App\Domains\Conti\Actions;

use App\Domains\Accounting\DataTransferObjects\JournalLineInput;
use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Services\PostJournalService;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\BusinessPartners\Services\ApplyPaymentService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DocumentType;
use App\Models\User;

/**
 * Un cobro o un pago aplicado a una partida abierta, como «Aplicar pago» en
 * las partidas del socio (OpenItemController::applyPayment): contabiliza el
 * movimiento contra la cuenta de pago y lo aplica a la partida.
 */
final class ApplyPaymentAction extends BaseContiAction
{
    public function key(): string
    {
        return 'aplicar_pago';
    }

    public function label(): string
    {
        return 'Aplicar un cobro o un pago a una partida';
    }

    public function description(): string
    {
        return 'Registra el cobro de un cliente o el pago a un proveedor contra una partida abierta (busque su id en «partidas-abiertas»): '
            .'contabiliza el movimiento contra la cuenta de banco o caja y rebaja el saldo de la partida.';
    }

    public function screen(): string
    {
        return 'business_partners.partners';
    }

    public function fields(): array
    {
        return [
            'partida' => 'id de la partida abierta (de «partidas-abiertas»)',
            'cuenta_pago' => 'código de la cuenta de banco o caja',
            'monto' => 'monto a aplicar; por defecto el saldo completo de la partida',
            'fecha' => 'AAAA-MM-DD; por defecto hoy',
        ];
    }

    public function prepare(array $input, Company $company, User $user): PreparedAction
    {
        $input['fecha'] = ($input['fecha'] ?? '') !== '' ? $input['fecha'] : now()->format('Y-m-d');

        $this->validate($input, [
            'partida' => ['required', 'integer'],
            'cuenta_pago' => ['required', 'string'],
            'monto' => ['nullable', 'numeric', 'gt:0'],
            'fecha' => ['required', 'date'],
        ]);

        // whereHas: la partida no lleva company_id propio; la aísla su socio.
        $item = BpOpenItem::whereHas('businessPartner')->with(['businessPartner', 'currency:id,code'])->find((int) $input['partida'])
            ?? $this->fail('partida', "No existe la partida #{$input['partida']} en esta compañía.");

        if ($item->status === 'closed') {
            $this->fail('partida', "La partida #{$item->id} ya está cerrada: no tiene saldo pendiente.");
        }

        $input['monto'] = ($input['monto'] ?? '') !== '' ? $input['monto'] : (string) $item->balance;
        $amount = bcadd((string) $input['monto'], '0', 2);

        if (bccomp($amount, (string) $item->balance, 2) > 0) {
            $this->fail('monto', "El monto ({$this->money($amount)}) es mayor que el saldo de la partida ({$this->money($item->balance)}).");
        }

        $account = $this->byCode(ChartOfAccount::class, $input['cuenta_pago'], 'cuenta_pago', 'una cuenta');

        if (! $account->accepts_posting || ! $account->is_active) {
            $this->fail('cuenta_pago', "La cuenta {$account->code} no acepta movimientos o está inactiva.");
        }

        /** @var BusinessPartner $partner */
        $partner = $item->businessPartner;
        $isSupplier = $partner->isSupplier();
        $date = date('Y-m-d', strtotime($input['fecha']));
        $remaining = bcsub((string) $item->balance, $amount, 2);

        return new PreparedAction(
            title: ($isSupplier ? 'Pago a ' : 'Cobro a ')."{$partner->code} {$partner->name}",
            items: $this->items([
                'Socio' => "{$partner->code} {$partner->name}",
                'Partida' => "#{$item->id} · ".trim(($item->document_type_code ?? '').'-'.($item->document_number ?? ''), '-'),
                'Saldo actual' => "{$item->currency?->code} {$this->money($item->balance)}",
                'Monto a aplicar' => "{$item->currency?->code} {$this->money($amount)}",
                'Saldo después' => "{$item->currency?->code} {$this->money($remaining)}".(bccomp($remaining, '0', 2) === 0 ? ' (queda cerrada)' : ''),
                'Cuenta de pago' => "{$account->code} {$account->description_es}",
                'Fecha' => $date,
                'Asiento' => $isSupplier
                    ? "Débito a la cuenta del proveedor, crédito a {$account->code}"
                    : "Débito a {$account->code}, crédito a la cuenta del cliente",
            ]),
            payload: ['open_item_id' => $item->id, 'account_id' => $account->id, 'amount' => $amount, 'date' => $date],
            input: $input,
        );
    }

    public function execute(PreparedAction $prepared, Company $company, User $user): ActionResult
    {
        $p = $prepared->payload;
        $item = BpOpenItem::whereHas('businessPartner')->findOrFail($p['open_item_id']);
        $partner = BusinessPartner::findOrFail($item->business_partner_id);
        $isSupplier = $partner->isSupplier();

        // El mismo tipo de documento que usa la pantalla para los pagos.
        $type = DocumentType::where('code', 'TRB')->first()
            ?? DocumentType::where('generates_journal', true)->firstOrFail();

        $entry = app(PostJournalService::class)->post(
            $company,
            $type,
            new \DateTime($p['date']),
            new \DateTime($p['date']),
            [
                new JournalLineInput($p['account_id'], $item->currency_id, debit: $isSupplier ? $p['amount'] : 0, credit: $isSupplier ? 0 : $p['amount']),
                new JournalLineInput($partner->gl_account_id, $item->currency_id, debit: $isSupplier ? 0 : $p['amount'], credit: $isSupplier ? $p['amount'] : 0, businessPartnerId: $partner->id),
            ],
            "Aplicación de pago — {$partner->code}",
            $user->id,
        );

        app(ApplyPaymentService::class)->apply($item, $entry, $p['amount'], new \DateTime($p['date']), createdBy: $user->id);

        return new ActionResult(
            'Pago aplicado a la partida #'.$item->id.' con el asiento '.$type->code.'-'.$entry->document_number.'.',
            route('business-partners.open-items', $partner->id),
            'Ver las partidas del socio',
        );
    }
}
