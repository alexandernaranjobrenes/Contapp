{{-- Un ícono Lucide del sitio público, dibujado en el servidor (SiteIcons).
     <x-site.icon name="bot" :size="20" class="…" label="Texto, si el ícono dice algo solo" /> --}}
@props(['name', 'size' => 16, 'label' => null])
{!! \App\Domains\Site\Support\SiteIcons::svg($name, (int) $size, (string) $attributes->get('class', ''), $label) !!}
