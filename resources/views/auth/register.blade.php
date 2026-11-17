<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budgetra</title>
    <link rel="icon" type="image/png" href="{{ asset('systemicons/budgetraicon.png') }}?v={{ filemtime(public_path('systemicons/budgetraicon.png')) }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    {{-- Versioned like the app layouts: without it the browser serves whatever
     copy of style.css it already had, and every change to this page's styling
     needs a hard refresh before anyone sees it. --}}
<link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}">
</head>
<body style="margin:0;padding:0;">

<div class="auth-wrapper">

    {{-- Left panel --}}
    <div class="auth-panel-left-register" style="position:relative;overflow:hidden;padding:0;">
        <div style="position:absolute;inset:0;background-image:url('{{ asset('stockimages/registersidebar.jpg') }}');background-size:cover;background-position:center;filter:blur(3px);transform:scale(1.05);"></div>
        <div style="position:absolute;inset:0;background:rgba(0,0,0,.25);"></div>
        <div style="position:relative;height:100%;display:flex;align-items:center;justify-content:center;">
            {{-- budgetra.png is a black line mark on an opaque white square, and
                 this panel is a blurred photo under a dark scrim — so it is
                 inverted and screen-blended to leave only white lines. See
                 .brand-mark-opaque (and why this wrapper has no z-index). --}}
            <img src="{{ asset('systemicons/budgetra.png') }}?v={{ filemtime(public_path('systemicons/budgetra.png')) }}"
                 alt="Budgetra" class="brand-mark-opaque" style="max-width:200px;width:60%;">
        </div>
    </div>

    {{-- Right panel --}}
    <div class="auth-panel-right">
        <div class="auth-form-wrap">
            <h1 class="auth-title">Create an Account</h1>

            {{-- No summary banner here: every validated field prints its own
                 message underneath itself, so this only ever repeated the
                 first of them a few hundred pixels higher up. --}}
            {{-- Same two classes as Sign In. auth-labels-inside: the field
                 names are the placeholders; the <label>s stay for screen
                 readers, hidden visually. auth-submit-errors-only: no red until
                 Create Account is pressed with something missing (cherry red
                 then), and typing in a field clears it. See style.css. --}}
            <form id="registerForm" method="POST" action="{{ route('register') }}" class="auth-labels-inside auth-submit-errors-only">
                @csrf

                {{-- First Name --}}
                <div class="form-group">
                    <label class="form-label" for="first_name">First Name</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><i class="fa-regular fa-user"></i></span>
                        <input id="first_name" type="text" name="first_name"
                               value="{{ old('first_name') }}"
                               class="form-control {{ $errors->has('first_name') ? 'is-invalid' : '' }}"
                               placeholder="First Name" required autofocus>
                    </div>
                    @error('first_name')<div class="error">{{ $message }}</div>@enderror
                </div>

                {{-- Last Name --}}
                <div class="form-group">
                    <label class="form-label" for="last_name">Last Name</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><i class="fa-regular fa-user"></i></span>
                        <input id="last_name" type="text" name="last_name"
                               value="{{ old('last_name') }}"
                               class="form-control {{ $errors->has('last_name') ? 'is-invalid' : '' }}"
                               placeholder="Last Name" required>
                    </div>
                    @error('last_name')<div class="error">{{ $message }}</div>@enderror
                </div>

                {{-- Email --}}
                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><i class="fa-regular fa-envelope"></i></span>
                        <input id="email" type="email" name="email"
                               value="{{ old('email') }}"
                               class="form-control {{ $errors->has('email') ? 'is-invalid' : '' }}"
                               placeholder="Email Address" required>
                    </div>
                    @error('email')<div class="error">{{ $message }}</div>@enderror
                </div>

                {{-- Password --}}
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><i class="fa-solid fa-lock"></i></span>
                        <input id="password" type="password" name="password"
                               class="form-control {{ $errors->has('password') ? 'is-invalid' : '' }}"
                               placeholder="Password" required>
                        <span class="input-suffix" id="togglePwd1" style="cursor:pointer;">
                            <i class="fa-regular fa-eye" id="eyeIcon1"></i>
                        </span>
                    </div>
                    @error('password')<div class="error">{{ $message }}</div>@enderror
                </div>

                {{-- Confirm Password --}}
                <div class="form-group">
                    <label class="form-label" for="password_confirmation">Confirm Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><i class="fa-solid fa-lock-open"></i></span>
                        <input id="password_confirmation" type="password" name="password_confirmation"
                               class="form-control" placeholder="Confirm Password" required>
                        <span class="input-suffix" id="togglePwd2" style="cursor:pointer;">
                            <i class="fa-regular fa-eye" id="eyeIcon2"></i>
                        </span>
                    </div>
                </div>

                @php
                    // The same list the Profile Builder draws its home-city
                    // options from — every country and territory, each with at
                    // least one city — so a traveler can never pick a home
                    // country that has no cities behind it.
                    // Sorted here rather than by hand: the config is grouped by
                    // region, and a country added to it should find its own
                    // place in this list instead of landing at the bottom.
                    // An English collator, not sort(): byte order would put
                    // "Åland Islands" after Zimbabwe.
                    $countries = array_keys(config('country_cities'));
                    (new \Collator('en'))->sort($countries);
                @endphp

                {{-- Country: a search box over the list, not a native <select>.
                     243 entries is too many to scroll, so typing filters them
                     as you go (country-combobox.js). The box itself has no
                     name; only a country actually picked from the list reaches
                     the hidden "country" field, so half-typed text is never
                     submitted. Optional, like before — not required. --}}
                <div class="form-group">
                    <label class="form-label" for="country">Country</label>
                    <div class="input-wrapper country-combo" id="countryCombo">
                        <span class="input-icon"><i class="fa-solid fa-earth-asia"></i></span>
                        <input id="country" type="text" class="form-control {{ $errors->has('country') ? 'is-invalid' : '' }}"
                               value="{{ old('country') }}" placeholder="Select your country"
                               autocomplete="off" spellcheck="false"
                               role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="countryList">
                        <i class="fa-solid fa-chevron-down country-combo-chevron" aria-hidden="true"></i>
                        <input type="hidden" name="country" id="countryValue" value="{{ old('country') }}">
                        <ul class="country-combo-list" id="countryList" role="listbox" aria-label="Countries" hidden>
                            @foreach($countries as $name)
                            <li class="country-combo-option" role="option" id="countryOption{{ $loop->index }}"
                                data-value="{{ $name }}" aria-selected="{{ old('country') === $name ? 'true' : 'false' }}">{{ $name }}</li>
                            @endforeach
                            <li class="country-combo-empty" aria-live="polite" hidden>No country matches that.</li>
                        </ul>
                    </div>
                    {{-- The only validated field that had no message of its own.
                         It can only fail on an over-long value, but with the
                         summary gone that failure would be invisible. --}}
                    @error('country')<div class="error">{{ $message }}</div>@enderror
                </div>

                {{-- While the request is in flight auth-form.js shows a bare
                     spinner here; data-busy-label is not drawn, it becomes the
                     button's accessible name for as long as that lasts. --}}
                {{-- Always clickable, like Sign In: there is no terms checkbox to
                     unlock it any more. Missing fields are caught on click. --}}
                <button type="submit" id="createAccountBtn" class="btn btn-primary btn-lg btn-block"
                        data-busy-label="Creating account…">
                    Create Account
                </button>
            </form>

            <div class="auth-footer-text">
                Already have an account? <a href="{{ route('login') }}">Login here</a>
            </div>

            <div style="text-align:center;margin-top:32px;font-size:12px;color:var(--muted);">
                © {{ date('Y') }} Budgetra. Smart travel, smarter spending.
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('js/auth-form.js') }}?v={{ filemtime(public_path('js/auth-form.js')) }}"></script>
<script src="{{ asset('js/country-combobox.js') }}?v={{ filemtime(public_path('js/country-combobox.js')) }}"></script>
<script>
function togglePassword(inputId, iconId) {
    var pwd  = document.getElementById(inputId);
    var icon = document.getElementById(iconId);
    if (pwd.type === 'password') {
        pwd.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        pwd.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}
document.getElementById('togglePwd1').addEventListener('click', function () {
    togglePassword('password', 'eyeIcon1');
});
document.getElementById('togglePwd2').addEventListener('click', function () {
    togglePassword('password_confirmation', 'eyeIcon2');
});
// Each eye only shows while its box has something in it (style.css hides it
// on :placeholder-shown). Emptying a box also puts it back to hidden, so
// whatever is typed next starts masked even if it was revealed before.
[['password', 'eyeIcon1'], ['password_confirmation', 'eyeIcon2']].forEach(function (pair) {
    document.getElementById(pair[0]).addEventListener('input', function () {
        if (this.value !== '') return;
        this.type = 'password';
        document.getElementById(pair[1]).className = 'fa-regular fa-eye';
    });
});

</script>

</body>
</html>
