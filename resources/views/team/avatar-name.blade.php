@props(['team'])

<span class="flex items-center gap-2">
    <x-filament::avatar :src="$team->getAvatarUrl()" :alt="$team->name" size="sm" />
    <span>{{ $team->name }}</span>
</span>
