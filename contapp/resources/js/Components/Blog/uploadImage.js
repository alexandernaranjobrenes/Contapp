import { requestJson } from '../../Utils/http';

/**
 * Sube una imagen del blog (la del texto o la portada) y devuelve
 * { ok, image: { id, url, width, height } } o { ok: false, message }.
 * Queda en el bucket como blog/imag_{id}.{ext} y sin artículo hasta que un
 * guardado la use (BlogImageService).
 */
export async function uploadBlogImage(file, rules) {
    if (!rules.accept.split(',').includes(file.type)) {
        return { ok: false, message: `La imagen tiene que estar en ${rules.formats}.` };
    }

    if (file.size > rules.max_megabytes * 1024 * 1024) {
        return { ok: false, message: `La imagen puede pesar hasta ${rules.max_megabytes} MB.` };
    }

    const body = new FormData();
    body.append('image', file);

    const result = await requestJson(route('backoffice.blog.images.store'), { method: 'POST', body });

    if (!result.ok) {
        return { ok: false, message: result.errors.image ?? result.message };
    }

    return { ok: true, image: result.data };
}
