import hljs from 'highlight.js/lib/common';
import { Marked } from 'marked';

const markedInstance = new Marked({
	gfm: true,
	breaks: true
});

function escapeHtml(raw: string): string {
	return raw
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;');
}

markedInstance.use({
	renderer: {
		code({ text, lang }) {
			const cleanLang = (lang ?? '').trim().split(/\s+/)[0] ?? '';
			let highlighted = '';
			if (cleanLang && hljs.getLanguage(cleanLang)) {
				highlighted = hljs.highlight(text, { language: cleanLang }).value;
			} else {
				highlighted = escapeHtml(text);
			}
			const langLabel = cleanLang ? escapeHtml(cleanLang) : 'kode';
			return `<pre><code class="hljs language-${langLabel}">${highlighted}</code></pre>`;
		}
	}
});

const SAFE_ELEMENTS = [
	'p',
	'br',
	'strong',
	'b',
	'em',
	'i',
	'u',
	's',
	'del',
	'code',
	'pre',
	'span',
	'blockquote',
	'ul',
	'ol',
	'li',
	'h1',
	'h2',
	'h3',
	'h4',
	'h5',
	'h6',
	'hr',
	'table',
	'thead',
	'tbody',
	'tr',
	'th',
	'td',
	'a'
];

const SAFE_ATTRIBUTES = ['class', 'href', 'target', 'rel', 'scope'];

let domPurifyPromise: Promise<{ sanitize: (html: string, cfg: Record<string, unknown>) => string }> | null =
	null;

function loadDOMPurify() {
	domPurifyPromise ??= import('dompurify').then((mod) => (mod.default ?? mod) as never);
	return domPurifyPromise;
}

export function renderMarkdownToRawHtml(markdown: string): string {
	const parsed = markedInstance.parse(markdown ?? '');
	return typeof parsed === 'string' ? parsed : '';
}

/**
 * Safely sanitize and insert HTML using native Element.prototype.setHTML (Sanitizer API)
 * with lazy DOMPurify fallback matching native Sanitizer behavior (modern-web-guidance sanitize-untrusted-html).
 */
export async function safeSetMarkdownHTML(el: HTMLElement, markdown: string): Promise<void> {
	const rawHtml = renderMarkdownToRawHtml(markdown);

	const proto = Element.prototype as unknown as {
		setHTML?: (html: string, opts?: unknown) => void;
	};

	if ('setHTML' in Element.prototype && typeof proto.setHTML === 'function') {
		try {
			proto.setHTML.call(el, rawHtml, {
				sanitizer: {
					elements: SAFE_ELEMENTS,
					attributes: SAFE_ATTRIBUTES
				}
			});
			enhanceLinksAndCodeBlocks(el);
			return;
		} catch {
			// Fall back to DOMPurify if browser experimental Sanitizer signature differs
		}
	}

	const DOMPurify = await loadDOMPurify();
	el.innerHTML = DOMPurify.sanitize(rawHtml, {
		ALLOWED_TAGS: [...SAFE_ELEMENTS, '#text'],
		ALLOWED_ATTR: SAFE_ATTRIBUTES,
		ALLOW_ARIA_ATTR: false,
		ALLOW_DATA_ATTR: false,
		KEEP_CONTENT: false
	});

	enhanceLinksAndCodeBlocks(el);
}

function enhanceLinksAndCodeBlocks(container: HTMLElement): void {
	for (const anchor of container.querySelectorAll('a')) {
		const href = anchor.getAttribute('href') ?? '';
		if (!/^https?:\/\/|^mailto:/i.test(href)) {
			anchor.removeAttribute('href');
		} else {
			anchor.setAttribute('target', '_blank');
			anchor.setAttribute('rel', 'noopener noreferrer');
		}
	}

	for (const pre of container.querySelectorAll('pre')) {
		if (pre.parentElement?.classList.contains('code-block-shell')) continue;

		const wrapper = document.createElement('div');
		wrapper.className = 'code-block-shell relative group my-3';

		const toolbar = document.createElement('div');
		toolbar.className =
			'flex items-center justify-between px-3 py-1.5 text-xs rounded-t-[10px] border border-b-0 border-[#243256] bg-[#11182e] text-[#cbd5e1]';

		const codeEl = pre.querySelector('code');
		const langClass = Array.from(codeEl?.classList ?? []).find((c) => c.startsWith('language-'));
		const langText = langClass ? langClass.replace('language-', '') : 'kode';

		const langSpan = document.createElement('span');
		langSpan.className = 'font-mono text-[11px]';
		langSpan.textContent = langText;

		const copyBtn = document.createElement('button');
		copyBtn.type = 'button';
		copyBtn.className =
			'px-2 py-0.5 rounded text-[11px] font-medium bg-[#18223f] hover:bg-[#2563eb] text-[#f8fafc] transition-colors cursor-pointer';
		copyBtn.textContent = 'Salin kode';
		copyBtn.addEventListener('click', async () => {
			try {
				await navigator.clipboard.writeText(codeEl?.textContent ?? pre.textContent ?? '');
				copyBtn.textContent = 'Tersalin';
				setTimeout(() => {
					copyBtn.textContent = 'Salin kode';
				}, 1800);
			} catch {
				copyBtn.textContent = 'Gagal menyalin';
			}
		});

		toolbar.append(langSpan, copyBtn);
		pre.style.marginTop = '0';
		pre.style.borderTopLeftRadius = '0';
		pre.style.borderTopRightRadius = '0';

		pre.parentNode?.insertBefore(wrapper, pre);
		wrapper.append(toolbar, pre);
	}
}

export function markdownAction(node: HTMLElement, content: string) {
	void safeSetMarkdownHTML(node, content);

	return {
		update(newContent: string) {
			void safeSetMarkdownHTML(node, newContent);
		}
	};
}
