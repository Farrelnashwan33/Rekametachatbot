import { goto } from '$app/navigation';
import { apiFetch, getStoredToken, setStoredToken } from './api';
import { themeManager } from './theme.svelte';
import type { AiStatus, AssistantMode, Conversation, User } from './types';

class AppState {
	user = $state<User | null>(null);
	aiStatus = $state<AiStatus>({ ai_ready: false, providers: [] });
	conversations = $state<Conversation[]>([]);
	authChecked = $state(false);
	loadingConversations = $state(false);

	// Pending prompt when launching from Explore AI into Smart Chat
	pendingStarter = $state<{ mode: AssistantMode; prompt: string; documentIds?: number[] } | null>(
		null
	);

	// Centralized ARIA polite announcement & visual toast
	politeAnnouncement = $state('');
	toast = $state<{ message: string; kind: 'info' | 'success' | 'error' } | null>(null);
	private toastTimer: ReturnType<typeof setTimeout> | null = null;

	notify(message: string, kind: 'info' | 'success' | 'error' = 'info'): void {
		this.politeAnnouncement = message;
		this.toast = { message, kind };
		if (this.toastTimer) clearTimeout(this.toastTimer);
		this.toastTimer = setTimeout(() => {
			this.toast = null;
		}, 4000);
	}

	async bootstrap(): Promise<void> {
		themeManager.init();
		const token = getStoredToken();
		if (!token) {
			this.user = null;
			this.authChecked = true;
			return;
		}

		try {
			const res = await apiFetch<{ user: User; ai_status: AiStatus }>('/api/auth/me');
			this.user = res.user;
			this.aiStatus = res.ai_status;
			if (res.user.preference?.theme) {
				themeManager.setMode(res.user.preference.theme);
			}
			await this.refreshConversations();
		} catch {
			setStoredToken(null);
			this.user = null;
		} finally {
			this.authChecked = true;
		}
	}

	async refreshAiStatus(): Promise<void> {
		try {
			this.aiStatus = await apiFetch<AiStatus>('/api/ai/status');
		} catch {
			// Ignore transient status fetch failure
		}
	}

	async refreshConversations(search = ''): Promise<void> {
		if (!this.user) return;
		this.loadingConversations = true;
		try {
			const q = search.trim() ? `?q=${encodeURIComponent(search.trim())}` : '';
			const res = await apiFetch<{ conversations: Conversation[] }>(`/api/conversations${q}`);
			this.conversations = res.conversations;
		} catch {
			// Handled locally where needed
		} finally {
			this.loadingConversations = false;
		}
	}

	upsertConversation(conv: Conversation): void {
		const idx = this.conversations.findIndex((c) => c.uuid === conv.uuid);
		if (idx >= 0) {
			this.conversations[idx] = conv;
		} else {
			this.conversations = [conv, ...this.conversations];
		}
		this.conversations = [...this.conversations].sort((a, b) => {
			if (a.is_pinned !== b.is_pinned) return a.is_pinned ? -1 : 1;
			const ta = new Date(a.last_message_at ?? a.updated_at).getTime();
			const tb = new Date(b.last_message_at ?? b.updated_at).getTime();
			return tb - ta;
		});
	}

	removeConversation(uuid: string): void {
		this.conversations = this.conversations.filter((c) => c.uuid !== uuid);
	}

	async logout(): Promise<void> {
		try {
			await apiFetch('/api/auth/logout', { method: 'POST' });
		} catch {
			// Proceed with local cleanup even if token already expired
		}
		setStoredToken(null);
		this.user = null;
		this.conversations = [];
		await goto('/login');
	}
}

export const appState = new AppState();

