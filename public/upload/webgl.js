'use strict';

/* ── Vertex shader ────────────────────────────────────────────────────────── */
const VERT_SRC = `
attribute vec2 aPosition;
varying vec2 vUv;

void main() {
    vUv = aPosition * 0.5 + 0.5;
    gl_Position = vec4(aPosition, 0.0, 1.0);
}
`.trim();

/* ── Fragment shader ──────────────────────────────────────────────────────── */
const FRAG_SRC = `
precision highp float;

uniform sampler2D uSrc;
uniform vec3 uRight;
uniform vec3 uUp;
uniform vec3 uForward;
uniform float uStripMinU;
uniform float uStripMaxU;

varying vec2 vUv;

const float PI = 3.14159265358979323846;

void main() {
    float s =  vUv.x * 2.0 - 1.0;
    float t = -vUv.y * 2.0 + 1.0;

    vec3 dir = normalize(uForward + s * uRight + t * uUp);

    float lon = atan(dir.x, dir.z);
    float lat = asin(clamp(dir.y, -1.0, 1.0));

    float u_full = fract((lon / PI + 1.0) * 0.5);
    float v      = 0.5 - lat / PI;

    if (u_full < uStripMinU || u_full >= uStripMaxU) {
        gl_FragColor = vec4(0.0);
        return;
    }

    float localU = (u_full - uStripMinU) / (uStripMaxU - uStripMinU);
    gl_FragColor = texture2D(uSrc, vec2(localU, v));
    gl_FragColor.a = 1.0;
}
`.trim();

/* ── Face basis vectors (derived from tocubemap.py direction formulas) ─────── */
// Each face maps output pixel (s, t) → 3-D direction via: dir = forward + s*right + t*up
// Coordinate system: +Z = front, +X = right, +Y = up (right-handed, matches tocubemap.py)
const FACE_BASES = new Map([
    ['f', { right: new Float32Array([ 1, 0,  0]), up: new Float32Array([0,  1,  0]), forward: new Float32Array([ 0,  0,  1]) }],
    ['b', { right: new Float32Array([-1, 0,  0]), up: new Float32Array([0,  1,  0]), forward: new Float32Array([ 0,  0, -1]) }],
    ['r', { right: new Float32Array([ 0, 0, -1]), up: new Float32Array([0,  1,  0]), forward: new Float32Array([ 1,  0,  0]) }],
    ['l', { right: new Float32Array([ 0, 0,  1]), up: new Float32Array([0,  1,  0]), forward: new Float32Array([-1,  0,  0]) }],
    ['u', { right: new Float32Array([ 1, 0,  0]), up: new Float32Array([0,  0, -1]), forward: new Float32Array([ 0,  1,  0]) }],
    ['d', { right: new Float32Array([ 1, 0,  0]), up: new Float32Array([0,  0,  1]), forward: new Float32Array([ 0, -1,  0]) }],
]);

/* ── CubeMapper class ─────────────────────────────────────────────────────── */

class CubeMapper {

    static FACE_BASES = FACE_BASES;

    /**
     * @param {HTMLImageElement} sourceImage  Fully loaded equirectangular image.
     * @param {number}           faceSize     Output face pixel dimension (square).
     */
    constructor(sourceImage, faceSize) {
        // ── WebGL canvas & context ──────────────────────────────────────────
        const glCanvas = document.createElement('canvas');
        const gl = glCanvas.getContext('webgl')
                || glCanvas.getContext('experimental-webgl');
        if (!gl) throw new Error('[CubeMapper] WebGL not available');

        // ── Texture size limits ─────────────────────────────────────────────
        const maxTex = gl.getParameter(gl.MAX_TEXTURE_SIZE);
        if (faceSize > maxTex) {
            console.warn(`[CubeMapper] faceSize ${faceSize} > MAX_TEXTURE_SIZE ${maxTex} — clamped`);
            faceSize = maxTex;
        }
        glCanvas.width  = faceSize;
        glCanvas.height = faceSize;

        // ── Source image dimensions & strip count ───────────────────────────
        const srcW      = sourceImage.naturalWidth;
        const srcH      = sourceImage.naturalHeight;
        const numStrips = Math.ceil(srcW / maxTex);

        if (numStrips > 1) {
            console.info(
                `[CubeMapper] Large source ${srcW}×${srcH} — ` +
                `using ${numStrips}-strip tiling (MAX_TEXTURE_SIZE=${maxTex})`
            );
        }
        if (srcH > maxTex) {
            console.warn(
                `[CubeMapper] Source height ${srcH} > MAX_TEXTURE_SIZE ${maxTex} — ` +
                `height will be clamped; slight vertical quality loss`
            );
        }

        // ── Compile shaders ─────────────────────────────────────────────────
        const vert = this._compileShader(gl, gl.VERTEX_SHADER,   VERT_SRC);
        const frag = this._compileShader(gl, gl.FRAGMENT_SHADER, FRAG_SRC);

        const program = gl.createProgram();
        gl.attachShader(program, vert);
        gl.attachShader(program, frag);
        gl.linkProgram(program);
        gl.deleteShader(vert);
        gl.deleteShader(frag);

        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
            throw new Error(`[CubeMapper] Program link error: ${gl.getProgramInfoLog(program)}`);
        }

