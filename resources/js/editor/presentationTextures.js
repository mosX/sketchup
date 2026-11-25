import { CanvasTexture, RepeatWrapping, SRGBColorSpace } from 'three';

export const woodGrainLength = 7.5;
export const woodGrainWidth = 2.8;
export const woodSideOffset = 0.31;

export const orientPineTexture = (source) => {
    const canvas = document.createElement('canvas');
    canvas.width = source.image.height;
    canvas.height = source.image.width;
    const context = canvas.getContext('2d');
    context.translate(canvas.width, 0);
    context.rotate(Math.PI / 2);
    context.drawImage(source.image, 0, 0);
    source.dispose();
    return new CanvasTexture(canvas);
};

const makeTexture = (width, height, baseImage, shade) => {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    if (baseImage) context.drawImage(baseImage, 0, 0, width, height);
    const image = baseImage ? context.getImageData(0, 0, width, height) : context.createImageData(width, height);
    for (let row = 0; row < height; row++) {
        for (let column = 0; column < width; column++) {
            const index = (row * width + column) * 4;
            shade(image.data, index, column / width, 1 - row / height);
            image.data[index + 3] = 255;
        }
    }
    context.putImageData(image, 0, 0);
    const texture = new CanvasTexture(canvas);
    texture.colorSpace = SRGBColorSpace;
    texture.wrapS = texture.wrapT = RepeatWrapping;
    return texture;
};

export const createSpruceTexture = () => makeTexture(512, 256, null, (pixels, index, u, v) => {
    const along = u * Math.PI * 2;
    const across = v * Math.PI * 2;
    const drift = 0.54 * Math.sin(along + 3 * across) + 0.3 * Math.sin(2 * along - 5 * across);
    const grain = 7 * Math.sin(19 * across + drift) + 3 * Math.sin(42 * across + 2 * drift)
        + 4 * Math.sin(6 * across + 0.7 * Math.sin(along));
    pixels[index] = 225 + grain;
    pixels[index + 1] = 204 + grain;
    pixels[index + 2] = 172 + grain;
});

export const createEndGrainTexture = (source) => makeTexture(512, 512, source.image, (pixels, index, u, v) => {
    const radius = Math.hypot((u - 0.5) * woodGrainWidth + 1.75, ((v - 0.5) * woodGrainWidth + 0.85) * 0.76);
    const rings = 0.5 + 0.5 * Math.sin(68 * radius + 1.2 * Math.sin(7 * radius));
    const shade = 0.78 + 0.18 * rings;
    pixels[index] *= shade;
    pixels[index + 1] *= shade;
    pixels[index + 2] *= shade;
});
