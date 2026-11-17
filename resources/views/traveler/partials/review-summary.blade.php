{{-- The average and the 5→1 breakdown. Included by reviews.blade.php,
     which supplies $floating, $avgRating, $ratingCounts and $totalReviews. --}}
@if ($totalReviews > 0)
<div class="atd-rev-summary {{ $floating ? '' : 'atd-rev-summary-inline' }}">
    <div class="atd-rev-score">
        <div class="atd-rev-score-num">{{ number_format($avgRating, 1) }}</div>
        <div class="atd-review-stars" style="font-size:16px;">
            @for ($i = 1; $i <= 5; $i++)
                <i class="fa-{{ $i <= round($avgRating) ? 'solid' : 'regular' }} fa-star"></i>
            @endfor
        </div>
        <div class="atd-rev-score-count">{{ $totalReviews }} {{ \Illuminate\Support\Str::plural('rating', $totalReviews) }}</div>
    </div>
    <div class="atd-rev-bars">
        @for ($star = 5; $star >= 1; $star--)
        @php
            $count = $ratingCounts[$star] ?? 0;
            $pct   = $totalReviews > 0 ? round($count / $totalReviews * 100) : 0;
        @endphp
        <div class="atd-rev-bar-row">
            <span>{{ $star }}</span>
            <div class="atd-rev-bar-track"><div class="atd-rev-bar-fill" style="width:{{ $pct }}%;"></div></div>
            <span>{{ $count }}</span>
        </div>
        @endfor
    </div>
</div>
@endif
