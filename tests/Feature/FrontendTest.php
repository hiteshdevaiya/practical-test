<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the Blade autocomplete demo page and its embedded configuration.
 */
final class FrontendTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The demo page renders the autocomplete markup and embeds the API key.
     *
     * @return void
     */
    public function test_demo_page_renders_autocomplete_ui(): void
    {
        config(['search.demo_api_key' => 'demo_publishable_key']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Autocomplete search demo');
        $response->assertSee('role="combobox"', false);
        $response->assertSee('role="listbox"', false);
        $response->assertSee('id="suggestions"', false);
        $response->assertSee('id="billable-usage"', false);
        $response->assertSee('id="search-requests"', false);
        $response->assertSee('id="timing"', false);

        // Blade's @json directive hex-escapes quotes, so compare the exact
        // generated payload instead of a hand-written JSON fragment.
        $expected = json_encode([
            'apiKey' => 'demo_publishable_key',
            'minLength' => 3,
            'limit' => 10,
        ], 15, 512);

        $response->assertSee((string) $expected, false);
    }

    /**
     * The demo page lists the default request limit from configuration.
     *
     * @return void
     */
    public function test_demo_page_embeds_configured_limit(): void
    {
        config([
            'search.demo_api_key' => 'demo_key',
            'search.default_limit' => 7,
        ]);

        $expected = json_encode([
            'apiKey' => 'demo_key',
            'minLength' => 3,
            'limit' => 7,
        ], 15, 512);

        $this->get('/')->assertSee((string) $expected, false);
    }

    /**
     * A missing demo key produces a visible setup warning instead of failing.
     *
     * @return void
     */
    public function test_demo_page_warns_when_api_key_is_missing(): void
    {
        config(['search.demo_api_key' => '']);

        $this->get('/')
            ->assertOk()
            ->assertSee('No demo API key is configured');
    }

    /**
     * The page ships no inline event handlers that would require CSP relaxations.
     *
     * @return void
     */
    public function test_demo_page_has_no_inline_event_handlers(): void
    {
        $response = $this->get('/');
        $html = $response->getContent();

        $this->assertIsString($html);
        $this->assertDoesNotMatchRegularExpression('/\son(?:click|load|error|submit|input)=/i', $html);
    }
}
