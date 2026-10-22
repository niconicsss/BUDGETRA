@props(['active' => ''])
@php
    $profileInitials = collect(explode(' ', auth()->user()->full_name ?? ''))
        ->filter()
        ->map(fn ($p) => mb_substr($p, 0, 1))
        ->take(2)
        ->implode('');
@endphp
<aside class="admin-sidebar" id="adminSidebar">

    <div class="admin-sidebar-header">
        <button class="admin-sidebar-toggle-btn" id="adminSidebarToggle" title="Toggle sidebar">
            <i class="fa-solid fa-angle-left" id="adminSidebarToggleIcon" style="color: white;"></i>
        </button>
    </div>

    <div class="admin-sidebar-brand">
        <!-- Added admin-sidebar-logo-img class to apply the white filter to the logo -->
        <img src="{{ asset('systemicons/budgetraicon.png') }}" alt="Budgetra" class="admin-sidebar-logo admin-sidebar-logo-img">
        <div class="admin-sidebar-brand-text">
            <div class="admin-sidebar-name">Budgetra</div>
        </div>
    </div>
    <div class="admin-sidebar-divider admin-sidebar-divider-brand"></div>

    <nav class="admin-sidebar-nav">
        @php
        $primaryLinks = [
            ['href' => route('admin.dashboard'),          'icon_file' => 'dashboard.png',      'label' => 'Dashboard',      'key' => 'dashboard'],
            ['href' => route('admin.users.index'),        'icon_file' => 'user-accounts.png',  'label' => 'User Accounts',  'key' => 'users'],
            ['href' => route('admin.destinations.index'), 'icon_file' => 'destinations.png',   'label' => 'Destinations',   'key' => 'destinations'],
            ['href' => route('admin.attractions.index'),  'icon_file' => 'attractions.png',    'label' => 'Attractions',    'key' => 'attractions'],
            ['href' => route('admin.travel-costs.index'), 'icon_file' => 'travel-costs.png',   'label' => 'Travel Costs',   'key' => 'travel-costs'],
            ['href' => route('admin.reviews.index'),      'icon_file' => 'user-reviews.png',   'label' => 'User Reviews',   'key' => 'reviews'],
        ];
        @endphp
        
        <div class="admin-sidebar-primary">
            @foreach ($primaryLinks as $link)
            <a href="{{ $link['href'] }}" class="admin-sidebar-link {{ $active === $link['key'] ? 'active' : '' }}" title="{{ $link['label'] }}">
                <img src="{{ asset('systemicons/' . $link['icon_file']) }}" alt="{{ $link['label'] }} Icon" class="admin-sidebar-icon">
                <span class="admin-sidebar-label">{{ $link['label'] }}</span>
            </a>
            @endforeach
        </div>

        <div class="admin-sidebar-foot">
            <div class="admin-sidebar-divider"></div>
            
            <a href="{{ route('admin.profile.edit') }}" class="admin-sidebar-link {{ $active === 'profile' ? 'active' : '' }}" title="Profile">
                @if (auth()->user()?->profile_photo)
                <!-- Bypassed the filter here so the user's actual profile photo doesn't turn white -->
                <img src="{{ Illuminate\Support\Facades\Storage::url(auth()->user()->profile_photo) }}"
                     alt="Profile" class="admin-sidebar-avatar" style="width:20px; height:20px; border-radius:50%; object-fit:cover;">
                @else
                <span class="admin-sidebar-avatar admin-sidebar-avatar-initials" style="width:20px; height:20px; display:inline-block; text-align:center;">{{ $profileInitials }}</span>
                @endif
                <span class="admin-sidebar-label">Profile</span>
            </a>
            
            <a href="{{ route('admin.settings.index') }}" class="admin-sidebar-link {{ $active === 'settings' ? 'active' : '' }}" title="Settings">
                <img src="{{ asset('systemicons/settings.png') }}" alt="Settings Icon" class="admin-sidebar-icon">
                <span class="admin-sidebar-label">Settings</span>
            </a>
            
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="admin-sidebar-link admin-sidebar-logout" style="background:none; border:none; width:100%; text-align:left; cursor:pointer;" title="Logout">
                    <img src="{{ asset('systemicons/logout.png') }}" alt="Logout Icon" class="admin-sidebar-icon">
                    <span class="admin-sidebar-label">Logout</span>
                </button>
            </form>
        </div>
    </nav>
</aside>

<style>
/* Turns black PNGs into white icons */
.admin-sidebar-icon, 
.admin-sidebar-logo-img {
    filter: brightness(0) invert(1);
}

.admin-sidebar-icon {
    width: 20px;
    height: 20px;
    object-fit: contain;
}

.admin-sidebar-logo-img {
    width: 30px; /* Adjust based on your logo's ideal size */
    height: 30px;
    object-fit: contain;
}

/* For the slim sidebar effect */
.admin-sidebar-collapsed .admin-sidebar-label,
.admin-sidebar-collapsed .admin-sidebar-brand-text {
    display: none;
}
</style>

<script>
(function () {
    function applyState(collapsed) {
        var shell = document.getElementById('adminShell');
        var icon  = document.getElementById('adminSidebarToggleIcon');
        if (!shell) return;
        shell.classList.toggle('admin-sidebar-collapsed', collapsed);
        if (icon) icon.className = collapsed ? 'fa-solid fa-angle-right' : 'fa-solid fa-angle-left';
    }

    applyState(localStorage.getItem('adminSidebarCollapsed') === '1');

    if (!window.__adminSidebarToggleBound) {
        window.__adminSidebarToggleBound = true;
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#adminSidebarToggle')) return;
            var shell = document.getElementById('adminShell');
            if (!shell) return;
            var c = !shell.classList.contains('admin-sidebar-collapsed');
            localStorage.setItem('adminSidebarCollapsed', c ? '1' : '0');
            applyState(c);
        });
    }
})();
</script>