<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Escanear un documento — CONTAPP</title>
    {{--
        La página que abre el QR de Conti en el teléfono (ContiScanPhoneController,
        CLAUDE.md secc. 32): tomar las fotos de un documento y mandarlas. Sin
        Inertia ni Vite, a propósito: en desarrollo el teléfono no llega al
        servidor de Vite. Las fotos se achican acá antes de subirse y no se
        guardan en ningún lado.
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
        .photos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; }
        .photo { position: relative; aspect-ratio: 3 / 4; border-radius: 0.5rem; overflow: hidden; border: 1px solid var(--border); background: var(--bg); }
        .photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .photo button { position: absolute; top: 0.25rem; right: 0.25rem; width: 1.8rem; height: 1.8rem; border: 0; border-radius: 50%; background: rgba(0, 0, 0, 0.6); color: #fff; font-size: 1rem; cursor: pointer; }
        .status { font-size: 0.92rem; }
        .status.is-error { color: var(--danger); }
        .status.is-ok { color: var(--success); font-weight: 600; }
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
    <div class="brand"><span class="brand-mark">C</span> CONTAPP · Conti</div>

    @if ($scan === null)
        <section class="card">
            <h1>Este código ya no sirve</h1>
            <p class="muted">Venció, ya se usó o no existe. Generá otro desde la computadora: en el botón de Conti, «Escanear».</p>
        </section>
    @else
        <section class="card" id="capture">
            <h1>Escanear: {{ $scan['titulo'] }}</h1>
            <p class="muted">Para registrar «{{ $scan['registro'] }}» en {{ $scan['compania'] }}. Tomá una foto del documento de frente, completo y con buena luz. Si tiene varias páginas, una foto por página (hasta {{ $maxPhotos }}).</p>

            <div class="photos" id="photos" hidden></div>

            <input id="camera" type="file" accept="image/*" capture="environment">
            <button type="button" class="btn" id="take">📷 Tomar foto</button>
            <button type="button" class="btn btn-primary" id="send" disabled>Enviar para leer</button>
            <p class="status muted" id="status" role="status">El código vence en <span id="left"></span>.</p>
        </section>

        <section class="card done" id="done" hidden>
            <span class="done-mark">✓</span>
            <h1>Listo</h1>
            <p class="muted">Revisalo en la computadora: el formulario ya está ahí. Podés cerrar esta página.</p>
        </section>

        <script>
            (() => {
                const MAX = {{ $maxPhotos }};
                const EXPIRES = {{ (int) $scan['vence'] }} * 1000;
                const URL_UPLOAD = @json(route('conti.phone.upload', $token, false));
                const CSRF = document.querySelector('meta[name="csrf-token"]').content;

                const camera = document.getElementById('camera');
                const take = document.getElementById('take');
                const send = document.getElementById('send');
                const status = document.getElementById('status');
                const grid = document.getElementById('photos');
                const left = document.getElementById('left');
                const photos = [];

                // Cuánto le queda al código.
                const tick = () => {
                    const ms = EXPIRES - Date.now();
                    if (ms <= 0) {
                        setStatus('El código venció. Generá otro desde la computadora.', 'error');
                        take.disabled = send.disabled = true;
                        return;
                    }
                    if (left) left.textContent = `${Math.floor(ms / 60000)}:${String(Math.floor(ms / 1000) % 60).padStart(2, '0')}`;
                    setTimeout(tick, 1000);
                };

                function setStatus(text, kind = '') {
                    status.className = `status ${kind === 'error' ? 'is-error' : kind === 'ok' ? 'is-ok' : 'muted'}`;
                    status.textContent = text;
                }

                // La foto, achicada a 1600 px y en JPEG: sube rápido y la IA la lee igual.
                function shrink(file) {
                    return new Promise((resolve, reject) => {
                        const img = new Image();
                        const url = URL.createObjectURL(file);
                        img.onload = () => {
                            const scale = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
                            const canvas = document.createElement('canvas');
                            canvas.width = Math.round(img.naturalWidth * scale);
                            canvas.height = Math.round(img.naturalHeight * scale);
                            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                            URL.revokeObjectURL(url);
                            canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('foto'))), 'image/jpeg', 0.82);
                        };
                        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('foto')); };
                        img.src = url;
                    });
                }

                function render() {
                    grid.hidden = photos.length === 0;
                    grid.innerHTML = '';
                    photos.forEach((photo, i) => {
                        const box = document.createElement('div');
                        box.className = 'photo';
                        const img = document.createElement('img');
                        img.src = photo.preview;
                        img.alt = `Página ${i + 1}`;
                        const remove = document.createElement('button');
                        remove.type = 'button';
                        remove.setAttribute('aria-label', `Quitar la página ${i + 1}`);
                        remove.textContent = '×';
                        remove.onclick = () => { URL.revokeObjectURL(photo.preview); photos.splice(i, 1); render(); };
                        box.append(img, remove);
                        grid.append(box);
                    });
                    take.textContent = photos.length ? '📷 Agregar otra página' : '📷 Tomar foto';
                    take.disabled = photos.length >= MAX;
                    send.disabled = photos.length === 0;
                }

                take.onclick = () => camera.click();

                camera.onchange = async () => {
                    const file = camera.files?.[0];
                    camera.value = '';
                    if (!file) return;
                    try {
                        const blob = await shrink(file);
                        photos.push({ blob, preview: URL.createObjectURL(blob) });
                        setStatus('Revisá que se lea bien, y enviala.');
                        render();
                    } catch {
                        setStatus('No se pudo usar esa foto. Probá de nuevo.', 'error');
                    }
                };

                send.onclick = async () => {
                    const body = new FormData();
                    photos.forEach((photo, i) => body.append('fotos[]', photo.blob, `pagina-${i + 1}.jpg`));
                    take.disabled = send.disabled = true;
                    status.className = 'status muted';
                    status.innerHTML = '<span style="display:inline-flex;gap:.5rem;align-items:center"><span class="spinner"></span> Conti está leyendo el documento…</span>';

                    let response;
                    try {
                        response = await fetch(URL_UPLOAD, {
                            method: 'POST',
                            body,
                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                            credentials: 'same-origin',
                        });
                    } catch {
                        setStatus('No hay conexión con CONTAPP. Revisá que el teléfono esté en la misma red y probá de nuevo.', 'error');
                        render();
                        return;
                    }

                    const data = await response.json().catch(() => ({}));

                    if (response.ok) {
                        photos.forEach((photo) => URL.revokeObjectURL(photo.preview));
                        document.getElementById('capture').hidden = true;
                        document.getElementById('done').hidden = false;
                        return;
                    }

                    const message = response.status === 422
                        ? Object.values(data.errors ?? {}).flat()[0] ?? data.message
                        : response.status === 419
                            ? 'La página estuvo abierta mucho tiempo. Volvé a escanear el código.'
                            : data.message;
                    setStatus(message || 'No se pudo leer el documento. Probá de nuevo.', 'error');

                    if (response.status === 410) return;
                    render();
                };

                tick();
            })();
        </script>
    @endif
</main>
</body>
</html>
