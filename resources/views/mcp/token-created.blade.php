@php
    /** @var string $token */
    /** @var string $url */
@endphp
<p>{{ voightTrans('pages.mcp_access.token_created_body') }}</p>
<pre><code>{{ $token }}</code></pre>
<p>{{ voightTrans('pages.mcp_access.claude_code_snippet') }}</p>
<pre><code>claude mcp add --transport http voight {{ $url }} --header "Authorization: Bearer {{ $token }}"</code></pre>
