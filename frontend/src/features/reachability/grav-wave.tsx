"use client";

import { useEffect, useRef } from "react";

const VERT = `
attribute vec2 aPos;
void main() { gl_Position = vec4(aPos, 0.0, 1.0); }
`;

const FRAG = `
precision highp float;
uniform float uTime;
uniform float uDPR;
uniform vec2  uResolution;

float hash(vec2 p) {
    p = fract(p * vec2(234.34, 435.345));
    p += dot(p, p + 34.23);
    return fract(p.x * p.y);
}

float stars(vec2 uv) {
    float v = 0.0;
    for (int si = 0; si < 2; si++) {
        float s     = 55.0 + float(si) * 85.0;
        vec2  id    = floor(uv * s);
        vec2  f     = fract(uv * s) - 0.5;
        float h     = hash(id);
        float ok    = step(0.962, h);
        float b     = (h - 0.962) / 0.038 * ok;
        float phase = hash(id + vec2(7.13, 31.41));
        b *= 0.55 + 0.45 * sin(uTime * 2.2 + phase * 6.2832);
        v += b * smoothstep(0.24, 0.0, length(f));
    }
    return clamp(v, 0.0, 1.0);
}

vec2 gravDisp(vec2 uv, float asp) {
    vec2  c    = vec2(0.5);
    vec2  d    = uv - c;
    d.x       *= asp;
    float dist = length(d);
    vec2  dir  = d / max(dist, 0.001);
    float disp = 0.0;
    for (int i = 0; i < 2; i++) {
        float ri  = mod(uTime * 0.09 - float(i) * 0.55, 1.2);
        float wd  = dist - ri;
        float sig = 0.006;
        float g   = exp(-wd * wd / (2.0 * sig * sig));
        disp += g * 0.028;
    }
    return dir * disp;
}

float gridLine(vec2 dispUV) {
    vec2  cssRes = uResolution / uDPR;
    vec2  gPx    = fract(dispUV * cssRes / 52.0) * 52.0;
    float lx     = smoothstep(1.0, 0.0, min(gPx.x, 52.0 - gPx.x));
    float ly     = smoothstep(1.0, 0.0, min(gPx.y, 52.0 - gPx.y));
    return max(lx, ly);
}

void main() {
    vec2  uv  = gl_FragCoord.xy / uResolution;
    float asp = uResolution.x / uResolution.y;
    vec2  disp = gravDisp(uv, asp);

    float sr = stars(uv + disp * 1.3);
    float sg = stars(uv + disp * 1.0);
    float sb = stars(uv + disp * 0.68);
    vec3  starCol = vec3(sr, sg, sb * 1.1) * 1.8;
    float starA   = min(max(sr, max(sg, sb)) * 1.8, 1.0);

    float grid    = gridLine(uv + disp);
    vec3  gridCol = vec3(0.659, 0.333, 0.969) * grid;

    vec3  col   = starCol + gridCol;
    float alpha = min(starA + grid, 0.75);
    gl_FragColor = vec4(col, alpha);
}
`;

export function GravWave() {
    const ref = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        const canvas = ref.current;
        if (!canvas) return;
        const gl = canvas.getContext("webgl", { alpha: true, premultipliedAlpha: false });
        if (!gl) return;

        function mkShader(src: string, type: number) {
            const s = gl!.createShader(type)!;
            gl!.shaderSource(s, src);
            gl!.compileShader(s);
            return s;
        }

        const prog = gl.createProgram()!;
        gl.attachShader(prog, mkShader(VERT, gl.VERTEX_SHADER));
        gl.attachShader(prog, mkShader(FRAG, gl.FRAGMENT_SHADER));
        gl.linkProgram(prog);
        gl.useProgram(prog);

        gl.enable(gl.BLEND);
        gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
        gl.clearColor(0, 0, 0, 0);

        const buf = gl.createBuffer()!;
        gl.bindBuffer(gl.ARRAY_BUFFER, buf);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);

        const aPos = gl.getAttribLocation(prog, "aPos");
        gl.enableVertexAttribArray(aPos);
        gl.vertexAttribPointer(aPos, 2, gl.FLOAT, false, 0, 0);

        const uTime = gl.getUniformLocation(prog, "uTime");
        const uRes  = gl.getUniformLocation(prog, "uResolution");
        const uDPR  = gl.getUniformLocation(prog, "uDPR");

        function resize() {
            if (!canvas || !gl) return;
            const dpr = Math.min(devicePixelRatio, 2);
            canvas.width  = Math.round(canvas.clientWidth  * dpr);
            canvas.height = Math.round(canvas.clientHeight * dpr);
            gl.viewport(0, 0, canvas.width, canvas.height);
        }

        resize();
        const ro = new ResizeObserver(resize);
        ro.observe(canvas);

        const t0 = performance.now();
        let raf: number;

        function draw() {
            const t = (performance.now() - t0) / 1000;
            gl!.clear(gl!.COLOR_BUFFER_BIT);
            gl!.uniform1f(uTime, t);
            gl!.uniform1f(uDPR, Math.min(devicePixelRatio, 2));
            gl!.uniform2f(uRes, canvas!.width, canvas!.height);
            gl!.drawArrays(gl!.TRIANGLE_STRIP, 0, 4);
            raf = requestAnimationFrame(draw);
        }

        raf = requestAnimationFrame(draw);

        return () => {
            cancelAnimationFrame(raf);
            ro.disconnect();
        };
    }, []);

    return (
        <canvas
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 h-full w-full"
            ref={ref}
        />
    );
}
