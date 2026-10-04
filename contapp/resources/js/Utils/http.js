import { messageForStatus } from './notify';

/**
 * Un pedido al servidor que contesta JSON, fuera de Inertia: el panel de
 * «Comentarios y noticias» de la barra superior, que está en todas las
 * pantallas y no puede recargar la de atrás en cada voto.
 *
 *     const result = await requestJson(route('feedback.vote', id), { method: 'PUT', body: { value: 1 } });
 *     if (result.ok) … result.data
 *     else if (result.status === 422) … result.errors.body
 *     else notifyError(result.message);
 *
 * Nunca lanza: devuelve { ok, status, data, errors, message }. errors trae el
 * primer mensaje de cada campo («images.0» queda como «images»); message, qué
 * decirle a la persona si no fue un error de validación.
 *
 * El token CSRF sale de la cookie XSRF-TOKEN, el mismo que usa Inertia. El de
 * la etiqueta <meta> se escribe con la primera carga y puede quedar viejo:
 * cerrar sesión y volver a entrar sin recargar la página lo cambia.
 */
export async function requestJson(url, { method = 'GET', body = null } = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const token = xsrfToken();
    if (token) headers['X-XSRF-TOKEN'] = token;

    let payload = body;
    if (body !== null && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(url, { method, headers, body: payload, credentials: 'same-origin' });
    } catch {
        return failure(0, null, 'No hay conexión con el servidor y la acción no se completó. Revisá tu conexión y probá de nuevo.');
    }

    const data = response.status === 204 ? null : await response.json().catch(() => null);

    if (response.ok) {
        return { ok: true, status: response.status, data, errors: {}, message: null };
    }

    if (response.status === 422) {
        return { ...failure(422, data, data?.message ?? 'Revisá los datos.'), errors: firstErrors(data?.errors) };
    }

    // Un 403 o un 404 con un motivo propio («Solo podés borrar lo que
    // escribiste vos.») lo dice; los textos genéricos de Laravel, en inglés, no.
    const own = data?.message && ![
        'This action is unauthorized.', 'Not Found', 'Unauthenticated.', 'Server Error', 'Too Many Attempts.',
    ].includes(data.message) && [403, 404].includes(response.status);

    return failure(response.status, data, own ? data.message : messageForStatus(response.status));
}

function failure(status, data, message) {
    return { ok: false, status, data, errors: {}, message };
}

function firstErrors(errors) {
    const result = {};

    for (const [field, messages] of Object.entries(errors ?? {})) {
        const key = field.split('.')[0];
        if (!result[key]) result[key] = Array.isArray(messages) ? messages[0] : messages;
    }

    return result;
}

function xsrfToken() {
    const match = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : null;
}
