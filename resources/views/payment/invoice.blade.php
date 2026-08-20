<x-payment.receipt-shell
    :title="__('payment.invoice.title')"
    :eyebrow="__('payment.invoice.eyebrow')"
    :reference="$reference"
    status="success"
    :status-label="__('payment.invoice.status')"
    :auto-redirect="$autoRedirect ?? false"
    :home-url="$homeUrl"
>
    <div class="card-block tone-success">
        <div class="section-title">{{ __('payment.invoice.summary_title') }}</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">{{ __('payment.invoice.method') }}</div>
                <div class="info-value">{{ __('payment.invoice.card_prefix') }} {{ $cardLast4 }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">{{ __('payment.invoice.transaction') }}</div>
                <div class="info-value">{{ $transactionId }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">{{ __('payment.invoice.date') }}</div>
                <div class="info-value">{{ $paidAt }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">{{ __('payment.invoice.contact') }}</div>
                <div class="info-value">{{ $email }}</div>
            </div>
        </div>

        <div class="total-box">
            <div class="total-label">{{ __('payment.invoice.total_paid') }}</div>
            <div class="total-value">{{ $currency }} {{ $amount }}</div>
        </div>
    </div>

    @unless ($autoRedirect ?? false)
        <p class="lead-text">{{ __('payment.invoice.thanks') }}</p>
    @endunless

    <div class="actions">
        @unless ($autoRedirect ?? false)
            <button type="button" class="btn btn-primary" onclick="window.print()">{{ __('payment.invoice.print') }}</button>
        @endunless
        <a class="btn btn-secondary" href="{{ $homeUrl ?: '#' }}">{{ __('payment.invoice.home') }}</a>
    </div>

    @unless ($autoRedirect ?? false)
        <p class="lead-text no-print">{{ __('payment.invoice.finish_note') }}</p>
    @endunless
</x-payment.receipt-shell>
