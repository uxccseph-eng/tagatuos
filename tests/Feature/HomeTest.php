<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomeTest extends TestCase
{
    public function test_homepage_contains_a_csrf_protected_form(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('home')
            ->assertSee('name="business_type"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('Generate');
    }

    public function test_valid_business_type_returns_the_dummy_response(): void
    {
        $this->post('/generate', ['business_type' => 'Coffee shop'])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertContent('Generated successfully');
    }

    public function test_business_type_is_required_and_validation_preserves_input(): void
    {
        $this->from('/')->post('/generate', ['business_type' => '   '])
            ->assertRedirect('/')
            ->assertSessionHasErrors('business_type');

        $businessType = str_repeat('a', 256);

        $this->from('/')->post('/generate', ['business_type' => $businessType])
            ->assertRedirect('/')
            ->assertSessionHasErrors('business_type')
            ->assertSessionHasInput('business_type', $businessType);
    }

    public function test_business_type_must_be_a_string(): void
    {
        $this->postJson('/generate', ['business_type' => ['Coffee shop']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_type');
    }

    public function test_post_without_a_csrf_token_is_rejected(): void
    {
        $this->app->instance('env', 'production');

        $this->post('/generate', ['business_type' => 'Coffee shop'])
            ->assertStatus(419);
    }

    public function test_post_with_a_matching_csrf_token_is_accepted(): void
    {
        $this->app->instance('env', 'production');

        $this->withSession(['_token' => 'test-csrf-token'])
            ->post('/generate', [
                '_token' => 'test-csrf-token',
                'business_type' => 'Coffee shop',
            ])
            ->assertOk()
            ->assertContent('Generated successfully');
    }
}
