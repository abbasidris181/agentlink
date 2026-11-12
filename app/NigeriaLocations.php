<?php

namespace App;

class NigeriaLocations
{
    /** @return array<string, string> */
    public static function states(): array
    {
        $states = [];
        foreach (array_keys(config('nigeria.states', [])) as $state) {
            $states[$state] = $state === 'FCT' ? 'FCT (Abuja)' : $state;
        }

        return $states;
    }

    /** @return list<string> */
    public static function lgas(string $state): array
    {
        return config('nigeria.states', [])[$state]['lgas'] ?? [];
    }

    public static function region(string $state): string
    {
        return config('nigeria.states', [])[$state]['region'] ?? '';
    }
}
