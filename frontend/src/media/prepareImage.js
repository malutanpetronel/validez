// Decizie M.4 (15.10.2026): JPEG/PNG/WebP cu latura mare peste 2560 px se micșorează în browser la 2560 px
// JPEG înainte de upload (mai puține date mobile, sub limita de 10 MB). HEIC și orice nu poate fi decodat
// pleacă neschimbat; serverul re-encodează oricum și elimină metadatele (ADR-0011 p.5-6).
export const MAX_SIDE = 2560;
const DECODABLE = ['image/jpeg', 'image/png', 'image/webp'];

export async function prepareImage(file) {
    if (!DECODABLE.includes(file.type) || typeof createImageBitmap !== 'function') return file;
    let bitmap;
    try {
        // Orientarea din EXIF se aplică la decodare: imaginea micșorată iese deja rotită corect.
        bitmap = await createImageBitmap(file, {imageOrientation: 'from-image'});
    } catch {
        return file;
    }
    try {
        const longest = Math.max(bitmap.width, bitmap.height);
        if (longest <= MAX_SIDE) return file;
        const scale = MAX_SIDE / longest;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const ctx = canvas.getContext('2d');
        // PNG transparent -> JPEG: fond alb, nu negru.
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
        if (!blob) return file;
        return new File([blob], `${(file.name || 'fotografie').replace(/\.[^.]+$/, '')}.jpg`, {type: 'image/jpeg'});
    } finally {
        bitmap.close?.();
    }
}

/** data:URL (cordova-plugin-camera, DATA_URL) -> File. */
export function dataUrlToFile(dataUrl, name = 'fotografie.jpg') {
    const [head, data] = dataUrl.includes(',') ? dataUrl.split(',') : ['data:image/jpeg;base64', dataUrl];
    const type = /data:([^;]+)/.exec(head)?.[1] || 'image/jpeg';
    const bytes = atob(data);
    const buffer = new Uint8Array(bytes.length);
    for (let i = 0; i < bytes.length; i += 1) buffer[i] = bytes.charCodeAt(i);
    return new File([buffer], name, {type});
}

/**
 * Android (Cordova): cameră sau galerie prin cordova-plugin-camera. Pluginul re-encodează la JPEG, rotit,
 * cu latura mare de cel mult 2560 px - deci și pozele HEIC din galerie ajung JPEG.
 */
export const hasCordovaCamera = () => Boolean(window.cordova && navigator.camera && window.Camera);

export function pickWithCordovaCamera(fromCamera) {
    const {Camera} = window;
    return new Promise((resolve, reject) => {
        navigator.camera.getPicture(
            (data) => resolve(dataUrlToFile(data, `fotografie-${Date.now()}.jpg`)),
            (message) => (/cancel|selected|no image/i.test(String(message)) ? resolve(null) : reject(new Error(`Fotografia nu a putut fi preluată: ${message}`))),
            {
                sourceType: fromCamera ? Camera.PictureSourceType.CAMERA : Camera.PictureSourceType.PHOTOLIBRARY,
                destinationType: Camera.DestinationType.DATA_URL,
                encodingType: Camera.EncodingType.JPEG,
                mediaType: Camera.MediaType.PICTURE,
                quality: 85,
                targetWidth: MAX_SIDE,
                targetHeight: MAX_SIDE,
                correctOrientation: true,
                saveToPhotoAlbum: false,
            },
        );
    });
}
