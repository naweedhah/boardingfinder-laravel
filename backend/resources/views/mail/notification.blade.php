<div style="font-family: Arial, sans-serif; color: #332d2a; line-height: 1.6;">
    <h2 style="margin-bottom: 12px;">{{ $title }}</h2>
    <p>Hello{{ $recipientName ? ' '.$recipientName : '' }},</p>
    <p>{{ $body }}</p>
    @if (! empty($details['postId']))
        <p style="color: #7e736d; font-size: 14px;">Reference listing: {{ $details['postId'] }}</p>
    @endif
    <p style="margin-top: 24px;">BoardingFinder Alerts</p>
</div>
