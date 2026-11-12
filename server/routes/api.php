<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomIconController;
use App\Http\Controllers\DataVersionController;
use App\Http\Controllers\FireflyProxyController;
use App\Http\Controllers\FireflyWebhookController;
use App\Http\Controllers\InternalNotifyController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PiggyBankController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\RecurrenceController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\RuleSuggestionController;
use App\Http\Controllers\SignInController;
use App\Http\Controllers\BankConnectionController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionTemplateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VersionController;
use App\Utils\RouteUtils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/


// Own resources
Route::get('info', [VersionController::class, 'getInfo']);
Route::get('user', [UserController::class, 'getUser']);
// Sign in with Firefly III: no token yet, so no token asked for (SignInController)
Route::get('signin', [SignInController::class, 'config']);
Route::post('signin/token', [SignInController::class, 'token']);
Route::get('data-version', [DataVersionController::class, 'get']);
Route::post('data-version', [DataVersionController::class, 'renew']);
Route::post('batch', [BatchController::class, 'read']);

RouteUtils::makeCRUD("transaction-templates", TransactionTemplateController::class);

Route::get('custom-icons', [CustomIconController::class, 'getAll']);
Route::get('custom-icons/{file}', [CustomIconController::class, 'getOne'])->where('file', '[^/]+');

// Firefly proxied resources
RouteUtils::makeCRUD("accounts", AccountController::class);
Route::get("accounts/groups", [AccountController::class, 'getAccountGroups']);

RouteUtils::makeCRUD("categories", CategoryController::class);
RouteUtils::makeCRUD("budgets", BudgetController::class);
RouteUtils::makeCRUD("piggy-banks", PiggyBankController::class);
RouteUtils::makeCRUD("recurrences", RecurrenceController::class);
RouteUtils::makeCRUD("tags", TagController::class);
Route::post("tags/{id}/total", [TagController::class, 'computeTotal'])->where('id', '[0-9]+');

// RouteUtils::makeCRUD("currencies", CurrencyController::class);

Route::get("currencies/{id}", [CurrencyController::class, 'getOne'])->where('id', '[0-9]+');
Route::get("currencies", [CurrencyController::class, 'getAll']);
Route::post("currencies", [CurrencyController::class, 'create']);
Route::put("currencies/{code}", [CurrencyController::class, 'update']);
Route::delete("currencies/{code}", [CurrencyController::class, 'delete']);

Route::get('currencies/exchange', [CurrencyController::class, 'exchangeRates']);

Route::get('budget-limits', [BudgetController::class, 'getBudgetLimits']);


RouteUtils::makeCRUD("transactions", TransactionController::class);
Route::get('search/transactions', [TransactionController::class, 'getAll']);
Route::get('search/transactions/total', [TransactionController::class, 'computeSearchTotal']);


RouteUtils::makeCRUD("profiles", ProfileController::class);

Route::get('rule-suggestions', [RuleSuggestionController::class, 'getAll']);
Route::get('rule-suggestions/{id}', [RuleSuggestionController::class, 'getOne'])->where('id', '[0-9]+');
Route::post('rule-suggestions/preview', [RuleSuggestionController::class, 'preview']);
Route::post('rule-suggestions/{id}/approve', [RuleSuggestionController::class, 'approve'])->where('id', '[0-9]+');
Route::post('rule-suggestions/{id}/categorize', [RuleSuggestionController::class, 'categorize'])->where('id', '[0-9]+');
Route::delete('rule-suggestions/{id}', [RuleSuggestionController::class, 'delete'])->where('id', '[0-9]+');

