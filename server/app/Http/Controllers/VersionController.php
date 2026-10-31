<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Base\BaseController;
use App\Services\AssistantLlmConfigService;
use App\Services\AssistantTranscriptionConfigService;
use Illuminate\Support\Facades\Http;


class VersionController extends BaseController
{

    public function getInfo(AssistantLlmConfigService $assistantLlmConfigService, AssistantTranscriptionConfigService $assistantTranscriptionConfigService)
    {
        return $this->respond([
            'version' => $this->currentVersion(),
            'latest_version' => $this->resolveLatestVersion(),
            'assistant_llm' => $assistantLlmConfigService->getPublicConfig(),
            'assistant_transcription' => $assistantTranscriptionConfigService->getPublicConfig(),
        ]);
    }


    // The VERSION file a release build writes, else composer.json's version. Null in a dev checkout without either.
    private function currentVersion()
    {
        $versionFile = base_path('VERSION');
        if (file_exists($versionFile)) {
            return trim(file_get_contents($versionFile)) ?: null;
        }
        $composer = json_decode((string)@file_get_contents(base_path('composer.json')), true);
        return $composer['version'] ?? null;
    }

    private function resolveLatestVersion()
    {
        $includeDev = str_ends_with($this->currentVersion() ?? '', '-dev');

        $url = 'https://api.github.com/repos/cioraneanu/firefly-pico/tags?per_page=100';
        $response = Http::get($url);
        if (!$response->successful()) {
            return null;
        }
        $versions = fcollect($response->json())
            ->map(fn($item) => $item['name'])
            ->filter(function ($item) use ($includeDev) {
                if ($includeDev) {
                    return str_ends_with($item, '-dev');
                }
                return !str_ends_with($item, '-dev');
            })
            ->toArray();

        // Custom sort for dev versions to handle the build number
        usort($versions, function ($a, $b) {
            return version_compare($a, $b);
        });

        $versions = array_reverse($versions);
        return $versions[0] ?? null;
    }
}
