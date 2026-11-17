<?php
namespace Tests\Feature\UI;

use Tests\TestCase;

/**
 * Required fields are marked `required`, and are red only once a submit is
 * refused.
 *
 * There used to be a "hint": a red border on any focused, still-empty
 * required field. Both auth pages autofocus their first field, so it painted
 * a page nobody had touched yet, and both have dropped it. `required` still
 * matters — it is what makes the browser refuse the submit and fire the
 * `invalid` events that auth-form.js turns into the red.
 */
class AuthRequiredFieldTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(public_path('css/style.css'));
    }

    public function test_an_empty_field_is_not_red_just_for_being_focused(): void
    {
        $this->assertStringNotContainsString(':required:focus:invalid', $this->css());

        // Both forms carry the class that documents it.
        foreach (['/login', '/register'] as $url) {
            $this->assertMatchesRegularExpression(
                '/<form [^>]*class="[^"]*\bauth-submit-errors-only\b/',
                $this->get($url)->getContent(),
                $url
            );
        }
    }

    public function test_it_does_not_reach_the_rest_of_the_app(): void
    {
        // Every form in Budgetra uses .form-control, and turning all of their
        // required fields red on focus is a separate decision.
        $this->assertStringNotContainsString(
            "\n.form-control:required:focus:invalid",
            $this->css()
        );
    }

    public function test_the_register_fields_it_applies_to_are_marked_required(): void
    {
        // The selector is inert without these, so they are part of the
        // behaviour rather than incidental markup.
        $html = $this->get('/register')->assertStatus(200)->getContent();

        foreach (['first_name', 'last_name', 'email', 'password', 'password_confirmation'] as $field) {
            $this->assertMatchesRegularExpression(
                '/name="' . $field . '"[^>]*required|required[^>]*name="' . $field . '"/s',
                $html,
                "{$field} is not marked required"
            );
        }
    }

    public function test_country_is_left_alone_because_it_is_optional(): void
    {
        // RegisterController validates it as nullable, so a red "required"
        // border on it would be telling the traveler something untrue.
        $html = $this->get('/register')->getContent();

        // A search box now (no name; it only fills the hidden field), and
        // neither of them is required.
        preg_match('/<input id="country"[^>]*>/s', $html, $box);
        preg_match('/<input type="hidden" name="country"[^>]*>/s', $html, $field);
        $this->assertNotEmpty($box, 'country search box not found');
        $this->assertNotEmpty($field, 'country field not found');
        $this->assertStringNotContainsString('required', $box[0] . $field[0]);
    }

    public function test_the_login_fields_are_marked_required_too(): void
    {
        $html = $this->get('/login')->assertStatus(200)->getContent();

        foreach (['email', 'password'] as $field) {
            $this->assertMatchesRegularExpression('/name="' . $field . '"[^>]*required/s', $html, $field);
        }
    }
}
