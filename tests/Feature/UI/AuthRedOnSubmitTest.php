<?php
namespace Tests\Feature\UI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sign In and Create an Account show no red until the submit button is
 * pressed with something missing, and that red is cherry.
 *
 * Both autofocus a field, so the old "empty required field" hint painted red
 * on a page nobody had touched. Typing in a field — even a half-typed email,
 * or a box typed in and emptied — clears its red; a refused submit brings it
 * back (.is-invalid).
 */
class AuthRedOnSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_page_has_nothing_marked_red(): void
    {
        foreach (['/login', '/register'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<form [^>]*class="[^"]*\bauth-submit-errors-only\b/', $html, $url);
            $this->assertStringNotContainsString('is-invalid', $html, $url);
        }

        // The focus hint is gone altogether.
        $this->assertStringNotContainsString(':required:focus:invalid', file_get_contents(public_path('css/style.css')));
    }

    public function test_create_account_is_clickable_like_sign_in(): void
    {
        // The terms checkbox that unlocked it is gone, so the button is live
        // from the start and missing fields are caught on click, as on Sign In.
        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringNotContainsString('agreeTerms', $html);
        $this->assertStringNotContainsString('Terms of Service', $html);
        $this->assertMatchesRegularExpression(
            '/<button type="submit" id="createAccountBtn" class="btn btn-primary btn-lg btn-block"\s+data-busy-label="Creating account…">/u',
            $html
        );
        $this->assertStringNotContainsString('auth-terms-check', file_get_contents(public_path('css/style.css')));
    }

    public function test_typing_or_clearing_a_field_takes_its_red_away(): void
    {
        // Checked in a browser on both pages: refused submit → all required
        // fields red; type "dafdad" (not a valid email yet) → email clears;
        // empty it again → stays clear; submit again → red.
        $js = file_get_contents(public_path('js/auth-form.js'));

        $this->assertMatchesRegularExpression(
            "/form\.addEventListener\('input', function \(e\) \{\s*e\.target\.classList\.remove\('is-invalid'\);\s*var group = e\.target\.closest\('\.form-group'\);\s*if \(group\) group\.querySelectorAll\('\.error'\)\.forEach\(function \(el\) \{ el\.remove\(\); \}\);\s*\}\);/",
            $js
        );
        // Not "only once the value is valid" any more.
        $this->assertStringNotContainsString('checkValidity', $js);
    }

    public function test_the_red_is_cherry(): void
    {
        $css = file_get_contents(public_path('css/style.css'));

        // Every red on the form reads --danger, so this one line recolours the
        // border, the placeholder and the message under the field.
        $this->assertStringContainsString('.auth-submit-errors-only { --danger: var(--cherry); }', $css);
        $this->assertMatchesRegularExpression('/:root \{[^}]*--cherry: #BE1E2D;/s', $css);
    }

    public function test_pressing_the_button_with_fields_missing_marks_them_red(): void
    {
        // In the browser: auth-form.js turns every failing field's `invalid`
        // event into .is-invalid.
        $js = file_get_contents(public_path('js/auth-form.js'));
        $this->assertStringContainsString("form.addEventListener('invalid'", $js);
        $this->assertStringContainsString("e.target.classList.add('is-invalid');", $js);

        // Without JavaScript the server refuses it and marks the fields.
        $login = $this->followingRedirects()->from('/login')
            ->post('/login', ['email' => '', 'password' => ''])
            ->getContent();
        $this->assertMatchesRegularExpression('/<input id="email"[^>]*class="form-control is-invalid"/s', $login);
        $this->assertMatchesRegularExpression('/<input id="password"[^>]*class="form-control is-invalid"/s', $login);

        $register = $this->followingRedirects()->from('/register')
            ->post('/register', ['first_name' => '', 'last_name' => '', 'email' => '', 'password' => ''])
            ->getContent();
        foreach (['first_name', 'last_name', 'email', 'password'] as $id) {
            $this->assertMatchesRegularExpression('/<input id="' . $id . '"[^>]*class="form-control is-invalid"/s', $register, $id);
        }
    }
}
