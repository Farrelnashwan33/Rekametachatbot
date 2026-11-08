<script lang="ts">
	import { goto } from '$app/navigation';
	import { ApiError, apiFetch, streamChatEndpoint } from '$lib/api';
	import { markdownAction } from '$lib/markdown';
	import { appState } from '$lib/state.svelte';
	import type {
		AssistantMode,
		Conversation,
		DocumentItem,
		Message,
		UserMemory
	} from '$lib/types';
	import { onMount, tick } from 'svelte';
	import Icon from './Icon.svelte';
	import ModalDialog from './ModalDialog.svelte';

	interface Props {
		conversationUuid?: string | null;
	}

	let { conversationUuid = null }: Props = $props();

	let conversation = $state<Conversation | null>(null);
	let messages = $state<Message[]>([]);
	let attachedDocuments = $state<DocumentItem[]>([]);
	let pendingMemories = $state<UserMemory[]>([]);

	let selectedMode = $state<AssistantMode>('general');
	let selectedProvider = $state<string>('');
	let selectedModel = $state<string>('');

	let promptInput = $state('');
	let loadingThread = $state(false);
	let threadError = $state<string | null>(null);
	let chatError = $state<{ message: string; code?: string } | null>(null);

	let isStreaming = $state(false);
	let streamingText = $state('');
	let abortController: AbortController | null = null;

	// Message editing state
	let editingMessageUuid = $state<string | null>(null);
	let editingDraft = $state('');

	// Document attachment modal state
	let attachModalOpen = $state(false);
	let vaultDocuments = $state<DocumentItem[]>([]);
	let uploadingDoc = $state(false);
	let selectedVaultIds = $state<number[]>([]);

	// Rename conversation inline state
	let renamingTitle = $state(false);
	let titleDraft = $state('');

	// Voice recognition state (Web Speech API)
	let speechSupported = $state(false);
	let isListening = $state(false);
	let recognitionInstance: { start: () => void; stop: () => void } | null = null;

	let threadContainer: HTMLElement | undefined = $state();

	const modeLabels: Record<AssistantMode, string> = {
		general: 'Smart Chat',
		academic: 'Academic Assistant',
		coding: 'Software & Debugging',
		document: 'Document Q&A',
		cv: 'CV Consultant',
		creative: 'Creative & Official Writing'
	};

	const starterCards: {
		mode: AssistantMode;
		title: string;
		desc: string;
		prompt: string;
	}[] = [
		{
			mode: 'academic',
			title: 'Penjelasan Materi & Matematika Bertahap',
			desc: 'Jabarkan konsep kuliah, turunan rumus, dan latihan soal beserta pembahasan langkah demi langkah.',
			prompt:
				'Jelaskan konsep Eigenvalue dan Eigenvector dalam Aljabar Linear secara intuitif, lalu berikan 1 contoh perhitungan matriks 2x2 langkah demi langkah.'
		},
		{
			mode: 'coding',
			title: 'Arsitektur Kode & Debugging',
			desc: 'Identifikasi akar masalah error, perbaiki performa query, atau rancang API yang bersih.',
			prompt:
				'Tunjukkan cara merancang sistem autentikasi API menggunakan token yang aman beserta strategi penanganan rate limiting dan validasi input.'
		},
		{
			mode: 'creative',
			title: 'Penulisan Surat Resmi, Proposal & Artikel',
			desc: 'Susun naskah kerja, email bisnis, kerangka presentasi, hingga ide konten terstruktur.',
			prompt:
				'Buatkan kerangka proposal pengajuan kerja sama kegiatan lokakarya kampus yang mencakup latar belakang, tujuan terukur, susunan acara, dan penutup resmi.'
		}
	];

	const availableModelsForProvider = $derived.by(() => {
		const p = appState.aiStatus.providers.find((item) => item.provider_key === selectedProvider);
		return p?.available_models ?? [];
	});

	function initProviderDefaults() {
		if (!selectedProvider && appState.aiStatus.providers.length > 0) {
			const def =
				appState.aiStatus.providers.find((p) => p.is_configured && p.is_default) ??
				appState.aiStatus.providers.find((p) => p.is_configured) ??
				appState.aiStatus.providers[0];
			if (def) {
				selectedProvider = def.provider_key;
				selectedModel = def.default_model;
			}
		}
	}

	onMount(() => {
		initProviderDefaults();

		// Feature detect Web Speech API
		const win = window as unknown as {
			SpeechRecognition?: new () => unknown;
			webkitSpeechRecognition?: new () => unknown;
		};
		const SpeechCtor = win.SpeechRecognition ?? win.webkitSpeechRecognition;
		if (SpeechCtor && (appState.user?.preference?.voice_enabled ?? true)) {
			speechSupported = true;
		}
	});

	$effect(() => {
		initProviderDefaults();
	});

	$effect(() => {
		const targetUuid = conversationUuid;
		if (targetUuid) {
			void loadConversation(targetUuid);
		} else {
			conversation = null;
			messages = [];
			attachedDocuments = [];
			pendingMemories = [];
			threadError = null;
			chatError = null;

			if (appState.pendingStarter) {
				selectedMode = appState.pendingStarter.mode;
				promptInput = appState.pendingStarter.prompt;
				if (appState.pendingStarter.documentIds?.length) {
					selectedVaultIds = [...appState.pendingStarter.documentIds];
					void loadVaultDocuments();
				}
				appState.pendingStarter = null;
			}
		}
	});

	async function scrollToBottom() {
		await tick();
		if (threadContainer) {
			threadContainer.scrollTop = threadContainer.scrollHeight;
		}
	}

	async function loadConversation(uuid: string) {
		loadingThread = true;
		threadError = null;
		chatError = null;
		try {
			const res = await apiFetch<{
				conversation: Conversation;
				messages: Message[];
				attached_documents: DocumentItem[];
				pending_memories: UserMemory[];
			}>(`/api/conversations/${uuid}`);

			conversation = res.conversation;
			messages = res.messages;
			attachedDocuments = res.attached_documents ?? [];
			selectedVaultIds = attachedDocuments.map((d) => d.id);
			pendingMemories = res.pending_memories ?? [];
			selectedMode = res.conversation.assistant_mode;
			const convProv = appState.aiStatus.providers.find(
				(p) => p.provider_key === res.conversation.provider_key
			);
			if (convProv && (convProv.is_configured || !appState.aiStatus.ai_ready)) {
				selectedProvider = convProv.provider_key;
				const modelExists = convProv.available_models.some(
					(m) => m.id === res.conversation.model_name
				);
				selectedModel =
					modelExists && res.conversation.model_name
						? res.conversation.model_name
						: convProv.default_model;
			} else {
				const fallbackProv =
					appState.aiStatus.providers.find((p) => p.is_configured && p.is_default) ??
					appState.aiStatus.providers.find((p) => p.is_configured);
				if (fallbackProv) {
					selectedProvider = fallbackProv.provider_key;
					selectedModel = fallbackProv.default_model;
				}
			}
			await scrollToBottom();
		} catch (err) {
			threadError = err instanceof ApiError ? err.message : 'Gagal memuat riwayat percakapan.';
		} finally {
			loadingThread = false;
		}
	}

	async function ensureConversationCreated(): Promise<Conversation> {
		if (conversation) return conversation;

		const created = await apiFetch<{ conversation: Conversation }>('/api/conversations', {
			method: 'POST',
			body: JSON.stringify({
				assistant_mode: selectedMode,
				provider_key: selectedProvider || null,
				model_name: selectedModel || null,
				attached_document_ids: selectedVaultIds
			})
		});

		conversation = created.conversation;
		appState.upsertConversation(created.conversation);
		return created.conversation;
	}

	async function handleSend(e?: Event) {
		e?.preventDefault();
		const text = promptInput.trim();
		if (!text || isStreaming) return;

		chatError = null;
		let activeConv: Conversation;
		const wasNew = !conversation;

		try {
			activeConv = await ensureConversationCreated();
		} catch (err) {
			chatError = {
				message: err instanceof ApiError ? err.message : 'Gagal membuat sesi percakapan baru.'
			};
			return;
		}

		promptInput = '';
		isStreaming = true;
		streamingText = '';
		abortController = new AbortController();

		try {
			await streamChatEndpoint(
				`/api/conversations/${activeConv.uuid}/messages`,
				'POST',
				{
					content: text,
					provider_key: selectedProvider || null,
					model: selectedModel || null,
					document_ids: selectedVaultIds
				},
				{
					onMeta: ({ conversation: updatedConv, user_message, memory_suggestions }) => {
						conversation = updatedConv;
						appState.upsertConversation(updatedConv);
						if (!messages.some((m) => m.uuid === user_message.uuid)) {
							messages = [...messages, user_message];
						}
						if (memory_suggestions?.length) {
							const unconfirmed = memory_suggestions.filter((m) => !m.is_confirmed);
							if (unconfirmed.length) {
								pendingMemories = [...unconfirmed, ...pendingMemories];
							}
						}
						void scrollToBottom();
					},
					onDelta: (delta) => {
						streamingText += delta;
						void scrollToBottom();
					},
					onDone: ({ assistant_message, conversation: updatedConv }) => {
						conversation = updatedConv;
						appState.upsertConversation(updatedConv);
						messages = [...messages, assistant_message];
						streamingText = '';
						void scrollToBottom();
					},
					onError: (err) => {
						chatError = { message: err.message, code: err.error_code };
					}
				},
				abortController.signal
			);

			if (wasNew && conversation) {
				await goto(`/c/${conversation.uuid}`, { replaceState: true });
			}
		} catch (err) {
			if ((err as Error)?.name === 'AbortError') {
				if (streamingText.trim() !== '') {
					appState.notify('Generasi jawaban dihentikan.', 'info');
					if (conversation) {
						await loadConversation(conversation.uuid);
					}
				}
			} else if (err instanceof ApiError) {
				chatError = { message: err.message, code: err.errorCode };
				const payloadUserMsg = err.payload?.user_message as Message | undefined;
				const payloadConv = err.payload?.conversation as Conversation | undefined;
				if (payloadConv) {
					conversation = payloadConv;
					appState.upsertConversation(payloadConv);
				}
				if (payloadUserMsg && !messages.some((m) => m.uuid === payloadUserMsg.uuid)) {
					messages = [...messages, payloadUserMsg];
				}
				if (wasNew && conversation) {
					await goto(`/c/${conversation.uuid}`, { replaceState: true });
				}
			} else {
				chatError = { message: 'Terjadi gangguan jaringan saat menghubungi server.' };
			}
		} finally {
			isStreaming = false;
			streamingText = '';
			abortController = null;
		}
	}

	function stopGeneration() {
		abortController?.abort();
	}

	async function submitEditMessage(msgUuid: string) {
		const trimmed = editingDraft.trim();
		if (!trimmed || isStreaming) return;

		editingMessageUuid = null;
		chatError = null;
		isStreaming = true;
		streamingText = '';
		abortController = new AbortController();

		try {
			await streamChatEndpoint(
				`/api/messages/${msgUuid}`,
				'PUT',
				{
					content: trimmed,
					provider_key: selectedProvider || null,
					model: selectedModel || null
				},
				{
					onMeta: ({ user_message }) => {
						const idx = messages.findIndex((m) => m.uuid === msgUuid);
						if (idx >= 0) {
							messages = [...messages.slice(0, idx), user_message];
						}
					},
					onDelta: (delta) => {
						streamingText += delta;
						void scrollToBottom();
					},
					onDone: ({ assistant_message, conversation: updatedConv }) => {
						conversation = updatedConv;
						appState.upsertConversation(updatedConv);
						messages = [...messages, assistant_message];
						streamingText = '';
					},
					onError: (err) => {
						chatError = { message: err.message, code: err.error_code };
					}
				},
				abortController.signal
			);
		} catch (err) {
			if ((err as Error)?.name !== 'AbortError') {
				chatError = {
					message: err instanceof ApiError ? err.message : 'Gagal memperbarui pesan.',
					code: err instanceof ApiError ? err.errorCode : undefined
				};
				if (conversation) await loadConversation(conversation.uuid);
			}
		} finally {
			isStreaming = false;
			streamingText = '';
			abortController = null;
		}
	}

	async function regenerateFromMessage(msg: Message) {
		if (isStreaming) return;
		chatError = null;
		isStreaming = true;
		streamingText = '';
		abortController = new AbortController();

		if (msg.role === 'assistant') {
			const idx = messages.findIndex((m) => m.uuid === msg.uuid);
			if (idx >= 0) {
				messages = messages.slice(0, idx);
			}
		}

		try {
			await streamChatEndpoint(
				`/api/messages/${msg.uuid}/regenerate`,
				'POST',
				{
					provider_key: selectedProvider || null,
					model: selectedModel || null
				},
				{
					onDelta: (delta) => {
						streamingText += delta;
						void scrollToBottom();
					},
					onDone: ({ assistant_message, conversation: updatedConv }) => {
						conversation = updatedConv;
						appState.upsertConversation(updatedConv);
						messages = [...messages, assistant_message];
						streamingText = '';
					},
					onError: (err) => {
						chatError = { message: err.message, code: err.error_code };
					}
				},
				abortController.signal
			);
		} catch (err) {
			if ((err as Error)?.name !== 'AbortError') {
				chatError = {
					message: err instanceof ApiError ? err.message : 'Gagal membuat ulang respons.',
					code: err instanceof ApiError ? err.errorCode : undefined
				};
			}
		} finally {
			isStreaming = false;
			streamingText = '';
			abortController = null;
		}
	}

	async function copyMessageContent(content: string) {
		try {
			await navigator.clipboard.writeText(content);
			appState.notify('Respons disalin ke papan klip.', 'success');
		} catch {
			appState.notify('Gagal menyalin teks.', 'error');
		}
	}

	async function toggleMessageFeedback(msg: Message, target: 'up' | 'down') {
		const next = msg.feedback === target ? null : target;
		try {
			const res = await apiFetch<{ data: Message }>(`/api/messages/${msg.uuid}/meta`, {
				method: 'PATCH',
				body: JSON.stringify({ feedback: next })
			});
			messages = messages.map((m) => (m.uuid === msg.uuid ? res.data : m));
		} catch {
			appState.notify('Gagal menyimpan umpan balik.', 'error');
		}
	}

	async function toggleMessageBookmark(msg: Message) {
		try {
			const res = await apiFetch<{ data: Message }>(`/api/messages/${msg.uuid}/meta`, {
				method: 'PATCH',
				body: JSON.stringify({ is_bookmarked: !msg.is_bookmarked })
			});
			messages = messages.map((m) => (m.uuid === msg.uuid ? res.data : m));
			appState.notify(
				res.data.is_bookmarked ? 'Pesan disimpan ke penanda.' : 'Penanda pesan dihapus.',
				'info'
			);
		} catch {
			appState.notify('Gagal memperbarui penanda.', 'error');
		}
	}

	async function confirmMemorySuggestion(mem: UserMemory) {
		try {
			await apiFetch(`/api/memories/${mem.id}`, {
				method: 'PATCH',
				body: JSON.stringify({ is_confirmed: true, is_active: true })
			});
			pendingMemories = pendingMemories.filter((m) => m.id !== mem.id);
			appState.notify('Preferensi disimpan ke Memori Personal.', 'success');
		} catch {
			appState.notify('Gagal menyimpan memori.', 'error');
		}
	}

	async function dismissMemorySuggestion(mem: UserMemory) {
		try {
			await apiFetch(`/api/memories/${mem.id}`, { method: 'DELETE' });
			pendingMemories = pendingMemories.filter((m) => m.id !== mem.id);
		} catch {
			// Ignore dismiss error
		}
	}

	async function loadVaultDocuments() {
		try {
			const res = await apiFetch<{ documents: DocumentItem[] }>('/api/documents');
			vaultDocuments = res.documents.filter((d) => d.status === 'ready');
			attachedDocuments = vaultDocuments.filter((d) => selectedVaultIds.includes(d.id));
		} catch {
			// Handled silently
		}
	}

	async function openAttachModal() {
		await loadVaultDocuments();
		attachModalOpen = true;
	}

	async function handleInlineDocUpload(event: Event) {
		const input = event.currentTarget as HTMLInputElement;
		const file = input.files?.[0];
		if (!file) return;

		uploadingDoc = true;
		const formData = new FormData();
		formData.append('file', file);

		try {
			const res = await apiFetch<{ document: DocumentItem }>('/api/documents', {
				method: 'POST',
				body: formData
			});
			vaultDocuments = [res.document, ...vaultDocuments];
			if (res.document.status === 'ready') {
				selectedVaultIds = Array.from(new Set([...selectedVaultIds, res.document.id]));
				attachedDocuments = vaultDocuments.filter((d) => selectedVaultIds.includes(d.id));
				selectedMode = 'document';
			}
			appState.notify(`Dokumen "${res.document.title}" siap dianalisis.`, 'success');
		} catch (err) {
			appState.notify(
				err instanceof ApiError ? err.message : 'Gagal mengunggah dokumen.',
				'error'
			);
		} finally {
			uploadingDoc = false;
			input.value = '';
		}
	}

	async function applyVaultSelection() {
		attachedDocuments = vaultDocuments.filter((d) => selectedVaultIds.includes(d.id));
		if (conversation) {
			try {
				const res = await apiFetch<{ conversation: Conversation }>(
					`/api/conversations/${conversation.uuid}`,
					{
						method: 'PATCH',
						body: JSON.stringify({ attached_document_ids: selectedVaultIds })
					}
				);
				conversation = res.conversation;
			} catch {
				appState.notify('Gagal memperbarui lampiran percakapan.', 'error');
			}
		}
		if (selectedVaultIds.length > 0 && selectedMode === 'general') {
			selectedMode = 'document';
		}
		attachModalOpen = false;
	}

	async function saveRenamedTitle() {
		if (!conversation || !titleDraft.trim()) {
			renamingTitle = false;
			return;
		}
		try {
			const res = await apiFetch<{ conversation: Conversation }>(
				`/api/conversations/${conversation.uuid}`,
				{
					method: 'PATCH',
					body: JSON.stringify({ title: titleDraft.trim() })
				}
			);
			conversation = res.conversation;
			appState.upsertConversation(res.conversation);
		} catch {
			appState.notify('Gagal mengubah judul percakapan.', 'error');
		} finally {
			renamingTitle = false;
		}
	}

	function toggleVoiceInput() {
		if (!speechSupported) return;
		if (isListening && recognitionInstance) {
			recognitionInstance.stop();
			isListening = false;
			return;
		}

		const win = window as unknown as {
			SpeechRecognition?: new () => {
				lang: string;
				interimResults: boolean;
				onresult: ((ev: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void) | null;
				onerror: (() => void) | null;
				onend: (() => void) | null;
				start: () => void;
				stop: () => void;
			};
			webkitSpeechRecognition?: new () => {
				lang: string;
				interimResults: boolean;
				onresult: ((ev: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void) | null;
				onerror: (() => void) | null;
				onend: (() => void) | null;
				start: () => void;
				stop: () => void;
			};
		};

		const Ctor = win.SpeechRecognition ?? win.webkitSpeechRecognition;
		if (!Ctor) return;

		const rec = new Ctor();
		rec.lang = appState.user?.preference?.language === 'en' ? 'en-US' : 'id-ID';
		rec.interimResults = false;
		rec.onresult = (ev) => {
			const transcript = ev.results?.[0]?.[0]?.transcript ?? '';
			if (transcript) {
				promptInput = promptInput ? `${promptInput} ${transcript}` : transcript;
			}
		};
		rec.onerror = () => {
			isListening = false;
		};
		rec.onend = () => {
			isListening = false;
		};

		recognitionInstance = rec;
		isListening = true;
		rec.start();
	}

	function handleComposerKeydown(e: KeyboardEvent) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			void handleSend();
		}
	}
