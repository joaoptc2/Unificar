/**
 * PLANEJAMENTO — editor visual de diagramas / fluxogramas / mapas de processo.
 * Vanilla JS + SVG, sem dependências. Geometria idêntica ao renderizador PHP
 * (modules/planejamento/lib/diagram.php).
 *
 * Uso:
 *   var ed = PlanDiagramEditor.init(document.getElementById('editor'), {
 *       data: {...},            // JSON do diagrama (v1)
 *       saveUrl: '...',         // POST JSON {_csrf_token,title,data,note}
 *       csrf: 'token',
 *       getTitle: fn, setTitle: fn,
 *       version: 1, fileName: 'diagrama',
 *       onSaved: fn(resp), onDirty: fn(bool)
 *   });
 *   PlanDiagramEditor.renderStatic(svgElement, data, {fit:true});
 *   PlanDiagramEditor.downloadPng(svgElement, 'nome');
 */
(function (global) {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var FONT = "'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    var SIDES = ['top', 'right', 'bottom', 'left'];

    var TYPES = {
        start:      { label: 'Início',          w: 160, h: 56,  fill: '#e8f5e9', stroke: '#2e7d32' },
        end:        { label: 'Fim',             w: 160, h: 56,  fill: '#ffebee', stroke: '#c62828' },
        process:    { label: 'Processo',        w: 200, h: 70,  fill: '#ffffff', stroke: '#1565c0' },
        decision:   { label: 'Decisão',         w: 180, h: 110, fill: '#fff8e1', stroke: '#ef6c00' },
        io:         { label: 'Entrada / saída', w: 200, h: 70,  fill: '#ede7f6', stroke: '#5e35b1' },
        document:   { label: 'Documento',       w: 200, h: 80,  fill: '#ffffff', stroke: '#546e7a' },
        database:   { label: 'Banco de dados',  w: 160, h: 90,  fill: '#e0f2f1', stroke: '#00695c' },
        subprocess: { label: 'Subprocesso',     w: 220, h: 70,  fill: '#e3f2fd', stroke: '#1565c0' },
        note:       { label: 'Nota',            w: 200, h: 90,  fill: '#fff9c4', stroke: '#f9a825' },
        text:       { label: 'Texto',           w: 200, h: 40,  fill: 'none',    stroke: 'none' },
        lane:       { label: 'Raia',            w: 800, h: 220, fill: '#f8f9fa', stroke: '#adb5bd' },
        circle:     { label: 'Conector',        w: 60,  h: 60,  fill: '#ffffff', stroke: '#1565c0' },
        actor:      { label: 'Ator',            w: 100, h: 120, fill: '#ffffff', stroke: '#37474f' }
    };
    var PALETTE_ORDER = ['start', 'end', 'process', 'decision', 'io', 'document', 'database', 'subprocess', 'note', 'text', 'lane', 'circle', 'actor'];

    // ------------------------------------------------------------------ util
    function svgEl(tag, attrs, parent) {
        var el = document.createElementNS(NS, tag);
        if (attrs) { for (var k in attrs) { if (attrs[k] !== null && attrs[k] !== undefined) { el.setAttribute(k, attrs[k]); } } }
        if (parent) { parent.appendChild(el); }
        return el;
    }
    function htmlEl(tag, attrs, parent, text) {
        var el = document.createElement(tag);
        if (attrs) { for (var k in attrs) { if (k === 'class') { el.className = attrs[k]; } else if (k === 'html') { el.innerHTML = attrs[k]; } else { el.setAttribute(k, attrs[k]); } } }
        if (text !== undefined) { el.textContent = text; }
        if (parent) { parent.appendChild(el); }
        return el;
    }
    function num(v, d) { var f = parseFloat(v); return isFinite(f) ? f : d; }
    function fmt(v) { return Math.round(v * 100) / 100; }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function isColor(v) { return typeof v === 'string' && (/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(v) || v === 'none'); }
    function safeLink(v) {
        if (typeof v !== 'string') { return null; }
        v = v.trim();
        if (!v || v.length > 500) { return null; }
        if (/^(https?:\/\/|mailto:)/i.test(v) || /^(\/|\.\/|index\.php|\?)/.test(v)) { return v; }
        return null;
    }
    function escapeXml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    // ------------------------------------------------------------- geometria
    var Geo = {
        charW: function (ch) {
            if (ch === ' ') { return 0.28; }
            if ("ijl|!.,:;'".indexOf(ch) >= 0) { return 0.28; }
            if ('ftrI()[]-'.indexOf(ch) >= 0) { return 0.36; }
            if ('mwMW@'.indexOf(ch) >= 0) { return 0.88; }
            if (ch.charCodeAt(0) > 127) { return ch.toUpperCase() === ch && ch.toLowerCase() !== ch ? 0.68 : 0.56; }
            if (ch >= 'A' && ch <= 'Z') { return 0.68; }
            return 0.56;
        },
        textWidth: function (s, fs) {
            var w = 0;
            for (var i = 0; i < s.length; i++) { w += Geo.charW(s[i]); }
            return w * fs;
        },
        wrap: function (text, maxW, fs) {
            var lines = [];
            maxW = Math.max(maxW, fs);
            String(text || '').split('\n').forEach(function (para) {
                para = para.replace(/\s+$/, '');
                if (para === '') { lines.push(''); return; }
                var cur = '';
                para.split(/ +/).forEach(function (word) {
                    if (word === '') { return; }
                    var t = cur === '' ? word : cur + ' ' + word;
                    if (Geo.textWidth(t, fs) <= maxW) { cur = t; return; }
                    if (cur !== '') { lines.push(cur); cur = ''; }
                    if (Geo.textWidth(word, fs) > maxW) {
                        var chunk = '';
                        for (var i = 0; i < word.length; i++) {
                            if (chunk !== '' && Geo.textWidth(chunk + word[i], fs) > maxW) { lines.push(chunk); chunk = ''; }
                            chunk += word[i];
                        }
                        cur = chunk;
                    } else { cur = word; }
                });
                lines.push(cur);
            });
            return lines;
        },
        style: function (n) {
            var d = TYPES[n.type] || TYPES.process;
            return {
                fill: n.fill || d.fill, stroke: n.stroke || d.stroke, color: n.color || '#212529',
                fontSize: num(n.fontSize, 14), bold: !!n.bold || n.type === 'lane',
                align: n.align || (n.type === 'note' ? 'left' : 'center'), strokeWidth: num(n.strokeWidth, 2)
            };
        },
        port: function (n, side) {
            var cx = n.x + n.w / 2, cy = n.y + n.h / 2;
            switch (side) {
                case 'top': return [cx, n.y];
                case 'right': return [n.x + n.w, cy];
                case 'bottom': return [cx, n.y + n.h];
                default: return [n.x, cy];
            }
        },
        autoSides: function (a, b) {
            var dx = (b.x + b.w / 2) - (a.x + a.w / 2), dy = (b.y + b.h / 2) - (a.y + a.h / 2);
            if (Math.abs(dx) > Math.abs(dy)) { return dx >= 0 ? ['right', 'left'] : ['left', 'right']; }
            return dy >= 0 ? ['bottom', 'top'] : ['top', 'bottom'];
        },
        stub: function (p, side, len) {
            switch (side) {
                case 'top': return [p[0], p[1] - len];
                case 'right': return [p[0] + len, p[1]];
                case 'bottom': return [p[0], p[1] + len];
                default: return [p[0] - len, p[1]];
            }
        },
        simplify: function (pts) {
            var out = [];
            pts.forEach(function (p) {
                var n = out.length;
                if (n > 0 && Math.abs(out[n - 1][0] - p[0]) < 0.01 && Math.abs(out[n - 1][1] - p[1]) < 0.01) { return; }
                if (n > 1) {
                    var a = out[n - 2], b = out[n - 1];
                    var cross = (b[0] - a[0]) * (p[1] - a[1]) - (b[1] - a[1]) * (p[0] - a[0]);
                    var dot = (b[0] - a[0]) * (p[0] - b[0]) + (b[1] - a[1]) * (p[1] - b[1]);
                    if (Math.abs(cross) < 0.01 && dot >= 0) { out[n - 1] = p; return; }
                }
                out.push(p);
            });
            return out;
        },
        edgePoints: function (e, from, to) {
            var auto = Geo.autoSides(from, to);
            var s1 = e.fromSide || auto[0], s2 = e.toSide || auto[1];
            var p1 = Geo.port(from, s1), p2 = Geo.port(to, s2);
            if (e.points && e.points.length) {
                var pts = [p1];
                e.points.forEach(function (p) { pts.push([num(p.x, 0), num(p.y, 0)]); });
                pts.push(p2);
                return Geo.simplify(pts);
            }
            if (e.route === 'straight') { return [p1, p2]; }
            var stub = 24, a = Geo.stub(p1, s1, stub), b = Geo.stub(p2, s2, stub);
            var v1 = s1 === 'top' || s1 === 'bottom', v2 = s2 === 'top' || s2 === 'bottom';
            var out = [p1, a];
            if (v1 && v2) { var my = (a[1] + b[1]) / 2; out.push([a[0], my]); out.push([b[0], my]); }
            else if (!v1 && !v2) { var mx = (a[0] + b[0]) / 2; out.push([mx, a[1]]); out.push([mx, b[1]]); }
            else if (v1) { out.push([a[0], b[1]]); }
            else { out.push([b[0], a[1]]); }
            out.push(b); out.push(p2);
            return Geo.simplify(out);
        },
        midpoint: function (pts) {
            var total = 0, i;
            for (i = 1; i < pts.length; i++) { total += Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]); }
            if (total <= 0 || pts.length < 2) { return pts[0] || [0, 0]; }
            var half = total / 2;
            for (i = 1; i < pts.length; i++) {
                var seg = Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]);
                if (half <= seg) { var t = seg > 0 ? half / seg : 0; return [pts[i - 1][0] + (pts[i][0] - pts[i - 1][0]) * t, pts[i - 1][1] + (pts[i][1] - pts[i - 1][1]) * t]; }
                half -= seg;
            }
            return pts[pts.length - 1];
        },
        textBox: function (n) {
            var x = n.x, y = n.y, w = n.w, h = n.h, al = Geo.style(n).align, o, ry;
            switch (n.type) {
                case 'decision': return [x + w * 0.225, y + h * 0.225, w * 0.55, h * 0.55, al, 'middle'];
                case 'io': o = Math.min(w * 0.2, 30); return [x + o + 4, y + 4, w - 2 * o - 8, h - 8, al, 'middle'];
                case 'database': ry = Math.min(14, h * 0.16); return [x + 8, y + 2 * ry, w - 16, h - 3 * ry, al, 'middle'];
                case 'subprocess': return [x + 14, y + 4, w - 28, h - 8, al, 'middle'];
                case 'note': return [x + 10, y + 10, w - 28, h - 20, al, 'top'];
                case 'actor': return [x - 20, y + h * 0.76, w + 40, h * 0.24, 'center', 'top'];
                case 'circle': return [x + w * 0.15, y + h * 0.15, w * 0.7, h * 0.7, al, 'middle'];
                case 'text': return [x + 4, y + 4, w - 8, h - 8, al, 'middle'];
                default: return [x + 8, y + 4, w - 16, h - 8, al, 'middle'];
            }
        },
        bbox: function (doc) {
            var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            doc.nodes.forEach(function (n) { minX = Math.min(minX, n.x); minY = Math.min(minY, n.y); maxX = Math.max(maxX, n.x + n.w); maxY = Math.max(maxY, n.y + n.h); });
            doc.edges.forEach(function (e) { (e.points || []).forEach(function (p) { minX = Math.min(minX, p.x); minY = Math.min(minY, p.y); maxX = Math.max(maxX, p.x); maxY = Math.max(maxY, p.y); }); });
            if (!isFinite(minX)) { return null; }
            return { x: minX, y: minY, w: maxX - minX, h: maxY - minY };
        },
        nearestSide: function (n, px, py) {
            var cx = n.x + n.w / 2, cy = n.y + n.h / 2;
            var dx = (px - cx) / Math.max(n.w, 1), dy = (py - cy) / Math.max(n.h, 1);
            if (Math.abs(dx) > Math.abs(dy)) { return dx > 0 ? 'right' : 'left'; }
            return dy > 0 ? 'bottom' : 'top';
        }
    };

    // -------------------------------------------------------- normalização
    function normalize(data) {
        data = data && typeof data === 'object' ? data : {};
        var c = data.canvas && typeof data.canvas === 'object' ? data.canvas : {};
        var doc = {
            v: 1,
            canvas: { w: Math.round(Math.min(20000, Math.max(200, num(c.w, 1600)))), h: Math.round(Math.min(20000, Math.max(200, num(c.h, 1000)))), grid: Math.round(Math.min(200, Math.max(0, num(c.grid, 20)))), bg: isColor(c.bg) ? c.bg : '#ffffff' },
            nodes: [], edges: []
        };
        var ids = {}, seq = 0;
        (Array.isArray(data.nodes) ? data.nodes : []).forEach(function (n) {
            if (!n || typeof n !== 'object') { return; }
            var type = TYPES[n.type] ? n.type : 'process', d = TYPES[type];
            var id = typeof n.id === 'string' && /^[A-Za-z0-9_\-]{1,40}$/.test(n.id) ? n.id : '';
            while (id === '' || ids[id]) { id = 'n' + (++seq); }
            ids[id] = true;
            var node = { id: id, type: type, x: fmt(num(n.x, 0)), y: fmt(num(n.y, 0)), w: fmt(Math.max(10, num(n.w, d.w))), h: fmt(Math.max(10, num(n.h, d.h))), text: typeof n.text === 'string' ? n.text.slice(0, 2000) : '' };
            ['fill', 'stroke', 'color'].forEach(function (k) { if (isColor(n[k])) { node[k] = n[k].toLowerCase(); } });
            if (n.fontSize !== undefined && isFinite(parseFloat(n.fontSize))) { node.fontSize = Math.round(Math.min(120, Math.max(6, parseFloat(n.fontSize)))); }
            if (n.bold) { node.bold = true; }
            if (n.align === 'left' || n.align === 'center' || n.align === 'right') { node.align = n.align; }
            if (n.strokeWidth !== undefined && isFinite(parseFloat(n.strokeWidth))) { node.strokeWidth = Math.min(20, Math.max(0, parseFloat(n.strokeWidth))); }
            var link = safeLink(n.link);
            if (link) { node.link = link; }
            doc.nodes.push(node);
        });
        var eids = {}, eseq = 0;
        (Array.isArray(data.edges) ? data.edges : []).forEach(function (e) {
            if (!e || typeof e !== 'object' || !ids[e.from] || !ids[e.to]) { return; }
            var id = typeof e.id === 'string' && /^[A-Za-z0-9_\-]{1,40}$/.test(e.id) ? e.id : '';
            while (id === '' || eids[id]) { id = 'e' + (++eseq); }
            eids[id] = true;
            var edge = { id: id, from: e.from, to: e.to };
            if (typeof e.label === 'string' && e.label.trim() !== '') { edge.label = e.label.slice(0, 200); }
            if (SIDES.indexOf(e.fromSide) >= 0) { edge.fromSide = e.fromSide; }
            if (SIDES.indexOf(e.toSide) >= 0) { edge.toSide = e.toSide; }
            if (e.style === 'dashed') { edge.style = 'dashed'; }
            if (e.arrow === 'both' || e.arrow === 'none' || e.arrow === 'end') { edge.arrow = e.arrow; }
            if (e.route === 'straight') { edge.route = 'straight'; }
            if (isColor(e.color) && e.color !== 'none') { edge.color = e.color.toLowerCase(); }
            if (e.width !== undefined && isFinite(parseFloat(e.width))) { edge.width = Math.min(12, Math.max(0.5, parseFloat(e.width))); }
            if (Array.isArray(e.points) && e.points.length) {
                edge.points = e.points.filter(function (p) { return p && isFinite(parseFloat(p.x)) && isFinite(parseFloat(p.y)); })
                    .slice(0, 50).map(function (p) { return { x: fmt(parseFloat(p.x)), y: fmt(parseFloat(p.y)) }; });
                if (!edge.points.length) { delete edge.points; }
            }
            doc.edges.push(edge);
        });
        return doc;
    }

    // ---------------------------------------------------------- renderizador
    /** Desenha o corpo (formas) de um nó dentro de <g>. */
    function drawShape(g, n) {
        var st = Geo.style(n), x = n.x, y = n.y, w = n.w, h = n.h, cx = x + w / 2, cy = y + h / 2;
        var base = { fill: st.fill, stroke: st.stroke, 'stroke-width': st.strokeWidth };
        function withBase(extra) { var o = {}; for (var k in base) { o[k] = base[k]; } for (var j in extra) { o[j] = extra[j]; } return o; }
        var o, a, ry, rx, fo, r, hy, neck, hip, arm, ls;
        switch (n.type) {
            case 'start': case 'end':
                svgEl('rect', withBase({ x: x, y: y, width: w, height: h, rx: h / 2 }), g); break;
            case 'decision':
                svgEl('polygon', withBase({ points: cx + ',' + y + ' ' + (x + w) + ',' + cy + ' ' + cx + ',' + (y + h) + ' ' + x + ',' + cy, 'stroke-linejoin': 'round' }), g); break;
            case 'io':
                o = Math.min(w * 0.2, 30);
                svgEl('polygon', withBase({ points: (x + o) + ',' + y + ' ' + (x + w) + ',' + y + ' ' + (x + w - o) + ',' + (y + h) + ' ' + x + ',' + (y + h), 'stroke-linejoin': 'round' }), g); break;
            case 'document':
                a = Math.min(12, h * 0.18);
                svgEl('path', withBase({ d: 'M' + x + ',' + y + ' L' + (x + w) + ',' + y + ' L' + (x + w) + ',' + (y + h - a) + ' C' + (x + w * 0.7) + ',' + (y + h - 3 * a) + ' ' + (x + w * 0.3) + ',' + (y + h + a) + ' ' + x + ',' + (y + h - a) + ' Z', 'stroke-linejoin': 'round' }), g); break;
            case 'database':
                ry = Math.min(14, h * 0.16); rx = w / 2;
                svgEl('path', withBase({ d: 'M' + x + ',' + (y + ry) + ' A' + rx + ',' + ry + ' 0 0 1 ' + (x + w) + ',' + (y + ry) + ' L' + (x + w) + ',' + (y + h - ry) + ' A' + rx + ',' + ry + ' 0 0 1 ' + x + ',' + (y + h - ry) + ' Z' }), g);
                svgEl('ellipse', withBase({ cx: cx, cy: y + ry, rx: rx, ry: ry }), g); break;
            case 'subprocess':
                svgEl('rect', withBase({ x: x, y: y, width: w, height: h, rx: 4 }), g);
                svgEl('line', { x1: x + 10, y1: y, x2: x + 10, y2: y + h, stroke: st.stroke, 'stroke-width': st.strokeWidth }, g);
                svgEl('line', { x1: x + w - 10, y1: y, x2: x + w - 10, y2: y + h, stroke: st.stroke, 'stroke-width': st.strokeWidth }, g); break;
            case 'note':
                fo = 16;
                svgEl('polygon', withBase({ points: x + ',' + y + ' ' + (x + w - fo) + ',' + y + ' ' + (x + w) + ',' + (y + fo) + ' ' + (x + w) + ',' + (y + h) + ' ' + x + ',' + (y + h), 'stroke-linejoin': 'round' }), g);
                svgEl('polygon', { points: (x + w - fo) + ',' + y + ' ' + (x + w - fo) + ',' + (y + fo) + ' ' + (x + w) + ',' + (y + fo), fill: st.stroke, 'fill-opacity': 0.35, stroke: st.stroke, 'stroke-width': 1 }, g); break;
            case 'text':
                svgEl('rect', { x: x, y: y, width: w, height: h, fill: 'transparent', stroke: 'none', 'class': 'pde-text-hit' }, g); break;
            case 'lane':
                svgEl('rect', withBase({ x: x, y: y, width: w, height: h }), g);
                if (w >= h * 1.5) { svgEl('rect', { x: x, y: y, width: 36, height: h, fill: st.stroke, 'fill-opacity': 0.14, stroke: st.stroke, 'stroke-width': st.strokeWidth }, g); }
                else { svgEl('rect', { x: x, y: y, width: w, height: 36, fill: st.stroke, 'fill-opacity': 0.14, stroke: st.stroke, 'stroke-width': st.strokeWidth }, g); }
                break;
            case 'circle':
                svgEl('ellipse', withBase({ cx: cx, cy: cy, rx: w / 2, ry: h / 2 }), g); break;
            case 'actor':
                r = Math.min(w, h) * 0.14; hy = y + r + 2; neck = y + 2 * r + 2; hip = y + h * 0.55; arm = neck + h * 0.12;
                ls = { stroke: st.stroke, 'stroke-width': st.strokeWidth, 'stroke-linecap': 'round', fill: 'none' };
                svgEl('rect', { x: x, y: y, width: w, height: h, fill: 'transparent', stroke: 'none', 'class': 'pde-text-hit' }, g);
                svgEl('circle', withBase({ cx: cx, cy: hy, r: r }), g);
                svgEl('line', withBase(Object.assign({ x1: cx, y1: neck, x2: cx, y2: hip }, ls)), g);
                svgEl('line', withBase(Object.assign({ x1: cx - w * 0.25, y1: arm, x2: cx + w * 0.25, y2: arm }, ls)), g);
                svgEl('line', withBase(Object.assign({ x1: cx, y1: hip, x2: cx - w * 0.2, y2: y + h * 0.75 }, ls)), g);
                svgEl('line', withBase(Object.assign({ x1: cx, y1: hip, x2: cx + w * 0.2, y2: y + h * 0.75 }, ls)), g); break;
            default:
                svgEl('rect', withBase({ x: x, y: y, width: w, height: h, rx: 6 }), g);
        }
    }

    function drawText(g, n) {
        var st = Geo.style(n), fs = st.fontSize, text = n.text || '';
        if (text.trim() === '') { return; }
        var attrs = { fill: st.color, 'font-size': fs, 'text-anchor': 'middle', 'dominant-baseline': 'central', 'pointer-events': 'none' };
        if (st.bold) { attrs['font-weight'] = 600; }
        var lh = fs * 1.25, lines, t, start, cx;
        if (n.type === 'lane') {
            if (n.w >= n.h * 1.5) {
                lines = Geo.wrap(text, n.h - 16, fs); cx = n.x + 18; var cy = n.y + n.h / 2;
                attrs.transform = 'rotate(-90 ' + cx + ' ' + cy + ')';
                t = svgEl('text', attrs, g);
                start = cy - (lines.length - 1) * lh / 2;
                lines.forEach(function (ln, i) { var ts = svgEl('tspan', { x: cx, y: start + i * lh }, t); ts.textContent = ln; });
            } else {
                lines = Geo.wrap(text, n.w - 16, fs); cx = n.x + n.w / 2;
                t = svgEl('text', attrs, g);
                start = n.y + 18 - (lines.length - 1) * lh / 2;
                lines.forEach(function (ln, i) { var ts = svgEl('tspan', { x: cx, y: start + i * lh }, t); ts.textContent = ln; });
            }
            return;
        }
        var tb = Geo.textBox(n), tx = tb[0], ty = tb[1], tw = tb[2], th = tb[3], align = tb[4], valign = tb[5];
        lines = Geo.wrap(text, tw, fs);
        attrs['text-anchor'] = align === 'left' ? 'start' : (align === 'right' ? 'end' : 'middle');
        var ax = align === 'left' ? tx : (align === 'right' ? tx + tw : tx + tw / 2);
        start = valign === 'top' ? ty + lh / 2 : (ty + th / 2) - (lines.length - 1) * lh / 2;
        t = svgEl('text', attrs, g);
        lines.forEach(function (ln, i) { var ts = svgEl('tspan', { x: ax, y: start + i * lh }, t); ts.textContent = ln === '' ? ' ' : ln; });
    }

    function markerId(prefix, color, start) {
        return prefix + '-' + (start ? 'as' : 'ae') + '-' + color.replace(/[^0-9a-f]/g, '');
    }

    /**
     * Desenha o documento completo dentro de um <svg> (limpa antes).
     * opts: fit (viewBox ajustada ao conteúdo), padding, prefix (ids dos marcadores),
     *       interactive (grupos com hit-areas para o editor), bg
     */
    function renderDoc(svg, doc, opts) {
        opts = opts || {};
        var prefix = opts.prefix || 'pd';
        while (svg.firstChild) { svg.removeChild(svg.firstChild); }
        svg.setAttribute('xmlns', NS);
        svg.setAttribute('font-family', FONT);
        var vx = 0, vy = 0, vw = doc.canvas.w, vh = doc.canvas.h;
        if (opts.fit) {
            var pad = opts.padding !== undefined ? opts.padding : 20, bb = Geo.bbox(doc);
            if (bb) { vx = bb.x - pad; vy = bb.y - pad; vw = Math.max(bb.w + 2 * pad, 40); vh = Math.max(bb.h + 2 * pad, 40); }
        }
        if (!opts.interactive) {
            svg.setAttribute('viewBox', fmt(vx) + ' ' + fmt(vy) + ' ' + fmt(vw) + ' ' + fmt(vh));
            svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
            if (opts.width !== undefined) { svg.setAttribute('width', opts.width); } else { svg.setAttribute('width', fmt(vw)); }
            if (opts.height !== undefined) { svg.setAttribute('height', opts.height); } else { svg.setAttribute('height', fmt(vh)); }
        }
        var defs = svgEl('defs', null, svg), colors = {};
        doc.edges.forEach(function (e) { colors[e.color || '#495057'] = true; });
        Object.keys(colors).forEach(function (c) {
            var m = svgEl('marker', { id: markerId(prefix, c, false), markerWidth: 11, markerHeight: 11, refX: 10, refY: 5.5, orient: 'auto', markerUnits: 'userSpaceOnUse' }, defs);
            svgEl('path', { d: 'M0,0.5 L10,5.5 L0,10.5 Z', fill: c }, m);
            var m2 = svgEl('marker', { id: markerId(prefix, c, true), markerWidth: 11, markerHeight: 11, refX: 1, refY: 5.5, orient: 'auto', markerUnits: 'userSpaceOnUse' }, defs);
            svgEl('path', { d: 'M11,0.5 L1,5.5 L11,10.5 Z', fill: c }, m2);
        });
        var root = opts.interactive ? svgEl('g', { 'class': 'pde-root' }, svg) : svg;
        var bg = opts.bg !== undefined ? opts.bg : doc.canvas.bg;
        if (!opts.interactive && bg && bg !== 'none') {
            svgEl('rect', { x: fmt(vx), y: fmt(vy), width: fmt(vw), height: fmt(vh), fill: bg }, root);
        }
        var byId = {};
        doc.nodes.forEach(function (n) { byId[n.id] = n; });

        function nodeGroup(n, parent) {
            var g = svgEl('g', { 'class': 'pd-node' + (opts.interactive ? ' pde-node' : ''), 'data-id': n.id, 'data-type': n.type }, null);
            drawShape(g, n);
            drawText(g, n);
            if (!opts.interactive && n.link) {
                var a = svgEl('a', { href: n.link, target: '_blank', rel: 'noopener' }, parent);
                a.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', n.link);
                a.appendChild(g);
            } else { parent.appendChild(g); }
            return g;
        }

        var gLanes = svgEl('g', { 'class': 'pd-lanes' }, root);
        doc.nodes.forEach(function (n) { if (n.type === 'lane') { nodeGroup(n, gLanes); } });
        var gEdges = svgEl('g', { 'class': 'pd-edges' }, root);
        doc.edges.forEach(function (e) {
            var from = byId[e.from], to = byId[e.to];
            if (!from || !to) { return; }
            var pts = Geo.edgePoints(e, from, to), color = e.color || '#495057', sw = num(e.width, 2);
            var d = pts.map(function (p, i) { return (i === 0 ? 'M' : ' L') + fmt(p[0]) + ',' + fmt(p[1]); }).join('');
            var g = svgEl('g', { 'class': 'pd-edge' + (opts.interactive ? ' pde-edge' : ''), 'data-id': e.id }, gEdges);
            if (opts.interactive) { svgEl('path', { d: d, fill: 'none', stroke: 'transparent', 'stroke-width': Math.max(14, sw + 10), 'class': 'pde-edge-hit' }, g); }
            var attrs = { d: d, fill: 'none', stroke: color, 'stroke-width': sw, 'stroke-linejoin': 'round' };
            if (e.style === 'dashed') { attrs['stroke-dasharray'] = fmt(sw * 4) + ',' + fmt(sw * 3); }
            var arrow = e.arrow || 'end';
            if (arrow === 'end' || arrow === 'both') { attrs['marker-end'] = 'url(#' + markerId(prefix, color, false) + ')'; }
            if (arrow === 'both') { attrs['marker-start'] = 'url(#' + markerId(prefix, color, true) + ')'; }
            svgEl('path', attrs, g);
            if (e.label) {
                var fs = 12, lns = Geo.wrap(e.label, 220, fs), lh = fs * 1.25, mw = 0;
                lns.forEach(function (ln) { mw = Math.max(mw, Geo.textWidth(ln, fs)); });
                var mp = Geo.midpoint(pts), bw = mw + 10, bh = lns.length * lh + 4;
                svgEl('rect', { x: fmt(mp[0] - bw / 2), y: fmt(mp[1] - bh / 2), width: fmt(bw), height: fmt(bh), rx: 3, fill: '#ffffff', 'fill-opacity': 0.92, 'class': 'pde-edge-label' }, g);
                var t = svgEl('text', { 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': fs, fill: color, 'pointer-events': 'none' }, g);
                var start = mp[1] - (lns.length - 1) * lh / 2;
                lns.forEach(function (ln, i) { var ts = svgEl('tspan', { x: fmt(mp[0]), y: fmt(start + i * lh) }, t); ts.textContent = ln; });
            }
        });
        var gNodes = svgEl('g', { 'class': 'pd-nodes' }, root);
        doc.nodes.forEach(function (n) { if (n.type !== 'lane') { nodeGroup(n, gNodes); } });
        return { root: root, view: { x: vx, y: vy, w: vw, h: vh } };
    }

    /** SVG standalone (string) do documento ajustado ao conteúdo. */
    function docToSvgString(doc, opts) {
        var svg = document.createElementNS(NS, 'svg');
        renderDoc(svg, doc, Object.assign({ fit: true, padding: 20, prefix: 'pd' }, opts || {}));
        svg.setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        return '<?xml version="1.0" encoding="UTF-8"?>\n' + new XMLSerializer().serializeToString(svg);
    }

    function download(blob, name) {
        var url = URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url; a.download = name; document.body.appendChild(a); a.click();
        setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 1500);
    }

    /** Converte um <svg> (ou string SVG) em PNG e baixa. */
    function svgToPng(svgOrString, name, scale, cb) {
        var str = typeof svgOrString === 'string' ? svgOrString : new XMLSerializer().serializeToString(svgOrString);
        if (str.indexOf('xmlns=') < 0) { str = str.replace('<svg', '<svg xmlns="' + NS + '"'); }
        var m = /viewBox="([^"]+)"/.exec(str), vb = m ? m[1].split(/[\s,]+/).map(parseFloat) : [0, 0, 1000, 700];
        var w = Math.max(1, Math.round(vb[2])), h = Math.max(1, Math.round(vb[3]));
        scale = scale || Math.min(3, Math.max(1, 2400 / w));
        // garante width/height absolutos para o rasterizador
        str = str.replace(/<svg([^>]*?)\swidth="[^"]*"/, '<svg$1').replace(/<svg([^>]*?)\sheight="[^"]*"/, '<svg$1')
            .replace('<svg', '<svg width="' + w + '" height="' + h + '"');
        var img = new Image();
        img.onload = function () {
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(w * scale); canvas.height = Math.round(h * scale);
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(function (blob) { if (blob) { download(blob, name); } if (cb) { cb(!!blob); } }, 'image/png');
        };
        img.onerror = function () { if (cb) { cb(false); } };
        img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(str);
    }

    function printSvg(svgString, title) {
        var w = window.open('', '_blank');
        if (!w) { alert('Permita pop-ups para imprimir.'); return; }
        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escapeXml(title || 'Diagrama') + '</title>'
            + '<style>@page{size:A4 landscape;margin:10mm}html,body{margin:0;padding:0;background:#fff}'
            + '.pg{width:100%;height:100vh;display:flex;align-items:center;justify-content:center}'
            + 'svg{max-width:100%;max-height:100%;width:auto;height:auto}'
            + '.bar{position:fixed;top:0;left:0;right:0;background:#222;color:#fff;padding:6px 12px;font:13px sans-serif;display:flex;gap:8px;align-items:center}'
            + '.bar button{font:13px sans-serif;padding:4px 10px}@media print{.bar{display:none}}</style></head><body>'
            + '<div class="bar"><span>' + escapeXml(title || 'Diagrama') + '</span><span style="flex:1"></span>'
            + '<button onclick="window.print()">Imprimir / PDF</button><button onclick="window.close()">Fechar</button></div>'
            + '<div class="pg">' + svgString.replace(/^<\?xml[^>]*>\s*/, '').replace(/<svg([^>]*?)\swidth="[^"]*"/, '<svg$1').replace(/<svg([^>]*?)\sheight="[^"]*"/, '<svg$1') + '</div>'
            + '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},300)})<\/script></body></html>';
        w.document.open(); w.document.write(html); w.document.close();
    }

    // ---------------------------------------------------------------- editor
    var ICONS = {
        undo: '↶', redo: '↷', zoomIn: '+', zoomOut: '−', fit: '⤢', grid: '#', snap: '⌗',
        front: '⬆', back: '⬇', trash: '🗑', save: '💾', svg: 'SVG', png: 'PNG', print: '🖨',
        alignL: '⫷', alignC: '⫶', alignR: '⫸', alignT: '⤒', alignM: '⫼', alignB: '⤓', distH: '↔', distV: '↕', full: '⛶'
    };
    function bi(name, fallback) {
        return '<i class="bi bi-' + name + '" aria-hidden="true"></i><span class="pde-ico-fb">' + fallback + '</span>';
    }

    function Editor(container, opts) {
        this.opts = opts || {};
        this.container = container;
        this.doc = normalize(this.opts.data || {});
        this.view = { x: 0, y: 0, k: 1 };
        this.sel = [];            // ids selecionados (nós e arestas)
        this.hover = null;
        this.undoStack = [];
        this.redoStack = [];
        this.clipboard = null;
        this.showGrid = true;
        this.snap = true;
        this.version = this.opts.version || 1;
        this.readOnly = !!this.opts.readOnly;
        this.savedJson = JSON.stringify(this.doc);
        this.savedTitle = this.getTitle();
        this.dirty = false;
        this.saving = false;
        this.versionNote = '';
        this.drag = null;
        this.build();
        this.render();
        this.fit(1);
        this.bind();
        this.updateStatus();
    }

    Editor.prototype = {
        // ------------------------------------------------------------ DOM
        build: function () {
            var self = this, c = this.container;
            c.classList.add('pde');
            c.innerHTML = '';
            // Bootstrap Icons indisponível (CDN bloqueada)? usa os textos alternativos
            try {
                var probe = htmlEl('i', { 'class': 'bi bi-save' }, c);
                var ff = global.getComputedStyle(probe).fontFamily || '';
                c.removeChild(probe);
                if (!/bootstrap-icons/i.test(ff)) { c.classList.add('pde-no-icons'); }
            } catch (e) { c.classList.add('pde-no-icons'); }
            var tb = this.toolbar = htmlEl('div', { 'class': 'pde-toolbar' }, c);
            function btn(id, icon, fb, title, cls) {
                var b = htmlEl('button', { type: 'button', 'class': 'pde-btn ' + (cls || ''), 'data-act': id, title: title, html: bi(icon, fb) }, tb);
                b.setAttribute('aria-label', title);
                return b;
            }
            function sep() { htmlEl('span', { 'class': 'pde-sep' }, tb); }
            if (!this.readOnly) {
                this.btnSave = btn('save', 'save', 'Salvar', 'Salvar (Ctrl+S)', 'pde-btn-primary');
                this.btnSave.innerHTML += '<span class="pde-btn-label">Salvar</span>';
                sep();
                btn('undo', 'arrow-counterclockwise', ICONS.undo, 'Desfazer (Ctrl+Z)');
                btn('redo', 'arrow-clockwise', ICONS.redo, 'Refazer (Ctrl+Y)');
                sep();
            }
            btn('zoomOut', 'zoom-out', ICONS.zoomOut, 'Reduzir (−)');
            this.zoomLabel = htmlEl('button', { type: 'button', 'class': 'pde-btn pde-zoom', 'data-act': 'zoom100', title: 'Zoom 100% (0)' }, tb, '100%');
            btn('zoomIn', 'zoom-in', ICONS.zoomIn, 'Ampliar (+)');
            btn('fit', 'arrows-fullscreen', ICONS.fit, 'Ajustar à janela (F)');
            sep();
            this.btnGrid = btn('grid', 'grid-3x3', ICONS.grid, 'Mostrar/ocultar grade', 'active');
            this.btnSnap = btn('snap', 'magnet', ICONS.snap, 'Alinhar à grade (snap)', 'active');
            if (!this.readOnly) {
                sep();
                btn('alignLeft', 'align-start', ICONS.alignL, 'Alinhar à esquerda');
                btn('alignCenter', 'align-center', ICONS.alignC, 'Centralizar horizontalmente');
                btn('alignRight', 'align-end', ICONS.alignR, 'Alinhar à direita');
                btn('alignTop', 'align-top', ICONS.alignT, 'Alinhar ao topo');
                btn('alignMiddle', 'align-middle', ICONS.alignM, 'Centralizar verticalmente');
                btn('alignBottom', 'align-bottom', ICONS.alignB, 'Alinhar à base');
                btn('distH', 'distribute-horizontal', ICONS.distH, 'Distribuir horizontalmente');
                btn('distV', 'distribute-vertical', ICONS.distV, 'Distribuir verticalmente');
                sep();
                btn('front', 'front', ICONS.front, 'Trazer para frente (Ctrl+Shift+])');
                btn('back', 'back', ICONS.back, 'Enviar para trás (Ctrl+Shift+[)');
                btn('duplicate', 'files', '⧉', 'Duplicar (Ctrl+D)');
                btn('delete', 'trash', ICONS.trash, 'Excluir (Delete)');
                sep();
            }
            btn('exportSvg', 'filetype-svg', ICONS.svg, 'Exportar SVG');
            btn('exportPng', 'filetype-png', ICONS.png, 'Exportar PNG');
            btn('print', 'printer', ICONS.print, 'Imprimir');
            htmlEl('span', { 'class': 'pde-spacer' }, tb);
            this.statusEl = htmlEl('span', { 'class': 'pde-status' }, tb, '');
            btn('fullscreen', 'arrows-angle-expand', ICONS.full, 'Modo foco (ocultar menus)');

            var body = htmlEl('div', { 'class': 'pde-body' }, c);
            // paleta
            if (!this.readOnly) {
                var pal = this.palette = htmlEl('div', { 'class': 'pde-palette' }, body);
                htmlEl('div', { 'class': 'pde-pal-title' }, pal, 'Formas');
                PALETTE_ORDER.forEach(function (type) {
                    var item = htmlEl('div', { 'class': 'pde-pal-item', 'data-type': type, title: TYPES[type].label + ' — clique para inserir ou arraste' }, pal);
                    var svg = svgEl('svg', { viewBox: '0 0 100 64', width: 72, height: 46 }, item);
                    var d = TYPES[type], sc = Math.min(84 / d.w, 48 / d.h);
                    var n = { id: 'p', type: type, x: (100 - d.w * sc) / 2, y: (64 - d.h * sc) / 2, w: d.w * sc, h: d.h * sc, text: '' };
                    if (type === 'lane') { n.text = 'Raia'; n.fontSize = 9; }
                    if (type === 'text') { n.text = 'Texto'; n.fontSize = 14; }
                    var g = svgEl('g', null, svg); drawShape(g, n); drawText(g, n);
                    htmlEl('div', { 'class': 'pde-pal-label' }, item, d.label);
                });
                htmlEl('div', { 'class': 'pde-pal-hint' }, pal, 'Dica: passe o mouse sobre uma forma e arraste a partir dos pontos azuis para conectar.');
            }
            // canvas
            var wrap = this.wrap = htmlEl('div', { 'class': 'pde-canvas-wrap', tabindex: '0' }, body);
            this.svg = svgEl('svg', { 'class': 'pde-svg', width: '100%', height: '100%' }, wrap);
            this.textarea = htmlEl('textarea', { 'class': 'pde-textedit', spellcheck: 'false' }, wrap);
            this.textarea.hidden = true;
            this.ghost = htmlEl('div', { 'class': 'pde-ghost' }, wrap);
            this.ghost.hidden = true;
            // propriedades
            this.props = htmlEl('div', { 'class': 'pde-props' }, body);
            this.buildProps();
            this.dialog = null;
        },

        buildProps: function () {
            var p = this.props, self = this;
            p.innerHTML = '';
            var fields = this.fields = {};
            function section(title) { var s = htmlEl('div', { 'class': 'pde-section' }, p); htmlEl('div', { 'class': 'pde-sec-title' }, s, title); return s; }
            function field(sec, key, label, type, extra) {
                var row = htmlEl('label', { 'class': 'pde-field' }, sec);
                htmlEl('span', null, row, label);
                var inp;
                if (type === 'select') {
                    inp = htmlEl('select', null, row);
                    (extra.options || []).forEach(function (o) { var op = htmlEl('option', { value: o[0] }, inp, o[1]); if (o[2]) { op.disabled = true; } });
                } else if (type === 'textarea') {
                    inp = htmlEl('textarea', { rows: extra && extra.rows || 3 }, row);
                } else if (type === 'checkbox') {
                    inp = htmlEl('input', { type: 'checkbox' }, row); row.classList.add('pde-field-check');
                } else {
                    inp = htmlEl('input', { type: type }, row);
                    if (extra) { for (var k in extra) { inp.setAttribute(k, extra[k]); } }
                }
                inp.dataset.key = key;
                fields[key] = inp;
                return inp;
            }
            // Nó
            var sn = this.secNode = section('Forma');
            field(sn, 'text', 'Texto', 'textarea', { rows: 3 });
            var typeOpts = PALETTE_ORDER.map(function (t) { return [t, TYPES[t].label]; });
            field(sn, 'type', 'Tipo', 'select', { options: typeOpts });
            var rowc = htmlEl('div', { 'class': 'pde-row3' }, sn);
            field(rowc, 'fill', 'Fundo', 'color');
            field(rowc, 'stroke', 'Borda', 'color');
            field(rowc, 'color', 'Texto', 'color');
            var rowf = htmlEl('div', { 'class': 'pde-row3' }, sn);
            field(rowf, 'fontSize', 'Fonte', 'number', { min: 6, max: 120, step: 1 });
            field(rowf, 'align', 'Alinh.', 'select', { options: [['center', 'Centro'], ['left', 'Esq.'], ['right', 'Dir.']] });
            field(rowf, 'strokeWidth', 'Espess.', 'number', { min: 0, max: 20, step: 0.5 });
            field(sn, 'bold', 'Negrito', 'checkbox');
            field(sn, 'noFill', 'Sem preenchimento', 'checkbox');
            var rowp = htmlEl('div', { 'class': 'pde-row4' }, sn);
            field(rowp, 'x', 'X', 'number', { step: 1 });
            field(rowp, 'y', 'Y', 'number', { step: 1 });
            field(rowp, 'w', 'Largura', 'number', { min: 10, step: 1 });
            field(rowp, 'h', 'Altura', 'number', { min: 10, step: 1 });
            field(sn, 'link', 'Link (URL)', 'url', { placeholder: 'https://…' });
            // Aresta
            var se = this.secEdge = section('Conector');
            field(se, 'label', 'Rótulo', 'text');
            var rowe = htmlEl('div', { 'class': 'pde-row2' }, se);
            field(rowe, 'style', 'Linha', 'select', { options: [['solid', 'Sólida'], ['dashed', 'Tracejada']] });
            field(rowe, 'arrow', 'Setas', 'select', { options: [['end', 'No fim'], ['both', 'Ambas'], ['none', 'Nenhuma']] });
            var rowe2 = htmlEl('div', { 'class': 'pde-row2' }, se);
            field(rowe2, 'route', 'Traçado', 'select', { options: [['ortho', 'Ortogonal'], ['straight', 'Reta']] });
            field(rowe2, 'ewidth', 'Espessura', 'number', { min: 0.5, max: 12, step: 0.5 });
            var rowe3 = htmlEl('div', { 'class': 'pde-row3' }, se);
            field(rowe3, 'ecolor', 'Cor', 'color');
            var sideOpts = [['', 'Auto'], ['top', 'Topo'], ['right', 'Direita'], ['bottom', 'Base'], ['left', 'Esquerda']];
            field(rowe3, 'fromSide', 'Saída', 'select', { options: sideOpts });
            field(rowe3, 'toSide', 'Chegada', 'select', { options: sideOpts });
            // Canvas
            var sc = this.secCanvas = section('Diagrama');
            var rowcv = htmlEl('div', { 'class': 'pde-row3' }, sc);
            field(rowcv, 'cw', 'Largura', 'number', { min: 200, max: 20000, step: 10 });
            field(rowcv, 'ch', 'Altura', 'number', { min: 200, max: 20000, step: 10 });
            field(rowcv, 'grid', 'Grade', 'number', { min: 0, max: 200, step: 5 });
            field(sc, 'bg', 'Cor de fundo', 'color');
            if (!this.readOnly) {
                field(sc, 'note', 'Nota da próxima versão', 'text', { placeholder: 'opcional', maxlength: 255 });
            }
            var help = htmlEl('div', { 'class': 'pde-help' }, sc);
            help.innerHTML = '<b>Atalhos:</b> duplo clique = editar texto · Delete = excluir · Ctrl+Z/Y = desfazer/refazer · '
                + 'Ctrl+C/V/D = copiar/colar/duplicar · Ctrl+A = selecionar tudo · Shift+clique = seleção múltipla · '
                + 'arraste o fundo = selecionar área · Espaço+arraste ou botão do meio = mover a tela · roda = zoom · setas = mover 1px (Shift = 10px).';
            this.secMulti = htmlEl('div', { 'class': 'pde-section pde-multi-info' }, p);

            // eventos dos campos
            Object.keys(fields).forEach(function (key) {
                var inp = fields[key];
                var live = function () { self.applyField(key, inp, false); };
                var commit = function () { self.applyField(key, inp, true); };
                inp.addEventListener('focus', function () { self.fieldBefore = self.snapshot(); });
                if (inp.type === 'checkbox' || inp.tagName === 'SELECT') { inp.addEventListener('change', commit); }
                else { inp.addEventListener('input', live); inp.addEventListener('change', commit); }
                inp.addEventListener('keydown', function (ev) { ev.stopPropagation(); if (ev.key === 'Enter' && inp.tagName !== 'TEXTAREA') { inp.blur(); } });
            });
            if (this.readOnly) {
                Object.keys(fields).forEach(function (k) { fields[k].disabled = true; });
            }
            this.updateProps();
        },

        // ----------------------------------------------------- estado/histórico
        snapshot: function () { return JSON.stringify(this.doc); },
        pushUndo: function (before) {
            if (before === undefined || before === null) { return; }
            if (before === this.snapshot()) { return; }
            this.undoStack.push(before);
            if (this.undoStack.length > 100) { this.undoStack.shift(); }
            this.redoStack = [];
            this.changed();
        },
        undo: function () {
            if (!this.undoStack.length) { return; }
            this.redoStack.push(this.snapshot());
            this.doc = JSON.parse(this.undoStack.pop());
            this.afterRestore();
        },
        redo: function () {
            if (!this.redoStack.length) { return; }
            this.undoStack.push(this.snapshot());
            this.doc = JSON.parse(this.redoStack.pop());
            this.afterRestore();
        },
        afterRestore: function () {
            var self = this;
            this.sel = this.sel.filter(function (id) { return !!self.find(id); });
            this.render();
            this.changed();
        },
        changed: function () {
            this.growCanvas();
            var d = this.snapshot() !== this.savedJson || this.getTitle() !== this.savedTitle;
            if (d !== this.dirty) { this.dirty = d; if (this.opts.onDirty) { this.opts.onDirty(d); } }
            this.updateStatus();
        },
        getTitle: function () { return this.opts.getTitle ? String(this.opts.getTitle() || '') : ''; },
        titleChanged: function () { this.changed(); },
        updateStatus: function () {
            if (!this.statusEl) { return; }
            var t;
            if (this.saving) { t = 'Salvando…'; }
            else if (this.readOnly) { t = 'Somente leitura'; }
            else if (this.dirty) { t = 'Alterações não salvas'; }
            else { t = 'Salvo' + (this.version ? ' (v' + this.version + ')' : ''); }
            this.statusEl.textContent = t;
            this.statusEl.className = 'pde-status ' + (this.saving ? 'is-saving' : (this.dirty ? 'is-dirty' : 'is-saved'));
            if (this.btnSave) { this.btnSave.classList.toggle('pde-dirty', this.dirty); }
            var self = this;
            this.toolbar.querySelectorAll('[data-act=undo],[data-act=redo]').forEach(function (b) {
                b.disabled = b.dataset.act === 'undo' ? !self.undoStack.length : !self.redoStack.length;
            });
        },
        growCanvas: function () {
            var bb = Geo.bbox(this.doc);
            if (!bb) { return; }
            var c = this.doc.canvas;
            if (bb.x + bb.w + 40 > c.w) { c.w = Math.min(20000, Math.ceil((bb.x + bb.w + 40) / 10) * 10); }
            if (bb.y + bb.h + 40 > c.h) { c.h = Math.min(20000, Math.ceil((bb.y + bb.h + 40) / 10) * 10); }
        },
        find: function (id) {
            for (var i = 0; i < this.doc.nodes.length; i++) { if (this.doc.nodes[i].id === id) { return this.doc.nodes[i]; } }
            for (var j = 0; j < this.doc.edges.length; j++) { if (this.doc.edges[j].id === id) { return this.doc.edges[j]; } }
            return null;
        },
        node: function (id) { for (var i = 0; i < this.doc.nodes.length; i++) { if (this.doc.nodes[i].id === id) { return this.doc.nodes[i]; } } return null; },
        edge: function (id) { for (var i = 0; i < this.doc.edges.length; i++) { if (this.doc.edges[i].id === id) { return this.doc.edges[i]; } } return null; },
        selNodes: function () { var self = this; return this.sel.map(function (id) { return self.node(id); }).filter(Boolean); },
        selEdges: function () { var self = this; return this.sel.map(function (id) { return self.edge(id); }).filter(Boolean); },
        isSel: function (id) { return this.sel.indexOf(id) >= 0; },
        uid: function (prefix) {
            var id;
            do { id = prefix + Math.random().toString(36).slice(2, 8); } while (this.find(id));
            return id;
        },
        snapV: function (v) { var g = this.doc.canvas.grid; return this.snap && g > 0 ? Math.round(v / g) * g : Math.round(v); },
        toJSON: function () { return normalize(this.doc); },

        // ----------------------------------------------------------- render
        render: function () {
            var r = renderDoc(this.svg, this.doc, { interactive: true, prefix: 'pde' });
            this.root = r.root;
            // fundo (página + grade) antes de tudo
            var bgG = svgEl('g', { 'class': 'pde-bg' });
            this.root.insertBefore(bgG, this.root.firstChild);
            var c = this.doc.canvas;
            svgEl('rect', { x: 0, y: 0, width: c.w, height: c.h, fill: c.bg || '#ffffff', 'class': 'pde-page' }, bgG);
            if (this.showGrid && c.grid > 0) {
                var defs = this.svg.querySelector('defs');
                var pat = svgEl('pattern', { id: 'pde-grid', width: c.grid, height: c.grid, patternUnits: 'userSpaceOnUse' }, defs);
                svgEl('path', { d: 'M ' + c.grid + ' 0 L 0 0 0 ' + c.grid, fill: 'none', stroke: '#dee2e6', 'stroke-width': 0.6 }, pat);
                var pat2 = svgEl('pattern', { id: 'pde-grid-big', width: c.grid * 5, height: c.grid * 5, patternUnits: 'userSpaceOnUse' }, defs);
                svgEl('rect', { width: c.grid * 5, height: c.grid * 5, fill: 'url(#pde-grid)' }, pat2);
                svgEl('path', { d: 'M ' + (c.grid * 5) + ' 0 L 0 0 0 ' + (c.grid * 5), fill: 'none', stroke: '#ced4da', 'stroke-width': 0.8 }, pat2);
                svgEl('rect', { x: 0, y: 0, width: c.w, height: c.h, fill: 'url(#pde-grid-big)', 'class': 'pde-gridrect' }, bgG);
            }
            this.overlay = svgEl('g', { 'class': 'pde-overlay' }, this.root);
            this.applyView();
            this.renderOverlay();
            this.updateProps();
        },
        applyView: function () {
            if (this.root) { this.root.setAttribute('transform', 'translate(' + fmt(this.view.x) + ',' + fmt(this.view.y) + ') scale(' + this.view.k + ')'); }
            if (this.zoomLabel) { this.zoomLabel.textContent = Math.round(this.view.k * 100) + '%'; }
            if (!this.textarea.hidden && this.editing) { this.positionTextarea(); }
        },
        renderOverlay: function () {
            var ov = this.overlay, self = this, k = this.view.k;
            if (!ov) { return; }
            while (ov.firstChild) { ov.removeChild(ov.firstChild); }
            var nodes = this.selNodes(), edges = this.selEdges();
            // contorno de seleção
            nodes.forEach(function (n) {
                svgEl('rect', { x: n.x - 2, y: n.y - 2, width: n.w + 4, height: n.h + 4, fill: 'none', stroke: '#1a73e8', 'stroke-width': 1.5 / k, 'stroke-dasharray': (4 / k) + ',' + (3 / k), 'pointer-events': 'none' }, ov);
            });
            edges.forEach(function (e) {
                var from = self.node(e.from), to = self.node(e.to);
                if (!from || !to) { return; }
                var pts = Geo.edgePoints(e, from, to);
                svgEl('path', { d: pts.map(function (p, i) { return (i ? ' L' : 'M') + p[0] + ',' + p[1]; }).join(''), fill: 'none', stroke: '#1a73e8', 'stroke-opacity': 0.35, 'stroke-width': 8 / k, 'pointer-events': 'none' }, ov);
                if (!self.readOnly) {
                    [[pts[0], 'from'], [pts[pts.length - 1], 'to']].forEach(function (pe) {
                        svgEl('circle', { cx: pe[0][0], cy: pe[0][1], r: 6 / k, fill: '#fff', stroke: '#1a73e8', 'stroke-width': 2 / k, 'class': 'pde-edge-end', 'data-edge': e.id, 'data-end': pe[1] }, ov);
                    });
                }
            });
            // alças de redimensionamento
            if (nodes.length === 1 && !this.readOnly) {
                var n = nodes[0], hs = 8 / k;
                var handles = { nw: [n.x, n.y], n: [n.x + n.w / 2, n.y], ne: [n.x + n.w, n.y], e: [n.x + n.w, n.y + n.h / 2], se: [n.x + n.w, n.y + n.h], s: [n.x + n.w / 2, n.y + n.h], sw: [n.x, n.y + n.h], w: [n.x, n.y + n.h / 2] };
                Object.keys(handles).forEach(function (h) {
                    svgEl('rect', { x: handles[h][0] - hs / 2, y: handles[h][1] - hs / 2, width: hs, height: hs, fill: '#fff', stroke: '#1a73e8', 'stroke-width': 1.5 / k, 'class': 'pde-handle pde-handle-' + h, 'data-handle': h }, ov);
                });
            }
            // portas de conexão (hover ou selecionado, nó único)
            var portNode = null;
            if (!this.readOnly) {
                if (this.hover && this.node(this.hover) && !(this.drag && this.drag.type === 'move')) { portNode = this.node(this.hover); }
                else if (nodes.length === 1 && !(this.drag && this.drag.type === 'move')) { portNode = nodes[0]; }
            }
            if (portNode && portNode.type !== 'lane') {
                SIDES.forEach(function (s) {
                    var p = Geo.port(portNode, s);
                    svgEl('circle', { cx: p[0], cy: p[1], r: 6 / k, fill: '#1a73e8', 'fill-opacity': 0.9, stroke: '#fff', 'stroke-width': 1.5 / k, 'class': 'pde-port', 'data-node': portNode.id, 'data-side': s }, ov);
                });
            }
            // rubber band / conector temporário / alvo
            if (this.drag && this.drag.type === 'band' && this.drag.rect) {
                var r = this.drag.rect;
                svgEl('rect', { x: r.x, y: r.y, width: r.w, height: r.h, fill: '#1a73e8', 'fill-opacity': 0.08, stroke: '#1a73e8', 'stroke-width': 1 / k, 'stroke-dasharray': (4 / k) + ',' + (3 / k), 'pointer-events': 'none' }, ov);
            }
            if (this.drag && (this.drag.type === 'connect' || this.drag.type === 'reconnect') && this.drag.cur) {
                var d = this.drag, p1 = d.start, p2 = d.cur;
                if (d.target) {
                    var tn = this.node(d.target);
                    svgEl('rect', { x: tn.x - 3, y: tn.y - 3, width: tn.w + 6, height: tn.h + 6, fill: 'none', stroke: '#2e7d32', 'stroke-width': 2 / k, 'pointer-events': 'none' }, ov);
                    p2 = Geo.port(tn, d.targetSide);
                    svgEl('circle', { cx: p2[0], cy: p2[1], r: 7 / k, fill: '#2e7d32', 'pointer-events': 'none' }, ov);
                }
                svgEl('line', { x1: p1[0], y1: p1[1], x2: p2[0], y2: p2[1], stroke: '#1a73e8', 'stroke-width': 2 / k, 'stroke-dasharray': (6 / k) + ',' + (4 / k), 'pointer-events': 'none' }, ov);
            }
        },

        // -------------------------------------------------------- propriedades
        updateProps: function () {
            if (!this.fields) { return; }
            var nodes = this.selNodes(), edges = this.selEdges(), f = this.fields;
            var showNode = nodes.length > 0, showEdge = edges.length > 0 && nodes.length === 0;
            this.secNode.hidden = !showNode;
            this.secEdge.hidden = !showEdge;
            this.secCanvas.hidden = showNode || showEdge;
            this.secMulti.hidden = !(nodes.length + edges.length > 1);
            if (nodes.length + edges.length > 1) { this.secMulti.textContent = (nodes.length + edges.length) + ' itens selecionados — as alterações valem para todos.'; }
            if (showNode) {
                var n = nodes[0], st = Geo.style(n), one = nodes.length === 1;
                f.text.value = one ? n.text : ''; f.text.disabled = !one || this.readOnly;
                f.type.value = n.type;
                f.fill.value = st.fill === 'none' ? '#ffffff' : st.fill.length === 4 ? expandHex(st.fill) : st.fill.slice(0, 7);
                f.noFill.checked = st.fill === 'none';
                f.stroke.value = st.stroke === 'none' ? '#ffffff' : st.stroke.length === 4 ? expandHex(st.stroke) : st.stroke.slice(0, 7);
                f.color.value = st.color.length === 4 ? expandHex(st.color) : st.color.slice(0, 7);
                f.fontSize.value = st.fontSize; f.align.value = st.align; f.strokeWidth.value = st.strokeWidth;
                f.bold.checked = !!n.bold;
                f.x.value = Math.round(n.x); f.y.value = Math.round(n.y); f.w.value = Math.round(n.w); f.h.value = Math.round(n.h);
                f.link.value = n.link || ''; f.link.disabled = !one || this.readOnly;
                ['x', 'y'].forEach(function (k) { f[k].disabled = !one; });
            }
            if (showEdge) {
                var e = edges[0];
                f.label.value = edges.length === 1 ? (e.label || '') : '';
                f.style.value = e.style || 'solid'; f.arrow.value = e.arrow || 'end'; f.route.value = e.route || 'ortho';
                f.ewidth.value = num(e.width, 2); f.ecolor.value = (e.color || '#495057').slice(0, 7);
                f.fromSide.value = e.fromSide || ''; f.toSide.value = e.toSide || '';
            }
            if (!showNode && !showEdge) {
                var c = this.doc.canvas;
                f.cw.value = c.w; f.ch.value = c.h; f.grid.value = c.grid; f.bg.value = (c.bg || '#ffffff').slice(0, 7);
            }
        },
        applyField: function (key, inp, commit) {
            if (this.readOnly) { return; }
            var nodes = this.selNodes(), edges = this.selEdges(), self = this, v = inp.value;
            var before = this.fieldBefore !== undefined ? this.fieldBefore : this.snapshot();
            var rerender = true;
            if (nodes.length) {
                nodes.forEach(function (n) {
                    switch (key) {
                        case 'text': if (nodes.length === 1) { n.text = v.slice(0, 2000); } break;
                        case 'type': if (TYPES[v]) { n.type = v; } break;
                        case 'fill': n.fill = v; break;
                        case 'noFill': if (inp.checked) { n.fill = 'none'; } else { delete n.fill; } break;
                        case 'stroke': n.stroke = v; break;
                        case 'color': n.color = v; break;
                        case 'fontSize': n.fontSize = Math.round(Math.min(120, Math.max(6, num(v, 14)))); break;
                        case 'align': n.align = v; break;
                        case 'strokeWidth': n.strokeWidth = Math.min(20, Math.max(0, num(v, 2))); break;
                        case 'bold': if (inp.checked) { n.bold = true; } else { delete n.bold; } break;
                        case 'x': if (nodes.length === 1) { n.x = num(v, n.x); } break;
                        case 'y': if (nodes.length === 1) { n.y = num(v, n.y); } break;
                        case 'w': n.w = Math.max(10, num(v, n.w)); break;
                        case 'h': n.h = Math.max(10, num(v, n.h)); break;
                        case 'link': if (nodes.length === 1) { var l = safeLink(v); if (l) { n.link = l; } else { delete n.link; } } rerender = false; break;
                        default: rerender = false;
                    }
                });
            } else if (edges.length) {
                edges.forEach(function (e) {
                    switch (key) {
                        case 'label': if (edges.length === 1) { if (v.trim()) { e.label = v.slice(0, 200); } else { delete e.label; } } break;
                        case 'style': if (v === 'dashed') { e.style = 'dashed'; } else { delete e.style; } break;
                        case 'arrow': if (v === 'end') { delete e.arrow; } else { e.arrow = v; } break;
                        case 'route': if (v === 'straight') { e.route = 'straight'; } else { delete e.route; } break;
                        case 'ewidth': e.width = Math.min(12, Math.max(0.5, num(v, 2))); break;
                        case 'ecolor': e.color = v; break;
                        case 'fromSide': if (v) { e.fromSide = v; } else { delete e.fromSide; } break;
                        case 'toSide': if (v) { e.toSide = v; } else { delete e.toSide; } break;
                        default: rerender = false;
                    }
                });
            } else {
                var c = this.doc.canvas;
                switch (key) {
                    case 'cw': c.w = Math.round(Math.min(20000, Math.max(200, num(v, c.w)))); break;
                    case 'ch': c.h = Math.round(Math.min(20000, Math.max(200, num(v, c.h)))); break;
                    case 'grid': c.grid = Math.round(Math.min(200, Math.max(0, num(v, c.grid)))); break;
                    case 'bg': c.bg = v; break;
                    case 'note': this.versionNote = v; rerender = false; return;
                    default: rerender = false;
                }
            }
            if (rerender) { this.render(); }
            if (commit) { this.pushUndo(before); this.fieldBefore = undefined; this.changed(); this.updateProps(); }
            else { this.changed(); }
        },

        // ------------------------------------------------------------ eventos
        bind: function () {
            var self = this, svg = this.svg, wrap = this.wrap;
            this.toolbar.addEventListener('click', function (ev) {
                var b = ev.target.closest('[data-act]');
                if (b && !b.disabled) { self.action(b.dataset.act); }
            });
            if (this.palette) {
                this.palette.addEventListener('pointerdown', function (ev) {
                    var item = ev.target.closest('.pde-pal-item');
                    if (!item || ev.button !== 0) { return; }
                    ev.preventDefault();
                    self.drag = { type: 'palette', nodeType: item.dataset.type, sx: ev.clientX, sy: ev.clientY, moved: false };
                    self.ghost.textContent = TYPES[item.dataset.type].label;
                    self.captureDoc();
                });
            }
            svg.addEventListener('pointerdown', function (ev) { self.onDown(ev); });
            svg.addEventListener('dblclick', function (ev) { self.onDblClick(ev); });
            svg.addEventListener('pointermove', function (ev) { self.onHover(ev); });
            svg.addEventListener('pointerleave', function () { if (self.hover && !self.drag) { self.hover = null; self.renderOverlay(); } });
            svg.addEventListener('wheel', function (ev) { self.onWheel(ev); }, { passive: false });
            svg.addEventListener('contextmenu', function (ev) { ev.preventDefault(); });
            wrap.addEventListener('keydown', function (ev) { self.onKey(ev); });
            document.addEventListener('keydown', function (ev) {
                if (ev.code === 'Space' && !self.isTyping(ev)) { self.spaceDown = true; wrap.classList.add('pde-pan-mode'); if (document.activeElement === wrap) { ev.preventDefault(); } }
            });
            document.addEventListener('keyup', function (ev) { if (ev.code === 'Space') { self.spaceDown = false; wrap.classList.remove('pde-pan-mode'); } });
            this.textarea.addEventListener('keydown', function (ev) {
                ev.stopPropagation();
                if (ev.key === 'Escape') { ev.preventDefault(); self.endTextEdit(false); }
                else if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); self.endTextEdit(true); }
            });
            this.textarea.addEventListener('blur', function () { if (self.editing) { self.endTextEdit(true); } });
            window.addEventListener('beforeunload', function (ev) {
                if (self.dirty && !self.readOnly) { ev.preventDefault(); ev.returnValue = 'Há alterações não salvas no diagrama.'; return ev.returnValue; }
            });
            window.addEventListener('resize', function () { self.applyView(); });
        },
        isTyping: function (ev) {
            var t = ev.target;
            return t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
        },
        captureDoc: function () {
            var self = this;
            var move = function (ev) { self.onMove(ev); };
            var up = function (ev) { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); document.removeEventListener('pointercancel', up); self.onUp(ev); };
            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', up);
            document.addEventListener('pointercancel', up);
        },
        toWorld: function (clientX, clientY) {
            var r = this.svg.getBoundingClientRect();
            return [(clientX - r.left - this.view.x) / this.view.k, (clientY - r.top - this.view.y) / this.view.k];
        },
        nodeAt: function (wx, wy) {
            var i, n;
            for (i = this.doc.nodes.length - 1; i >= 0; i--) { n = this.doc.nodes[i]; if (n.type !== 'lane' && wx >= n.x && wx <= n.x + n.w && wy >= n.y && wy <= n.y + n.h) { return n; } }
            for (i = this.doc.nodes.length - 1; i >= 0; i--) { n = this.doc.nodes[i]; if (n.type === 'lane' && wx >= n.x && wx <= n.x + n.w && wy >= n.y && wy <= n.y + n.h) { return n; } }
            return null;
        },
        onHover: function (ev) {
            if (this.drag) { return; }
            var g = ev.target.closest('.pde-node');
            var id = g ? g.dataset.id : (ev.target.closest('.pde-port') ? ev.target.closest('.pde-port').dataset.node : null);
            if (id !== this.hover) { this.hover = id; this.renderOverlay(); }
        },
        onDown: function (ev) {
            if (ev.button === 2) { return; }
            this.wrap.focus({ preventScroll: true });
            if (this.editing) { this.endTextEdit(true); }
            var w = this.toWorld(ev.clientX, ev.clientY), t = ev.target;
            var pan = ev.button === 1 || this.spaceDown || (ev.altKey && ev.button === 0);
            if (pan) {
                ev.preventDefault();
                this.drag = { type: 'pan', sx: ev.clientX, sy: ev.clientY, vx: this.view.x, vy: this.view.y };
                this.wrap.classList.add('pde-panning');
                this.captureDoc();
                return;
            }
            if (ev.button !== 0) { return; }
            ev.preventDefault();
            if (!this.readOnly) {
                var port = t.closest('.pde-port');
                if (port) {
                    var pn = this.node(port.dataset.node);
                    this.drag = { type: 'connect', from: pn.id, fromSide: port.dataset.side, start: Geo.port(pn, port.dataset.side), cur: w };
                    this.captureDoc();
                    return;
                }
                var end = t.closest('.pde-edge-end');
                if (end) {
                    var e = this.edge(end.dataset.edge);
                    if (e) {
                        var other = end.dataset.end === 'from' ? this.node(e.to) : this.node(e.from);
                        var auto = Geo.autoSides(this.node(e.from), this.node(e.to));
                        var otherSide = end.dataset.end === 'from' ? (e.toSide || auto[1]) : (e.fromSide || auto[0]);
                        this.drag = { type: 'reconnect', edgeId: e.id, end: end.dataset.end, start: Geo.port(other, otherSide), cur: w, before: this.snapshot() };
                        this.captureDoc();
                        return;
                    }
                }
                var handle = t.closest('.pde-handle');
                if (handle && this.selNodes().length === 1) {
                    var rn = this.selNodes()[0];
                    this.drag = { type: 'resize', handle: handle.dataset.handle, id: rn.id, orig: { x: rn.x, y: rn.y, w: rn.w, h: rn.h }, sx: w[0], sy: w[1], before: this.snapshot() };
                    this.captureDoc();
                    return;
                }
            }
            var eg = t.closest('.pde-edge');
            if (eg) {
                this.select(eg.dataset.id, ev.shiftKey);
                return;
            }
            var ng = t.closest('.pde-node');
            if (ng) {
                var id = ng.dataset.id;
                if (ev.shiftKey) { this.select(id, true); }
                else if (!this.isSel(id)) { this.select(id, false); }
                if (this.readOnly) { return; }
                var self = this, moving = {};
                this.selNodes().forEach(function (n) {
                    moving[n.id] = { x: n.x, y: n.y };
                    if (n.type === 'lane') {
                        self.doc.nodes.forEach(function (m) {
                            if (m.id !== n.id && !self.isSel(m.id) && self.centerInside(m, n)) { moving[m.id] = { x: m.x, y: m.y }; }
                        });
                    }
                });
                this.drag = { type: 'move', sx: w[0], sy: w[1], moving: moving, before: this.snapshot(), moved: false, shift: ev.shiftKey, clickId: id };
                this.captureDoc();
                return;
            }
            // fundo: régua de seleção
            this.drag = { type: 'band', sx: w[0], sy: w[1], rect: null, add: ev.shiftKey, base: ev.shiftKey ? this.sel.slice() : [] };
            if (!ev.shiftKey) { this.select(null); }
            this.captureDoc();
        },
        centerInside: function (m, lane) {
            var cx = m.x + m.w / 2, cy = m.y + m.h / 2;
            return cx >= lane.x && cx <= lane.x + lane.w && cy >= lane.y && cy <= lane.y + lane.h;
        },
        onMove: function (ev) {
            var d = this.drag;
            if (!d) { return; }
            var w = this.toWorld(ev.clientX, ev.clientY), self = this;
            switch (d.type) {
                case 'pan':
                    this.view.x = d.vx + (ev.clientX - d.sx); this.view.y = d.vy + (ev.clientY - d.sy);
                    this.applyView();
                    break;
                case 'move':
                    var dx = w[0] - d.sx, dy = w[1] - d.sy;
                    if (!d.moved && Math.hypot(dx, dy) < 3 / this.view.k) { return; }
                    d.moved = true;
                    Object.keys(d.moving).forEach(function (id) {
                        var n = self.node(id); if (!n) { return; }
                        n.x = self.snapV(d.moving[id].x + dx); n.y = self.snapV(d.moving[id].y + dy);
                    });
                    this.render();
                    break;
                case 'resize':
                    var n = this.node(d.id), o = d.orig, h = d.handle, rx = w[0] - d.sx, ry = w[1] - d.sy;
                    var nx = o.x, ny = o.y, nw = o.w, nh = o.h;
                    if (h.indexOf('e') >= 0) { nw = Math.max(10, this.snapV(o.x + o.w + rx) - o.x); }
                    if (h.indexOf('s') >= 0) { nh = Math.max(10, this.snapV(o.y + o.h + ry) - o.y); }
                    if (h.indexOf('w') >= 0) { nx = Math.min(this.snapV(o.x + rx), o.x + o.w - 10); nw = o.x + o.w - nx; }
                    if (h.indexOf('n') >= 0) { ny = Math.min(this.snapV(o.y + ry), o.y + o.h - 10); nh = o.y + o.h - ny; }
                    n.x = nx; n.y = ny; n.w = nw; n.h = nh;
                    this.render();
                    break;
                case 'band':
                    d.rect = { x: Math.min(d.sx, w[0]), y: Math.min(d.sy, w[1]), w: Math.abs(w[0] - d.sx), h: Math.abs(w[1] - d.sy) };
                    this.renderOverlay();
                    break;
                case 'connect':
                case 'reconnect':
                    d.cur = w;
                    var tn = this.nodeAt(w[0], w[1]);
                    if (tn && tn.type === 'lane') { tn = null; }
                    d.target = tn ? tn.id : null;
                    d.targetSide = tn ? Geo.nearestSide(tn, w[0], w[1]) : null;
                    this.renderOverlay();
                    break;
                case 'palette':
                    if (!d.moved && Math.hypot(ev.clientX - d.sx, ev.clientY - d.sy) < 4) { return; }
                    d.moved = true;
                    this.ghost.hidden = false;
                    var wr = this.wrap.getBoundingClientRect();
                    this.ghost.style.left = (ev.clientX - wr.left + 8) + 'px';
                    this.ghost.style.top = (ev.clientY - wr.top + 8) + 'px';
                    break;
            }
        },
        onUp: function (ev) {
            var d = this.drag, self = this;
            if (!d) { return; }
            this.drag = null;
            this.wrap.classList.remove('pde-panning');
            switch (d.type) {
                case 'move':
                    if (d.moved) {
                        this.fitLanes(Object.keys(d.moving));
                        this.render();
                        this.pushUndo(d.before);
                    } else if (!d.shift && d.clickId) {
                        this.select(d.clickId, false);
                    }
                    this.renderOverlay();
                    break;
                case 'resize':
                    this.fitLanes([d.id]);
                    this.render();
                    this.pushUndo(d.before);
                    break;
                case 'band':
                    if (d.rect && (d.rect.w > 2 || d.rect.h > 2)) {
                        var r = d.rect, ids = d.base.slice();
                        this.doc.nodes.forEach(function (n) {
                            if (n.x >= r.x && n.y >= r.y && n.x + n.w <= r.x + r.w && n.y + n.h <= r.y + r.h && ids.indexOf(n.id) < 0) { ids.push(n.id); }
                        });
                        this.doc.edges.forEach(function (e) {
                            if (ids.indexOf(e.from) >= 0 && ids.indexOf(e.to) >= 0 && ids.indexOf(e.id) < 0) { ids.push(e.id); }
                        });
                        this.sel = ids;
                    }
                    this.render();
                    break;
                case 'connect':
                    if (d.target && d.target !== d.from) {
                        var before = this.snapshot();
                        var e = { id: this.uid('e'), from: d.from, to: d.target, fromSide: d.fromSide, toSide: d.targetSide };
                        this.doc.edges.push(e);
                        this.sel = [e.id];
                        this.render();
                        this.pushUndo(before);
                    } else { this.renderOverlay(); }
                    break;
                case 'reconnect':
                    var re = this.edge(d.edgeId);
                    if (re && d.target) {
                        if (d.end === 'from') { if (d.target !== re.to) { re.from = d.target; re.fromSide = d.targetSide; } }
                        else if (d.target !== re.from) { re.to = d.target; re.toSide = d.targetSide; }
                        this.render();
                        this.pushUndo(d.before);
                    } else { this.renderOverlay(); }
                    break;
                case 'palette':
                    this.ghost.hidden = true;
                    var rect = this.svg.getBoundingClientRect();
                    if (!d.moved) {
                        this.insertNode(d.nodeType, null);
                    } else if (ev.clientX >= rect.left && ev.clientX <= rect.right && ev.clientY >= rect.top && ev.clientY <= rect.bottom) {
                        var w = this.toWorld(ev.clientX, ev.clientY), t = TYPES[d.nodeType];
                        this.insertNode(d.nodeType, [w[0] - t.w / 2, w[1] - t.h / 2]);
                    }
                    break;
            }
        },
        onDblClick: function (ev) {
            var eg = ev.target.closest('.pde-edge'), ng = ev.target.closest('.pde-node');
            if (this.readOnly) { return; }
            if (ng) { this.select(ng.dataset.id, false); this.startTextEdit(ng.dataset.id); }
            else if (eg) { this.select(eg.dataset.id, false); this.startTextEdit(eg.dataset.id); }
        },
        onWheel: function (ev) {
            ev.preventDefault();
            var r = this.svg.getBoundingClientRect();
            var factor = ev.deltaY < 0 ? 1.1 : 1 / 1.1;
            this.zoomAt(factor, ev.clientX - r.left, ev.clientY - r.top);
        },
        onKey: function (ev) {
            if (this.isTyping(ev)) { return; }
            var k = ev.key, ctrl = ev.ctrlKey || ev.metaKey, ro = this.readOnly, handled = true;
            if (ctrl && (k === 'z' || k === 'Z') && ev.shiftKey) { if (!ro) { this.redo(); } }
            else if (ctrl && (k === 'z' || k === 'Z')) { if (!ro) { this.undo(); } }
            else if (ctrl && (k === 'y' || k === 'Y')) { if (!ro) { this.redo(); } }
            else if (ctrl && (k === 'c' || k === 'C')) { this.copy(); }
            else if (ctrl && (k === 'v' || k === 'V')) { if (!ro) { this.paste(); } }
            else if (ctrl && (k === 'd' || k === 'D')) { if (!ro) { this.copy(); this.paste(); } }
            else if (ctrl && (k === 'a' || k === 'A')) { this.selectAll(); }
            else if (ctrl && (k === 's' || k === 'S')) { if (!ro) { this.save(); } }
            else if (ctrl && ev.shiftKey && k === ']') { this.action('front'); }
            else if (ctrl && ev.shiftKey && k === '[') { this.action('back'); }
            else if (k === 'Delete' || k === 'Backspace') { if (!ro) { this.deleteSelection(); } }
            else if (k === 'Escape') { this.select(null); }
            else if (k === '+' || k === '=') { this.action('zoomIn'); }
            else if (k === '-' || k === '_') { this.action('zoomOut'); }
            else if (k === '0') { this.action('zoom100'); }
            else if (k === 'f' || k === 'F') { this.fit(); }
            else if (k === 'F2' || k === 'Enter') { var n = this.selNodes(); if (!ro && n.length === 1) { this.startTextEdit(n[0].id); } else if (!ro && this.selEdges().length === 1) { this.startTextEdit(this.selEdges()[0].id); } else { handled = false; } }
            else if (k.indexOf('Arrow') === 0) {
                if (ro) { handled = false; }
                else {
                    var step = ev.shiftKey ? 10 : 1, dx = k === 'ArrowLeft' ? -step : k === 'ArrowRight' ? step : 0, dy = k === 'ArrowUp' ? -step : k === 'ArrowDown' ? step : 0;
                    var nodes = this.selNodes();
                    if (nodes.length) {
                        var before = this.snapshot();
                        nodes.forEach(function (nd) { nd.x += dx; nd.y += dy; });
                        this.render(); this.pushUndo(before);
                    } else { this.view.x -= dx * 20; this.view.y -= dy * 20; this.applyView(); }
                }
            }
            else { handled = false; }
            if (handled) { ev.preventDefault(); }
        },

        // ------------------------------------------------------------- ações
        action: function (act) {
            var self = this;
            switch (act) {
                case 'save': this.save(); break;
                case 'undo': this.undo(); break;
                case 'redo': this.redo(); break;
                case 'zoomIn': this.zoomAt(1.2); break;
                case 'zoomOut': this.zoomAt(1 / 1.2); break;
                case 'zoom100': this.setZoom(1); break;
                case 'fit': this.fit(); break;
                case 'grid': this.showGrid = !this.showGrid; this.btnGrid.classList.toggle('active', this.showGrid); this.render(); break;
                case 'snap': this.snap = !this.snap; this.btnSnap.classList.toggle('active', this.snap); break;
                case 'delete': this.deleteSelection(); break;
                case 'duplicate': this.copy(); this.paste(); break;
                case 'front': case 'back': this.reorder(act === 'front'); break;
                case 'alignLeft': case 'alignCenter': case 'alignRight': case 'alignTop': case 'alignMiddle': case 'alignBottom': this.align(act.slice(5).toLowerCase()); break;
                case 'distH': this.distribute('h'); break;
                case 'distV': this.distribute('v'); break;
                case 'exportSvg': this.exportSvg(); break;
                case 'exportPng': this.exportPng(); break;
                case 'print': this.print(); break;
                case 'fullscreen':
                    document.body.classList.toggle('pde-focus');
                    this.toolbar.querySelector('[data-act=fullscreen]').classList.toggle('active', document.body.classList.contains('pde-focus'));
                    setTimeout(function () { self.applyView(); }, 50);
                    break;
            }
        },
        select: function (id, add) {
            if (id === null) { this.sel = []; }
            else if (add) { var i = this.sel.indexOf(id); if (i >= 0) { this.sel.splice(i, 1); } else { this.sel.push(id); } }
            else { this.sel = [id]; }
            this.renderOverlay();
            this.updateProps();
        },
        selectAll: function () {
            this.sel = this.doc.nodes.map(function (n) { return n.id; }).concat(this.doc.edges.map(function (e) { return e.id; }));
            this.renderOverlay(); this.updateProps();
        },
        insertNode: function (type, pos) {
            if (this.readOnly || !TYPES[type]) { return null; }
            var before = this.snapshot(), t = TYPES[type], x, y;
            if (pos) { x = this.snapV(pos[0]); y = this.snapV(pos[1]); }
            else {
                var r = this.svg.getBoundingClientRect();
                var c = this.toWorld(r.left + r.width / 2, r.top + r.height / 2);
                this.insertCount = (this.insertCount || 0) + 1;
                x = this.snapV(c[0] - t.w / 2 + (this.insertCount % 6) * 20); y = this.snapV(c[1] - t.h / 2 + (this.insertCount % 6) * 20);
            }
            var n = { id: this.uid('n'), type: type, x: x, y: y, w: t.w, h: t.h, text: type === 'text' ? 'Texto' : (type === 'lane' ? 'Raia' : (type === 'note' || type === 'circle' ? '' : t.label)) };
            if (type === 'lane') { this.doc.nodes.unshift(n); } else { this.doc.nodes.push(n); }
            this.sel = [n.id];
            this.render();
            this.pushUndo(before);
            return n;
        },
        deleteSelection: function () {
            if (!this.sel.length) { return; }
            var before = this.snapshot(), sel = this.sel, self = this;
            var removedNodes = {};
            this.doc.nodes = this.doc.nodes.filter(function (n) { if (sel.indexOf(n.id) >= 0) { removedNodes[n.id] = true; return false; } return true; });
            this.doc.edges = this.doc.edges.filter(function (e) { return sel.indexOf(e.id) < 0 && !removedNodes[e.from] && !removedNodes[e.to]; });
            this.sel = [];
            this.render();
            this.pushUndo(before);
        },
        copy: function () {
            var nodes = this.selNodes(), ids = nodes.map(function (n) { return n.id; });
            if (!nodes.length) { return; }
            var edges = this.doc.edges.filter(function (e) { return ids.indexOf(e.from) >= 0 && ids.indexOf(e.to) >= 0; });
            this.clipboard = clone({ nodes: nodes, edges: edges });
            this.pasteCount = 0;
        },
        paste: function () {
            if (!this.clipboard || this.readOnly) { return; }
            var before = this.snapshot(), map = {}, self = this, off = 20 * (++this.pasteCount), newSel = [];
            var data = clone(this.clipboard);
            data.nodes.forEach(function (n) {
                var nid = self.uid('n'); map[n.id] = nid; n.id = nid; n.x += off; n.y += off;
                if (n.type === 'lane') { self.doc.nodes.unshift(n); } else { self.doc.nodes.push(n); }
                newSel.push(nid);
            });
            data.edges.forEach(function (e) { e.id = self.uid('e'); e.from = map[e.from]; e.to = map[e.to]; if (e.points) { e.points.forEach(function (p) { p.x += off; p.y += off; }); } self.doc.edges.push(e); newSel.push(e.id); });
            this.sel = newSel;
            this.render();
            this.pushUndo(before);
        },
        reorder: function (front) {
            var nodes = this.selNodes();
            if (!nodes.length) { return; }
            var before = this.snapshot(), ids = nodes.map(function (n) { return n.id; });
            var lanes = this.doc.nodes.filter(function (n) { return n.type === 'lane'; }), others = this.doc.nodes.filter(function (n) { return n.type !== 'lane'; });
            function move(arr) {
                var selected = arr.filter(function (n) { return ids.indexOf(n.id) >= 0; }), rest = arr.filter(function (n) { return ids.indexOf(n.id) < 0; });
                return front ? rest.concat(selected) : selected.concat(rest);
            }
            this.doc.nodes = move(lanes).concat(move(others));
            this.render();
            this.pushUndo(before);
        },
        align: function (mode) {
            var nodes = this.selNodes();
            if (nodes.length < 2) { return; }
            var before = this.snapshot();
            var minX = Math.min.apply(null, nodes.map(function (n) { return n.x; })), maxX = Math.max.apply(null, nodes.map(function (n) { return n.x + n.w; }));
            var minY = Math.min.apply(null, nodes.map(function (n) { return n.y; })), maxY = Math.max.apply(null, nodes.map(function (n) { return n.y + n.h; }));
            nodes.forEach(function (n) {
                switch (mode) {
                    case 'left': n.x = minX; break;
                    case 'center': n.x = (minX + maxX) / 2 - n.w / 2; break;
                    case 'right': n.x = maxX - n.w; break;
                    case 'top': n.y = minY; break;
                    case 'middle': n.y = (minY + maxY) / 2 - n.h / 2; break;
                    case 'bottom': n.y = maxY - n.h; break;
                }
            });
            this.render();
            this.pushUndo(before);
        },
        distribute: function (dir) {
            var nodes = this.selNodes();
            if (nodes.length < 3) { return; }
            var before = this.snapshot(), h = dir === 'h';
            nodes.sort(function (a, b) { return h ? a.x - b.x : a.y - b.y; });
            var first = nodes[0], last = nodes[nodes.length - 1];
            var total = h ? (last.x + last.w - first.x) : (last.y + last.h - first.y);
            var sizes = nodes.reduce(function (s, n) { return s + (h ? n.w : n.h); }, 0);
            var gap = (total - sizes) / (nodes.length - 1), pos = h ? first.x : first.y;
            nodes.forEach(function (n) { if (h) { n.x = pos; pos += n.w + gap; } else { n.y = pos; pos += n.h + gap; } });
            this.render();
            this.pushUndo(before);
        },
        fitLanes: function (movedIds) {
            var self = this, pad = 12;
            this.doc.nodes.forEach(function (lane) {
                if (lane.type !== 'lane') { return; }
                movedIds.forEach(function (id) {
                    var n = self.node(id);
                    if (!n || n.id === lane.id || n.type === 'lane' || !self.centerInside(n, lane)) { return; }
                    var bandX = lane.w >= lane.h * 1.5 ? 36 : 0, bandY = lane.w >= lane.h * 1.5 ? 0 : 36;
                    if (n.x < lane.x + bandX + pad) { var dx = lane.x + bandX + pad - n.x; lane.x -= dx; lane.w += dx; }
                    if (n.y < lane.y + bandY + pad) { var dy = lane.y + bandY + pad - n.y; lane.y -= dy; lane.h += dy; }
                    if (n.x + n.w + pad > lane.x + lane.w) { lane.w = n.x + n.w + pad - lane.x; }
                    if (n.y + n.h + pad > lane.y + lane.h) { lane.h = n.y + n.h + pad - lane.y; }
                });
            });
        },

        // ---------------------------------------------------------- zoom/pan
        setZoom: function (k, cx, cy) {
            var r = this.svg.getBoundingClientRect();
            if (cx === undefined) { cx = r.width / 2; cy = r.height / 2; }
            k = Math.min(4, Math.max(0.1, k));
            var wx = (cx - this.view.x) / this.view.k, wy = (cy - this.view.y) / this.view.k;
            this.view.k = k;
            this.view.x = cx - wx * k; this.view.y = cy - wy * k;
            this.applyView();
            this.renderOverlay();
        },
        zoomAt: function (factor, cx, cy) { this.setZoom(this.view.k * factor, cx, cy); },
        fit: function (maxK) {
            var r = this.svg.getBoundingClientRect(), bb = Geo.bbox(this.doc) || { x: 0, y: 0, w: this.doc.canvas.w, h: this.doc.canvas.h };
            if (r.width < 10 || r.height < 10) { return; }
            var pad = 40, k = Math.min((r.width - pad) / Math.max(bb.w, 1), (r.height - pad) / Math.max(bb.h, 1));
            k = Math.min(maxK || 4, Math.max(0.1, k));
            this.view.k = k;
            this.view.x = (r.width - bb.w * k) / 2 - bb.x * k;
            this.view.y = (r.height - bb.h * k) / 2 - bb.y * k;
            this.applyView();
            this.renderOverlay();
        },

        // ------------------------------------------------------ edição texto
        startTextEdit: function (id) {
            var n = this.node(id), e = this.edge(id);
            if (!n && !e) { return; }
            if (this.editing) { this.endTextEdit(true); }
            this.editing = { id: id, before: this.snapshot(), isEdge: !!e };
            var ta = this.textarea;
            ta.value = n ? n.text : (e.label || '');
            ta.hidden = false;
            this.positionTextarea();
            ta.focus();
            ta.select();
        },
        positionTextarea: function () {
            if (!this.editing) { return; }
            var ta = this.textarea, k = this.view.k, id = this.editing.id, n = this.node(id), e = this.edge(id), box, fs = 14, st, align = 'center';
            if (n) {
                st = Geo.style(n); fs = st.fontSize; align = st.align;
                if (n.type === 'lane') {
                    box = n.w >= n.h * 1.5 ? [n.x + 40, n.y + 4, Math.min(300, n.w - 48), 36] : [n.x + 4, n.y + 2, n.w - 8, 32];
                } else { var tb = Geo.textBox(n); box = [tb[0], tb[1], tb[2], tb[3]]; }
                if (box[3] < fs * 2.6) { var extra = fs * 2.6 - box[3]; box[1] -= extra / 2; box[3] = fs * 2.6; }
            } else if (e) {
                var from = this.node(e.from), to = this.node(e.to), mp = Geo.midpoint(Geo.edgePoints(e, from, to));
                fs = 12; box = [mp[0] - 70, mp[1] - 16, 140, 32];
            } else { return; }
            ta.style.left = (this.view.x + box[0] * k) + 'px';
            ta.style.top = (this.view.y + box[1] * k) + 'px';
            ta.style.width = Math.max(40, box[2] * k) + 'px';
            ta.style.height = Math.max(24, box[3] * k) + 'px';
            ta.style.fontSize = (fs * k) + 'px';
            ta.style.textAlign = align;
            ta.style.fontWeight = (st && st.bold) ? '600' : '400';
            ta.style.color = st ? st.color : '#212529';
        },
        endTextEdit: function (commit) {
            var ed = this.editing;
            if (!ed) { return; }
            this.editing = null;
            var ta = this.textarea, v = ta.value;
            ta.hidden = true;
            if (commit) {
                var n = this.node(ed.id), e = this.edge(ed.id);
                if (n) { n.text = v.slice(0, 2000); }
                else if (e) { if (v.trim()) { e.label = v.slice(0, 200); } else { delete e.label; } }
                this.render();
                this.pushUndo(ed.before);
                this.changed();
            }
            this.wrap.focus({ preventScroll: true });
        },

        // --------------------------------------------------- salvar/exportar
        save: function (extra) {
            var self = this;
            if (this.readOnly || this.saving || !this.opts.saveUrl) { return Promise.resolve(null); }
            if (this.editing) { this.endTextEdit(true); }
            var title = this.getTitle().trim();
            if (!title) { alert('Informe o título do diagrama.'); return Promise.resolve(null); }
            this.saving = true; this.updateStatus();
            var payload = Object.assign({ _csrf_token: this.opts.csrf, title: title, data: this.toJSON(), note: this.versionNote || '' }, extra || {});
            return fetch(this.opts.saveUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.opts.csrf, 'X-Requested-With': 'fetch' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Resposta inválida do servidor (HTTP ' + r.status + ').' }; }); })
            .then(function (resp) {
                self.saving = false;
                if (resp && resp.ok) {
                    self.savedJson = self.snapshot(); self.savedTitle = self.getTitle();
                    self.version = resp.version || self.version;
                    self.versionNote = ''; if (self.fields && self.fields.note) { self.fields.note.value = ''; }
                    self.changed();
                    if (self.opts.onSaved) { self.opts.onSaved(resp); }
                } else {
                    self.updateStatus();
                    alert('Não foi possível salvar: ' + (resp && resp.error ? resp.error : 'erro desconhecido.'));
                }
                return resp;
            }).catch(function (err) {
                self.saving = false; self.updateStatus();
                alert('Falha de rede ao salvar: ' + err);
                return null;
            });
        },
        fileName: function (ext) {
            var base = (this.opts.fileName || this.getTitle() || 'diagrama').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase() || 'diagrama';
            return base + '.' + ext;
        },
        svgString: function () { return docToSvgString(this.toJSON()); },
        exportSvg: function () {
            download(new Blob([this.svgString()], { type: 'image/svg+xml;charset=utf-8' }), this.fileName('svg'));
        },
        exportPng: function (cb) { svgToPng(this.svgString(), this.fileName('png'), null, cb); },
        print: function () { printSvg(this.svgString(), this.getTitle()); }
    };

    function expandHex(h) { return '#' + h[1] + h[1] + h[2] + h[2] + h[3] + h[3]; }

    global.PlanDiagramEditor = {
        TYPES: TYPES,
        Geo: Geo,
        normalize: normalize,
        init: function (el, opts) { return new Editor(el, opts); },
        renderStatic: function (svg, data, opts) { return renderDoc(svg, normalize(data), Object.assign({ fit: true }, opts || {})); },
        toSvgString: function (data, opts) { return docToSvgString(normalize(data), opts); },
        downloadSvg: function (svgOrString, name) {
            var str = typeof svgOrString === 'string' ? svgOrString : new XMLSerializer().serializeToString(svgOrString);
            download(new Blob([str], { type: 'image/svg+xml;charset=utf-8' }), name || 'diagrama.svg');
        },
        downloadPng: function (svgOrString, name, cb) { svgToPng(svgOrString, name || 'diagrama.png', null, cb); },
        printSvg: printSvg
    };
})(window);
