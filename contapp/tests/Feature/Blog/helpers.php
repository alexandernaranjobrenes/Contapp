<?php

use App\Domains\Blog\Models\BlogCategory;
use App\Domains\Blog\Models\BlogImage;
use App\Domains\Blog\Models\BlogPost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Ayudas de las pruebas del blog (CLAUDE.md secc. 41). El disco «s3» tiene
| que estar falso (Storage::fake) y ser el de las imágenes
| (filesystems.media) antes de usarlas.
*/

if (! function_exists('blogUpload')) {
    /** Una imagen de verdad (GD) para subir, JPG o PNG según el nombre. */
    function blogUpload(string $name = 'portada.jpg', int $width = 1200, int $height = 630): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 11, 31, 58));

        ob_start();
        str_ends_with($name, '.png') ? imagepng($image) : imagejpeg($image, null, 90);

        return UploadedFile::fake()->createWithContent($name, ob_get_clean());
    }
}

if (! function_exists('blogImage')) {
    /**
     * Una imagen ya subida, con su archivo en el bucket falso, sin pasar por
     * GD (el PHP del host no lo trae).
     */
    function blogImage(?BlogPost $post = null, string $extension = 'jpg'): BlogImage
    {
        $image = BlogImage::create(['post_id' => $post?->id, 'mime' => 'image/jpeg', 'width' => 1200, 'height' => 630, 'size' => 1000]);
        $path = BlogImage::pathFor($image->id, $extension);

        Storage::disk('s3')->put($path, 'imagen');
        $image->forceFill(['path' => $path])->save();

        return $image;
    }
}

if (! function_exists('blogPost')) {
    /** Un artículo publicado, con su portada y su texto. */
    function blogPost(array $attributes = []): BlogPost
    {
        $post = BlogPost::create([
            'title' => 'Cómo emitir una factura electrónica',
            'slug' => 'como-emitir-una-factura-electronica',
            'excerpt' => 'Los pasos para emitir una factura electrónica v4.4 desde Pivox.',
            'content' => '<h2>Antes de empezar</h2><p>Revisá los datos del emisor.</p>',
            'status' => BlogPost::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'word_count' => 8,
            'reading_minutes' => 1,
            ...$attributes,
        ]);

        if (! array_key_exists('cover_image_id', $attributes)) {
            $cover = blogImage($post);
            $post->forceFill(['cover_image_id' => $cover->id, 'cover_alt' => 'Una factura electrónica'])->save();
        }

        return $post->fresh();
    }
}

if (! function_exists('blogCategory')) {
    function blogCategory(string $name = 'Facturación electrónica', string $slug = 'facturacion-electronica'): BlogCategory
    {
        return BlogCategory::create(['name' => $name, 'slug' => $slug, 'description' => "Todo sobre {$name}."]);
    }
}

if (! function_exists('blogPayload')) {
    /** Lo que manda el editor al guardar. */
    function blogPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Cierre fiscal en Costa Rica',
            'slug' => '',
            'excerpt' => 'Qué revisar antes del cierre del período fiscal.',
            'content' => '<h2>Antes del cierre</h2><p>Conciliá los bancos y revisá las partidas abiertas.</p>',
            'category_id' => null,
            'cover_image_id' => null,
            'cover_alt' => '',
            'author_name' => 'Ana Mora',
            'seo_title' => '',
            'meta_description' => '',
            'focus_keyword' => '',
            'canonical_url' => '',
            'noindex' => 0,
            'status' => BlogPost::STATUS_DRAFT,
            'published_at' => '',
            'announce_in_news' => 0,
            'news_title' => '',
            'news_body' => '',
        ], $overrides);
    }
}
