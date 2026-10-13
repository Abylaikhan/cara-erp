// Static inventory of the legacy HTML: DOM structure (parse5) and inline JavaScript AST (acorn).
// Never executes the analysed code. Runs in a container without network and with read-only mounts.
// Usage: node legacy_inventory.mjs <file.html>  → JSON on stdout: { inventory, redaction_values }
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import * as acorn from 'acorn';
import * as walk from 'acorn-walk';
import { parse } from 'parse5';

const TOOL_VERSION = '1.0.0';
const DB_METHODS = new Set(['sbFetchAllPages', 'sbFetch', 'sbInsertMany', 'sbInsert', 'sbPost', 'sbUpsert', 'sbPatch', 'sbDelete']);
const VOID_HANDLER_NAMES = new Set(['if', 'for', 'while', 'switch', 'return', 'function', 'typeof', 'catch', 'alert', 'confirm', 'prompt']);
const SECRET_NAME = /^(_?pwd|pwds|passwords?|pass|secret|token|api_?key|apikey|anon_?key|service_?key|supabase_?key|sb_?key)$/i;
const SECRET_VALUE = [/^eyJ[\w-]{10,}\.[\w-]{10,}\.[\w-]{10,}$/, /AIza[\w-]{30,}/, /\bsk-[\w-]{20,}/, /\bsb_(?:publishable|secret)_[\w-]{10,}/];

const file = process.argv[2];
if (!file) {
    console.error('usage: node legacy_inventory.mjs <file.html>');
    process.exit(64);
}

const raw = readFileSync(file);
const sha256 = (data) => createHash('sha256').update(data).digest('hex');
let text = raw.toString('utf8');
if (text.charCodeAt(0) === 0xfeff) text = text.slice(1);
text = text.replace(/\r\n?/g, '\n'); // CRLF → LF keeps line numbers; offsets below refer to this text

const lineStarts = [0];
for (let i = 0; i < text.length; i++) if (text.charCodeAt(i) === 10) lineStarts.push(i + 1);
function lineOf(offset) {
    let lo = 0, hi = lineStarts.length - 1;
    while (lo < hi) {
        const mid = (lo + hi + 1) >> 1;
        if (lineStarts[mid] <= offset) lo = mid; else hi = mid - 1;
    }
    return lo + 1;
}
const collapse = (s) => s.replace(/\s+/g, ' ').trim();
const clip = (s, n) => Array.from(s).slice(0, n).join(''); // by code points: never split a surrogate pair
const shortHash = (s) => sha256(s).slice(0, 16);

const redaction = new Set();
function considerSecret(name, value) {
    if (typeof value !== 'string' || value.length < 4) return;
    if ((name && SECRET_NAME.test(name)) || SECRET_VALUE.some((re) => re.test(value))) redaction.add(value);
}

function maskUrl(url) {
    try {
        const u = new URL(url, 'https://relative.invalid/');
        if (u.protocol === 'data:' || u.protocol === 'blob:') return null;
        let host = u.host;
        if (/supabase\.(co|in)$/.test(host)) host = '*.' + host.split('.').slice(-2).join('.');
        return u.host === 'relative.invalid' ? u.pathname : `${u.protocol}//${host}${u.pathname}`;
    } catch {
        return null;
    }
}

// ---------------------------------------------------------------- HTML
const document = parse(text, { sourceCodeLocationInfo: true });
const pages = [], modals = [], controls = [], scripts = [], resources = [];
const tables = { count: 0, by_group: {} };
const controlSeen = new Map();
const attr = (node, name) => node.attrs?.find((a) => a.name === name)?.value;
const classes = (node) => (attr(node, 'class') || '').split(/\s+/).filter(Boolean);

function textContent(node) {
    if (node.nodeName === '#text') return node.value;
    if (node.nodeName === 'script' || node.nodeName === 'style') return '';
    return (node.childNodes || []).map(textContent).join(' ');
}

