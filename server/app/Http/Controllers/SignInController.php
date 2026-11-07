<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Base\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sign in with Firefly III (firefly-pocket-stack, Setup UI step 11): an authorization code with PKCE for the public
 * client the stack registers for Pocket, so a new device signs in instead of pasting a token. The browser goes to
 * Firefly III's own login (its two-factor authentication included), comes back to /oauth/callback, and this exchanges
 * the code for the token: Firefly III's /oauth/token does not answer other origins (its CORS covers api/* only), and
 * Pocket's server reaches it on the internal network. No secret is involved: the client is public, and a code is
 * worth nothing without the verifier only the browser that asked for it holds. Nothing is kept here.
 */
class SignInController extends BaseController
{
    /** Whether this server offers the sign-in, and where the browser goes for it. */
    public function config(): JsonResponse
    {
        $public = rtrim((string) config('app.firefly_public_url'), '/');
        $client = (string) config('app.firefly_client_id');
        if ($public === '' || $client === '') {
            return response()->json(['available' => false]);
        }
        return response()->json([
            'available' => true,
            'authorize_url' => $public . '/oauth/authorize',
            'client_id' => $client,
            'redirect_uri' => self::redirectUri(),
        ]);
    }

    /** The code Firefly III sent the browser back with, and the browser's verifier, for a token. */
    public function token(Request $request): JsonResponse
    {
        $client = (string) config('app.firefly_client_id');
        if ($client === '' || (string) config('app.firefly_public_url') === '') {
            return response()->json(['message' => 'This server does not offer signing in with Firefly III.'], 404);
        }
        $code = $request->input('code');
        $verifier = $request->input('code_verifier');
        // RFC 7636: a verifier is 43 to 128 of these
        if (!is_string($code) || $code === '' || strlen($code) > 4096
            || !is_string($verifier) || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            return response()->json(['message' => 'That sign-in did not come back from Firefly III as it should. Sign in again.'], 422);
        }
        try {
            $response = Http::asForm()->acceptJson()->timeout(30)
                ->post(rtrim((string) config('app.firefly_url'), '/') . '/oauth/token', [
                    'grant_type' => 'authorization_code',
                    'client_id' => $client,
                    'redirect_uri' => self::redirectUri(),
                    'code_verifier' => $verifier,
                    'code' => $code,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Sign in with Firefly III: Firefly III did not answer', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Firefly III did not answer. Try again in a minute.'], 502);
        }
        $token = $response->json('access_token');
        if (!$response->successful() || !is_string($token) || $token === '') {
            Log::info('Sign in with Firefly III: Firefly III refused the code', ['status' => $response->status(), 'error' => $response->json('error')]);
            return response()->json(['message' => 'Firefly III did not take that sign-in. Sign in again.'], 400);
        }
        return response()->json(['token' => $token, 'expires_in' => $response->json('expires_in')]);
    }

    /** Where Firefly III sends the browser back: the web app's own page, registered with the client. */
    private static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/') . '/oauth/callback';
    }
}
