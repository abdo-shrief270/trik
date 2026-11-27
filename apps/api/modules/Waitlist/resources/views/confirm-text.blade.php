{{-- text/plain part: not HTML, so nothing is escaped here (the HTML view escapes everything). --}}
{!! $copy['greeting'] !!}

{!! $copy['intro'] !!}

{!! $copy['button'] !!}:
{!! $confirmUrl !!}

{!! $copy['next'] !!}

{!! $copy['expiry'] !!} {!! $copy['notYou'] !!}

--
{!! $copy['footer'] !!}
{!! $copy['unsubscribe'] !!}: {!! $unsubscribeUrl !!}
