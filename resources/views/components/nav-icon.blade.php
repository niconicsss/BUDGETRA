@props(['icon', 'style' => '', 'svg' => false])
{{-- One icon slot, two kinds of icon.

     A value starting with "fa-" is a Font Awesome class and is drawn as a
     glyph, which is how the chevron, the search magnifier and the profile
     row still work. Anything else is the basename of a PNG in
     public/systemicons, painted through .app-icon's mask.

     :svg="true" uses systemicons/{name}.svg instead when one exists (PNG
     otherwise) — the traveler sidebar sets it. An icon that really did get
     its SVG also carries .app-icon-svg, so CSS can size those separately.

     Keeping both behind one tag means a row can move between the two by
     changing a single array value, and nothing downstream has to know. --}}
@if (str_starts_with($icon, 'fa-'))
<i class="{{ $icon }}"@if ($style) style="{{ $style }}"@endif></i>
@else
@php $usesSvg = $svg && is_file(public_path("systemicons/{$icon}.svg")); @endphp
<i class="app-icon{{ $usesSvg ? ' app-icon-svg' : '' }}" style="{{ system_icon($icon, 'app-icon', (bool) $svg) }}{{ $style ? ';' . $style : '' }}"></i>
@endif
