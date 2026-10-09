<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Escanear un código de barras — CONTAPP</title>
    {{--
        La página que abre en el teléfono el QR del código de barras de un
        artículo (ItemBarcodePhoneController, CLAUDE.md secc. 33). El código se
        lee acá mismo, con ZXing, y se manda solo el texto: ninguna imagen sale
        del teléfono. Sin Inertia ni Vite, como la de Conti: en desarrollo el
        teléfono no llega al servidor de Vite. ZXing va como archivo en
        public/vendor/zxing (npm run vendor:zxing).

        - Con conexión segura (https): video en vivo, lee solo.
        - Sin ella (http en la red local): con una foto del código.
    --}}
    <style>
        :root {
            --bg: #f4f7f8; --surface: #ffffff; --text: #10242b; --muted: #5b6f76; --border: #d6e0e3;
            --primary: #0d4a5c; --primary-soft: #e2eff2; --on-primary: #ffffff; --danger: #b42318; --success: #1e7a4c;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d1a1e; --surface: #13252b; --text: #e6eef0; --muted: #94a8ae; --border: #25404a;
                --primary: #3aa3bd; --primary-soft: #16343d; --on-primary: #06161b; --danger: #f97066; --success: #4ade80;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100dvh; padding: 1.25rem 1rem 2rem;
            background: var(--bg); color: var(--text);
            font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        main { max-width: 30rem; margin: 0 auto; display: flex; flex-direction: column; gap: 1rem; }
        .brand { display: flex; align-items: center; gap: 0.6rem; font-weight: 700; }
        .brand-mark { display: inline-grid; place-items: center; width: 2.2rem; height: 2.2rem; border-radius: 50%; background: var(--primary); color: var(--on-primary); font-size: 0.85rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 0.9rem; padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem; }
        h1 { margin: 0; font-size: 1.15rem; }
        p { margin: 0; }
        .muted { color: var(--muted); font-size: 0.88rem; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem; width: 100%; min-height: 3rem; padding: 0.7rem 1rem; border: 1px solid var(--border); border-radius: 0.6rem; background: var(--surface); color: var(--text); font: inherit; font-weight: 600; cursor: pointer; }
        .btn-primary { background: var(--primary); border-color: var(--primary); color: var(--on-primary); }
        .btn:disabled { opacity: 0.5; cursor: default; }
        .view { position: relative; width: 100%; aspect-ratio: 4 / 3; overflow: hidden; border-radius: 0.6rem; background: #000; }
        .view video { width: 100%; height: 100%; object-fit: cover; display: block; }
        .guide { position: absolute; left: 10%; right: 10%; top: 50%; height: 2px; background: #ef4444; box-shadow: 0 0 6px #ef4444; }
        .code { padding: 0.75rem; border-radius: 0.6rem; background: var(--primary-soft); font: 700 1.4rem/1.3 ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; text-align: center; overflow-wrap: anywhere; }
        .status { font-size: 0.92rem; }
        .status.is-error { color: var(--danger); }
        .spinner { width: 1rem; height: 1rem; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .done { text-align: center; align-items: center; padding: 1.5rem 1rem; }
        .done-mark { display: inline-grid; place-items: center; width: 3rem; height: 3rem; border-radius: 50%; background: var(--primary-soft); color: var(--success); font-size: 1.5rem; }
        input[type="file"] { display: none; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<main>
    <div class="brand"><span class="brand-mark">C</span> CONTAPP</div>

    @if ($scan === null)
        <section class="card">
            <h1>Este código ya no sirve</h1>
            <p class="muted">Venció, ya se usó o no existe. Generá otro desde la computadora: en el artículo, «Escanear».</p>
        </section>
    @else
        <section class="card" id="scan">
            <h1>Escanear el código de barras</h1>
            <p class="muted">
                @if ($scan['para'])
                    Para «{{ $scan['para'] }}» en {{ $scan['compania'] }}.
                @else
                    Para un artículo de {{ $scan['compania'] }}.
                @endif
            </p>

            <div class="view" id="live" hidden>
                <video id="video" muted playsinline></video>
                <span class="guide" aria-hidden="true"></span>
            </div>

            <input id="camera" type="file" accept="image/*" capture="environment">
            <button type="button" class="btn" id="take">📷 Tomar foto del código</button>
            <p class="status muted" id="status" role="status">Abriendo la cámara…</p>
            <p class="muted">El código vence en <span id="left"></span>.</p>
        </section>

        <section class="card" id="result" hidden>
            <h1>Leí este código</h1>
            <p class="code" id="code"></p>
            <button type="button" class="btn btn-primary" id="send">Enviar a la computadora</button>
            <button type="button" class="btn" id="again">Escanear de nuevo</button>
            <p class="status muted" id="send-status" role="status"></p>
        </section>

        <section class="card done" id="done" hidden>
            <span class="done-mark">✓</span>
            <h1>Listo</h1>
            <p class="muted">El código ya está en la computadora: revisalo ahí y guardá el artículo. Podés cerrar esta página.</p>
        </section>

        <script src="/vendor/zxing/zxing-browser.min.js?v={{ @filemtime(public_path('vendor/zxing/zxing-browser.min.js')) }}"></script>
        <script>
            (() => {
                const EXPIRES = {{ (int) $scan['vence'] }} * 1000;
                const URL_SEND = @json(route('barcode-phone.store', $token, false));
                const CSRF = document.querySelector('meta[name="csrf-token"]').content;
                const $ = (id) => document.getElementById(id);
                // DecodeHintType.TRY_HARDER de ZXing (la versión para el navegador no exporta el enum).
                const TRY_HARDER = 3;

                const Z = window.ZXingBrowser;
                const liveReader = Z ? new Z.BrowserMultiFormatOneDReader(undefined, { delayBetweenScanAttempts: 200 }) : null;
                const photoReader = Z ? new Z.BrowserMultiFormatOneDReader(new Map([[TRY_HARDER, true]])) : null;
                let controls = null;
                let code = null;
                let expired = false;

                function setStatus(el, text, error = false) {
                    el.className = `status ${error ? 'is-error' : 'muted'}`;
                    el.textContent = text;
                }

                const tick = () => {
                    const ms = EXPIRES - Date.now();
                    if (ms <= 0) {
                        expired = true;
                        stopLive();
                        setStatus($('status'), 'El código venció. Generá otro desde la computadora.', true);
                        setStatus($('send-status'), 'El código venció. Generá otro desde la computadora.', true);
                        $('take').disabled = $('send').disabled = $('again').disabled = true;
                        return;
                    }
                    $('left').textContent = `${Math.floor(ms / 60000)}:${String(Math.floor(ms / 1000) % 60).padStart(2, '0')}`;
                    setTimeout(tick, 1000);
                };

                function stopLive() {
                    controls?.stop();
                    controls = null;
                    $('live').hidden = true;
                }

                // Video en vivo solo con conexión segura (https); si no, con una foto.
                async function startLive() {
                    if (!Z) {
                        setStatus($('status'), 'No se pudo cargar el lector. Recargá la página.', true);
                        $('take').disabled = true;
                        return;
                    }
                    if (!navigator.mediaDevices?.getUserMedia) {
                        setStatus($('status'), 'Tomá una foto del código: que se vea completo, derecho y con buena luz.');
                        return;
                    }
                    try {
                        $('live').hidden = false;
                        controls = await liveReader.decodeFromConstraints(
                            { video: { facingMode: { ideal: 'environment' } } },
                            $('video'),
                            (result) => { if (result) found(result.getText()); },
                        );
                        if (code !== null) stopLive();
                        else setStatus($('status'), 'Apuntá al código de barras, derecho y que se vea completo: se lee solo.');
                    } catch {
                        $('live').hidden = true;
                        setStatus($('status'), 'No se pudo abrir la cámara en vivo. Tomá una foto del código.');
                    }
                }

                function found(text) {
                    if (code !== null || expired) return;
                    code = String(text).trim();
                    stopLive();
                    $('code').textContent = code;
                    $('scan').hidden = true;
                    $('result').hidden = false;
                    setStatus($('send-status'), 'Revisá que sea el del artículo y mandalo.');
                }

                // La foto, achicada: una de 12 megapíxeles tarda mucho en leerse.
                function canvasFrom(image, maxSide) {
                    const scale = Math.min(1, maxSide / Math.max(image.naturalWidth, image.naturalHeight));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(image.naturalWidth * scale);
                    canvas.height = Math.round(image.naturalHeight * scale);
                    canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
                    return canvas;
                }

                $('take').onclick = () => $('camera').click();

                $('camera').onchange = () => {
                    const file = $('camera').files?.[0];
                    $('camera').value = '';
                    if (!file || !photoReader) return;

                    setStatus($('status'), 'Leyendo la foto…');
                    const url = URL.createObjectURL(file);
                    const image = new Image();
                    image.onload = () => {
                        URL.revokeObjectURL(url);
                        for (const side of [1600, 1000]) {
                            try {
                                found(photoReader.decodeFromCanvas(canvasFrom(image, side)).getText());
                                return;
                            } catch {
                                // Con otro tamaño, a veces sí.
                            }
                        }
                        setStatus($('status'), 'No encontré un código de barras en la foto. Probá más cerca, derecho, con buena luz y que se vea completo.', true);
                    };
                    image.onerror = () => {
                        URL.revokeObjectURL(url);
                        setStatus($('status'), 'No se pudo usar esa foto. Probá de nuevo.', true);
                    };
                    image.src = url;
                };

                $('again').onclick = () => {
                    code = null;
                    $('result').hidden = true;
                    $('scan').hidden = false;
                    setStatus($('status'), 'Abriendo la cámara…');
                    startLive();
                };

                $('send').onclick = async () => {
                    $('send').disabled = $('again').disabled = true;
                    $('send-status').className = 'status muted';
                    $('send-status').innerHTML = '<span style="display:inline-flex;gap:.5rem;align-items:center"><span class="spinner"></span> Enviando…</span>';

                    let response;
                    try {
                        response = await fetch(URL_SEND, {
                            method: 'POST',
                            body: JSON.stringify({ codigo: code }),
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                            credentials: 'same-origin',
                        });
                    } catch {
                        setStatus($('send-status'), 'No hay conexión con CONTAPP. Revisá que el teléfono esté en la misma red y probá de nuevo.', true);
                        $('send').disabled = $('again').disabled = false;
                        return;
                    }

                    if (response.ok) {
                        $('result').hidden = true;
                        $('done').hidden = false;
                        return;
                    }

                    const data = await response.json().catch(() => ({}));
                    const message = response.status === 422
                        ? Object.values(data.errors ?? {}).flat()[0] ?? data.message
                        : response.status === 419
                            ? 'La página estuvo abierta mucho tiempo. Volvé a escanear el código QR.'
                            : data.message;
                    setStatus($('send-status'), message || 'No se pudo enviar. Probá de nuevo.', true);
                    if (response.status !== 410) $('send').disabled = $('again').disabled = false;
                };

                tick();
                startLive();
            })();
        </script>
    @endif
</main>
</body>
</html>
