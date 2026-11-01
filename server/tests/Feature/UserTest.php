<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function ($request) {
            $authorization = $request->header('Authorization')[0] ?? $request->header('authorization')[0] ?? '';
            if ($authorization !== 'Bearer test-token') {
                return Http::response(['message' => 'Unauthenticated.'], 401);
            }
            return Http::response(['data' => ['id' => '1', 'attributes' => ['email' => 'demo@example.com']]]);
        });
    }

    public function test_a_good_token_returns_the_user()
    {
        $this->getJson('api/user', ['Authorization' => 'Bearer test-token'])->assertOk()->assertJsonPath('data.id', '1');
    }

    // The web app tells a refused token from a missing resource by this answer
    public function test_a_refused_token_is_a_401_not_an_empty_200()
    {
        $this->getJson('api/user', ['Authorization' => 'Bearer bad-token'])->assertUnauthorized();
    }
}
