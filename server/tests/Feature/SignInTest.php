<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignInTest extends TestCase
{
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private function offered(): void
    {
        config([
            'app.url' => 'https://app.example.test',
            'app.firefly_url' => 'http://app:8080',
            'app.firefly_public_url' => 'https://firefly.example.test/',
            'app.firefly_client_id' => '7',
        ]);
    }

    public function test_it_is_not_offered_until_the_stack_sets_the_client()
    {
        config(['app.firefly_public_url' => '', 'app.firefly_client_id' => '']);
        $this->getJson('api/signin')->assertOk()->assertExactJson(['available' => false]);
        $this->postJson('api/signin/token', ['code' => 'c', 'code_verifier' => self::VERIFIER])->assertNotFound();
    }

    public function test_it_says_where_the_browser_signs_in()
    {
        $this->offered();
        $this->getJson('api/signin')->assertOk()->assertExactJson([
            'available' => true,
            'authorize_url' => 'https://firefly.example.test/oauth/authorize',
            'client_id' => '7',
            'redirect_uri' => 'https://app.example.test/oauth/callback',
        ]);
    }

    public function test_a_code_and_its_verifier_become_a_token_on_the_internal_network()
    {
        $this->offered();
        Http::fake(['http://app:8080/oauth/token' => Http::response(['token_type' => 'Bearer', 'expires_in' => 31536000, 'access_token' => 'the-token', 'refresh_token' => 'kept-nowhere'])]);

        $this->postJson('api/signin/token', ['code' => 'the-code', 'code_verifier' => self::VERIFIER])
            ->assertOk()->assertExactJson(['token' => 'the-token', 'expires_in' => 31536000]);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://app:8080/oauth/token'
            && $request['grant_type'] === 'authorization_code' && $request['client_id'] === '7'
            && $request['redirect_uri'] === 'https://app.example.test/oauth/callback'
            && $request['code'] === 'the-code' && $request['code_verifier'] === self::VERIFIER
            && !isset($request['client_secret']));
    }

    public function test_a_code_firefly_refuses_is_no_token()
    {
        $this->offered();
        Http::fake(['http://app:8080/oauth/token' => Http::response(['error' => 'invalid_grant', 'message' => 'The authorization code was revoked.'], 400)]);

        $this->postJson('api/signin/token', ['code' => 'used-code', 'code_verifier' => self::VERIFIER])
            ->assertStatus(400)->assertJsonMissingPath('token');
    }

    public function test_a_malformed_request_never_reaches_firefly()
    {
        $this->offered();
        Http::fake();
        foreach ([
            ['code' => 'c'],
            ['code' => 'c', 'code_verifier' => 'too-short'],
            ['code' => 'c', 'code_verifier' => str_repeat('a', 129)],
            ['code' => 'c', 'code_verifier' => str_repeat('a', 42) . '+'],
            ['code' => '', 'code_verifier' => self::VERIFIER],
            ['code' => ['c'], 'code_verifier' => self::VERIFIER],
        ] as $body) {
            $this->postJson('api/signin/token', $body)->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_firefly_not_answering_is_a_502()
    {
        $this->offered();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->postJson('api/signin/token', ['code' => 'c', 'code_verifier' => self::VERIFIER])->assertStatus(502);
    }
}
