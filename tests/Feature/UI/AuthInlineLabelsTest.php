<?php
namespace Tests\Feature\UI;

use Tests\TestCase;

/**
 * Sign In and Create an Account name their fields inside the boxes: the
 * placeholders carry the names, and the labels above the boxes are hidden
 * visually but still there for screen readers.
 */
class AuthInlineLabelsTest extends TestCase
{
    /** Field id => placeholder, per page. */
    private const PLACEHOLDERS = [
        '/login' => [
            'email'    => 'Email Address',
            'password' => 'Password',
        ],
        '/register' => [
            'first_name'            => 'First Name',
            'last_name'             => 'Last Name',
            'email'                 => 'Email Address',
            'password'              => 'Password',
            'password_confirmation' => 'Confirm Password',
        ],
    ];

    private function css(): string
    {
        return file_get_contents(public_path('css/style.css'));
    }

    public function test_the_field_names_are_the_placeholders(): void
    {
        foreach (self::PLACEHOLDERS as $url => $fields) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach ($fields as $id => $placeholder) {
                $this->assertMatchesRegularExpression(
                    '/<input id="' . $id . '"[^>]*placeholder="' . preg_quote($placeholder, '/') . '"/su',
                    $html,
                    "{$url} #{$id}"
                );
            }
        }
    }

    public function test_the_password_boxes_say_password_not_dots(): void
    {
        foreach (['/login', '/register'] as $url) {
            $this->assertStringNotContainsString('placeholder="•', $this->get($url)->getContent(), $url);
        }
        // The dots had their own letter-spacing; it went with them.
        $this->assertStringNotContainsString('[name="password"]::placeholder', $this->css());
    }

    public function test_the_labels_stay_for_screen_readers_but_are_hidden(): void
    {
        foreach (['/login' => ['email', 'password'], '/register' => ['first_name', 'last_name', 'email', 'password', 'password_confirmation', 'country']] as $url => $ids) {
            $html = $this->get($url)->getContent();

            $this->assertMatchesRegularExpression('/<form [^>]*class="[^"]*\bauth-labels-inside\b/', $html, $url);
            foreach ($ids as $id) {
                $this->assertStringContainsString('<label class="form-label" for="' . $id . '">', $html, "{$url} #{$id}");
            }
        }

        $this->assertMatchesRegularExpression(
            '/\.auth-labels-inside \.form-label \{\s*position: absolute; width: 1px; height: 1px;[^}]*clip: rect\(0, 0, 0, 0\);/s',
            $this->css()
        );
    }

    public function test_the_placeholder_is_readable_and_turns_red_on_an_error(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('.auth-labels-inside .form-control::placeholder { color: var(--muted); opacity: 1; }', $css);
        $this->assertStringContainsString('.auth-labels-inside .form-control.is-invalid::placeholder { color: var(--danger); }', $css);
    }

    public function test_each_eye_shows_only_once_its_box_has_something_in_it(): void
    {
        // Checked in a browser on both pages: empty → no eye; typed → eye;
        // revealed and then cleared → no eye, masked again for the next key.
        // On Create an Account each of the two eyes follows its own box.
        $this->assertStringContainsString(
            '.auth-labels-inside .form-control:placeholder-shown ~ .input-suffix { display: none; }',
            $this->css()
        );

        // :placeholder-shown needs a non-empty placeholder, and the eye has to
        // come after the input for ~ to reach it.
        $this->assertMatchesRegularExpression(
            '/<input id="password"[^>]*placeholder="Password"[^>]*>\s*<span class="input-suffix" id="togglePwd"/s',
            $this->get('/login')->getContent()
        );
        $register = $this->get('/register')->getContent();
        $this->assertMatchesRegularExpression(
            '/<input id="password"[^>]*placeholder="Password"[^>]*>\s*<span class="input-suffix" id="togglePwd1"/s',
            $register
        );
        $this->assertMatchesRegularExpression(
            '/<input id="password_confirmation"[^>]*placeholder="Confirm Password"[^>]*>\s*<span class="input-suffix" id="togglePwd2"/su',
            $register
        );

        // Clearing a box re-masks it.
        $this->assertMatchesRegularExpression(
            "/getElementById\('password'\)\.addEventListener\('input', function \(\) \{\s*if \(this\.value !== ''\) return;\s*this\.type = 'password';/",
            $this->get('/login')->getContent()
        );
        $this->assertStringContainsString("[['password', 'eyeIcon1'], ['password_confirmation', 'eyeIcon2']].forEach(", $register);
        $this->assertMatchesRegularExpression("/if \(this\.value !== ''\) return;\s*this\.type = 'password';/", $register);
    }
}
