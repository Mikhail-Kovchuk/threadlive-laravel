{{-- Plain text: nothing here is HTML, so nothing is escaped. --}}
{!! __('A reply from :name', ['name' => $team]) !!}

{!! __('You have a reply in the chat you left.') !!}

@foreach ($messages as $reply)
@if ($reply['author'])
{!! $reply['author'] !!}:
@endif
{!! $reply['body'] !!}
@foreach ($reply['files'] as $file)
📎 {!! $file['name'] !!}: {!! $file['url'] !!}
@endforeach

@endforeach
@if ($link)
{!! __('Continue the conversation') !!}: {!! $link !!}

@endif
{!! __('You get this because you left your e-mail in the chat. Replies you have read in the chat are not sent.') !!}
