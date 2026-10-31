<?php

namespace Tests\Feature;

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TagSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Firefly saves the tag and answers with it; Pocket then stores its own fields
        Http::fake(function ($request) {
            if (preg_match('#/api/v1/tags(/(\d+))?$#', parse_url($request->url(), PHP_URL_PATH), $match)) {
                return Http::response(['data' => ['id' => $match[2] ?? '7', 'type' => 'tags', 'attributes' => ['tag' => $request['tag'] ?? 'x']]]);
            }
            return Http::response(null, 404);
        });
    }

    private function headers()
    {
        return ['Authorization' => 'Bearer test-token'];
    }

    public function test_create_without_pocket_fields_succeeds()
    {
        // What the web app sends: no is_todo, icon or parent_id (used to fail with NOT NULL constraint failed: tags.is_todo)
        $response = $this->postJson('api/tags', ['tag' => 'BB-holiday', 'description' => null], $this->headers());

        $response->assertOk();
        $this->assertSame('7', $response->json('data.id'));
        $this->assertFalse($response->json('data.attributes.is_todo'));
        $this->assertFalse((bool)Tag::find(7)->is_todo);
    }

    public function test_rename_keeps_icon_and_todo()
    {
        Tag::create(['id' => 7, 'icon' => 'IconBeach', 'is_todo' => true]);

        $this->putJson('api/tags/7', ['tag' => 'BB-renamed'], $this->headers())->assertOk();

        $tag = Tag::find(7);
        $this->assertEquals('IconBeach', $tag->icon);
        $this->assertTrue((bool)$tag->is_todo);
    }

    public function test_marking_todo_clears_the_other_one()
    {
        Tag::create(['id' => 3, 'is_todo' => true]);

        $this->putJson('api/tags/7', ['tag' => 'BB-todo', 'is_todo' => true, 'icon' => 'IconList'], $this->headers())->assertOk();

        $this->assertTrue((bool)Tag::find(7)->is_todo);
        $this->assertEquals('IconList', Tag::find(7)->icon);
        $this->assertFalse((bool)Tag::find(3)->is_todo);
    }
}
