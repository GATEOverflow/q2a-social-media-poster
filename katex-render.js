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
        result += tex.substring(i, m.index) + m[0];
        let start = m.index + m[0].length, braces = 1, j = start;
        while (j < tex.length && braces > 0) {
            if (tex[j] === '{') braces++;
            else if (tex[j] === '}') braces--;
            if (braces > 0) j++;
        }
        result += tex.substring(start, j).replace(/(?<!\\)_/g, '\\_') + '}';
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

function renderTex(tex, displayMode) {
    tex = _fixKatexCompat(tex, displayMode);
    return katex.renderToString(tex, { throwOnError: false, displayMode: displayMode });
}

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { input += chunk; });
process.stdin.on('end', () => {
    let html = input;

    // Replace $$...$$ display math first
    html = html.replace(/\$\$([\s\S]+?)\$\$/g, (match, tex) => {
        try {
            return renderTex(tex.trim(), true);
        } catch (e) {
            return match;
        }
    });

    // Replace $...$ inline math (not $$)
    html = html.replace(/(?<!\$)\$(?!\$)(.+?)(?<!\$)\$(?!\$)/g, (match, tex) => {
        try {
            return renderTex(tex.trim(), false);
        } catch (e) {
            return match;
        }
    });

    // Replace \(...\) inline math
    html = html.replace(/\\\((.+?)\\\)/g, (match, tex) => {
        try {
            return renderTex(tex.trim(), false);
        } catch (e) {
            return match;
        }
    });

    // Replace \[...\] display math
    html = html.replace(/\\\[([\s\S]+?)\\\]/g, (match, tex) => {
        try {
            return renderTex(tex.trim(), true);
        } catch (e) {
            return match;
        }
    });

    process.stdout.write(html);
});
