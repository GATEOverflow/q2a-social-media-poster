#!/usr/bin/env node
/**
 * Server-side KaTeX renderer.
 * Reads HTML from stdin, replaces $...$ and $$...$$ math delimiters
 * with pre-rendered KaTeX HTML, and outputs to stdout.
 */
const katex = require(__dirname + '/node_modules/katex');

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { input += chunk; });
process.stdin.on('end', () => {
    let html = input;

    // Replace $$...$$ display math first
    html = html.replace(/\$\$([\s\S]+?)\$\$/g, (match, tex) => {
        try {
            return katex.renderToString(tex.trim(), { throwOnError: false, displayMode: true });
        } catch (e) {
            return match;
        }
    });

    // Replace $...$ inline math (not $$)
    html = html.replace(/(?<!\$)\$(?!\$)(.+?)(?<!\$)\$(?!\$)/g, (match, tex) => {
        try {
            return katex.renderToString(tex.trim(), { throwOnError: false, displayMode: false });
        } catch (e) {
            return match;
        }
    });

    // Replace \(...\) inline math
    html = html.replace(/\\\((.+?)\\\)/g, (match, tex) => {
        try {
            return katex.renderToString(tex.trim(), { throwOnError: false, displayMode: false });
        } catch (e) {
            return match;
        }
    });

    // Replace \[...\] display math
    html = html.replace(/\\\[([\s\S]+?)\\\]/g, (match, tex) => {
        try {
            return katex.renderToString(tex.trim(), { throwOnError: false, displayMode: true });
        } catch (e) {
            return match;
        }
    });

    process.stdout.write(html);
});
