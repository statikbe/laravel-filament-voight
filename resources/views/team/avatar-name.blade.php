@props(['team', 'size' => 'sm'])

<span class="inline-flex items-center gap-2">
    <x-filament::avatar :src="$team->getAvatarUrl()" :alt="$team->name" :size="$size" />
    <span>{{ $team->name }}</span>
</span>