// Firefly's own rules, without the suggester's group above (RuleController)
Route::get('rule-groups', [RuleController::class, 'getGroups']);
Route::post('rule-groups', [RuleController::class, 'createGroup']);
Route::get('rule-groups/{id}', [RuleController::class, 'getGroup'])->where('id', '[0-9]+');
Route::get('rule-groups/{id}/rules', [RuleController::class, 'getGroupRules'])->where('id', '[0-9]+');
Route::match(['put', 'delete'], 'rule-groups/{id}', [RuleController::class, 'changeGroup'])->where('id', '[0-9]+');
Route::get('rule-groups/{id}/test', [RuleController::class, 'changeGroup'])->where('id', '[0-9]+');
Route::post('rule-groups/{id}/trigger', [RuleController::class, 'changeGroup'])->where('id', '[0-9]+');
Route::get('rules', [RuleController::class, 'getRules']);
Route::post('rules', [RuleController::class, 'createRule']);
Route::post('rules/test', [RuleController::class, 'testConditions']);
Route::get('rules/{id}', [RuleController::class, 'getRule'])->where('id', '[0-9]+');
Route::match(['put', 'delete'], 'rules/{id}', [RuleController::class, 'changeRule'])->where('id', '[0-9]+');
Route::get('rules/{id}/test', [RuleController::class, 'changeRule'])->where('id', '[0-9]+');
Route::post('rules/{id}/trigger', [RuleController::class, 'changeRule'])->where('id', '[0-9]+');

Route::get('bank-connections',[BankConnectionController::class, 'getAll']);
Route::get('bank-connections/banks', [BankConnectionController::class, 'getBanks']);
Route::post('bank-connections', [BankConnectionController::class, 'create']);
Route::get('bank-connections/{name}', [BankConnectionController::class, 'getOne'])->where('name', '[a-z0-9-]+');
Route::put('bank-connections/{name}/accounts', [BankConnectionController::class, 'saveAccounts'])->where('name', '[a-z0-9-]+');
Route::post('bank-connections/{name}/reauthenticate', [BankConnectionController::class, 'reauthenticate'])->where('name', '[a-z0-9-]+');
Route::post('bank-connections/{name}/import', [BankConnectionController::class, 'import'])->where('name', '[a-z0-9-]+');
Route::delete('bank-connections/{name}', [BankConnectionController::class, 'delete'])->where('name', '[a-z0-9-]+');

Route::get('push/public-key', [PushController::class, 'getPublicKey']);
Route::post('push/subscriptions', [PushController::class, 'subscribe']);
Route::delete('push/subscriptions', [PushController::class, 'unsubscribe']);
Route::get('push/preferences', [PushController::class, 'getPreferences']);
Route::put('push/preferences', [PushController::class, 'savePreferences']);
Route::get('notifications', [NotificationController::class, 'index']);
Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->where('id', '[0-9]+');
// Not behind the user login: the FireflyX importer/suggester calls it with NOTIFY_API_TOKEN. The reverse proxy hides /api/internal/*
Route::post('internal/notify', [InternalNotifyController::class, 'notify']);
// Not behind the user login either: Firefly's webhook, checked by its signature (FIREFLY_WEBHOOK_SECRET)
Route::post('internal/firefly-webhook', [FireflyWebhookController::class, 'receive']);

Route::get('system-status', [SystemStatusController::class, 'get']);
Route::get('system-status/errors', [SystemStatusController::class, 'errors']);

Route::post('assistant/rambles', [AssistantController::class, 'create']);
Route::get('assistant/rambles/count', [AssistantController::class, 'getCount']);
Route::get('assistant/rambles', [AssistantController::class, 'getAll']);
Route::get('assistant/rambles/{id}/voice', [AssistantController::class, 'getRambleVoice'])->where('id', '[0-9]+');
Route::delete('assistant/rambles', [AssistantController::class, 'deleteRambles']);
Route::delete('assistant/rambles/{id}', [AssistantController::class, 'deleteRamble'])->where('id', '[0-9]+');
Route::post('assistant/interpret-transactions', [AssistantController::class, 'interpretTransactions']);
Route::post('assistant/test-llm', [AssistantController::class, 'testLlm']);
Route::post('assistant/test-transcription', [AssistantController::class, 'testTranscription']);

// FireflyX's deploy smoke test calls this (no login, no Firefly call): keep it, or change scripts/deploy.sh there first
Route::get('/test', function (Request $request) {
    return "Test!";
});

// Just proxy the requests in which we do not change anything straight to Firefly III
$proxyMethods = ['get', 'post', 'put', 'patch', 'delete'];
foreach ($proxyMethods as $proxyMethod) {
    Route::$proxyMethod('{any?}', [FireflyProxyController::class, 'proxyRequest'])->where('any', '.*');
}
