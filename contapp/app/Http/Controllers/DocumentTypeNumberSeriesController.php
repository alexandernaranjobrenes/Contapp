<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Models\DocumentTypeNumberSeries;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentTypeNumberSeriesController extends Controller
{
    public function store(Request $request, int $documentType, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();
        $documentType = DocumentType::findOrFail($documentType);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('document_type_number_series')->where('company_id', $companyId)->where('document_type_id', $documentType->id),
            ],
            'holder_name' => ['nullable', 'string', 'max:255'],
            'range_from' => ['required', 'integer', 'min:1'],
            'range_to' => ['required', 'integer', 'gte:range_from'],
        ]);

        DocumentTypeNumberSeries::create([
            ...$validated,
            'company_id' => $companyId,
            'document_type_id' => $documentType->id,
            'next_number' => $validated['range_from'],
            'is_active' => true,
        ]);

        return back()->with('success', "Serie \"{$validated['name']}\" creada.");
    }

    public function update(Request $request, int $series): RedirectResponse
    {
        $series = DocumentTypeNumberSeries::findOrFail($series);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('document_type_number_series')
                    ->where('company_id', $series->company_id)
                    ->where('document_type_id', $series->document_type_id)
                    ->ignore($series->id),
            ],
            'holder_name' => ['nullable', 'string', 'max:255'],
            'range_to' => ['required', 'integer', function ($attribute, $value, $fail) use ($series) {
                $minimum = max($series->range_from, $series->next_number - 1);
                if ($value < $minimum) {
                    $fail("El número final no puede ser menor a {$minimum}: ya hay números asignados hasta ahí.");
                }
            }],
            'is_active' => ['boolean'],
        ]);

        $series->update($validated);

        return back()->with('success', "Serie \"{$series->name}\" actualizada.");
    }

    public function destroy(int $series): RedirectResponse
    {
        $series = DocumentTypeNumberSeries::findOrFail($series);

        // Igual que con cualquier catálogo: nada usado se borra. Una serie
        // que nunca asignó un número (next_number sigue en range_from) sí
        // se puede eliminar; una que ya emitió algo, solo se inactiva.
        if ($series->next_number !== $series->range_from) {
            return back()->withErrors([
                'series' => "La serie \"{$series->name}\" ya tiene números asignados; no se puede eliminar. Podés inactivarla en su lugar.",
            ]);
        }

        $series->delete();

        return back()->with('success', "Serie \"{$series->name}\" eliminada.");
    }
}
