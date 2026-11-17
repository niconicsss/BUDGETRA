@extends('layouts.app')
@section('title', $attraction->name)

@push('styles')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
@endpush

@section('content')

@php
    $categoryMeta = [
        'Culture'   => ['icon' => 'fa-landmark',      'color' => '#C084FC'],
        'Nature'    => ['icon' => 'fa-leaf',          'color' => '#4ADE80'],
        'Adventure' => ['icon' => 'fa-person-hiking', 'color' => '#FB923C'],
        'Shopping'  => ['icon' => 'fa-bag-shopping',  'color' => '#60A5FA'],
    ][$attraction->category] ?? ['icon' => 'fa-map-pin', 'color' => 'var(--primary)'];
@endphp

<a href="{{ route('attractions.index') }}" class="atd-back">
    <i class="fa-solid fa-arrow-left"></i> All Attractions
</a>

{{-- Hero photo --}}
<div class="atd-hero">
    @if ($attraction->image)
    <img src="{{ asset('storage/' . $attraction->image) }}" alt="{{ $attraction->name }}" class="atd-hero-img">
    @else
    <div class="atd-hero-noimg"><i class="fa-solid fa-image"></i></div>
    @endif

    <div class="atd-hero-overlay">
        <div class="atd-hero-eyebrow">Attraction · {{ $attraction->destination }}</div>
        <h1 class="atd-hero-title">{{ $attraction->name }}</h1>
    </div>

    @if ($attraction->category)
    <span class="atd-chip atd-chip-category" style="--chip-color:{{ $categoryMeta['color'] }};">
        <i class="fa-solid {{ $categoryMeta['icon'] }}"></i> {{ $attraction->category }}
    </span>
    @endif
</div>

{{-- Travelers' reviews and the form to share yours — shared with the
     destination page (traveler/partials/reviews.blade.php). --}}
@include('traveler.partials.reviews', [
    'placeName'   => $attraction->name,
    'placeFields' => ['attraction_id' => $attraction->id],
    'floating'    => true,
])

<style>
    .atd-back {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 13px; font-weight: 600; color: var(--primary); text-decoration: none;
        background: none; border: none; padding: 0; margin-bottom: 10px;
    }
    .atd-back i { font-size: 11px; }

    .atd-hero {
        position: relative; border-radius: 22px; overflow: hidden;
        height: 420px; min-height: 420px; flex-shrink: 0;
        background: var(--bg);
        box-shadow: 0 12px 32px rgba(0,0,0,0.14);
    }
    .atd-hero-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .atd-hero-noimg {
        position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
        background: linear-gradient(150deg, var(--primary) 0%, #C9A84C 100%); color: rgba(255,255,255,.5); font-size: 48px;
    }
    .atd-chip {
        position: absolute; top: 18px; left: 18px; z-index: 2;
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 11.5px; font-weight: 700; letter-spacing: .02em; color: #fff;
        padding: 7px 14px; border-radius: 99px;
        background: color-mix(in srgb, var(--chip-color) 55%, rgba(0,0,0,.35));
        border: 1px solid rgba(255,255,255,.28);
        backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    }

    .atd-hero-overlay {
        position: absolute; inset: 0; z-index: 1;
        display: flex; flex-direction: column; justify-content: flex-end;
        /* Generous bottom padding — the floating rating-summary card below
           pulls up -70px over the hero's bottom edge, so the title needs
           enough clearance to never sit behind it. */
        padding: 28px 32px 110px;
        background: linear-gradient(180deg, rgba(0,0,0,0) 35%, rgba(0,0,0,.68) 100%);
    }
    .atd-hero-eyebrow {
        font-family: 'IBM Plex Mono', monospace; font-size: 12px; letter-spacing: .14em; text-transform: uppercase;
        color: rgba(255,255,255,.85); margin-bottom: 8px;
    }
    .atd-hero-title { font-family: 'Fraunces', serif; font-weight: 700; font-size: 36px; line-height: 1.1; color: #fff; margin: 0; }
    @media (max-width: 600px) {
        .atd-hero-overlay { padding: 20px 20px 90px; }
        .atd-hero-title { font-size: 28px; }
    }

</style>

@endsection
