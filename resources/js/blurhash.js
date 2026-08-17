const B83 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';

function b83decode(str, start, len) {
    let value = 0;
    for (let i = 0; i < len; i++) {
        value = value * 83 + B83.indexOf(str[start + i]);
    }
    return value;
}

function srgbToLinear(c) {
    const v = c / 255;
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
}

function linearToSrgb(c) {
    const v = c <= 0.0031308 ? c * 12.92 : 1.055 * Math.pow(c, 1 / 2.4) - 0.055;
    return Math.max(0, Math.min(1, v));
}

// Decodes a BlurHash into a filled RGBA canvas. Returns null if invalid.
export function blurhashToCanvas(hash, width, height, punch = 1) {
    if (!hash || hash.length < 6) return null;
    try {
        const sizeFlag = b83decode(hash, 0, 1);
        const numY = Math.floor(sizeFlag / 9) + 1;
        const numX = (sizeFlag % 9) + 1;

        const colors = [];
        for (let i = 0; i < numX * numY; i++) {
            if (i === 0) {
                const value = b83decode(hash, 2, 4);
                colors.push({ r: (value >> 16) & 255, g: (value >> 8) & 255, b: value & 255 });
            } else {
                const value = b83decode(hash, 4 + i * 2, 2);
                colors.push({
                    r: Math.round(((value >> 10) & 31) * 255 / 31) - 128,
                    g: Math.round(((value >> 5) & 31) * 255 / 31) - 128,
                    b: Math.round((value & 31) * 255 / 31) - 128,
                });
            }
        }

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        const imageData = ctx.createImageData(width, height);
        const data = imageData.data;

        for (let y = 0; y < height; y++) {
            for (let x = 0; x < width; x++) {
                let r = 0, g = 0, b = 0;
                for (let j = 0; j < numY; j++) {
                    for (let i = 0; i < numX; i++) {
                        const basis = Math.cos((Math.PI * x * i) / width) * Math.cos((Math.PI * y * j) / height);
                        const color = colors[i + j * numX];
                        const weight = (i === 0 && j === 0) ? 1 : punch;
                        r += color.r * basis * weight;
                        g += color.g * basis * weight;
                        b += color.b * basis * weight;
                    }
                }

                const idx = (y * width + x) * 4;
                data[idx] = Math.max(0, Math.min(255, Math.round(linearToSrgb(srgbToLinear(r)) * 255)));
                data[idx + 1] = Math.max(0, Math.min(255, Math.round(linearToSrgb(srgbToLinear(g)) * 255)));
                data[idx + 2] = Math.max(0, Math.min(255, Math.round(linearToSrgb(srgbToLinear(b)) * 255)));
                data[idx + 3] = 255;
            }
        }

        ctx.putImageData(imageData, 0, 0);
        return canvas;
    } catch (e) {
        return null;
    }
}