<?php
// Runs inside the Firefly III container and prints a new Personal Access Token for a registered user.
// Usage: php create-token.php <email> <token-name>
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $email, $tokenName] = $argv;

$user = FireflyIII\User::where('email', $email)->firstOrFail();

// Passport issues personal tokens through a personal access client; a fresh install has none until the profile page is opened
Illuminate\Support\Facades\Artisan::call('firefly-iii:laravel-passport-keys');
try {
    echo $user->createToken($tokenName)->accessToken;
} catch (RuntimeException $e) {
    Illuminate\Support\Facades\Artisan::call('passport:client', ['--personal' => true, '--name' => 'Pocket dev personal access client', '--no-interaction' => true]);
    echo $user->createToken($tokenName)->accessToken;
}
