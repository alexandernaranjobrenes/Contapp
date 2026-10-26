<?php

namespace App\Http\Controllers\Backoffice;

use App\Domains\Blog\Exceptions\BlogException;
use App\Domains\Blog\Services\BlogImageService;
use App\Domains\Feedback\Support\AttachedImage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Subir una imagen desde el editor del blog (CLAUDE.md secc. 41): la del
 * texto o la portada. Contesta JSON, porque el editor la inserta sin salir
 * de la pantalla; queda en el bucket como blog/imag_{id}.{ext}.
 */
class BlogImageController extends Controller
{
    public function store(Request $request, BlogImageService $images): JsonResponse
    {
        $rules = AttachedImage::validation('images', 1);

        $request->validate([
            'image' => ['required', ...$rules['rules']['images.*']],
        ], [
            'image.required' => 'Elegí una imagen.',
            'image.image' => $rules['messages']['images.*.image'],
            'image.mimes' => $rules['messages']['images.*.mimes'],
            'image.max' => $rules['messages']['images.*.max'],
            'image.dimensions' => $rules['messages']['images.*.dimensions'],
            'image.uploaded' => $rules['messages']['images.*.uploaded'],
        ]);

        try {
            $image = $images->store($request->file('image'), $request->user('propietario'));
        } catch (BlogException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['image' => [$e->getMessage()]]], 422);
        }

        return response()->json([
            'id' => $image->id,
            'url' => $image->url(),
            'width' => $image->width,
            'height' => $image->height,
        ], 201);
    }
}
