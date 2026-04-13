#!/usr/bin/env node
/**
 * Server-side KaTeX renderer.
 * Reads HTML from stdin, replaces $...$ and $$...$$ math delimiters
 * with pre-rendered KaTeX HTML, and outputs to stdout.
 *
 * Includes KaTeX compatibility fixes for MathJax-authored content:
 * - \color{X}{Y} -> \textcolor{X}{Y}
 * - \hline removal outside array/tabular
 * - \\[1em] spacing args stripped
 * - \renewcommand{\arraystretch}{...} stripped
 * - \tag{X} -> \qquad (X) in inline mode
 * - \bbox[opts]{X} -> \boxed{X}
 * - bare _ escaped inside \text{} blocks
 * - @{...} stripped from array column specs
 */
const katex = require(__dirname + '/node_modules/katex');

/**
 * Escape bare underscores inside \text{}, \textbf{}, etc.
 */
function _fixTextUnderscores(tex) {
    const re = /\\text(?:bf|it|rm|sf|tt|normal)?\s*\{/g;
    let result = '', i = 0, m;
    while ((m = re.exec(tex)) !== null) {
        const cmd = m[0];
        result += tex.substring(i, m.index);
        let start = m.index + cmd.length, braces = 1, j = start;
        while (j < tex.length && braces > 0) {
            if (tex[j] === '{') braces++;
            else if (tex[j] === '}') braces--;
            if (braces > 0) j++;
        }
        let inner = tex.substring(start, j);
        /* Handle nested $...$ — exit text, insert math, re-enter text */
        const parts = inner.split(/(?<!\\)\$/g);
        if (parts.length >= 3) {
            let rebuilt = '';
            for (let pi = 0; pi < parts.length; pi++) {
                if (pi % 2 === 0) {
                    const t = parts[pi].replace(/(?<!\\)_/g, '\\_').replace(/(?<!\\)#/g, '\\#');
                    if (t.length > 0 || pi === 0) rebuilt += cmd + t + '}';
                } else {
                    rebuilt += parts[pi];
                }
            }
            result += rebuilt;
        } else {
            inner = inner.replace(/(?<!\\)_/g, '\\_').replace(/(?<!\\)#/g, '\\#');
            result += cmd + inner + '}';
        }
        i = j + 1; re.lastIndex = i;
    }
    return result + tex.substring(i);
}

/**
 * Strip @{...} from \begin{array}{...} column specs.
 */
function _fixArrayAtCols(tex) {
    const pat = /\\begin\{array\}\{/g;
    let result = '', lastEnd = 0, m;
    while ((m = pat.exec(tex)) !== null) {
        result += tex.substring(lastEnd, m.index) + m[0];
        let start = m.index + m[0].length, braces = 1, j = start;
        while (j < tex.length && braces > 0) {
            if (tex[j] === '{') braces++;
            else if (tex[j] === '}') braces--;
            if (braces > 0) j++;
        }
        let spec = tex.substring(start, j), cleaned = '', ci = 0;
        while (ci < spec.length) {
            if (spec[ci] === '@' && ci + 1 < spec.length && spec[ci + 1] === '{') {
                let b = 0; ci++;
                while (ci < spec.length) {
                    if (spec[ci] === '{') b++;
                    else if (spec[ci] === '}') { b--; if (b === 0) { ci++; break; } }
                    ci++;
                }
            } else { cleaned += spec[ci]; ci++; }
        }
        result += cleaned.trim() + '}';
        lastEnd = j + 1; pat.lastIndex = lastEnd;
    }
    return result + tex.substring(lastEnd);
}

/**
 * Apply all KaTeX compatibility fixes for MathJax-authored content.
 */
function _fixKatexCompat(tex, isDisplay) {
    tex = _fixTextUnderscores(tex);
    tex = _fixArrayAtCols(tex);
    // \color{X}{Y} -> \textcolor{X}{Y}
    tex = tex.replace(/\\color\s*(\{[^}]*\})\s*\{/g, '\\textcolor$1{');
    // Remove \hline outside array/tabular
    if (!/\\begin\{(array|tabular)\}/.test(tex)) {
        tex = tex.replace(/\\hline\b/g, '');
    }
    // Strip \\[1em] spacing args after line breaks
    tex = tex.replace(/\\\\\s*\[\s*[\d.]+\s*(em|ex|pt|mm|cm|in|pc|mu)\s*\]/g, '\\\\');
    // Strip \renewcommand{\arraystretch}{...}
    tex = tex.replace(/\\renewcommand\s*\{\s*\\arraystretch\s*\}\s*\{[^}]*\}/g, '');
    // \tag{X} -> \qquad (X) in inline mode
    if (!isDisplay) {
        tex = tex.replace(/\\tag\*?\s*\{([^}]*)\}/g, '\\qquad ($1)');
    }
    // \bbox[opts]{X} -> \boxed{X}
    tex = tex.replace(/\\bbox\s*(?:\[[^\]]*\])?\s*\{/g, '\\boxed{');
    return tex;
}

/**
 * Decode HTML entities within LaTeX content (same as site's _texFromHtml).
 * The DB stores content as HTML, so math delimiters may contain &lt; &gt; &amp; etc.
 */
function _texFromHtml(html) {
    let s = html;
    // Convert block-level tags to newlines
    s = s.replace(/<br\s*\/?>/gi, '\n');
    s = s.replace(/<\/p>/gi, '\n');
    s = s.replace(/<p[^>]*>/gi, '');
    s = s.replace(/<\/div>/gi, '\n');
    s = s.replace(/<div[^>]*>/gi, '');
    // Handle common HTML entities
    s = s.replace(/&nbsp;/gi, ' ');
    s = s.replace(/&lt;/gi, '<');
    s = s.replace(/&gt;/gi, '>');
    s = s.replace(/&amp;/gi, '&');
    s = s.replace(/&quot;/gi, '"');
    s = s.replace(/&#(\d+);/g, (m, code) => String.fromCharCode(parseInt(code)));
    s = s.replace(/&#x([0-9a-fA-F]+);/g, (m, code) => String.fromCharCode(parseInt(code, 16)));
    // Remove any remaining HTML tags
    s = s.replace(/<[^>]+>/g, '');
    return s;
}

function renderTex(tex, displayMode) {
    tex = _texFromHtml(tex);
    // Escape bare % (LaTeX comment char) — content from HTML has no intentional comments
    tex = tex.replace(/(?<!\\)%/g, '\\%');
    tex = _fixKatexCompat(tex, displayMode);
    return katex.renderToString(tex, { throwOnError: false, displayMode: displayMode });
}

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { input += chunk; });
process.stdin.on('end', () => {
    let html = input;

    // 1. Handle <script type="math/tex">...</script> tags (legacy MathJax format)
    html = html.replace(/<script[^>]*type=["']math\/tex(?:;\s*mode=display)?["'][^>]*>([\s\S]*?)<\/script>/gi, (match, tex) => {
        const isDisplay = /mode=display/i.test(match);
        try {
            return renderTex(tex.trim(), isDisplay);
        } catch (e) {
            return match;
        }
    });

    // 2. Replace $$...$$ display math first
    html = html.replace(/\$\$([\s\S]+?)\$\$/g, (match, tex) => {
        try {
            return renderTex(tex.trim(), true);
        } catch (e) {
            return match;
        }
    });

    // 3. Replace $...\begin{...}...\end{...}...$ (inline with environments)
    html = html.replace(/\$([^\$]*?\\begin\{[^\$]*?)\$/g, (match, tex) => {
        try {
            return renderTex(tex.trim(), false);
        } catch (e) {
            return match;
        }
    });

    // 4. Replace $...$ inline math (not $$)
    html = html.replace(/(?<!\$)\$(?!\$)(.+?)(?<!\$)\$(?!\$)/g, (match, tex) => {
        try {
            return renderTex(tex.trim(), false);
        } catch (e) {
            return match;
        }
    });

    // 5. Replace \(...\) inline math
    html = html.replace(/\\\((.+?)\\\)/g, (match, tex) => {
        try {
            return renderTex(tex.trim(), false);
        } catch (e) {
            return match;
        }
    });

    // 6. Replace \[...\] display math
    html = html.replace(/\\\[([\s\S]+?)\\\]/g, (match, tex) => {
        try {
            return renderTex(tex.trim(), true);
        } catch (e) {
            return match;
        }
    });

    // 7. Handle bare \begin{env}...\end{env} outside of $ delimiters
    html = html.replace(/\\begin\{([^}]+)\}([\s\S]*?)\\end\{\1\}/g, (match, env, inner) => {
        try {
            const tex = '\\begin{' + env + '}' + _texFromHtml(inner) + '\\end{' + env + '}';
            const fixed = _fixKatexCompat(tex, true);
            return katex.renderToString(fixed, { displayMode: true, throwOnError: false });
        } catch (e) {
            return match;
        }
    });

    process.stdout.write(html);
});
