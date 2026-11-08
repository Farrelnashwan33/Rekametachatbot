import type { Conversation, Message, UserMemory } from './types';

const TOKEN_KEY = 'rekametachat_token';

export function getApiBase(): string {
	const metaEnv = (import.meta as unknown as { env?: Record<string, string | undefined> }).env;
	const configured = metaEnv?.PUBLIC_API_BASE_URL?.trim()?.replace(/\/$/, '') ?? '';

	if (typeof window !== 'undefined') {
		const isLocalVite =
			(window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') &&
			(window.location.port === '5173' || window.location.port === '4173');

		// Route through same-origin Vite /api proxy in local dev to avoid localhost vs 127.0.0.1 CORS/PNA blocks
		if (
			isLocalVite &&
			(!configured ||
				configured === 'http://127.0.0.1:8000' ||
				configured === 'http://localhost:8000')
		) {
			return '';
		}
	}

	return configured || 'http://127.0.0.1:8000';
}

export function getStoredToken(): string | null {
	if (typeof window === 'undefined') return null;
	try {
		return localStorage.getItem(TOKEN_KEY);
	} catch {
		return null;
	}
}

export function setStoredToken(token: string | null): void {
	if (typeof window === 'undefined') return;
	try {
		if (token) {
			localStorage.setItem(TOKEN_KEY, token);
		} else {
			localStorage.removeItem(TOKEN_KEY);
		}
	} catch {
		// Ignore storage restriction errors
	}
}

export class ApiError extends Error {
	status: number;
	errorCode?: string;
	fieldErrors?: Record<string, string[]>;
	payload?: Record<string, unknown>;

	constructor(
		message: string,
		status: number,
		errorCode?: string,
		fieldErrors?: Record<string, string[]>,
		payload?: Record<string, unknown>
	) {
		super(message);
		this.name = 'ApiError';
		this.status = status;
		this.errorCode = errorCode;
		this.fieldErrors = fieldErrors;
		this.payload = payload;
	}
}

function getUnreachableBackendMessage(): string {
	const metaEnv = (import.meta as unknown as { env?: Record<string, string | undefined> }).env;
	const configured = metaEnv?.PUBLIC_API_BASE_URL?.trim() ?? '';
	if (typeof window !== 'undefined' && window.location.protocol === 'https:' && !configured) {
		return 'Backend API produksi belum dihubungkan. Atur Build Variable PUBLIC_API_BASE_URL di Cloudflare ke URL server Laravel Anda.';
	}
	return 'Tidak dapat terhubung ke server API (port 8000). Pastikan backend Laravel sedang berjalan.';
}

export async function apiFetch<T = Record<string, unknown>>(
	path: string,
	options: RequestInit = {}
): Promise<T> {
	const token = getStoredToken();
	const headers = new Headers(options.headers ?? {});
	headers.set('Accept', 'application/json');

	if (!(options.body instanceof FormData) && !headers.has('Content-Type')) {
		headers.set('Content-Type', 'application/json');
	}

	if (token) {
		headers.set('Authorization', `Bearer ${token}`);
	}

	let response: Response;
	try {
		response = await fetch(`${getApiBase()}${path}`, {
			...options,
			headers
		});
	} catch {
		throw new ApiError(
			getUnreachableBackendMessage(),
			503,
			'BACKEND_UNREACHABLE'
		);
	}

	let data: Record<string, unknown> = {};
	const contentType = response.headers.get('content-type') ?? '';
	if (contentType.includes('application/json')) {
		data = (await response.json().catch(() => ({}))) as Record<string, unknown>;
	}

	if (!response.ok) {
		const fallbackMsg =
			response.status === 502 || response.status === 504
				? 'Server API backend (port 8000) belum merespons. Pastikan php artisan serve sedang berjalan.'
				: `Permintaan gagal dengan status HTTP ${response.status}.`;
		const msg = (typeof data.message === 'string' && data.message) || fallbackMsg;
		throw new ApiError(
			msg,
			response.status,
			typeof data.error_code === 'string' ? data.error_code : undefined,
			typeof data.errors === 'object' && data.errors !== null
				? (data.errors as Record<string, string[]>)
				: undefined,
			data
		);
	}

	return data as T;
}

export async function apiDownloadBlob(path: string): Promise<{ blob: Blob; filename: string }> {
	const token = getStoredToken();
	const headers = new Headers();
	if (token) {
		headers.set('Authorization', `Bearer ${token}`);
	}

	let response: Response;
	try {
		response = await fetch(`${getApiBase()}${path}`, { headers });
	} catch {
		throw new ApiError(
			getUnreachableBackendMessage(),
			503,
			'BACKEND_UNREACHABLE'
		);
	}

	if (!response.ok) {
		let errText = 'Gagal mengunduh file.';
		try {
			const json = await response.json();
			if (json?.message) errText = json.message;
		} catch {
			// Fallback message
		}
		throw new ApiError(errText, response.status);
	}

	const disposition = response.headers.get('content-disposition') ?? '';
	const match = disposition.match(/filename="?([^"]+)"?/i);
	const filename = match?.[1] ?? 'dokumen-rekametachat.pdf';
	const blob = await response.blob();

	return { blob, filename };
}

