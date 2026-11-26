@php
    $empresa = \Filament\Facades\Filament::getTenant();
@endphp

{{-- Marca Vendix: ícono "VD" (public/images/marca) + nombre + empresa activa. En el login
     (sin empresa) se muestra el logotipo horizontal completo. --}}
<div {{ $attributes->class(['fi-logo', 'sidebar-brand']) }}>
    @if ($empresa)
        <img src="{{ asset('images/marca/vendix-icono-64.png') }}" alt="" class="sidebar-brand-icono" width="36" height="36">
        <span class="sidebar-brand-texto">
            <span class="sidebar-brand-nombre">Vendix</span>
            <span class="sidebar-brand-subtitulo">{{ $empresa->getFilamentName() }}</span>
        </span>
    @else
        <img src="{{ asset('images/marca/vendix-logo.png') }}" alt="Vendix" class="vendix-logo-login">
    @endif
</div>
