<section id="blog" class="site-section" aria-labelledby="home-blog-title">
    <div class="site-container">
        <div class="site-section-head home-posts-head" data-reveal>
            <div>
                <p class="site-eyebrow"><x-site.icon name="newspaper" /> Del blog</p>
                <h2 id="home-blog-title" class="site-h2">Para llevar tu contabilidad al día</h2>
            </div>
            <a href="{{ route('blog.index') }}" class="btn btn-ghost">Ver todos los artículos <x-site.icon name="arrow-right" /></a>
        </div>

        <div class="blog-grid">
            @foreach ($posts as $post)
                @include('site.blog.card', ['post' => $post, 'heading' => 'h3'])
            @endforeach
        </div>
    </div>
</section>
