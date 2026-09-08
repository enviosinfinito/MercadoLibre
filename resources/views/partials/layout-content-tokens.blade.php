@php
    $slideOver = config('layout.slide_over', []);
    $slideWidth = max(1, min(100, (int) ($slideOver['width_percent'] ?? 45)));
    $slideStep = max(0, min(50, (int) ($slideOver['nested_step_percent'] ?? 5)));
    $slideMin = max(1, min(100, (int) ($slideOver['min_width_percent'] ?? 30)));
    if ($slideMin > $slideWidth) {
        $slideMin = $slideWidth;
    }
@endphp
<style>
:root {
    --slide-over-width: {{ $slideWidth }}%;
    --slide-over-nested-step: {{ $slideStep }}%;
    --slide-over-min-width: {{ $slideMin }}%;
}
</style>
