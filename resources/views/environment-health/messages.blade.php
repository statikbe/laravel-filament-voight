@props(['issues'])

<ul class="list-disc ps-5">
    @foreach ($issues as $issue)
        <li>{{ $issue->message }}</li>
    @endforeach
</ul>