export interface StreamHandlers {
	onMeta?: (payload: {
		conversation: Conversation;
		user_message: Message;
		memory_suggestions: UserMemory[];
	}) => void;
	onDelta?: (deltaText: string) => void;
	onDone?: (payload: { assistant_message: Message; conversation: Conversation }) => void;
	onError?: (err: { message: string; error_code?: string }) => void;
}

export async function streamChatEndpoint(
	path: string,
	method: 'POST' | 'PUT',
	body: Record<string, unknown>,
	handlers: StreamHandlers,
	signal?: AbortSignal
): Promise<void> {
	const token = getStoredToken();
	const headers = new Headers({
		'Content-Type': 'application/json',
		Accept: 'text/event-stream, application/json'
	});
	if (token) {
		headers.set('Authorization', `Bearer ${token}`);
	}

	let response: Response;
	try {
		response = await fetch(`${getApiBase()}${path}`, {
			method,
			headers,
			body: JSON.stringify({ ...body, stream: true }),
			signal
		});
	} catch (err) {
		if ((err as Error)?.name === 'AbortError') {
			throw err;
		}
		throw new ApiError(
			getUnreachableBackendMessage(),
			503,
			'BACKEND_UNREACHABLE'
		);
	}

	const contentType = response.headers.get('content-type') ?? '';
	if (!response.ok || contentType.includes('application/json')) {
		const json = (await response.json().catch(() => ({}))) as Record<string, unknown>;
		if (!response.ok) {
			throw new ApiError(
				(typeof json.message === 'string' && json.message) ||
					'Layanan AI tidak dapat memproses pesan saat ini.',
				response.status,
				typeof json.error_code === 'string' ? json.error_code : undefined,
				undefined,
				json
			);
		}
		if (json.conversation && json.user_message) {
			handlers.onMeta?.({
				conversation: json.conversation as Conversation,
				user_message: json.user_message as Message,
				memory_suggestions: (json.memory_suggestions as UserMemory[]) ?? []
			});
		}
		if (json.assistant_message && json.conversation) {
			handlers.onDone?.({
				assistant_message: json.assistant_message as Message,
				conversation: json.conversation as Conversation
			});
		}
		return;
	}

	if (!response.body) {
		throw new ApiError('Browser tidak mendukung pembacaan stream respons.', 500);
	}

	const reader = response.body.getReader();
	const decoder = new TextDecoder();
	let buffer = '';

	while (true) {
		const { value, done } = await reader.read();
		if (done) break;
		buffer += decoder.decode(value, { stream: true });

		let boundary = buffer.indexOf('\n\n');
		while (boundary !== -1) {
			const rawBlock = buffer.slice(0, boundary);
			buffer = buffer.slice(boundary + 2);
			boundary = buffer.indexOf('\n\n');

			let eventName = 'message';
			let dataStr = '';
			for (const line of rawBlock.split('\n')) {
				const trimmed = line.trim();
				if (trimmed.startsWith('event:')) {
					eventName = trimmed.slice(6).trim();
				} else if (trimmed.startsWith('data:')) {
					dataStr += trimmed.slice(5).trim();
				}
			}

			if (!dataStr) continue;
			try {
				const parsed = JSON.parse(dataStr);
				if (eventName === 'meta') {
					handlers.onMeta?.(parsed);
				} else if (eventName === 'delta') {
					handlers.onDelta?.(parsed.content ?? '');
				} else if (eventName === 'done') {
					handlers.onDone?.(parsed);
				} else if (eventName === 'error') {
					handlers.onError?.(parsed);
				}
			} catch {
				// Ignore malformed partial frame
			}
		}
	}
}
