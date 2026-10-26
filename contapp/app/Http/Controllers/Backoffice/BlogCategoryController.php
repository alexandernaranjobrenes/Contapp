<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Blog\Models\BlogCategory;
use App\Domains\Site\Services\SitemapBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Las categorías del blog, desde su ventana en la lista de artículos
 * (CLAUDE.md secc. 41). La dirección (/blog/categoria/{slug}) sale del
 * nombre. Una categoría con artículos no se elimina.
 */
class BlogCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        BlogCategory::create([
            'name' => $validated['name'],
            'slug' => $this->slug($validated['name']),
            'description' => $validated['description'],
        ]);

        SitemapBuilder::forget();

        return back()->with('success', "La categoría {$validated['name']} quedó creada.");
    }

    public function update(Request $request, BlogCategory $category): RedirectResponse
    {
        $validated = $this->validated($request, $category);

        $category->update([
            'name' => $validated['name'],
            'slug' => $category->name === $validated['name'] ? $category->slug : $this->slug($validated['name'], $category),
            'description' => $validated['description'],
        ]);

        SitemapBuilder::forget();

        return back()->with('success', "Los cambios de la categoría {$category->name} quedaron guardados.");
    }

    public function destroy(BlogCategory $category): RedirectResponse
    {
        if ($category->posts()->exists()) {
            return back()->withErrors([
                'category' => "La categoría {$category->name} tiene artículos: pasalos a otra categoría antes de eliminarla.",
            ]);
        }

        $category->delete();
        SitemapBuilder::forget();

        return back()->with('success', "La categoría {$category->name} se eliminó.");
    }

    /** @return array{name: string, description: ?string} */
    private function validated(Request $request, ?BlogCategory $category = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.BlogCategory::MAX_NAME, Rule::unique('blog_categories', 'name')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:'.BlogCategory::MAX_DESCRIPTION],
        ], [], ['name' => 'nombre', 'description' => 'descripción']);

        $description = trim((string) ($validated['description'] ?? ''));

        return ['name' => trim($validated['name']), 'description' => $description === '' ? null : $description];
    }

    private function slug(string $name, ?BlogCategory $category = null): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;

        for ($n = 2; BlogCategory::query()->where('slug', $slug)->when($category, fn ($query) => $query->whereKeyNot($category->id))->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
