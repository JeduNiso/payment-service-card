<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago de servicio</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>
        :root {
            --bg: #eaf1f5;
            --card: #ffffff;
            --panel: #0f172a;
            --primary: #f97316;
            --primary-dark: #dd6b20;
            --muted: #64748b;
            --line: #dfe7ee;
            --success: #42d7c2;
            --danger: #dc2626;
            --shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
        }

        * { box-sizing: border-box; }

        body {
            position: relative;
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: linear-gradient(180deg, #eef4ff 0%, var(--bg) 100%);
            color: #0f172a;
            overflow-x: hidden;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            width: 34rem;
            height: 34rem;
            filter: blur(72px);
            opacity: 0.55;
            pointer-events: none;
            animation: ambientFloat 18s ease-in-out infinite alternate;
            z-index: 0;
        }

        body::before {
            top: -8rem;
            left: -8rem;
            background: rgba(96, 165, 250, 0.28);
        }

        body::after {
            right: -10rem;
            bottom: -8rem;
            background: rgba(249, 115, 22, 0.2);
            animation-delay: 2s;
        }

        @keyframes ambientFloat {
            0% { transform: translate3d(0, 0, 0) scale(1); }
            100% { transform: translate3d(24px, 18px, 0) scale(1.08); }
        }

        .layout {
            position: relative;
            z-index: 1;
            width: min(1180px, calc(100% - 32px));
            margin: 48px auto;
            display: grid;
            grid-template-columns: 1.02fr 0.98fr;
            gap: 28px;
        }

        .panel {
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(148,163,184,0.18);
            backdrop-filter: blur(8px);
            border-radius: 30px;
            box-shadow: 0 24px 50px rgba(15, 23, 42, 0.12);
            border: 1px solid rgba(255,255,255,0.65);
            transform: translateY(0);
        }

        .summary {
            padding: 24px 28px 28px;
            background: linear-gradient(180deg, #0f1b2d 0%, #0b1726 100%);
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.28);
            min-height: 690px;
        }

        .summary::after {
            content: "";
            position: absolute;
            inset: auto -80px -80px auto;
            width: 220px;
            height: 220px;
            background: rgba(249, 115, 22, 0.18);
            border-radius: 50%;
        }

        .summary-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            position: relative;
            z-index: 1;
        }

        .brand-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.14);
            font-size: 12px;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            color: #dbeafe;
            font-weight: 700;
        }

        .brand-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 18px rgba(66, 215, 194, 0.95);
        }

        .summary h1 {
            margin: 24px 0 8px;
            font-size: clamp(2.3rem, 3vw, 4rem);
            font-weight: 900;
            line-height: 1;
            letter-spacing: -0.06em;
            position: relative;
            z-index: 1;
        }

        .summary-subtitle {
            color: rgba(255,255,255,0.8);
            font-size: 1.05rem;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
        }

        .ticket {
            margin-top: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 20px;
            padding: 18px 18px 16px;
            position: relative;
            z-index: 1;
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            color: rgba(255,255,255,0.76);
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }

        .ticket-code {
            margin-top: 10px;
            font-size: clamp(2rem, 2.7vw, 3.1rem);
            font-weight: 800;
            letter-spacing: 0.08em;
            color: white;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-top: 18px;
        }

        .meta-item {
            background: rgba(15, 23, 42, 0.28);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 12px 14px;
        }

        .meta-label {
            color: rgba(191,219,254,0.8);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }

        .meta-value {
            margin-top: 8px;
            font-size: 1.05rem;
            font-weight: 700;
        }

        .total-box {
            margin-top: 26px;
            border-top: 1px solid rgba(255,255,255,0.12);
            padding-top: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: relative;
            z-index: 1;
        }

        .total-label {
            color: rgba(255,255,255,0.76);
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 0.76rem;
        }

        .total-value {
            font-size: clamp(2rem, 3vw, 3.1rem);
            font-weight: 900;
            letter-spacing: -0.07em;
        }

        .checkout {
            padding: 30px 28px 24px;
        }

        .header-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 26px;
        }

        .eyebrow {
            color: var(--muted);
            font-size: 0.76rem;
            letter-spacing: 0.11em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .checkout h2 {
            margin: 10px 0 0;
            font-size: clamp(1.8rem, 2vw, 2.4rem);
            letter-spacing: -0.05em;
        }

        .secure-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid rgba(15,118,110,0.2);
            background: rgba(13,148,136,0.08);
            color: var(--success);
            font-size: 0.78rem;
            font-weight: 700;
        }

        .alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(220,38,38,0.18);
            background: rgba(254,242,242,0.85);
            color: #991b1b;
            font-size: 0.96rem;
            margin-bottom: 18px;
        }

        .card-scene {
            perspective: 1600px;
            margin-bottom: 20px;
        }

        .card-preview {
            position: relative;
            min-height: 210px;
            border-radius: 20px;
            transform-style: preserve-3d;
            transition: transform 0.9s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.3s ease;
            box-shadow: 0 18px 30px rgba(15, 23, 42, 0.22);
            animation: cardFloat 6s ease-in-out infinite;
        }

        .card-preview::before {
            content: "";
            position: absolute;
            inset: -10% -8%;
            background: radial-gradient(circle at center, rgba(249,115,22,0.28), transparent 58%);
            filter: blur(18px);
            z-index: -1;
            opacity: 0.9;
        }

        .card-preview.is-flipped {
            transform: rotateY(180deg);
        }

        @keyframes cardFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-6px); }
        }

        .card-face {
            position: absolute;
            inset: 0;
            backface-visibility: hidden;
            border-radius: 20px;
            overflow: hidden;
            padding: 20px 18px 18px;
            color: white;
        }

        .card-front {
            background: linear-gradient(135deg, #111827 0%, #1f2937 100%);
        }

        .card-front::before,
        .card-back::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.08), transparent 52%, rgba(249,115,22,0.08));
            pointer-events: none;
        }

        .card-back {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            transform: rotateY(180deg);
        }

        .card-chip {
            width: 44px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, #fef3c7, #fbbf24);
            margin-bottom: 22px;
        }

        .card-number {
            font-size: clamp(1.15rem, 2vw, 1.6rem);
            letter-spacing: 0.18em;
            font-weight: 700;
            min-height: 30px;
        }

        .card-meta {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 12px;
            margin-top: 18px;
        }

        .card-label {
            color: rgba(255,255,255,0.7);
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }

        .card-name {
            font-size: 1rem;
            font-weight: 700;
        }

        .card-expiry {
            font-size: 0.98rem;
            font-weight: 700;
        }

        .card-bar {
            height: 44px;
            background: rgba(0, 0, 0, 0.6);
            margin: 18px -18px 18px -18px;
        }

        .card-signature {
            margin: 0 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.9);
            font-size: 0.72rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .card-signature strong {
            font-size: 1rem;
            letter-spacing: 0.18em;
            color: #ffffff;
        }

        form {
            display: grid;
            gap: 20px;
        }

        .field-group {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        .field label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #334155;
        }

        .field input,
        .field select {
            width: 100%;
            padding: 14px 15px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.9);
            font-size: 1rem;
            color: #0f172a;
            transform: translateY(0);
            transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease, background 0.15s ease;
        }

        .field input:focus,
        .field select:focus {
            outline: none;
            border-color: rgba(249, 115, 22, 0.7);
            box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.12);
            transform: translateY(-1px);
        }

        .field input.has-value,
        .field select.has-value {
            border-color: rgba(13, 148, 136, 0.35);
            background: rgba(255,255,255,0.98);
        }

        .field input::placeholder { color: #94a3b8; }

        .submit-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-top: 8px;
        }

        .mini-note {
            color: var(--muted);
            font-size: 0.8rem;
        }

        .btn {
            position: relative;
            overflow: hidden;
            border: 0;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            font-weight: 800;
            cursor: pointer;
            padding: 16px 24px;
            font-size: 1rem;
            letter-spacing: 0.02em;
            box-shadow: 0 12px 24px rgba(249,115,22,0.28);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .btn::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent 0%, rgba(255,255,255,0.25) 50%, transparent 100%);
            transform: translateX(-135%);
            transition: transform 0.7s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(249,115,22,0.36);
        }

        .btn:hover::before {
            transform: translateX(135%);
        }

        .btn:disabled {
            opacity: 0.8;
            cursor: wait;
        }

        @media (max-width: 980px) {
            .layout {
                grid-template-columns: 1fr;
                margin: 28px auto;
            }
            .summary {
                order: 2;
            }
            .checkout {
                order: 1;
            }
        }

        @media (max-width: 620px) {
            .field-group {
                grid-template-columns: 1fr;
            }
            .submit-row {
                flex-direction: column;
                align-items: stretch;
            }
            .btn {
                width: 100%;
            }
        }
    </style>
    @endif
