@php
    /** @var string $token */
    /** @var string $command */
@endphp
<p>{{ voightTrans('pages.mcp_access.token_created_body') }}</p>
<pre><code>{{ $token }}</code></pre>
<p>{{ voightTrans('pages.mcp_access.claude_code_snippet') }}</p>
<pre><code>{{ $command }}</code></pre>
