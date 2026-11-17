/**
 * Create Account: the Country search box.
 *
 * A text box over the list of every country and territory. Typing filters the
 * list as you go — ignoring case and accents, so "aland" finds Åland Islands
 * and "cote" finds Côte d'Ivoire. Click a country, or move with ↑/↓ and press
 * Enter, to pick it; Escape closes the list.
 *
 * The box has no name of its own. Only a country actually picked from the
 * list is copied into the hidden "country" field the form submits, so a
 * half-typed "Phili" is never saved as somebody's country. Leaving the box
 * puts back the last country picked (or empties it if there was none).
 */
(function () {
    var combo  = document.getElementById('countryCombo');
    if (!combo) return;

    var input  = document.getElementById('country');
    var hidden = document.getElementById('countryValue');
    var list   = document.getElementById('countryList');
    var empty  = list.querySelector('.country-combo-empty');
    var options = [].slice.call(list.querySelectorAll('.country-combo-option'));

    // "Åland Islands" → "aland islands": decompose, drop the accent marks.
    function fold(text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }
    options.forEach(function (o) { o._key = fold(o.dataset.value); });

    var active = null;
    var picking = false;   // true while pick() announces its own change

    // In the order they are shown, which filter() re-ranks as you type.
    function visible() {
        return [].slice.call(list.querySelectorAll('.country-combo-option:not([hidden])'));
    }

    function setActive(option) {
        if (active) active.classList.remove('is-active');
        active = option;
        if (active) {
            active.classList.add('is-active');
            input.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    // Best matches first: names that start with what was typed ("ma" →
    // Madagascar, Malaysia…), then names with a word that does ("Isle of
    // Man"), then anything else containing it ("Bahamas"). Each group keeps
    // alphabetical order; with nothing typed the full list is alphabetical.
    function rank(key, q) {
        if (key.indexOf(q) === 0) return 0;
        if ((' ' + key.replace(/[^a-z0-9]+/g, ' ')).indexOf(' ' + q) !== -1) return 1;
        return key.indexOf(q) !== -1 ? 2 : -1;
    }

    function filter(query) {
        var q = fold(query);
        var groups = [[], [], []];
        var unmatched = [];

        options.forEach(function (o) {
            var r = q === '' ? 0 : rank(o._key, q);
            o.hidden = r === -1;
            (r === -1 ? unmatched : groups[r]).push(o);
        });

        groups[0].concat(groups[1], groups[2], unmatched).forEach(function (o) { list.appendChild(o); });
        list.appendChild(empty);

        empty.hidden = unmatched.length < options.length;
        list.scrollTop = 0;
        setActive(null);
    }

    function open() {
        if (!list.hidden) return;
        list.hidden = false;
        combo.classList.add('is-open');
        input.setAttribute('aria-expanded', 'true');

        // Opening on the country already picked shows the whole list with that
        // one in view, rather than a list filtered down to just itself.
        var picked = hidden.value;
        filter(picked && input.value === picked ? '' : input.value);
        var current = options.filter(function (o) { return o.dataset.value === picked; })[0];
        if (current && !current.hidden) setActive(current);
    }

    function close() {
        if (list.hidden) return;
        list.hidden = true;
        combo.classList.remove('is-open');
        input.setAttribute('aria-expanded', 'false');
        setActive(null);
    }

    function pick(option) {
        hidden.value = option.dataset.value;
        input.value  = option.dataset.value;
        options.forEach(function (o) { o.setAttribute('aria-selected', o === option ? 'true' : 'false'); });
        close();
        // Lets auth-form.js clear any error shown under the field.
        picking = true;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        picking = false;
    }

    // Leaving the box keeps only a real pick. Typing a country's exact name and
    // walking away counts as picking it.
    function settle() {
        var typed = fold(input.value);
        if (typed === '') {
            hidden.value = '';
            options.forEach(function (o) { o.setAttribute('aria-selected', 'false'); });
            return;
        }
        var exact = options.filter(function (o) { return o._key === typed; })[0];
        if (exact) { pick(exact); return; }
        input.value = hidden.value;
    }

    input.addEventListener('focus', open);
    input.addEventListener('click', open);

    input.addEventListener('input', function () {
        if (picking) return;   // our own event from pick(), not typing
        open();
        filter(input.value);
        var first = visible()[0];
        if (first && input.value.trim() !== '') setActive(first);
    });

    input.addEventListener('keydown', function (e) {
        var shown = visible();

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (list.hidden) { open(); return; }
            if (!shown.length) return;
            var i = shown.indexOf(active);
            i = e.key === 'ArrowDown' ? Math.min(i + 1, shown.length - 1) : Math.max(i - 1, 0);
            setActive(shown[i]);
        } else if (e.key === 'Enter') {
            // With the list open, Enter picks — it must not submit the form.
            if (!list.hidden) {
                e.preventDefault();
                if (active) pick(active);
                else if (shown.length === 1) pick(shown[0]);
            }
        } else if (e.key === 'Escape') {
            if (!list.hidden) {
                e.preventDefault();
                close();
                input.value = hidden.value;
            }
        } else if (e.key === 'Tab') {
            if (active) pick(active);
            close();
        }
    });

    input.addEventListener('blur', function () {
        close();
        settle();
    });

    // mousedown, not click: a click lands after the box has already blurred
    // and closed the list.
    list.addEventListener('mousedown', function (e) {
        var option = e.target.closest('.country-combo-option');
        e.preventDefault();
        if (option) pick(option);
    });

    list.addEventListener('mousemove', function (e) {
        var option = e.target.closest('.country-combo-option');
        if (option && option !== active) setActive(option);
    });
})();
