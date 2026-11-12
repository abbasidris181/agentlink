@if (in_array($serviceId, ['nin-v1', 'nin-v2', 'nin-v3', 'personalization', 'nin-modification', 'ipe-clearing', 'instant-ipe', 'mod-ipe', 'validations', 'delinking'], true))
    <img src="{{ asset('assets/images/nimc.png') }}" alt="" aria-hidden="true" width="407" height="290" class="{{ $imageClass ?? $iconClass }} rounded-lg bg-white object-contain">
@elseif (in_array($serviceId, ['bvn-verification', 'bvn retrival', 'bvn-modification', 'bvn-license-onboarding'], true))
    <img src="{{ asset('assets/images/bvn.png') }}" alt="" aria-hidden="true" class="{{ $imageClass ?? $iconClass }} rounded-lg bg-white object-contain">
@else
<svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <rect x="4" y="4" width="16" height="16" rx="4"/>
    <path d="M8 12h8m-4-4v8"/>
</svg>

@endif
