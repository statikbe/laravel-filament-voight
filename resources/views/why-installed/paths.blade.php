@props(['explanations'])

<div class="space-y-4">
    @forelse ($explanations as $explanation)
        <div class="space-y-2">
            @if (count($explanations) > 1)
                <p class="font-medium">
                    {{ voightTrans('models.package.why_installed.installed_copy', ['number' => $loop->iteration]) }}
                </p>
            @endif

            <ul class="list-disc space-y-1 ps-5 font-mono text-sm">
                @foreach ($explanation['result']->paths as $path)
                    <li>{{ $path->describe() }}</li>
                @endforeach
            </ul>

            @if ($explanation['result']->truncated)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ voightTrans('models.package.why_installed.truncated') }}
                </p>
            @endif
        </div>
    @empty
        <p>{{ voightTrans('models.package.why_installed.no_longer_installed') }}</p>
    @endforelse
</div>
