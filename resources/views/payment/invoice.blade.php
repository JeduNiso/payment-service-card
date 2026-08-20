<x-payment.receipt-shell
    title="Pago completado"
    eyebrow="Comprobante de Pago"
    :reference="$reference"
    status="success"
    status-label="PAGADO"
>
    <div class="card-block tone-success">
        <div class="section-title">Resumen del pago</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Método</div>
                <div class="info-value">Tarjeta {{ $cardLast4 }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Transacción</div>
                <div class="info-value">{{ $transactionId }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Fecha</div>
                <div class="info-value">{{ $paidAt }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Contacto</div>
                <div class="info-value">{{ $email }}</div>
            </div>
        </div>

        <div class="total-box">
            <div class="total-label">Total pagado</div>
            <div class="total-value">{{ $currency }} {{ $amount }}</div>
        </div>
    </div>

    <p class="lead-text">Gracias por su compra. Conserve este comprobante como respaldo de su transacción.</p>

    <div class="actions">
        <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir comprobante</button>
        <a class="btn btn-secondary" href="{{ $homeUrl ?: '#' }}">Volver al inicio</a>
    </div>
</x-payment.receipt-shell>
