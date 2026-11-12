@php
    /** @var list<\Statikbe\FilamentVoight\Support\McpClient> $clients */
    /** @var bool $oauthAvailable */
    use Illuminate\Support\Js;

    $copiedMessage = Js::from(__('filament-forms::components.text_input.actions.copy.message'));
@endphp
<div class="space-y-4">
    @unless ($oauthAvailable)
        <x-filament::callout
            :heading="voightTrans('pages.mcp_access.clients.oauth_unavailable_heading')"
            :description="voightTrans('pages.mcp_access.clients.oauth_unavailable_body')"
            icon="heroicon-o-information-circle"
            color="info"
        />
    @endunless

    @if ($clients !== [])
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($clients as $client)
            <x-filament::section
                compact
                :heading="$client->name"
                :description="$client->description"
                :icon="$client->icon"
                :data-mcp-client="$client->key"
            >
                <div class="flex flex-col gap-3">
                    @if ($client->snippet !== null)
                        <pre class="overflow-x-auto whitespace-pre-wrap break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs text-gray-950 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/10"><code>{{ $client->snippet }}</code></pre>
                    @endif

                    @if ($client->hint !== null)
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $client->hint }}</p>
                    @endif

                    <div>
                        @if ($client->buttonUrl !== null)
                            <x-filament::button
                                tag="a"
                                :href="$client->buttonUrl"
                                :target="$client->opensNewTab ? '_blank' : null"
                                :rel="$client->opensNewTab ? 'noopener noreferrer' : null"
                                :x-on:click="$client->copies === null ? null : 'window.navigator.clipboard.writeText(' . Js::from($client->copies) . '); $tooltip(' . $copiedMessage . ', { theme: $store.theme, timeout: 2000 })'"
                                size="sm"
                            >
                                {{ $client->buttonLabel }}
                            </x-filament::button>
                        @else
                            <x-filament::button
                                :x-on:click="'window.navigator.clipboard.writeText(' . Js::from($client->copies) . '); $tooltip(' . $copiedMessage . ', { theme: $store.theme, timeout: 2000 })'"
                                icon="heroicon-o-clipboard-document"
                                size="sm"
                            >
                                {{ $client->buttonLabel }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
    @endif
</div>
