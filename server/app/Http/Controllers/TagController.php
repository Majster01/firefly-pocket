<?php

namespace App\Http\Controllers;

use App\Authorizations\BaseAuthorization;
use App\Http\Controllers\Base\BaseControllerFirefly;
use App\Models\Tag;
use App\Services\TagService;
use Illuminate\Http\Request;


class TagController extends BaseControllerFirefly
{

    public function __construct()
    {
        parent::__construct("/api/v1/tags", Tag::class);
    }

    // ---------------------------


    protected function onPostSave($item)
    {
        $id = fget($item, 'data.id');
        $find = ['id' => $id];

        // We should only have 1 tag marked as to-do. Clear any old ones...
        if (request()->boolean('is_todo')) {
            Tag::where('is_todo', true)->where('id', '!=', $id)->update(['is_todo' => false]);
        }

        // Only what the client sent: a plain rename from the web app sends none of these, and must neither
        // wipe the icon nor write null into the NOT NULL is_todo (the tag is already saved in Firefly by now)
        $fill = request()->only(['icon', 'parent_id']);
        if (request()->has('is_todo')) {
            $fill['is_todo'] = request()->boolean('is_todo');
        }
        $tag = Tag::firstOrNew($find);
        $tag->fill($fill);
        $tag->is_todo ??= false;
        $tag->save();

        $fill = [
            'icon' => $tag->icon,
            'parent_id' => $tag->parent_id,
            'is_todo' => (bool)$tag->is_todo,
        ];
        $fill['total_amount'] = $tag->total_amount;
        $fill['total_currency_id'] = $tag->total_currency_id;

        foreach ($fill as $key => $value) {
            fset($item, "data.attributes.$key", $value);
        }
        return $item;
    }

    // ---------------------------

    public function computeTotal(Request $request)
    {
        BaseAuthorization::checkUser();
        return $this->respond(['data' => app(TagService::class)->computeTotal($request->id)]);
    }

}