        // ── Cache uniform / attribute locations ─────────────────────────────
        const aPosition  = gl.getAttribLocation(program,  'aPosition');
        const uSrc       = gl.getUniformLocation(program, 'uSrc');
        const uRight     = gl.getUniformLocation(program, 'uRight');
        const uUp        = gl.getUniformLocation(program, 'uUp');
        const uForward   = gl.getUniformLocation(program, 'uForward');
        const uStripMinU = gl.getUniformLocation(program, 'uStripMinU');
        const uStripMaxU = gl.getUniformLocation(program, 'uStripMaxU');

        // ── Fullscreen quad (two triangles, clip-space) ─────────────────────
        const quadVerts = new Float32Array([
            -1, -1,   1, -1,  -1,  1,
             1, -1,   1,  1,  -1,  1,
        ]);
        const vertBuf = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, vertBuf);
        gl.bufferData(gl.ARRAY_BUFFER, quadVerts, gl.STATIC_DRAW);

        // ── Source texture ──────────────────────────────────────────────────
        const srcTex = gl.createTexture();
        gl.bindTexture(gl.TEXTURE_2D, srcTex);
        // WebGL1 requires CLAMP_TO_EDGE on both axes for NPOT textures.
        // Horizontal wrap is handled by fract() in the shader; strip remapping handles strips.
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);

        if (numStrips === 1 && srcH <= maxTex) {
            // Source fits in a single texture — upload once in the constructor.
            gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, sourceImage);
            if (gl.getError() !== gl.NO_ERROR) {
                throw new Error('[CubeMapper] Failed to upload source texture');
            }
        }
        // Multi-strip path: texImage2D is deferred to _uploadStrip() per-render.

        // ── FBO texture + framebuffer ────────────────────────────────────────
        // RGBA required (not RGB) for readPixels compatibility in WebGL1.
        const fboTex = gl.createTexture();
        gl.bindTexture(gl.TEXTURE_2D, fboTex);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, faceSize, faceSize, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);

        const fbo = gl.createFramebuffer();
        gl.bindFramebuffer(gl.FRAMEBUFFER, fbo);
        gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, fboTex, 0);

        const status = gl.checkFramebufferStatus(gl.FRAMEBUFFER);
        if (status !== gl.FRAMEBUFFER_COMPLETE) {
            throw new Error(`[CubeMapper] Framebuffer incomplete: 0x${status.toString(16)}`);
        }
        gl.bindFramebuffer(gl.FRAMEBUFFER, null);

        // ── 2-D readback canvas ───────────────────────────────────────────────
        const readCanvas = document.createElement('canvas');
        readCanvas.width  = faceSize;
        readCanvas.height = faceSize;

        // ── Store instance state ─────────────────────────────────────────────
        this._gl          = gl;
        this._glCanvas    = glCanvas;
        this._readCanvas  = readCanvas;
        this._program     = program;
        this._aPosition   = aPosition;
        this._uSrc        = uSrc;
        this._uRight      = uRight;
        this._uUp         = uUp;
        this._uForward    = uForward;
        this._uStripMinU  = uStripMinU;
        this._uStripMaxU  = uStripMaxU;
        this._vertBuf     = vertBuf;
        this._srcTex      = srcTex;
        this._fboTex      = fboTex;
        this._fbo         = fbo;
        this._faceSize    = faceSize;
        this._maxTex      = maxTex;
        this._sourceImage = sourceImage;
        this._srcW        = srcW;
        this._srcH        = srcH;
        this._numStrips   = numStrips;
    }

    /**
     * Renders one cubemap face and returns it as a JPEG Blob.
     *
     * @param {string} faceLetter  One of: 'b' | 'd' | 'f' | 'l' | 'r' | 'u'
     * @param {number} jpegQuality 0–1 (default 0.92)
     * @returns {Promise<Blob>}
     */
    async renderFaceAsBlob(faceLetter, jpegQuality = 0.92) {
        const basis = CubeMapper.FACE_BASES.get(faceLetter);
        if (!basis) throw new Error(`[CubeMapper] Unknown face letter: '${faceLetter}'`);

        if (this._numStrips === 1) {
            // ── Single-texture path ─────────────────────────────────────────
            const pixels = this._renderPass(basis, 0.0, 1.0);
            return this._pixelsToBlob(pixels, jpegQuality);
        }

        // ── Multi-strip path ────────────────────────────────────────────────
        const faceSize  = this._faceSize;
        const composite = new Uint8Array(faceSize * faceSize * 4);

        for (let i = 0; i < this._numStrips; i++) {
            const startCol = i * this._maxTex;
            const endCol   = Math.min((i + 1) * this._maxTex, this._srcW);
            const minU     = startCol / this._srcW;
            const maxU     = endCol   / this._srcW;

            this._uploadStrip(startCol, endCol);
            const stripBuf = this._renderPass(basis, minU, maxU);

            // Merge: wherever this strip rendered a valid pixel (alpha=255), overwrite composite.
            for (let j = 0; j < stripBuf.length; j += 4) {
                if (stripBuf[j + 3] > 0) {
                    composite[j]     = stripBuf[j];
                    composite[j + 1] = stripBuf[j + 1];
                    composite[j + 2] = stripBuf[j + 2];
                    composite[j + 3] = 255;
                }
            }
        }

        return this._pixelsToBlob(composite, jpegQuality);
    }

    /** Releases all GPU resources. */
    destroy() {
        const gl = this._gl;
        if (!gl) return;

        gl.deleteTexture(this._srcTex);
        gl.deleteTexture(this._fboTex);
        gl.deleteFramebuffer(this._fbo);
        gl.deleteBuffer(this._vertBuf);
        gl.deleteProgram(this._program);
        gl.getExtension('WEBGL_lose_context')?.loseContext();

        this._gl          = null;
        this._glCanvas    = null;
        this._readCanvas  = null;
        this._sourceImage = null;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Crops a horizontal strip from the source image and uploads it to srcTex.
     * Called once per strip per face in the multi-strip path.
     *
     * @param {number} startCol  First source column (inclusive)
     * @param {number} endCol    Last source column (exclusive)
     */
    _uploadStrip(startCol, endCol) {
        const gl    = this._gl;
        const cropW = endCol - startCol;
        const cropH = Math.min(this._srcH, this._maxTex);

        const c   = document.createElement('canvas');
        c.width   = cropW;
        c.height  = cropH;
        c.getContext('2d').drawImage(
            this._sourceImage,
            startCol, 0, cropW, this._srcH,   // source region
            0,        0, cropW, cropH,          // destination (scales height if clamped)
        );

        gl.bindTexture(gl.TEXTURE_2D, this._srcTex);
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, c);
    }

    /**
     * Sets uniforms, draws the quad, reads back pixels.
     *
     * @param {{right, up, forward}} basis  Face basis vectors
     * @param {number} minU  Strip start in full-image UV [0,1)
     * @param {number} maxU  Strip end   in full-image UV [0,1)
     * @returns {Uint8Array}  Raw RGBA pixels, faceSize × faceSize, top-to-bottom
     */
    _renderPass(basis, minU, maxU) {
        const gl       = this._gl;
        const faceSize = this._faceSize;

        gl.bindFramebuffer(gl.FRAMEBUFFER, this._fbo);
        gl.viewport(0, 0, faceSize, faceSize);

        gl.useProgram(this._program);
        gl.uniform1i(this._uSrc,      0);
        gl.uniform3fv(this._uRight,   basis.right);
        gl.uniform3fv(this._uUp,      basis.up);
        gl.uniform3fv(this._uForward, basis.forward);
        gl.uniform1f(this._uStripMinU, minU);
        gl.uniform1f(this._uStripMaxU, maxU);

        gl.activeTexture(gl.TEXTURE0);
        gl.bindTexture(gl.TEXTURE_2D, this._srcTex);

        gl.bindBuffer(gl.ARRAY_BUFFER, this._vertBuf);
        gl.enableVertexAttribArray(this._aPosition);
        gl.vertexAttribPointer(this._aPosition, 2, gl.FLOAT, false, 0, 0);

        gl.drawArrays(gl.TRIANGLES, 0, 6);

        // The y-flip in the shader (t = -vUv.y*2+1) makes GL row 0 the face's top row,
        // so readPixels output is already in top-to-bottom order for canvas ImageData.
        const pixels = new Uint8Array(faceSize * faceSize * 4);
        gl.readPixels(0, 0, faceSize, faceSize, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
        gl.bindFramebuffer(gl.FRAMEBUFFER, null);

        return pixels;
    }

    /**
     * Encodes a raw RGBA pixel buffer as a JPEG Blob via the 2-D readback canvas.
     *
     * @param {Uint8Array} pixels
     * @param {number}     quality  0–1 JPEG quality
     * @returns {Promise<Blob>}
     */
    _pixelsToBlob(pixels, quality) {
        const faceSize  = this._faceSize;
        const ctx       = this._readCanvas.getContext('2d');
        const imageData = ctx.createImageData(faceSize, faceSize);
        imageData.data.set(pixels);
        ctx.putImageData(imageData, 0, 0);

        return new Promise((resolve, reject) => {
            this._readCanvas.toBlob(
                (blob) => blob
                    ? resolve(blob)
                    : reject(new Error('[CubeMapper] toBlob returned null')),
                'image/jpeg',
                quality,
            );
        });
    }

    _compileShader(gl, type, src) {
        const shader = gl.createShader(type);
        gl.shaderSource(shader, src);
        gl.compileShader(shader);
        if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
            const log = gl.getShaderInfoLog(shader);
            gl.deleteShader(shader);
            throw new Error(`[CubeMapper] Shader compile error:\n${log}`);
        }
        return shader;
    }
}

