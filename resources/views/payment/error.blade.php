<x-payment.receipt-shell
    :title="$title"
    :eyebrow="__('payment.error.eyebrow')"
    :reference="$reference"
    status="danger"
    :status-label="__('payment.error.status')"
>
    <div class="card-block tone-danger">
        <div class="section-title">{{ $title }}</div>
        <div class="message-box"><strong>{{ __('payment.error.reason_label') }}</strong> {{ $reason }}</div>
        <div class="code-row"><span class="code-chip">{{ $errorCode }}</span></div>
    </div>

    <div class="actions">
        <a class="btn btn-secondary" href="{{ $homeUrl ?: '#' }}">{{ __('payment.error.home') }}</a>
    </div>
</x-payment.receipt-shell>