</script>

<div class="flex h-full min-h-0 flex-col">
	<!-- Top Stage Header -->
	<header
		class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] bg-[var(--bg-surface)] px-4 py-3 md:px-6"
	>
		<div class="flex min-w-0 items-center gap-2.5">
			{#if renamingTitle && conversation}
				<form
					onsubmit={(e) => {
						e.preventDefault();
						void saveRenamedTitle();
					}}
					class="flex items-center gap-2"
				>
					<label for="conv-title-input" class="visually-hidden">Judul percakapan</label>
					<input
						id="conv-title-input"
						type="text"
						bind:value={titleDraft}
						class="rounded-[10px] border border-[var(--border-control)] bg-[var(--bg-canvas)] px-2.5 py-1 text-sm font-semibold text-[var(--text-primary)]"
					/>
					<button
						type="submit"
						class="rounded-[10px] bg-[var(--accent-blue)] px-2.5 py-1 text-xs font-semibold text-white cursor-pointer"
					>
						Simpan
					</button>
				</form>
			{:else}
				<h1
					id="main-stage"
					tabindex="-1"
					class="truncate text-base font-semibold text-[var(--text-primary)]"
				>
					{conversation ? conversation.title : 'Percakapan Baru'}
				</h1>
				{#if conversation}
					<button
						type="button"
						onclick={() => {
							titleDraft = conversation?.title ?? '';
							renamingTitle = true;
						}}
						aria-label="Ubah judul percakapan"
						class="inline-flex h-7 w-7 items-center justify-center rounded-[10px] text-[var(--text-muted)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)] cursor-pointer"
					>
						<Icon name="edit" class="h-3.5 w-3.5" />
					</button>
				{/if}
			{/if}
		</div>

		<div class="flex flex-wrap items-center gap-2">
			<label for="mode-select" class="visually-hidden">Mode asisten</label>
			<select
				id="mode-select"
				bind:value={selectedMode}
				onchange={async () => {
					if (conversation) {
						const res = await apiFetch<{ conversation: Conversation }>(
							`/api/conversations/${conversation.uuid}`,
							{
								method: 'PATCH',
								body: JSON.stringify({ assistant_mode: selectedMode })
							}
						);
						conversation = res.conversation;
					}
				}}
				class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-elevated)] px-2.5 py-1.5 text-xs font-medium text-[var(--text-primary)]"
			>
				{#each Object.entries(modeLabels) as [key, label] (key)}
					<option value={key}>{label}</option>
				{/each}
			</select>

			{#if appState.aiStatus.providers.length > 0}
				<label for="provider-select" class="visually-hidden">Provider AI</label>
				<select
					id="provider-select"
					bind:value={selectedProvider}
					onchange={() => {
						const p = appState.aiStatus.providers.find((x) => x.provider_key === selectedProvider);
						if (p) selectedModel = p.default_model;
					}}
					class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-elevated)] px-2.5 py-1.5 text-xs font-medium text-[var(--text-primary)]"
				>
					{#each appState.aiStatus.providers as prov (prov.provider_key)}
						<option value={prov.provider_key}>
							{prov.display_name}{prov.is_configured ? '' : ' (Belum Aktif)'}
						</option>
					{/each}
				</select>

				{#if availableModelsForProvider.length > 0}
					<label for="model-select" class="visually-hidden">Model AI</label>
					<select
						id="model-select"
						bind:value={selectedModel}
						class="max-w-[180px] truncate rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-elevated)] px-2.5 py-1.5 font-mono text-xs text-[var(--text-primary)]"
					>
						{#each availableModelsForProvider as mdl (mdl.id)}
							<option value={mdl.id}>{mdl.label}</option>
						{/each}
					</select>
				{/if}
			{/if}
		</div>
	</header>

	<!-- Honest Unconfigured Provider Banner (Rule N: Never pretend when AI key is missing) -->
	{#if !appState.aiStatus.ai_ready}
		<div
			role="status"
			class="mx-4 mt-3 flex flex-wrap items-center justify-between gap-3 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--warn-bg)] px-4 py-3 text-xs text-[var(--warn-text)] md:mx-6"
		>
			<div class="flex items-start gap-2.5">
				<Icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
				<div>
					<p class="font-semibold">API Key Provider AI Belum Dikonfigurasi</p>
					<p class="mt-0.5">
						Rekametachat tidak memalsukan respons AI. Silakan isi API key (OpenAI, Anthropic, Gemini,
						atau OpenRouter) melalui file <code class="font-mono">.env</code> backend atau halaman konfigurasi
						Admin agar percakapan AI aktif. Fitur manajemen dokumen, ekstraksi teks, dan penyusunan/ekspor
						PDF CV sudah dapat digunakan penuh.
					</p>
				</div>
			</div>
			{#if appState.user?.role === 'admin'}
				<a
					href="/admin"
					class="shrink-0 rounded-[10px] bg-[var(--accent-blue)] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)]"
				>
					Konfigurasi AI Provider
				</a>
			{/if}
		</div>
	{/if}

	<!-- Main Scrollable Thread -->
	<div bind:this={threadContainer} class="flex-1 overflow-y-auto px-4 py-6 md:px-8">
		<div class="mx-auto max-w-3xl">
			{#if loadingThread}
				<div class="py-12 text-center">
					<progress class="w-48" aria-label="Memuat percakapan"></progress>
					<p class="mt-2 text-sm text-[var(--text-muted)]">Memuat riwayat percakapan...</p>
				</div>
			{:else if threadError}
				<div
					role="alert"
					class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--danger-bg)] p-4 text-sm text-[var(--danger-text)]"
				>
					<p class="font-semibold">Percakapan gagal dimuat</p>
					<p class="mt-1">{threadError}</p>
					{#if conversationUuid}
						<button
							type="button"
							onclick={() => conversationUuid && loadConversation(conversationUuid)}
							class="mt-3 rounded-[10px] bg-[var(--accent-blue)] px-3 py-1.5 text-xs font-semibold text-white cursor-pointer"
						>
							Muat Ulang
						</button>
					{/if}
				</div>
			{:else if messages.length === 0 && !isStreaming}
				<!-- Empty State -->
				<div class="py-6 md:py-10">
					<div
						class="inline-flex items-center gap-2 rounded-[6px] bg-[var(--accent-violet-bg)] px-2.5 py-1 text-xs font-semibold text-[var(--accent-violet-text)]"
					>
						Mode Aktif: {modeLabels[selectedMode]}
					</div>
					<h2 class="mt-3 text-2xl font-bold tracking-tight text-[var(--text-primary)] md:text-3xl">
						Halo{appState.user ? `, ${appState.user.name.split(' ')[0]}` : ''}. Apa yang ingin kita
						kerjakan hari ini?
					</h2>
					<p class="mt-2 max-w-xl text-sm text-[var(--text-secondary)]">
						Tanyakan materi akademik, analisis dokumen kerja (PDF, DOCX, TXT, CSV), susun CV ramah
						ATS, atau diskusikan kode pemrograman dalam Bahasa Indonesia maupun Inggris.
					</p>

					<!-- Asymmetric Workflow Starters (R-14 hierarchy: featured primary row + secondary pair) -->
					<div class="mt-7 grid gap-3 md:grid-cols-2">
						{#each starterCards as item, idx (item.title)}
							<button
								type="button"
								onclick={() => {
									selectedMode = item.mode;
									promptInput = item.prompt;
								}}
								class="{idx === 0 ? 'md:col-span-2' : ''} group rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4 text-start transition-colors hover:border-[var(--accent-link)] cursor-pointer"
							>
								<div class="flex items-center justify-between gap-2">
									<span class="text-sm font-semibold text-[var(--text-primary)]">{item.title}</span>
									<span
										class="rounded-[6px] bg-[var(--bg-elevated)] px-2 py-0.5 text-[11px] font-medium text-[var(--text-secondary)]"
									>
										{modeLabels[item.mode]}
									</span>
								</div>
								<p class="mt-1.5 text-xs text-[var(--text-muted)]">{item.desc}</p>
							</button>
						{/each}
					</div>
				</div>
			{:else}
				<!-- Active Messages Thread -->
				<div class="space-y-6">
					{#each messages as msg (msg.uuid)}
						{#if msg.role === 'user'}
							<div class="flex flex-col items-end">
								<div class="flex items-center gap-2 text-xs text-[var(--text-muted)] mb-1">
									{#if msg.is_edited}
										<span>(diedit)</span>
									{/if}
									<span class="font-semibold text-[var(--text-secondary)]">Anda</span>
								</div>

								{#if editingMessageUuid === msg.uuid}
									<div
										class="w-full max-w-xl rounded-[14px] border border-[var(--accent-link)] bg-[var(--bg-surface)] p-3"
									>
										<label for="edit-msg-{msg.uuid}" class="visually-hidden">Edit pesan</label>
										<textarea
											id="edit-msg-{msg.uuid}"
											bind:value={editingDraft}
											rows="3"
											class="w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-2.5 text-sm text-[var(--text-primary)]"
										></textarea>
										<div class="mt-2 flex justify-end gap-2">
											<button
												type="button"
												onclick={() => (editingMessageUuid = null)}
												class="rounded-[10px] border border-[var(--border-subtle)] px-3 py-1.5 text-xs font-medium text-[var(--text-secondary)] cursor-pointer"
											>
												Batal
											</button>
											<button
												type="button"
												onclick={() => submitEditMessage(msg.uuid)}
												class="rounded-[10px] bg-[var(--accent-blue)] px-3 py-1.5 text-xs font-semibold text-white cursor-pointer"
											>
												Simpan & Kirim Ulang
											</button>
										</div>
									</div>
								{:else}
									<div
										class="max-w-[88%] rounded-[14px] bg-[var(--accent-blue)] px-4 py-3 text-sm text-white whitespace-pre-wrap"
									>
										{msg.content}
									</div>
									<div class="mt-1 flex items-center gap-1">
										<button
											type="button"
											onclick={() => {
												editingMessageUuid = msg.uuid;
												editingDraft = msg.content;
											}}
											aria-label="Edit pesan ini"
											class="inline-flex items-center gap-1 rounded-[6px] px-2 py-1 text-[11px] text-[var(--text-muted)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)] cursor-pointer"
										>
											<Icon name="edit" class="h-3 w-3" />
											<span>Edit</span>
										</button>
										<button
											type="button"
											onclick={() => regenerateFromMessage(msg)}
											aria-label="Kirim ulang pesan ini"
											class="inline-flex items-center gap-1 rounded-[6px] px-2 py-1 text-[11px] text-[var(--text-muted)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)] cursor-pointer"
										>
											<Icon name="refresh" class="h-3 w-3" />
											<span>Ulangi</span>
										</button>
									</div>
								{/if}
							</div>
						{:else if msg.role === 'assistant'}
							<article
								class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4 md:p-5"
							>
								<div class="mb-2 flex items-center justify-between gap-2">
									<div class="flex items-center gap-2">
										<span class="text-xs font-bold text-[var(--text-primary)]">Rekametachat</span>
										{#if msg.model_name}
											<span
												class="rounded-[6px] bg-[var(--bg-elevated)] px-2 py-0.5 font-mono text-[11px] text-[var(--text-muted)]"
											>
												{msg.model_name}
											</span>
										{/if}
										{#if msg.is_stopped}
											<span
												class="rounded-[6px] bg-[var(--warn-bg)] px-2 py-0.5 text-[11px] text-[var(--warn-text)]"
											>
												Dihentikan pengguna
											</span>
										{/if}
									</div>
								</div>

								<div class="markdown-body" use:markdownAction={msg.content}></div>

								{#if msg.citations && msg.citations.length > 0}
									<div class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-[var(--border-subtle)] pt-2.5">
										<span class="text-[11px] font-semibold text-[var(--text-muted)]">Sumber Dokumen:</span>
										{#each msg.citations as cite (`${cite.document_id}-${cite.chunk_index}`)}
											<span
												class="rounded-[6px] bg-[var(--accent-violet-bg)] px-2 py-0.5 text-[11px] font-medium text-[var(--accent-violet-text)]"
											>
												{cite.title} (#{cite.chunk_index})
											</span>
										{/each}
									</div>
								{/if}

								<!-- Assistant Action Bar (matching reference image: feedback + bookmark + copy + regenerate) -->
								<div
									class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-[var(--border-subtle)] pt-2.5"
								>
									<div class="flex items-center gap-1">
										<button
											type="button"
											onclick={() => toggleMessageFeedback(msg, 'up')}
											aria-label="Jawaban membantu"
											aria-pressed={msg.feedback === 'up'}
											class="inline-flex h-7 w-7 items-center justify-center rounded-[6px] {msg.feedback ===
											'up'
												? 'bg-[var(--accent-blue)] text-white'
												: 'text-[var(--text-muted)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)]'} cursor-pointer"
										>
											<Icon name="thumb-up" class="h-3.5 w-3.5" />
										</button>
										<button
											type="button"
											onclick={() => toggleMessageFeedback(msg, 'down')}
											aria-label="Jawaban kurang tepat"
											aria-pressed={msg.feedback === 'down'}
											class="inline-flex h-7 w-7 items-center justify-center rounded-[6px] {msg.feedback ===
											'down'
												? 'bg-[var(--danger-bg)] text-[var(--danger-text)]'
												: 'text-[var(--text-muted)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)]'} cursor-pointer"
										>
											<Icon name="thumb-down" class="h-3.5 w-3.5" />
										</button>
									</div>

									<div class="flex items-center gap-1">
										<button
											type="button"
											onclick={() => toggleMessageBookmark(msg)}
											aria-label={msg.is_bookmarked ? 'Hapus penanda' : 'Tandai pesan'}
											aria-pressed={Boolean(msg.is_bookmarked)}
											class="inline-flex items-center gap-1 rounded-[6px] px-2 py-1 text-xs {msg.is_bookmarked
												? 'bg-[var(--accent-violet-bg)] text-[var(--accent-violet-text)]'
												: 'text-[var(--text-muted)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)]'} cursor-pointer"
										>
											<Icon name="bookmark" class="h-3.5 w-3.5" />
											<span>{msg.is_bookmarked ? 'Tersimpan' : 'Tandai'}</span>
										</button>
										<button
											type="button"
											onclick={() => copyMessageContent(msg.content)}
											aria-label="Salin respons"
											class="inline-flex items-center gap-1 rounded-[6px] px-2 py-1 text-xs text-[var(--text-muted)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)] cursor-pointer"
										>
											<Icon name="copy" class="h-3.5 w-3.5" />
											<span>Salin</span>
										</button>
										<button
											type="button"
											onclick={() => regenerateFromMessage(msg)}
											aria-label="Buat ulang respons"
											class="inline-flex items-center gap-1 rounded-[6px] px-2 py-1 text-xs text-[var(--text-muted)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)] cursor-pointer"
										>
											<Icon name="refresh" class="h-3.5 w-3.5" />
											<span>Buat Ulang</span>
										</button>
									</div>
								</div>
							</article>
						{/if}
					{/each}

					{#if isStreaming}
						<article
							class="rounded-[14px] border border-[var(--accent-link)] bg-[var(--bg-surface)] p-4 md:p-5"
						>
							<div class="mb-2 flex items-center gap-2">
								<span class="text-xs font-bold text-[var(--text-primary)]">Rekametachat</span>
								<span class="text-xs text-[var(--text-muted)]">sedang menyusun jawaban...</span>
							</div>
							{#if streamingText}
								<div class="markdown-body" use:markdownAction={streamingText}></div>
							{:else}
								<progress class="w-36" aria-label="Menunggu token pertama dari provider AI"></progress>
							{/if}
						</article>
					{/if}
				</div>
			{/if}

			<!-- Consent-based Personal Memory Suggestions -->
			{#if pendingMemories.length > 0}
				<div class="mt-4 space-y-2">
					{#each pendingMemories as mem (mem.id)}
						<div
							class="flex flex-wrap items-center justify-between gap-2 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--accent-violet-bg)] px-4 py-2.5 text-xs text-[var(--accent-violet-text)]"
						>
							<div>
								<span class="font-semibold">Simpan ke Memori Personal?</span>
								<span class="ms-1.5">{mem.memory_value}</span>
							</div>
							<div class="flex items-center gap-2">
								<button
									type="button"
									onclick={() => confirmMemorySuggestion(mem)}
									class="rounded-[6px] bg-[#5b21b6] px-2.5 py-1 font-semibold text-white cursor-pointer"
								>
									Setuju Simpan
								</button>
								<button
									type="button"
									onclick={() => dismissMemorySuggestion(mem)}
									class="rounded-[6px] border border-current px-2.5 py-1 font-medium cursor-pointer"
								>
									Abaikan
								</button>
							</div>
						</div>
					{/each}
				</div>
			{/if}

			<!-- Error State Banner inside Chat -->
			{#if chatError}
				<div
					role="alert"
					class="mt-4 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--danger-bg)] p-4 text-xs text-[var(--danger-text)]"
				>
					<div class="flex flex-wrap items-center justify-between gap-2">
						<div>
							<p class="font-semibold">
								{chatError.code === 'AI_PROVIDER_NOT_CONFIGURED'
									? 'Layanan AI Belum Dikonfigurasi'
									: 'Permintaan Tidak Dapat Diproses'}
							</p>
							<p class="mt-1">{chatError.message}</p>
						</div>
						{#if chatError.code === 'AI_PROVIDER_NOT_CONFIGURED' && appState.user?.role === 'admin'}
							<a
								href="/admin"
								class="rounded-[10px] bg-[var(--accent-blue)] px-3 py-1.5 font-semibold text-white"
							>
								Buka Pengaturan Provider
							</a>
						{/if}
					</div>
				</div>
			{/if}
		</div>
	</div>

	<!-- Bottom Composer -->
	<div class="border-t border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-3 md:px-6">
		<div class="mx-auto max-w-3xl">
			{#if attachedDocuments.length > 0}
				<div class="mb-2 flex flex-wrap items-center gap-1.5">
					{#each attachedDocuments as doc (doc.id)}
						<span
							class="inline-flex items-center gap-1.5 rounded-[6px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1 text-xs text-[var(--text-primary)]"
						>
							<Icon name="document" class="h-3.5 w-3.5 text-[var(--accent-link)]" />
							<span class="max-w-[180px] truncate">{doc.title}</span>
							<button
								type="button"
								aria-label={`Lepas lampiran ${doc.title}`}
								onclick={() => {
									selectedVaultIds = selectedVaultIds.filter((id) => id !== doc.id);
									void applyVaultSelection();
								}}
								class="text-[var(--text-muted)] hover:text-[var(--text-primary)] cursor-pointer"
							>
								<Icon name="close" class="h-3 w-3" />
							</button>
						</span>
					{/each}
				</div>
			{/if}

			<form
				onsubmit={handleSend}
				class="composer-box flex items-end gap-2 rounded-[18px] border border-[var(--border-control)] bg-[var(--bg-surface)] p-2.5 shadow-sm"
			>
				<button
					type="button"
					onclick={openAttachModal}
					aria-label="Lampirkan dokumen"
					class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] hover:text-[var(--text-primary)] cursor-pointer"
				>
					<Icon name="paperclip" class="h-4 w-4" />
				</button>

				<label for="chat-prompt-input" class="visually-hidden">Tulis pesan untuk Rekametachat</label>
				<textarea
					id="chat-prompt-input"
					bind:value={promptInput}
					onkeydown={handleComposerKeydown}
					placeholder="Tulis pesan atau instruksi..."
					rows="1"
					class="composer-textarea py-2 px-1 text-sm"
				></textarea>

				{#if speechSupported}
					<button
						type="button"
						onclick={toggleVoiceInput}
						aria-label={isListening ? 'Hentikan dikte suara' : 'Dikte pesan dengan suara'}
						aria-pressed={isListening}
						class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] {isListening
							? 'bg-[var(--danger-bg)] text-[var(--danger-text)]'
							: 'text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)]'} cursor-pointer"
					>
						<Icon name="mic" class="h-4 w-4" />
					</button>
				{/if}

				{#if isStreaming}
					<button
						type="button"
						onclick={stopGeneration}
						aria-label="Hentikan generasi jawaban"
						class="inline-flex h-10 items-center gap-1.5 rounded-[10px] bg-[var(--danger-bg)] px-3.5 text-xs font-semibold text-[var(--danger-text)] cursor-pointer"
					>
						<Icon name="stop" class="h-3.5 w-3.5" />
						<span>Hentikan</span>
					</button>
				{:else}
					<button
						type="submit"
						disabled={!promptInput.trim()}
						aria-label="Kirim pesan"
						class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] bg-[var(--accent-blue)] text-white transition-colors hover:bg-[var(--accent-blue-hover)] disabled:opacity-45 cursor-pointer disabled:cursor-not-allowed"
					>
						<Icon name="send" class="h-4 w-4" />
					</button>
				{/if}
			</form>
		</div>
	</div>
</div>

<!-- Document Attachment Native Modal Dialog -->
<ModalDialog
	bind:open={attachModalOpen}
	title="Lampirkan Dokumen ke Percakapan"
	onClose={() => (attachModalOpen = false)}
>
	<div class="space-y-4">
		<div
			class="rounded-[14px] border border-dashed border-[var(--border-control)] bg-[var(--bg-elevated)] p-4"
		>
			<label
				for="inline-doc-upload"
				class="block text-xs font-semibold text-[var(--text-primary)] mb-1.5"
			>
				Unggah Dokumen Baru (PDF, DOCX, TXT, CSV maks. 10 MB)
			</label>
			<input
				id="inline-doc-upload"
				type="file"
				accept=".pdf,.docx,.txt,.csv"
				disabled={uploadingDoc}
				onchange={handleInlineDocUpload}
				class="block w-full text-xs text-[var(--text-secondary)] file:me-3 file:rounded-[10px] file:border-0 file:bg-[var(--accent-blue)] file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white file:cursor-pointer"
			/>
			{#if uploadingDoc}
				<p class="mt-2 text-xs text-[var(--text-muted)]">Mengekstrak teks & mengindeks bagian dokumen...</p>
			{/if}
		</div>

		<div>
			<h3 class="text-xs font-semibold text-[var(--text-secondary)] mb-2">
				Pilih dari Ruang Dokumen Anda
			</h3>
			{#if vaultDocuments.length === 0}
				<p class="text-xs text-[var(--text-muted)] py-3">
					Belum ada dokumen tersimpan. Unggah file di atas untuk mulai menganalisis dokumen di dalam
					percakapan.
				</p>
			{:else}
				<div class="max-h-56 space-y-1.5 overflow-y-auto pr-1">
					{#each vaultDocuments as doc (doc.id)}
						<label
							for="vault-doc-{doc.id}"
							class="flex items-center justify-between gap-3 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs cursor-pointer"
						>
							<span class="flex items-center gap-2 min-w-0">
								<input
									id="vault-doc-{doc.id}"
									type="checkbox"
									checked={selectedVaultIds.includes(doc.id)}
									onchange={(e) => {
										const checked = (e.currentTarget as HTMLInputElement).checked;
										selectedVaultIds = checked
											? [...selectedVaultIds, doc.id]
											: selectedVaultIds.filter((id) => id !== doc.id);
									}}
								/>
								<span class="truncate font-medium text-[var(--text-primary)]">{doc.title}</span>
							</span>
							<span class="shrink-0 font-mono text-[11px] uppercase text-[var(--text-muted)]">
								{doc.extension} ({doc.word_count} kata)
							</span>
						</label>
					{/each}
				</div>
			{/if}
		</div>

		<div class="flex justify-end gap-2 pt-2">
			<button
				type="button"
				onclick={() => (attachModalOpen = false)}
				class="rounded-[10px] border border-[var(--border-subtle)] px-3.5 py-2 text-xs font-medium text-[var(--text-secondary)] cursor-pointer"
			>
				Batal
			</button>
			<button
				type="button"
				onclick={applyVaultSelection}
				class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white cursor-pointer"
			>
				Terapkan Lampiran ({selectedVaultIds.length})
			</button>
		</div>
	</div>
</ModalDialog>
