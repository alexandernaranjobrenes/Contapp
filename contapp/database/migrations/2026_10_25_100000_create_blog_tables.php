<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El blog del sitio público (CLAUDE.md secc. 41). Lo escribe el backoffice
     * con un editor como Word; lo lee cualquiera en /blog. Global, sin
     * compañía.
     *
     * - blog_posts.content es HTML ya limpio (BlogHtml): solo las etiquetas y
     *   atributos permitidos. Una imagen va como <img data-image-id="…">, sin
     *   su dirección: se arma al mostrarla, así un cambio de dominio del bucket
     *   no rompe nada.
     * - blog_post_slugs guarda las direcciones anteriores de un artículo: la
     *   vieja redirige a la nueva con un 301, y Google no pierde lo que ya
     *   indexó.
     * - blog_images son las imágenes del bucket, como blog/imag_{id}.{ext}.
     *   Una recién subida queda sin artículo (post_id nulo) hasta que un
     *   guardado la usa; si nadie la usa en un día, se borra.
     * - news_posts.blog_post_id: la noticia que avisa de un artículo. Su
     *   imagen es la portada del artículo y su enlace, el del artículo.
     */
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('slug', 100)->unique();
            $table->string('description', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->string('title', 200);
            $table->string('slug', 200)->unique();
            $table->string('excerpt', 300)->nullable();
            $table->longText('content');
            $table->string('status', 10)->default('draft');
            $table->timestamp('published_at')->nullable();
            // La portada, una de blog_images. Sin llave foránea a propósito:
            // blog_images también apunta a blog_posts, y dos llaves cruzadas
            // traban crear y borrar. La valida BlogPostService.
            $table->unsignedBigInteger('cover_image_id')->nullable()->index();
            $table->string('cover_alt', 200)->nullable();
            $table->string('author_name', 120)->nullable();
            $table->string('seo_title', 70)->nullable();
            $table->string('meta_description', 170)->nullable();
            $table->string('focus_keyword', 80)->nullable();
            $table->string('canonical_url', 500)->nullable();
            $table->boolean('noindex')->default(false);
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedSmallInteger('reading_minutes')->default(1);
            // El aviso en Noticias: se publica junto con el artículo.
            $table->boolean('announce_in_news')->default(false);
            $table->string('news_title', 150)->nullable();
            $table->text('news_body')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('blog_post_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->string('slug', 200)->unique();
            $table->timestamps();
        });

        Schema::create('blog_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->nullable()->constrained('blog_posts')->nullOnDelete();
            $table->string('path')->nullable();
            $table->string('mime', 40)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('propietarios')->nullOnDelete();
            $table->timestamps();

            $table->index(['post_id', 'created_at']);
        });

        Schema::table('news_posts', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->nullable()->unique()->after('image_path')->constrained('blog_posts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('news_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('blog_post_id');
        });

        Schema::dropIfExists('blog_images');
        Schema::dropIfExists('blog_post_slugs');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_categories');
    }
};
