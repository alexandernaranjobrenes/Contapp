<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\Company;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Payroll\Exceptions\InvalidPayrollException;
use App\Domains\Payroll\Models\PayrollEntry;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Payroll\Services\PayslipDocument;
use App\Mail\PayslipMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El comprobante de pago del trabajador, en sus cuatro salidas: pantalla,
 * impresión, PDF y correo.
 *
 * Los datos los arma PayslipDocument, una sola vez, para las cuatro. Ver su
 * encabezado: si cada salida armara los suyos, el comprobante que el trabajador
 * recibe por correo podría no coincidir con el que firma en papel.
 *
 * ── El envío por correo va a la COLA ─────────────────────────────────────
 *
 * Una planilla de cien personas son cien correos con un PDF cada uno. Mandarlos
 * dentro de la petición la haría expirar a mitad de camino, y entonces nadie
 * sabría cuántos salieron. Encolados, la pantalla contesta de inmediato y el
 * despacho lo hace el trabajador de cola.
 *
 * Consecuencia operativa que conviene tener presente: si no hay un proceso
 * `queue:work` corriendo, los correos quedan esperando en la tabla `jobs` sin
 * salir. No es un error del envío — es que falta quien los despache.
 */
class PayslipController extends Controller
{
    public function __construct(private readonly PayslipDocument $document) {}

    public function show(int $entry, CurrentCompany $currentCompany): Response
    {
        return Inertia::render('Payroll/Payslips/Show', $this->payload($entry, $currentCompany));
    }

    /** La versión para imprimir: el mismo contenido sin la navegación. */
    public function print(int $entry, CurrentCompany $currentCompany): Response
    {
        return Inertia::render('Payroll/Payslips/Print', $this->payload($entry, $currentCompany));
    }

    /** El PDF, que es el que se archiva y el que se adjunta al correo. */
    public function pdf(int $entry, CurrentCompany $currentCompany): HttpResponse
    {
        $company = Company::findOrFail($currentCompany->id());
        $model = $this->entryOrFail($company, $entry);

        return $this->document->pdf($company, $model)
            ->download($this->document->filename($model));
    }

    /**
     * Manda el comprobante al correo del trabajador.
     *
     * Si la ficha no tiene correo no se falla en silencio: se dice de quién
     * es, porque el único arreglo posible es llenarle la ficha.
     */
    public function email(int $entry, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());
        $model = $this->entryOrFail($company, $entry);

        $email = $model->employee?->email;

        if ($email === null || trim($email) === '') {
            return back()->withErrors(['payroll' => 'La ficha de '.($model->employee?->fullName() ?? 'el trabajador').
                ' no tiene correo electrónico: hay que llenarlo antes de poder enviarle el comprobante.']);
        }

        Mail::to($email)->queue(new PayslipMail($company->id, $model->id));

        return back()->with('success',
            "Comprobante en camino a {$email}. Si no llega, revisá que el despachador de correos esté corriendo.");
    }

    /**
     * Manda los comprobantes de TODO un período.
     *
     * ── No es todo o nada, y es a propósito ─────────────────────────────
     *
     * Con cincuenta trabajadores, que uno no tenga correo no puede impedir que
     * los otros cuarenta y nueve reciban el suyo. Así que se mandan los que se
     * pueden y se informa, por nombre, a quiénes no se les pudo: es una lista
     * de fichas por completar, no un error.
     *
     * Es la decisión opuesta a la del proceso masivo de vacaciones, que sí es
     * todo o nada. La diferencia es que ahí se está moviendo un saldo —dejarlo
     * a medias descuadra el control— y acá solo se está avisando algo.
     */
    public function emailPeriod(int $payrollPeriod, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());
        $period = PayrollPeriod::findOrFail($payrollPeriod);

        if (! in_array($period->status, ['calculated', 'approved', 'posted', 'closed'], true)) {
            return back()->withErrors(['payroll' => 'El período todavía no está calculado: no hay comprobantes que enviar.']);
        }

        $entries = PayrollEntry::withoutGlobalScope(CompanyScope::class)
            ->with('employee:id,code,first_name,last_name1,last_name2,email')
            ->where('payroll_period_id', $period->id)
            ->get();

        $sent = 0;
        $missing = [];

        foreach ($entries as $entry) {
            $email = $entry->employee?->email;

            if ($email === null || trim($email) === '') {
                $missing[] = $entry->employee?->code.' '.$entry->employee?->fullName();

                continue;
            }

            Mail::to($email)->queue(new PayslipMail($company->id, $entry->id));
            $sent++;
        }

        $message = "Se encolaron {$sent} comprobante(s) de {$period->name}.";

        if ($missing !== []) {
            $message .= ' Sin correo en la ficha, así que no se les envió: '.
                implode('; ', array_slice($missing, 0, 10)).
                (count($missing) > 10 ? ' y '.(count($missing) - 10).' más.' : '.');
        }

        return back()->with('success', $message);
    }

    /** @return array<string, mixed> */
    private function payload(int $entry, CurrentCompany $currentCompany): array
    {
        $company = Company::findOrFail($currentCompany->id());

        return $this->document->payload($company, $this->entryOrFail($company, $entry));
    }

    /**
     * La boleta, comprobando que sea de la compañía activa.
     *
     * ── Por qué no alcanza con findOrFail ───────────────────────────────
     *
     * `payroll_entries` no tiene `company_id` —su compañía la hereda del
     * período— así que el modelo no lleva el scope de compañía y un
     * `findOrFail($id)` encuentra la boleta de CUALQUIER empresa. Con eso,
     * alguien con permiso de planillas en su propia compañía podía abrir el
     * comprobante de un trabajador de otra probando números de id, y con el
     * envío por correo habría podido además mandárselo a un tercero.
     *
     * El filtro se hace por el período, que sí sabe de qué compañía es. Se
     * contesta 404 y no 403 a propósito: confirmar que la boleta existe pero es
     * de otra empresa ya es información.
     */
    private function entryOrFail(Company $company, int $entryId): PayrollEntry
    {
        try {
            return $this->document->findEntry($company, $entryId);
        } catch (InvalidPayrollException) {
            abort(404, 'Esa boleta no existe en esta compañía.');
        }
    }
}
