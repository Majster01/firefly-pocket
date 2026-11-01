<?php

namespace App\Http\Controllers;

use App\Exceptions\GeneralException;
use App\Http\Controllers\Base\BaseController;
use App\Http\Controllers\Base\BaseControllerFirefly;
use Illuminate\Http\Client\Response as FireflyResponse;
use Illuminate\Http\Request;

/**
 * Firefly III's own rules and rule groups, forwarded as they are, except for the suggester's group
 * (RULE_GROUP_SUGGESTIONS). That group belongs to the Suggestions pages (RuleSuggestionService): the Rules pages
 * never list it, open it, change it, or move a rule into it.
 */
class RuleController extends BaseControllerFirefly
{
    public function __construct()
    {
        parent::__construct('/api/v1/');
    }

    // ------------------------------------------------------------------ rule groups

    public function getGroups(Request $request)
    {
        return $this->listWithoutHidden($request, 'attributes.title');
    }

    public function getGroup(Request $request)
    {
        $response = $this->forward($request);
        if ($response->ok() && $this->isHidden(fget($response->json(), 'data.attributes.title'))) {
            $this->notFound();
        }
        return $this->pass($response);
    }

    /** The rules of one group. Each rule names its group, so the hidden one is told apart without another call. */
    public function getGroupRules(Request $request)
    {
        $response = $this->forward($request);
        if ($response->ok() && fcollect(fget($response->json(), 'data') ?? [])->contains(fn($rule) => $this->isHidden(fget($rule, 'attributes.rule_group_title')))) {
            $this->notFound();
        }
        return $this->pass($response);
    }

    public function createGroup(Request $request)
    {
        $this->refuseHiddenTitle($request->input('title'));
        return $this->pass($this->forward($request));
    }

    /** PUT and DELETE rule-groups/{id}, GET rule-groups/{id}/test, POST rule-groups/{id}/trigger */
    public function changeGroup(Request $request, $id)
    {
        if ($missing = $this->guardGroup($id)) {
            return $missing;
        }
        if ($request->isMethod('PUT')) {
            $this->refuseHiddenTitle($request->input('title'));
        }
        return $this->pass($this->forward($request));
    }

    // ------------------------------------------------------------------ rules

    public function getRules(Request $request)
    {
        return $this->listWithoutHidden($request, 'attributes.rule_group_title');
    }

    public function getRule(Request $request)
    {
        $response = $this->forward($request);
        if ($response->ok() && $this->isHidden(fget($response->json(), 'data.attributes.rule_group_title'))) {
            $this->notFound();
        }
        return $this->pass($response);
    }

    public function createRule(Request $request)
    {
        $this->refuseHiddenTarget($request);
        return $this->pass($this->forward($request));
    }

    /** PUT and DELETE rules/{id}, GET rules/{id}/test, POST rules/{id}/trigger */
    public function changeRule(Request $request, $id)
    {
        $rule = $this->firefly('get', "rules/$id");
        if (!$rule->ok()) {
            // Firefly answers 401 for an id that does not exist: the web app tells that apart from a bad token
            return $this->pass($rule);
        }
        if ($this->isHidden(fget($rule->json(), 'data.attributes.rule_group_title'))) {
            $this->notFound();
        }
        if ($request->isMethod('PUT')) {
            $this->refuseHiddenTarget($request);
        }
        return $this->pass($this->forward($request));
    }

    // ------------------------------------------------------------------

    private function isHidden($groupTitle)
    {
        $hidden = (string)config('app.rule_group_suggestions');
        return $hidden !== '' && is_string($groupTitle) && mb_strtolower(trim(html_entity_decode($groupTitle, ENT_QUOTES))) === mb_strtolower($hidden);
    }

    /** A Firefly list with the hidden group (or its rules) left out, its counts lowered to match */
    private function listWithoutHidden(Request $request, $titlePath)
    {
        $response = $this->forward($request);
        if (!$response->ok()) {
            return $this->pass($response);
        }
        $body = $response->json();
        $data = fget($body, 'data') ?? [];
        $kept = array_values(array_filter($data, fn($item) => !$this->isHidden(fget($item, $titlePath))));
        $removed = count($data) - count($kept);
        $body['data'] = $kept;
        foreach (['total', 'count'] as $key) {
            if (is_numeric(fget($body, "meta.pagination.$key"))) {
                fset($body, "meta.pagination.$key", max(0, fget($body, "meta.pagination.$key") - $removed));
            }
        }
        return response()->json($body);
    }

    /** Null when the group may be changed, else the answer to send instead */
    private function guardGroup($id)
    {
        $group = $this->firefly('get', "rule-groups/$id");
        if (!$group->ok()) {
            return $this->pass($group);
        }
        if ($this->isHidden(fget($group->json(), 'data.attributes.title'))) {
            $this->notFound();
        }
        return null;
    }

    private function refuseHiddenTitle($title)
    {
        if ($this->isHidden($title)) {
            throw new GeneralException('"' . config('app.rule_group_suggestions') . '" is reserved for category suggestions. Choose another name.', BaseController::HTTP_CODE_UNPROCESSABLE_ENTITY);
        }
    }

    /** A rule may not be created in, or moved into, the hidden group */
    private function refuseHiddenTarget(Request $request)
    {
        $reserved = new GeneralException('Rules cannot be added to "' . config('app.rule_group_suggestions') . '": it holds category suggestions.', BaseController::HTTP_CODE_UNPROCESSABLE_ENTITY);
        if ($this->isHidden($request->input('rule_group_title'))) {
            throw $reserved;
        }
        $groupId = $request->input('rule_group_id');
        if ($groupId === null || $groupId === '') {
            return;
        }
        $group = $this->firefly('get', 'rule-groups/' . rawurlencode((string)$groupId));
        if ($group->ok() && $this->isHidden(fget($group->json(), 'data.attributes.title'))) {
            throw $reserved;
        }
    }

    private function notFound()
    {
        throw new GeneralException('Not found', BaseController::HTTP_CODE_NOT_FOUND);
    }

    /** The request as it came (method, path, query, body), sent on to Firefly */
    private function forward(Request $request): FireflyResponse
    {
        return $this->getHttpClient()->send($request->method(), self::fireflyUrlFor($request), ['body' => $request->getContent()]);
    }

    private function firefly($method, $path): FireflyResponse
    {
        return $this->getHttpClient()->$method(config('app.firefly_url') . "/api/v1/$path");
    }

    private function pass(FireflyResponse $response)
    {
        return response($response->body(), $response->status(), array_filter(['Content-Type' => $response->header('Content-Type')]));
    }
}
