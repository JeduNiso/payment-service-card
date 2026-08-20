<x-payment.receipt-shell
    :title="$title"
    eyebrow="Estado del Pago"
    :reference="$reference"
    status="danger"
    status-label="No completado"
>
    <div class="card-block tone-danger">
        <div class="section-title">{{ $title }}</div>
        <div class="message-box"><strong>Motivo:</strong> {{ $reason }}</div>
        <div class="code-row"><span class="code-chip">{{ $errorCode }}</span></div>
    </div>

    <div class="actions">
        <a class="btn btn-secondary" href="{{ $homeUrl ?: '#' }}">Volver al inicio</a>
    </div>
</x-payment.receipt-shell>
