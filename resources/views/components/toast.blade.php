@props(['id' => 'appToast', 'message' => null])
{{-- Floating success pill, lower right ("Trip added successfully!").

     Rendered with a message, it plays once on load: the whole show-hold-hide
     is a CSS animation (.app-toast.is-showing in style.css), so it needs no
     script and survives wire:navigate page swaps. Rendered empty, it waits
     for window.budgetraToast(message, id) — the profile page's
     "Save Changes" uses that, since it saves without a reload. --}}
<div id="{{ $id }}" class="app-toast{{ $message ? ' is-showing' : '' }}" role="status" aria-live="polite">
    <span class="app-toast-icon"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
    <span class="app-toast-msg">{{ $message }}</span>
</div>
@once
<script>
    window.budgetraToast = function (message, id) {
        var toast = document.getElementById(id || 'appToast');
        if (!toast) return;
        toast.querySelector('.app-toast-msg').textContent = message;
        // Restart the animation even if a previous pill is still showing.
        toast.classList.remove('is-showing');
        void toast.offsetWidth;
        toast.classList.add('is-showing');
    };
</script>
@endonce
