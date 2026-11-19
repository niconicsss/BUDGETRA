/*
 * Instant feedback for buttons that submit a normal form (a full page load).
 *
 * The moment such a form really submits, its submit button dims and stops
 * taking clicks, so it is obvious the click landed and a second click can't
 * send the form twice. Livewire buttons need none of this: Livewire 4 sets
 * data-loading on whatever element started a request, and style.css styles
 * that the same way.
 *
 * Left alone:
 *  - Submits something cancelled with preventDefault(): the fetch-based forms
 *    (auth-form.js, which shows its own spinner), Livewire's wire:submit, and
 *    a confirm() the traveler said no to. The check waits a tick so it sees
 *    every listener's verdict, not just the ones registered before this one.
 *  - Forms that never leave the page: target="_blank", and anything marked
 *    data-no-busy, such as a form whose response is a file download.
 */
(function () {
    // wire:navigate re-runs body scripts on every page; one listener is enough.
    if (window.budgetraPressFeedback) return;
    window.budgetraPressFeedback = true;

    // A safety net for a response that never replaces the page (a download
    // nobody marked data-no-busy): give the button back rather than leave it
    // dead.
    var GIVE_BACK_AFTER_MS = 15000;

    function release(button) {
        button.removeAttribute('data-submitting');
        button.removeAttribute('aria-busy');
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        var button = e.submitter;
        if (!button || !(form instanceof HTMLFormElement)) return;
        if (form.hasAttribute('data-no-busy') || button.hasAttribute('data-no-busy')) return;
        if ((form.getAttribute('target') || '_self') !== '_self') return;

        setTimeout(function () {
            if (e.defaultPrevented) return;
            button.setAttribute('data-submitting', '');
            button.setAttribute('aria-busy', 'true');
            setTimeout(function () { release(button); }, GIVE_BACK_AFTER_MS);
        }, 0);
    });

    // Back/forward can restore this page from the browser's cache exactly as
    // it was left, dimmed button included.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('[data-submitting]').forEach(release);
    });
})();
