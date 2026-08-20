@props([
    'title' => 'Pago de servicio',
    'eyebrow' => 'Comprobante de Pago',
    'reference' => '—',
    'status' => 'success',
    'statusLabel' => 'Pagado',
    'autoRedirect' => false,
    'homeUrl' => null,
    'redirectSeconds' => 10,
])

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        {{-- Same design tokens as resources/views/payment/checkout.blade.php, so the
             whole payment journey (checkout -> comprobante/error) feels like one product. --}}
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
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
            z-index: 0;
        }

        body::before { top: -8rem; left: -8rem; background: rgba(96, 165, 250, 0.28); }
        body::after { right: -10rem; bottom: -8rem; background: rgba(249, 115, 22, 0.2); }

        .wrap {
            position: relative;
            z-index: 1;
            width: min(680px, 100%);
        }

        .panel {
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(255,255,255,0.65);
            backdrop-filter: blur(8px);
            border-radius: 30px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .header {
            padding: 28px 30px 26px;
            background: linear-gradient(180deg, #0f1b2d 0%, #0b1726 100%);
            color: #ffffff;
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }

        .header::after {
            content: "";
            position: absolute;
            inset: auto -60px -60px auto;
            width: 180px;
            height: 180px;
            background: rgba(249, 115, 22, 0.18);
            border-radius: 50%;
        }

        .eyebrow {
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.7);
            position: relative;
            z-index: 1;
        }

        .reference {
            margin-top: 8px;
            font-size: clamp(1.8rem, 3vw, 2.6rem);
            font-weight: 900;
            letter-spacing: -0.05em;
            position: relative;
            z-index: 1;
            word-break: break-word;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            white-space: nowrap;
            position: relative;
            z-index: 1;
        }

        .status-pill.success { background: rgba(66,215,194,0.14); border: 1px solid rgba(66,215,194,0.4); color: #b6fbe8; }
        .status-pill.danger { background: rgba(220,38,38,0.16); border: 1px solid rgba(248,113,113,0.4); color: #fecaca; }

        .status-dot { width: 9px; height: 9px; border-radius: 50%; }
        .status-pill.success .status-dot { background: var(--success); box-shadow: 0 0 14px rgba(66,215,194,0.9); }
        .status-pill.danger .status-dot { background: #f87171; box-shadow: 0 0 14px rgba(248,113,113,0.9); }

        .body { padding: 28px 30px 30px; }

        .card-block {
            border-radius: 18px;
            border: 1px solid var(--line);
            padding: 20px 20px 18px;
            background: #f8fafc;
        }

        .card-block .section-title {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 14px;
        }

        .card-block.tone-success { background: linear-gradient(180deg, #f2fbf9, #eafaf5); border-color: rgba(66,215,194,0.35); }
        .card-block.tone-success .section-title { color: #0f8f79; }
        .card-block.tone-danger { background: linear-gradient(180deg, #fff6f6, #fff0f0); border-color: rgba(220,38,38,0.22); }
        .card-block.tone-danger .section-title { color: #b91c1c; }

        .info-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 14px; }
        .info-item { background: #ffffff; border: 1px solid rgba(148,163,184,0.18); border-radius: 12px; padding: 12px 14px; }
        .info-label { font-size: 10px; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
        .info-value { font-size: 0.98rem; font-weight: 700; word-break: break-word; }

        .total-box {
            margin-top: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--panel) 0%, #17233a 100%);
            color: #ffffff;
        }
        .total-label { font-size: 11px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(255,255,255,0.7); }
        .total-value { font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 900; letter-spacing: -0.05em; }

        .message-box {
            padding: 18px 18px;
            color: #374151;
            line-height: 1.7;
            font-size: 0.98rem;
        }
        .message-box strong { color: #0f172a; }

        .code-row { display: flex; justify-content: center; margin-top: 16px; }
        .code-chip {
            display: inline-block;
            padding: 7px 14px;
            border-radius: 999px;
            background: #fff0f0;
            border: 1px solid rgba(220,38,38,0.2);
            color: #b91c1c;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .lead-text { color: var(--muted); font-size: 0.92rem; line-height: 1.6; text-align: center; margin-top: 22px; }

        .actions { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; margin-top: 24px; }

        .redirect-note {
            margin-top: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--muted);
            font-size: 0.86rem;
        }
        .redirect-note .spinner {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid var(--line);
            border-top-color: var(--primary);
            animation: redirectSpin 0.8s linear infinite;
            flex-shrink: 0;
        }
        @keyframes redirectSpin { to { transform: rotate(360deg); } }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 14px;
            padding: 15px 22px;
            min-width: 200px;
            font-weight: 800;
            font-size: 0.96rem;
            letter-spacing: 0.02em;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(249,115,22,0.28);
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 14px 28px rgba(249,115,22,0.36); }

        .btn-secondary {
            background: #eef2f6;
            color: #0f172a;
            border: 1px solid var(--line);
        }
        .btn-secondary:hover { transform: translateY(-1px); }

        @media (max-width: 560px) {
            .header { flex-direction: column; }
            .info-grid { grid-template-columns: 1fr; }
            .total-box { flex-direction: column; align-items: flex-start; }
            .actions { flex-direction: column; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="panel">
            <div class="header">
                <div>
                    <div class="eyebrow">{{ $eyebrow }}</div>
                    <div class="reference">{{ $reference }}</div>
                </div>
                <div class="status-pill {{ $status }}">
                    <span class="status-dot"></span> {{ $statusLabel }}
                </div>
            </div>
            <div class="body">
                {{ $slot }}

                @if ($autoRedirect && $homeUrl)
                    <div class="redirect-note" id="redirectNote" data-template="{{ __('payment.redirect_note') }}">
                        <span class="spinner" aria-hidden="true"></span>
                        <span id="redirectNoteText"></span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($autoRedirect && $homeUrl)
        <script>
            (function () {
                var redirectUrl = @json($homeUrl);
                var seconds = {{ (int) $redirectSeconds }};
                var template = document.getElementById('redirectNote').dataset.template;
                var textEl = document.getElementById('redirectNoteText');
                var deadline = Date.now() + seconds * 1000;

                function tick() {
                    var remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
                    textEl.textContent = template.replace(':seconds', remaining);

                    if (Date.now() >= deadline) {
                        window.location.href = redirectUrl;
                        return;
                    }

                    setTimeout(tick, 250);
                }

                // Polls real elapsed time (instead of one setTimeout for the full delay)
                // so the redirect still fires right on schedule even after a blocking
                // action like window.print() pauses script execution for a while.
                tick();
            })();
        </script>
    @endif
</body>
</html>