</head>
<body>
    <div class="layout">
        <aside class="panel summary">
            <div class="summary-header">
                <span class="brand-pill"><span class="brand-dot"></span> Secure checkout</span>
            </div>

            <h1>Pago de servicio</h1>
            <div class="summary-subtitle">Realiza tus pagos de forma segura.</div>

            <div class="ticket">
                <div class="ticket-header">
                    <span>Referencia</span>
                    <span>#{!! $booking['code'] ?? '---' !!}</span>
                </div>
                <div class="ticket-code">{{ strtoupper($booking['code'] ?? '---') }}</div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <div class="meta-label">Servicio</div>
                        <div class="meta-value">{{ $booking['description'] ?? $booking['service'] ?? 'Pago de servicio' }}</div>
                    </div>
                </div>
            </div>

            <div class="total-box">
                <div>
                    <div class="total-label">Total a pagar</div>
                </div>
                <div class="total-value">{{ number_format((float) ($booking['amount'] ?? 0), 2, ',', '.') }} {{ $booking['currency'] ?? 'BOB' }}</div>
            </div>
        </aside>

        <main class="panel checkout">
            <div class="header-row">
                <div>
                    <div class="eyebrow">Checkout</div>
                    <h2>Datos del pago</h2>
                </div>
                <span class="secure-tag">🔒 Pago seguro</span>
            </div>

            @if (!$valid)
                <div class="alert">⚠️ {{ $error ?? 'La sesión de pago ya no es válida.' }}</div>
            @else
                @if (session('payment_error'))
                    <div class="alert">⚠️ {{ session('payment_error') }}</div>
                @endif

                <div class="card-scene">
                    <div class="card-preview" id="creditCardPreview" aria-live="polite">
                        <div class="card-face card-front">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                                <div class="card-chip"></div>
                                <div style="font-size:0.72rem;letter-spacing:0.14em;text-transform:uppercase;opacity:0.8;" id="cardBrandLabel">Tarjeta</div>
                            </div>
                            <div class="card-number" id="cardNumberField">•••• •••• •••• ••••</div>
                            <div class="card-meta">
                                <div>
                                    <div class="card-label">Titular</div>
                                    <div class="card-name" id="cardNameField">NOMBRE Y APELLIDO</div>
                                </div>
                                <div>
                                    <div class="card-label">Expira</div>
                                    <div class="card-expiry" id="cardExpiryField">MM/AA</div>
                                </div>
                            </div>
                        </div>
                        <div class="card-face card-back">
                            <div class="card-bar"></div>
                            <div class="card-signature">
                                <span>CVV</span>
                                <strong id="cardCvvField">•••</strong>
                            </div>
                        </div>
                    </div>
                </div>

                @php
                    // Test card + billing data, only pre-filled when CHECKOUT_PREFILL_TEST_CARD=true
                    // (local/staging convenience). In production this stays false so real
                    // customers get a blank form and type their own card.
                    $prefill = config('services.checkout.prefill_test_card')
                        ? [
                            'card_number' => '4000000000001000',
                            'cardholder_name' => 'Roberto Jimenez',
                            'expiry_month' => 12,
                            'expiry_year' => 2028,
                            'cvv' => '123',
                            'billing_email' => 'cliente@example.com',
                            'billing_first_name' => 'Roberto',
                            'billing_last_name' => 'Jimenez',
                            'billing_address1' => 'Calle Herber 123',
                            'billing_locality' => 'La Paz',
                            'billing_country' => 'BO',
                        ]
                        : [];
                @endphp
                <form method="POST" action="{{ url('/payments/' . rawurlencode($token)) }}">
                    @csrf
                    <div class="field">
                        <label for="card_number">Número de tarjeta</label>
                        <input id="card_number" name="card_number" type="text" inputmode="numeric" maxlength="19" autocomplete="cc-number" placeholder="1234 5678 9012 3456" value="{{ old('card_number', $prefill['card_number'] ?? '') }}" required>
                    </div>

                    <div class="field">
                        <label for="cardholder_name">Nombre del titular</label>
                        <input id="cardholder_name" name="cardholder_name" type="text" autocomplete="cc-name" placeholder="Nombre y apellido" value="{{ old('cardholder_name', $prefill['cardholder_name'] ?? '') }}" required>
                    </div>

                    <div class="field-group">
                        <div class="field">
                            <label for="expiry_month">Mes</label>
                            <select id="expiry_month" name="expiry_month" required>
                                <option value="" @selected(old('expiry_month', $prefill['expiry_month'] ?? '') === '') disabled hidden>MM</option>
                                @for ($month = 1; $month <= 12; $month++)
                                    <option value="{{ $month }}" @selected(old('expiry_month', $prefill['expiry_month'] ?? '') == $month)>{{ str_pad($month, 2, '0', STR_PAD_LEFT) }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="field">
                            <label for="expiry_year">Año</label>
                            <select id="expiry_year" name="expiry_year" required>
                                <option value="" @selected(old('expiry_year', $prefill['expiry_year'] ?? '') === '') disabled hidden>AAAA</option>
                                @for ($year = (int) date('Y'); $year <= (int) date('Y') + 14; $year++)
                                    <option value="{{ $year }}" @selected(old('expiry_year', $prefill['expiry_year'] ?? '') == $year)>{{ $year }}</option>
                                @endfor
                            </select>
                        </div>
                    </div>

                    <div class="field-group">
                        <div class="field">
                            <label for="cvv">CVV</label>
                            <input id="cvv" name="cvv" type="password" inputmode="numeric" maxlength="4" autocomplete="cc-csc" placeholder="123" value="{{ old('cvv', $prefill['cvv'] ?? '') }}" required>
                        </div>
                        <div class="field">
                            <label for="billing_email">Correo electrónico</label>
                            <input id="billing_email" name="billing_email" type="email" autocomplete="email" placeholder="tucorreo@ejemplo.com" value="{{ old('billing_email', $prefill['billing_email'] ?? '') }}" required>
                        </div>
                    </div>

                    <div class="field-group">
                        <div class="field">
                            <label for="billing_first_name">Nombre</label>
                            <input id="billing_first_name" name="billing_first_name" type="text" value="{{ old('billing_first_name', $prefill['billing_first_name'] ?? '') }}">
                        </div>
                        <div class="field">
                            <label for="billing_last_name">Apellido</label>
                            <input id="billing_last_name" name="billing_last_name" type="text" value="{{ old('billing_last_name', $prefill['billing_last_name'] ?? '') }}">
                        </div>
                    </div>

                    <div class="field">
                        <label for="billing_address1">Dirección</label>
                        <input id="billing_address1" name="billing_address1" type="text" value="{{ old('billing_address1', $prefill['billing_address1'] ?? '') }}">
                    </div>

                    <div class="field-group">
                        <div class="field">
                            <label for="billing_locality">Ciudad</label>
                            <input id="billing_locality" name="billing_locality" type="text" value="{{ old('billing_locality', $prefill['billing_locality'] ?? '') }}">
                        </div>
                        <div class="field">
                            <label for="billing_country">País</label>
                            <input id="billing_country" name="billing_country" type="text" maxlength="2" value="{{ old('billing_country', $prefill['billing_country'] ?? '') }}">
                        </div>
                    </div>

                    <div class="submit-row">
                        <div class="mini-note">Tus datos se usan sólo para procesar este pago.</div>
                        <button class="btn" type="submit">Pagar ahora</button>
                    </div>
                </form>
            @endif
        </main>
    </div>

    <script>
        const cardNumberInput = document.getElementById('card_number');
        const cardholderInput = document.getElementById('cardholder_name');
        const expiryMonthInput = document.getElementById('expiry_month');
        const expiryYearInput = document.getElementById('expiry_year');
        const cvvInput = document.getElementById('cvv');
        const form = document.querySelector('form');
        const cardPreview = document.getElementById('creditCardPreview');

        function syncFieldState(input) {
            if (!input) return;
            input.classList.toggle('has-value', input.value.trim() !== '');
        }

        document.querySelectorAll('.field input, .field select').forEach((input) => {
            syncFieldState(input);
            input.addEventListener('input', () => syncFieldState(input));
            input.addEventListener('change', () => syncFieldState(input));
        });
        const cardNumberOutput = document.getElementById('cardNumberField');
        const cardNameOutput = document.getElementById('cardNameField');
        const cardExpiryOutput = document.getElementById('cardExpiryField');
        const cardCvvOutput = document.getElementById('cardCvvField');
        const cardBrandLabel = document.getElementById('cardBrandLabel');

        function formatCardNumber(value) {
            const digits = value.replace(/\D/g, '').slice(0, 16);
            return digits.replace(/(\d{4})(?=\d)/g, '$1 ');
        }

        function detectCardBrand(value) {
            const digits = value.replace(/\D/g, '');
            if (/^4/.test(digits)) return 'Visa';
            if (/^(5[1-5]|2[2-7])/.test(digits)) return 'Mastercard';
            if (/^3[47]/.test(digits)) return 'Amex';
            return 'Tarjeta';
        }

        function updateCardPreview() {
            const raw = cardNumberInput ? cardNumberInput.value : '';
            const value = formatCardNumber(raw);
            const visible = value ? value.padEnd(19, ' ') : '•••• •••• •••• ••••';
            cardNumberOutput.textContent = visible;

            const brand = detectCardBrand(raw);
            if (cardBrandLabel) {
                cardBrandLabel.textContent = brand;
            }
        }

        if (cardNumberInput) {
            cardNumberInput.addEventListener('input', (event) => {
                const value = formatCardNumber(event.target.value);
                event.target.value = value;
                updateCardPreview();
            });
        }

        if (cardholderInput) {
            cardholderInput.addEventListener('input', (event) => {
                const value = event.target.value.trim();
                cardNameOutput.textContent = value || 'NOMBRE Y APELLIDO';
            });
        }

        if (cvvInput) {
            cvvInput.addEventListener('input', (event) => {
                const digits = event.target.value.replace(/\D/g, '').slice(0, 4);
                event.target.value = digits;
                cardCvvOutput.textContent = digits ? digits.padEnd(3, '•') : '•••';
            });

            cvvInput.addEventListener('focus', () => {
                if (cardPreview) cardPreview.classList.add('is-flipped');
            });

            cvvInput.addEventListener('blur', () => {
                if (cardPreview) cardPreview.classList.remove('is-flipped');
            });
        }

        ['card_number', 'cardholder_name', 'expiry_month', 'expiry_year'].forEach((fieldId) => {
            const field = document.getElementById(fieldId);
            if (!field) return;

            field.addEventListener('focus', () => {
                if (cardPreview) cardPreview.classList.remove('is-flipped');
            });
        });

        function updateExpiryDisplay() {
            const month = expiryMonthInput ? expiryMonthInput.value : '12';
            const year = expiryYearInput ? expiryYearInput.value : '2028';
            const suffix = String(year).slice(-2);
            cardExpiryOutput.textContent = month && year ? `${month.padStart(2, '0')}/${suffix}` : 'MM/AA';
        }

        if (expiryMonthInput) expiryMonthInput.addEventListener('change', updateExpiryDisplay);
        if (expiryYearInput) expiryYearInput.addEventListener('change', updateExpiryDisplay);

        if (form) {
            form.addEventListener('submit', () => {
                const submitButton = form.querySelector('button[type="submit"]');
                if (!submitButton) return;

                submitButton.disabled = true;
                submitButton.textContent = 'Procesando...';
            });
        }

        updateCardPreview();
        updateExpiryDisplay();
        if (cardCvvOutput) cardCvvOutput.textContent = '•••';
    </script>
</body>
</html>