/* ── Window globals ───────────────────────────────────────────────────────── */

window.CubeMapper = CubeMapper;

/**
 * DevTools helper: render one cubemap face from the currently accepted file
 * and open it as a JPEG in a new browser tab.
 *
 * Requires window.panoFile (set automatically when a valid JPG is dropped).
 *
 * @param {string} faceLetter  'b' | 'd' | 'f' | 'l' | 'r' | 'u'
 * @param {number} [faceSize]  Output pixel dimension. Defaults to round(width / π).
 *
 * @example
 * await previewFace('f');
 * await previewFace('u', 1024);
 */
window.previewFace = async function previewFace(faceLetter, faceSize) {
    if (!window.panoFile) {
        console.error('[previewFace] window.panoFile is null — drop a JPG panorama on the page first');
        return;
    }

    const objUrl = URL.createObjectURL(window.panoFile);
    const img    = new Image();

    await new Promise((resolve, reject) => {
        img.onload  = resolve;
        img.onerror = () => reject(new Error('[previewFace] Failed to decode image'));
        img.src = objUrl;
    });
    URL.revokeObjectURL(objUrl);

    if (faceSize === undefined) {
        faceSize = Math.round(img.naturalWidth / Math.PI);
    }

    const mapper = new CubeMapper(img, faceSize);
    try {
        const blob    = await mapper.renderFaceAsBlob(faceLetter);
        const blobUrl = URL.createObjectURL(blob);
        window.open(blobUrl, '_blank');
        // blobUrl is intentionally not revoked — the new tab needs it while loading.
    } finally {
        mapper.destroy();
    }
};