function handlerCalls(code) {
    const names = new Set();
    try {
        const ast = acorn.parse(code, { ecmaVersion: 'latest', allowReturnOutsideFunction: true });
        walk.simple(ast, {
            CallExpression(n) {
                if (n.callee.type === 'Identifier') names.add(n.callee.name);
                else if (n.callee.type === 'MemberExpression' && !n.callee.computed && n.callee.property.type === 'Identifier') names.add(n.callee.property.name);
            },
        });
    } catch {
        for (const m of code.matchAll(/([A-Za-z_$][\w$]*)\s*\(/g)) names.add(m[1]);
    }
    return [...names].filter((n) => !VOID_HANDLER_NAMES.has(n)).sort();
}

function visit(node, group) {
    if (node.nodeName === '#text' || node.nodeName === '#comment' || node.nodeName === '#documentType') return;
    const loc = node.sourceCodeLocation;
    const tag = node.tagName;
    if (tag) {
        const id = attr(node, 'id') || '';
        const cl = classes(node);
        const isPage = cl.includes('page');
        const isModal = cl.includes('modal-overlay') || id.startsWith('modal-');
        if ((isPage || isModal) && loc) {
            const rec = {
                id,
                line: loc.startLine,
                end_line: loc.endLine ?? loc.startLine,
                hash: shortHash(collapse(text.slice(loc.startOffset, loc.endOffset ?? loc.startOffset))),
                text_hash: shortHash(collapse(textContent(node))),
            };
            (isPage ? pages : modals).push(rec);
            group = id || group;
        }
        const handlers = (node.attrs || []).filter((a) => a.name.startsWith('on'));
        if (['button', 'input', 'select', 'textarea', 'th', 'option'].includes(tag) || handlers.length || cl.includes('tab')) {
            const name = attr(node, 'name') || '';
            const label = clip(collapse(textContent(node)), 120);
            const base = [group, tag, id || name || label].join('|');
            const n = (controlSeen.get(base) || 0) + 1;
            controlSeen.set(base, n);
            controls.push({
                key: `${base}#${n}`,
                tag,
                group,
                line: loc?.startLine ?? null,
                id,
                name,
                type: attr(node, 'type') || '',
                text: label,
                handlers: handlers.map((h) => h.name).sort(),
                calls: [...new Set(handlers.flatMap((h) => handlerCalls(h.value)))].sort(),
            });
        }
        if (tag === 'table') {
            tables.count++;
            tables.by_group[group] = (tables.by_group[group] || 0) + 1;
        }
        for (const [t, a] of [['script', 'src'], ['link', 'href'], ['iframe', 'src'], ['img', 'src']]) {
            const v = tag === t ? attr(node, a) : undefined;
            const masked = v ? maskUrl(v) : null;
            if (masked) resources.push({ tag, attr: a, url: masked });
        }
        if (tag === 'script' && !attr(node, 'src')) {
            const content = node.childNodes?.[0];
            const type = (attr(node, 'type') || 'text/javascript').toLowerCase();
            const start = content?.sourceCodeLocation?.startOffset ?? loc?.startTag?.endOffset ?? 0;
            scripts.push({ index: scripts.length + 1, type, start, code: content?.value || '' });
        }
    }
    const children = node.content ? node.content.childNodes : node.childNodes;
    for (const child of children || []) visit(child, group);
}
visit(document, 'global');

// ---------------------------------------------------------------- JavaScript
const JS_TYPES = new Set(['text/javascript', 'application/javascript', 'module', 'text/babel']);
const functions = [], dbItems = [], storage = [], network = [];
let dynamicDbCalls = 0;
const scriptReports = [];

function tokenHash(code) {
    const parts = [];
    try {
        for (const tok of acorn.tokenizer(code, { ecmaVersion: 'latest', allowReturnOutsideFunction: true, allowHashBang: true })) {
            parts.push(tok.type.label + ':' + (tok.value === undefined ? '' : String(tok.value)));
        }
    } catch {
        return shortHash(collapse(code));
    }
    return shortHash(parts.join('\u0001'));
}

function literalString(node) {
    if (!node) return null;
    if (node.type === 'Literal' && typeof node.value === 'string') return node.value;
    if (node.type === 'TemplateLiteral' && node.expressions.length === 0) return node.quasis[0].value.cooked;
    return null;
}

function enclosingFunction(ancestors) {
    for (let i = ancestors.length - 2; i >= 0; i--) {
        const a = ancestors[i];
        if (a.type === 'FunctionDeclaration' && a.id) return a.id.name;
        if ((a.type === 'FunctionExpression' || a.type === 'ArrowFunctionExpression')) {
            const parent = ancestors[i - 1];
            if (parent?.type === 'VariableDeclarator' && parent.id.type === 'Identifier') return parent.id.name;
            if (parent?.type === 'Property' && parent.key.type === 'Identifier') return parent.key.name;
            if (parent?.type === 'AssignmentExpression' && parent.left.type === 'MemberExpression' && !parent.left.computed) return parent.left.property.name;
        }
    }
    return null;
}

for (const s of scripts) {
    const report = { index: s.index, type: s.type, line: lineOf(s.start), end_line: lineOf(s.start + s.code.length), bytes: Buffer.byteLength(s.code), ok: null };
    scriptReports.push(report);
    if (!JS_TYPES.has(s.type) || !s.code.trim()) {
        report.ok = null;
        report.skipped = JS_TYPES.has(s.type) ? 'empty' : 'non-javascript type';
        continue;
    }
    let ast;
    try {
        ast = acorn.parse(s.code, { ecmaVersion: 'latest', sourceType: s.type === 'module' ? 'module' : 'script', allowHashBang: true });
        report.ok = true;
    } catch (e) {
        report.ok = false;
        report.error = { message: String(e.message).replace(/\s*\(\d+:\d+\)$/, ''), line: lineOf(s.start + (e.pos ?? 0)) };
        continue;
    }
    const abs = (n) => s.start + n;
    const fnRecord = (name, kind, node, extra = {}) => {
        const calls = new Set();
        walk.simple(node.body, {
            CallExpression(c) {
                if (c.callee.type === 'Identifier') calls.add(c.callee.name);
            },
        });
        functions.push({
            name,
            kind,
            script: s.index,
            line: lineOf(abs(node.start)),
            end_line: lineOf(abs(node.end)),
            hash: tokenHash(s.code.slice(node.start, node.end)),
            async: !!node.async,
            calls: [...calls].sort(),
            ...extra,
        });
    };
    walk.ancestor(ast, {
        FunctionDeclaration(node) {
            if (node.id) fnRecord(node.id.name, 'declaration', node);
        },
        VariableDeclarator(node, _st, ancestors) {
            const init = node.init;
            if (node.id.type === 'Identifier' && init && (init.type === 'FunctionExpression' || init.type === 'ArrowFunctionExpression') && ancestors.length <= 4) {
                fnRecord(node.id.name, 'variable', init);
            }
            if (node.id.type === 'Identifier') considerSecret(node.id.name, literalString(init));
            if (node.id.type === 'Identifier' && init?.type === 'ObjectExpression' && SECRET_NAME.test(node.id.name)) {
                for (const p of init.properties) if (p.type === 'Property') considerSecret('password', literalString(p.value));
            }
        },
        AssignmentExpression(node) {
            const l = node.left, r = node.right;
            if (l.type === 'MemberExpression' && !l.computed && l.object.type === 'Identifier' && l.object.name === 'window' && (r.type === 'FunctionExpression' || r.type === 'ArrowFunctionExpression')) {
                fnRecord('window.' + l.property.name, 'window-assignment', r);
            }
        },
        Property(node) {
            const key = node.key.type === 'Identifier' ? node.key.name : literalString(node.key);
            considerSecret(key, literalString(node.value));
        },
        Literal(node) {
            if (typeof node.value === 'string') considerSecret(null, node.value);
        },
        CallExpression(node, _st, ancestors) {
            const callee = node.callee;
            const name = callee.type === 'Identifier' ? callee.name : null;
            if (name && DB_METHODS.has(name)) {
                const table = literalString(node.arguments[0]);
                if (table === null) dynamicDbCalls++;
                else dbItems.push({ method: name, table, line: lineOf(abs(node.start)), function: enclosingFunction(ancestors) });
            }
            if (name === 'fetch') {
                const target = literalString(node.arguments[0]) ?? (node.arguments[0]?.type === 'TemplateLiteral' ? node.arguments[0].quasis[0].value.cooked : null);
                network.push({ callee: 'fetch', target: target ? maskUrl(target) : null, line: lineOf(abs(node.start)), function: enclosingFunction(ancestors) });
            }
            if (callee.type === 'MemberExpression' && !callee.computed && callee.object.type === 'Identifier' && ['localStorage', 'sessionStorage'].includes(callee.object.name)) {
                const method = callee.property.name;
                if (['getItem', 'setItem', 'removeItem'].includes(method)) {
                    storage.push({ api: callee.object.name, method, key: literalString(node.arguments[0]), line: lineOf(abs(node.start)) });
                }
            }
        },
    });
}

// Stable keys for functions: name + occurrence number (duplicates keep source order).
const fnSeen = new Map();
functions.sort((a, b) => a.line - b.line || a.name.localeCompare(b.name));
for (const f of functions) {
    const n = (fnSeen.get(f.name) || 0) + 1;
    fnSeen.set(f.name, n);
    f.key = `${f.name}#${n}`;
}
const declarations = functions.filter((f) => f.kind === 'declaration');
const duplicates = Object.fromEntries([...fnSeen].filter(([, n]) => n > 1).sort());
const byTable = {};
for (const c of dbItems) {
    byTable[c.table] ??= {};
    byTable[c.table][c.method] = (byTable[c.table][c.method] || 0) + 1;
}
const regexDeclarations = [...text.matchAll(/\b(?:async\s+)?function\s+([\w$]+)\s*\(/g)].length;

const inventory = {
    schema: 'promtech.legacy-inventory/1',
    tool: { name: 'legacy_inventory', version: TOOL_VERSION, node: process.version, acorn: acorn.version },
    source: { bytes: raw.length, lines: lineStarts.length - (text.endsWith('\n') ? 1 : 0), sha256: sha256(raw) },
    counts: {
        pages: pages.length,
        modals: modals.length,
        controls: controls.length,
        html_tables: tables.count,
        scripts: scripts.length,
        scripts_failed: scriptReports.filter((r) => r.ok === false).length,
        function_declarations: declarations.length,
        function_declarations_regex: regexDeclarations,
        functions_all: functions.length,
        db_tables: Object.keys(byTable).length,
        db_calls: dbItems.length,
        db_calls_dynamic: dynamicDbCalls,
        storage_calls: storage.length,
    },
    scripts: scriptReports,
    pages,
    modals,
    controls,
    html_tables: tables,
    functions,
    duplicate_function_names: duplicates,
    db_calls: { by_table: Object.fromEntries(Object.entries(byTable).sort()), dynamic: dynamicDbCalls, items: dbItems },
    storage,
    network,
    resources: [...new Map(resources.map((r) => [r.tag + ' ' + r.url, r])).values()].sort((a, b) => a.url.localeCompare(b.url)),
};

process.stdout.write(JSON.stringify({ inventory, redaction_values: [...redaction].sort() }));
