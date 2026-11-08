<script lang="ts">
	import { ApiError, apiFetch } from '$lib/api';
	import Icon from '$lib/components/Icon.svelte';
	import ModalDialog from '$lib/components/ModalDialog.svelte';
	import { appState } from '$lib/state.svelte';
	import type { AdminProvider } from '$lib/types';
	import { onMount } from 'svelte';

	interface AdminMetrics {
		total_users: number;
		active_users_7d: number;
		total_conversations: number;
		total_messages: number;
		total_documents: number;
		tokens_today: number;
		tokens_7d: number;
		tokens_all_time: number;
		requests_today: number;
		errors_today: number;
	}

	interface ProviderUsageRow {
		provider_key: string;
		model_name: string;
		requests: number;
		total_tokens: number;
		avg_latency_ms: number | null;
	}

	interface ErrorLogRow {
		id: number;
		feature: string;
		provider_key: string | null;
		model_name: string | null;
		status: string;
		http_status: number | null;
		error_code: string | null;
		error_message: string | null;
		latency_ms: number | null;
		created_at: string;
	}

	interface AdminUserRow {
		id: number;
		name: string;
		email: string;
		role: 'user' | 'admin';
		is_active: boolean;
		daily_token_limit: number | null;
		conversations_count: number;
		documents_count: number;
		tokens_today: number;
		last_login_at: string | null;
		created_at: string;
	}

	interface ProviderFormDraft {
		base_url: string;
		default_model: string;
		models_csv: string;
		api_key: string;
		temperature: number;
		max_output_tokens: number;
		daily_token_limit_per_user: number;
		rate_limit_per_minute: number;
		is_enabled: boolean;
		is_default: boolean;
		supports_streaming: boolean;
	}

	let activeSection = $state<'overview' | 'providers' | 'users' | 'errors'>('providers');

	let loading = $state(true);
	let loadError = $state<string | null>(null);

	let metrics = $state<AdminMetrics | null>(null);
	let byProvider = $state<ProviderUsageRow[]>([]);
	let recentErrors = $state<ErrorLogRow[]>([]);
	let providers = $state<AdminProvider[]>([]);
	let users = $state<AdminUserRow[]>([]);

	let providerDrafts = $state<Record<number, ProviderFormDraft>>({});
	let savingProviderId = $state<number | null>(null);

	let userQuotaDrafts = $state<Record<number, string>>({});
	let savingUserId = $state<number | null>(null);

	// New Provider Modal
	let addProviderModalOpen = $state(false);
	let newProvKey = $state('');
	let newProvName = $state('');
	let newProvDriver = $state<'openai_compatible' | 'anthropic'>('openai_compatible');
	let newProvUrl = $state('https://api.openai.com/v1');
	let newProvModel = $state('gpt-4o-mini');
	let newProvApiKey = $state('');
	let creatingProvider = $state(false);
	let createProviderError = $state<string | null>(null);

	function buildProviderDrafts(list: AdminProvider[]): void {
		const map: Record<number, ProviderFormDraft> = {};
		for (const p of list) {
			map[p.id] = {
				base_url: p.base_url,
				default_model: p.default_model,
				models_csv: (p.available_models ?? []).map((m) => m.id).join(', '),
				api_key: '',
				temperature: Number(p.temperature),
				max_output_tokens: Number(p.max_output_tokens),
				daily_token_limit_per_user: Number(p.daily_token_limit_per_user),
				rate_limit_per_minute: Number(p.rate_limit_per_minute),
				is_enabled: Boolean(p.is_enabled),
				is_default: Boolean(p.is_default),
				supports_streaming: Boolean(p.supports_streaming)
			};
		}
		providerDrafts = map;
	}

	function buildUserQuotaDrafts(list: AdminUserRow[]): void {
		const map: Record<number, string> = {};
		for (const u of list) {
			map[u.id] = u.daily_token_limit !== null ? String(u.daily_token_limit) : '';
		}
		userQuotaDrafts = map;
	}

	async function loadAdminData(): Promise<void> {
		if (appState.user?.role !== 'admin') {
			loading = false;
			return;
		}
		loading = true;
		loadError = null;
		try {
			const [overviewRes, providersRes, usersRes] = await Promise.all([
				apiFetch<{
					metrics: AdminMetrics;
					by_provider: ProviderUsageRow[];
					recent_errors: ErrorLogRow[];
				}>('/api/admin/overview'),
				apiFetch<{ providers: AdminProvider[] }>('/api/admin/providers'),
				apiFetch<{ users: AdminUserRow[] }>('/api/admin/users')
			]);

			metrics = overviewRes.metrics;
			byProvider = overviewRes.by_provider;
			recentErrors = overviewRes.recent_errors;
			providers = providersRes.providers;
			users = usersRes.users;

			buildProviderDrafts(providers);
			buildUserQuotaDrafts(users);
		} catch (err) {
			loadError =
				err instanceof ApiError
					? err.message
					: 'Gagal memuat data operasional panel administrator.';
		} finally {
			loading = false;
		}
	}

	async function saveProvider(prov: AdminProvider, clearKey = false): Promise<void> {
		const draft = providerDrafts[prov.id];
		if (!draft) return;

		savingProviderId = prov.id;
		try {
			const modelIds = draft.models_csv
				.split(',')
				.map((m) => m.trim())
				.filter(Boolean);
			if (!modelIds.includes(draft.default_model.trim()) && draft.default_model.trim()) {
				modelIds.unshift(draft.default_model.trim());
			}

			const payload: Record<string, unknown> = {
				base_url: draft.base_url.trim(),
				default_model: draft.default_model.trim(),
				available_models: modelIds.map((id) => ({
					id,
					label: id,
					supports_streaming: draft.supports_streaming
				})),
				temperature: Number(draft.temperature),
				max_output_tokens: Number(draft.max_output_tokens),
				daily_token_limit_per_user: Number(draft.daily_token_limit_per_user),
				rate_limit_per_minute: Number(draft.rate_limit_per_minute),
				is_enabled: draft.is_enabled,
				is_default: draft.is_default,
				supports_streaming: draft.supports_streaming
			};

			if (clearKey) {
				payload.clear_api_key = true;
			} else if (draft.api_key.trim() !== '') {
				payload.api_key = draft.api_key.trim();
			}

			await apiFetch(`/api/admin/providers/${prov.id}`, {
				method: 'PUT',
				body: JSON.stringify(payload)
			});

			const refreshed = await apiFetch<{ providers: AdminProvider[] }>('/api/admin/providers');
			providers = refreshed.providers;
			buildProviderDrafts(providers);
			await appState.refreshAiStatus();
			appState.notify(`Konfigurasi ${prov.display_name} disimpan.`, 'success');
		} catch (err) {
			appState.notify(
				err instanceof ApiError ? err.message : 'Gagal menyimpan konfigurasi provider.',
				'error'
			);
		} finally {
			savingProviderId = null;
		}
	}

	async function handleCreateProvider(e: SubmitEvent): Promise<void> {
		e.preventDefault();
		creatingProvider = true;
		createProviderError = null;
		try {
			await apiFetch('/api/admin/providers', {
				method: 'POST',
				body: JSON.stringify({
					provider_key: newProvKey.trim().toLowerCase(),
					display_name: newProvName.trim(),
					driver: newProvDriver,
					base_url: newProvUrl.trim(),
					default_model: newProvModel.trim(),
					api_key: newProvApiKey.trim() || null,
					is_enabled: true
				})
			});

			const refreshed = await apiFetch<{ providers: AdminProvider[] }>('/api/admin/providers');
			providers = refreshed.providers;
			buildProviderDrafts(providers);
			await appState.refreshAiStatus();

			addProviderModalOpen = false;
			newProvKey = '';
			newProvName = '';
			newProvApiKey = '';
			appState.notify('Provider AI baru berhasil ditambahkan.', 'success');
		} catch (err) {
			createProviderError =
				err instanceof ApiError ? err.message : 'Gagal menambahkan provider baru.';
		} finally {
			creatingProvider = false;
		}
	}

	async function updateUserRow(
		target: AdminUserRow,
		changes: { role?: 'user' | 'admin'; is_active?: boolean; saveQuota?: boolean }
	): Promise<void> {
		savingUserId = target.id;
		try {
			const payload: Record<string, unknown> = {};
			if (changes.role !== undefined) payload.role = changes.role;
			if (changes.is_active !== undefined) payload.is_active = changes.is_active;
			if (changes.saveQuota) {
				const raw = (userQuotaDrafts[target.id] ?? '').trim();
				payload.daily_token_limit = raw === '' ? null : Number(raw);
			}

			const res = await apiFetch<{ user: Partial<AdminUserRow> }>(
				`/api/admin/users/${target.id}`,
				{
					method: 'PATCH',
					body: JSON.stringify(payload)
				}
			);

			users = users.map((u) => (u.id === target.id ? { ...u, ...res.user } : u));
			appState.notify(`Pengguna ${target.name} diperbarui.`, 'success');
		} catch (err) {
			appState.notify(
				err instanceof ApiError ? err.message : 'Gagal memperbarui akun pengguna.',
				'error'
			);
		} finally {
			savingUserId = null;
		}
	}

	function formatDateShort(iso: string | null): string {
		if (!iso) return 'Belum tercatat';
		try {
			return new Date(iso).toLocaleString('id-ID', {
				day: '2-digit',
				month: 'short',
				hour: '2-digit',
				minute: '2-digit'
			});
		} catch {
			return iso;
		}
	}

	onMount(() => {
		void loadAdminData();
	});
</script>

<svelte:head>
	<title>Admin Panel | Rekametachat</title>
</svelte:head>

<div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
	{#if appState.user?.role !== 'admin'}
		<div
			role="alert"
			class="rounded-[14px] border border-[var(--danger-text)]/30 bg-[var(--danger-bg)] p-6 text-[var(--danger-text)]"
		>
			<h1 id="main-stage" tabindex="-1" class="text-lg font-bold">Akses Terbatas</h1>
			<p class="mt-1 text-xs">
				Halaman ini hanya dapat diakses oleh akun dengan peran Administrator.
			</p>
			<a
				href="/"
				class="mt-4 inline-block rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white"
			>
				Kembali ke Ruang Percakapan
			</a>
		</div>
	{:else}
		<header class="border-b border-[var(--border-subtle)] pb-5">
			<div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
				<div>
					<p class="text-xs font-semibold tracking-wide text-[var(--accent-link)] uppercase">
						Tata Kelola Sistem & Provider AI
					</p>
					<h1
						id="main-stage"
						tabindex="-1"
						class="mt-1 text-2xl font-bold tracking-tight text-[var(--text-primary)] sm:text-3xl"
					>
						Panel Operasional Admin
					</h1>
					<p class="mt-1 text-sm text-[var(--text-secondary)]">
						Kelola kunci API provider AI terenkripsi, model aktif, batas kuota token harian, serta
						pantau metrik penggunaan dan kesehatan API.
					</p>
				</div>

				<div class="flex items-center gap-2">
					<button
						type="button"
						onclick={() => (addProviderModalOpen = true)}
						class="inline-flex items-center gap-1.5 rounded-[10px] bg-[var(--accent-blue)] px-3.5 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
					>
						<Icon name="plus" class="h-4 w-4" />
						<span>Tambah Provider</span>
					</button>
					<button
						type="button"
						onclick={() => loadAdminData()}
						class="inline-flex items-center gap-1.5 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-xs font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
					>
						<Icon name="refresh" class="h-3.5 w-3.5" />
						<span>Segarkan</span>
					</button>
				</div>
			</div>

			<!-- Privacy Guarantee Notice -->
			<div
				class="mt-4 flex items-center gap-2.5 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3.5 py-2.5 text-xs text-[var(--text-secondary)]"
			>
				<Icon name="shield" class="h-4 w-4 shrink-0 text-[var(--accent-link)]" />
				<span>
					<strong>Perlindungan Privasi Aktif:</strong> Isi pesan percakapan pribadi, memori pengguna,
					dan konten dokumen tidak ditampilkan kepada administrator. Hanya agregat statistik dan log
					status teknis yang dipantau.
				</span>
			</div>
		</header>

		{#if loading}
			<div
				class="mt-6 flex flex-col items-center justify-center rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] py-14"
			>
				<progress class="w-44" aria-label="Memuat metrik operasional admin"></progress>
				<p class="mt-2 text-xs text-[var(--text-muted)]">Mengambil metrik dan daftar provider...</p>
			</div>
		{:else if loadError}
			<div
				role="alert"
				class="mt-6 rounded-[14px] bg-[var(--danger-bg)] p-5 text-xs text-[var(--danger-text)]"
			>
				<p class="font-semibold">{loadError}</p>
				<button
					type="button"
					onclick={() => loadAdminData()}
					class="mt-3 rounded-[10px] bg-[var(--accent-blue)] px-3.5 py-2 font-semibold text-white cursor-pointer"
				>
					Coba Lagi
				</button>
			</div>
		{:else}
			<!-- KPI Summary Strip -->
			{#if metrics}
				<section
					aria-label="Ringkasan statistik platform"
					class="mt-6 grid grid-cols-2 gap-3.5 lg:grid-cols-4"
				>
					<div class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4">
						<p class="text-xs font-medium text-[var(--text-muted)]">Total Pengguna</p>
						<p class="mt-1 font-mono text-2xl font-bold text-[var(--text-primary)]">
							{metrics.total_users.toLocaleString('id-ID')}
						</p>
						<p class="mt-0.5 text-[11px] text-[var(--text-secondary)]">
							{metrics.active_users_7d} aktif dalam 7 hari terakhir
						</p>
					</div>

					<div class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4">
						<p class="text-xs font-medium text-[var(--text-muted)]">Volume Percakapan</p>
						<p class="mt-1 font-mono text-2xl font-bold text-[var(--text-primary)]">
							{metrics.total_conversations.toLocaleString('id-ID')}
						</p>
						<p class="mt-0.5 text-[11px] text-[var(--text-secondary)]">
							{metrics.total_messages.toLocaleString('id-ID')} pesan · {metrics.total_documents} dokumen
						</p>
					</div>

					<div class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4">
						<p class="text-xs font-medium text-[var(--text-muted)]">Konsumsi Token</p>
						<p class="mt-1 font-mono text-2xl font-bold text-[var(--text-primary)]">
							{metrics.tokens_today.toLocaleString('id-ID')}
						</p>
						<p class="mt-0.5 text-[11px] text-[var(--text-secondary)]">
							Hari ini · {metrics.tokens_7d.toLocaleString('id-ID')} dalam 7 hari
						</p>
					</div>

					<div class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4">
						<p class="text-xs font-medium text-[var(--text-muted)]">Kesehatan API Hari Ini</p>
						<p class="mt-1 font-mono text-2xl font-bold text-[var(--text-primary)]">
							{metrics.requests_today.toLocaleString('id-ID')}
						</p>
						<p
							class="mt-0.5 text-[11px] {metrics.errors_today > 0
								? 'text-[var(--warn-text)]'
								: 'text-[var(--success-text)]'}"
						>
							{metrics.errors_today} kendala tercatat hari ini
						</p>
					</div>
				</section>
			{/if}

			<!-- Admin Navigation Tabs -->
			<div
				class="mt-6 flex flex-wrap gap-2 border-b border-[var(--border-subtle)] pb-3"
				role="tablist"
				aria-label="Bagian panel admin"
			>
				{#each [
					{ id: 'providers', label: `Provider & Model AI (${providers.length})` },
					{ id: 'users', label: `Pengguna & Kuota (${users.length})` },
					{ id: 'overview', label: 'Statistik Penggunaan Model' },
					{ id: 'errors', label: `Log Kendala API (${recentErrors.length})` }
				] as tab (tab.id)}
					<button
						type="button"
						role="tab"
						aria-selected={activeSection === tab.id}
						onclick={() => (activeSection = tab.id as typeof activeSection)}
						class="rounded-[10px] px-3.5 py-2 text-xs font-semibold cursor-pointer {activeSection ===
						tab.id
							? 'bg-[var(--accent-blue)] text-white'
							: 'border border-[var(--border-subtle)] bg-[var(--bg-surface)] text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)]'}"
					>
						{tab.label}
					</button>
				{/each}
			</div>

			<!-- SECTION: AI PROVIDERS & MODELS -->
			{#if activeSection === 'providers'}
				<div class="mt-5 space-y-5">
					{#each providers as prov (prov.id)}
						{@const draft = providerDrafts[prov.id]}
						{#if draft}
							<section
								aria-labelledby="prov-title-{prov.id}"
								class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-4"
							>
								<div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border-subtle)] pb-3.5">
									<div>
										<div class="flex flex-wrap items-center gap-2">
											<h2
												id="prov-title-{prov.id}"
												class="text-base font-bold text-[var(--text-primary)]"
											>
												{prov.display_name}
											</h2>
											<span class="rounded-[6px] bg-[var(--bg-elevated)] px-2 py-0.5 font-mono text-[11px] text-[var(--text-secondary)]">
												{prov.provider_key} · {prov.driver}
											</span>
											<span
												class="rounded-[6px] px-2 py-0.5 text-[11px] font-semibold {prov.has_api_key
													? 'bg-[var(--success-bg)] text-[var(--success-text)]'
													: 'bg-[var(--warn-bg)] text-[var(--warn-text)]'}"
											>
												{prov.has_api_key
													? `API Key Siap (${prov.masked_api_key ?? prov.key_source})`
													: 'API Key Belum Diisi'}
											</span>
										</div>
									</div>

									<div class="flex flex-wrap items-center gap-4 text-xs">
										<label class="flex items-center gap-1.5 cursor-pointer">
											<input type="checkbox" bind:checked={draft.is_enabled} class="h-4 w-4 rounded" />
											<span class="font-semibold text-[var(--text-primary)]">Aktif</span>
										</label>
										<label class="flex items-center gap-1.5 cursor-pointer">
											<input type="checkbox" bind:checked={draft.is_default} class="h-4 w-4 rounded" />
											<span class="font-semibold text-[var(--text-primary)]">Provider Utama</span>
										</label>
										<label class="flex items-center gap-1.5 cursor-pointer">
											<input
												type="checkbox"
												bind:checked={draft.supports_streaming}
												class="h-4 w-4 rounded"
											/>
											<span class="text-[var(--text-secondary)]">Streaming SSE</span>
										</label>
									</div>
								</div>

								<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
									<div>
										<label
											for="prov-url-{prov.id}"
											class="block text-xs font-semibold text-[var(--text-secondary)]"
										>
											Base URL Endpoint
										</label>
										<input
											id="prov-url-{prov.id}"
											type="url"
											spellcheck="false"
											bind:value={draft.base_url}
											class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 font-mono text-xs text-[var(--text-primary)]"
										/>
									</div>

									<div>
										<label
											for="prov-model-{prov.id}"
											class="block text-xs font-semibold text-[var(--text-secondary)]"
										>
											Model Default
										</label>
										<input
											id="prov-model-{prov.id}"
											type="text"
											spellcheck="false"
											bind:value={draft.default_model}
											class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 font-mono text-xs text-[var(--text-primary)]"
										/>
									</div>

									<div>
										<label
											for="prov-key-{prov.id}"
											class="block text-xs font-semibold text-[var(--text-secondary)]"
										>
											API Key Baru (Disimpan Terenkripsi)
										</label>
										<input
											id="prov-key-{prov.id}"
											type="password"
											autocomplete="off"
											spellcheck="false"
											bind:value={draft.api_key}
											placeholder={prov.has_api_key
												? 'Biarkan kosong jika tidak diganti'
												: 'Masukkan sk-... atau kunci provider'}
											class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 font-mono text-xs text-[var(--text-primary)]"
										/>
									</div>
								</div>

								<div>
									<label
										for="prov-models-csv-{prov.id}"
										class="block text-xs font-semibold text-[var(--text-secondary)]"
									>
										Daftar ID Model Tersedia (Pisahkan dengan koma)
									</label>
									<input
										id="prov-models-csv-{prov.id}"
										type="text"
										spellcheck="false"
										bind:value={draft.models_csv}
										class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 font-mono text-xs text-[var(--text-primary)]"
									/>
								</div>

								<div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
									<div>
										<label
											for="prov-temp-{prov.id}"
											class="block text-[11px] font-semibold text-[var(--text-secondary)]"
										>
											Temperature (0 sampai 2)
										</label>
										<input
											id="prov-temp-{prov.id}"
											type="number"
											step="0.1"
											min="0"
											max="2"
											bind:value={draft.temperature}
											class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
										/>
									</div>

									<div>
										<label
											for="prov-maxtok-{prov.id}"
											class="block text-[11px] font-semibold text-[var(--text-secondary)]"
										>
											Maks Output Token
										</label>
										<input
											id="prov-maxtok-{prov.id}"
											type="number"
											min="128"
											max="64000"
											bind:value={draft.max_output_tokens}
											class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
										/>
									</div>

									<div>
										<label
											for="prov-quota-{prov.id}"
											class="block text-[11px] font-semibold text-[var(--text-secondary)]"
										>
											Batas Token Harian / User
										</label>
										<input
											id="prov-quota-{prov.id}"
											type="number"
											min="0"
											bind:value={draft.daily_token_limit_per_user}
											class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
										/>
									</div>

									<div>
										<label
											for="prov-rpm-{prov.id}"
											class="block text-[11px] font-semibold text-[var(--text-secondary)]"
										>
											Rate Limit / Menit
										</label>
										<input
											id="prov-rpm-{prov.id}"
											type="number"
											min="1"
											max="600"
											bind:value={draft.rate_limit_per_minute}
											class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
										/>
									</div>
								</div>

								<div class="flex flex-wrap justify-end gap-2.5 pt-1">
									{#if prov.key_source === 'database_encrypted'}
										<button
											type="button"
											disabled={savingProviderId === prov.id}
											onclick={() => saveProvider(prov, true)}
											class="rounded-[10px] border border-[var(--border-subtle)] px-3.5 py-2 text-xs font-semibold text-[var(--danger-text)] hover:bg-[var(--danger-bg)] cursor-pointer"
										>
											Hapus API Key Tersimpan
										</button>
									{/if}

									<button
										type="button"
										disabled={savingProviderId === prov.id}
										onclick={() => saveProvider(prov, false)}
										class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
									>
										{savingProviderId === prov.id
											? 'Menyimpan...'
											: `Simpan ${prov.display_name}`}
									</button>
								</div>
							</section>
						{/if}
					{/each}
				</div>

			<!-- SECTION: USERS & QUOTAS TABLE -->
			{:else if activeSection === 'users'}
				<section aria-labelledby="admin-users-heading" class="mt-5 space-y-3">
					<h2 id="admin-users-heading" class="text-sm font-bold text-[var(--text-primary)]">
						Manajemen Akun Pengguna & Kuota Token Harian
					</h2>
					<!-- svelte-ignore a11y_no_noninteractive_tabindex -->
					<div
						class="table-wrapper"
						tabindex="0"
						role="region"
						aria-labelledby="admin-users-heading"
					>
						<table>
							<thead>
								<tr>
									<th scope="col">Pengguna</th>
									<th scope="col">Peran</th>
									<th scope="col">Aktivitas</th>
									<th scope="col">Token Hari Ini</th>
									<th scope="col">Kuota Harian Khusus</th>
									<th scope="col">Status</th>
								</tr>
							</thead>
							<tbody>
								{#each users as u (u.id)}
									<tr>
										<td data-label="Pengguna">
											<p class="font-semibold text-[var(--text-primary)]">{u.name}</p>
											<p class="text-xs text-[var(--text-muted)]">{u.email}</p>
										</td>
										<td data-label="Peran">
											<select
												aria-label="Peran untuk {u.name}"
												value={u.role}
												disabled={u.id === appState.user?.id || savingUserId === u.id}
												onchange={(e) =>
													updateUserRow(u, {
														role: e.currentTarget.value as 'user' | 'admin'
													})}
												class="rounded-[6px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-2 py-1 text-xs text-[var(--text-primary)]"
											>
												<option value="user">User</option>
												<option value="admin">Admin</option>
											</select>
										</td>
										<td data-label="Aktivitas" class="text-xs text-[var(--text-secondary)]">
											{u.conversations_count} chat · {u.documents_count} dok
										</td>
										<td data-label="Token Hari Ini" class="font-mono text-xs">
											{u.tokens_today.toLocaleString('id-ID')}
										</td>
										<td data-label="Kuota Harian">
											<div class="flex items-center gap-1.5">
												<input
													type="number"
													min="0"
													aria-label="Batas token harian {u.name}"
													bind:value={userQuotaDrafts[u.id]}
													placeholder="Ikuti provider"
													class="w-28 rounded-[6px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-2 py-1 font-mono text-xs text-[var(--text-primary)]"
												/>
												<button
													type="button"
													disabled={savingUserId === u.id}
													onclick={() => updateUserRow(u, { saveQuota: true })}
													class="rounded-[6px] bg-[var(--accent-blue)] px-2.5 py-1 text-xs font-semibold text-white cursor-pointer"
												>
													Atur
												</button>
											</div>
										</td>
										<td data-label="Status">
											<button
												type="button"
												disabled={u.id === appState.user?.id || savingUserId === u.id}
												onclick={() => updateUserRow(u, { is_active: !u.is_active })}
												class="rounded-[6px] px-2.5 py-1 text-xs font-semibold cursor-pointer {u.is_active
													? 'bg-[var(--success-bg)] text-[var(--success-text)]'
													: 'bg-[var(--danger-bg)] text-[var(--danger-text)]'}"
											>
												{u.is_active ? 'Aktif' : 'Nonaktif'}
											</button>
										</td>
									</tr>
								{/each}
							</tbody>
						</table>
					</div>
				</section>

			<!-- SECTION: USAGE BY MODEL -->
			{:else if activeSection === 'overview'}
				<section aria-labelledby="usage-model-heading" class="mt-5 space-y-3">
					<h2 id="usage-model-heading" class="text-sm font-bold text-[var(--text-primary)]">
						Distribusi Penggunaan Token per Provider & Model
					</h2>
					{#if byProvider.length === 0}
						<div class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 text-xs text-[var(--text-muted)]">
							Belum ada catatan pemakaian model AI. Statistik akan terisi otomatis ketika percakapan
							atau fitur dokumen dijalankan.
						</div>
					{:else}
						<!-- svelte-ignore a11y_no_noninteractive_tabindex -->
						<div
							class="table-wrapper"
							tabindex="0"
							role="region"
							aria-labelledby="usage-model-heading"
						>
							<table>
								<thead>
									<tr>
										<th scope="col">Provider</th>
										<th scope="col">Model</th>
										<th scope="col">Jumlah Request</th>
										<th scope="col">Total Token</th>
										<th scope="col">Rata-rata Latensi</th>
									</tr>
								</thead>
								<tbody>
									{#each byProvider as row, i (i)}
										<tr>
											<td data-label="Provider" class="font-semibold">
												{row.provider_key || 'n/a'}
											</td>
											<td data-label="Model" class="font-mono text-xs">
												{row.model_name || 'n/a'}
											</td>
											<td data-label="Jumlah Request" class="font-mono text-xs">
												{Number(row.requests).toLocaleString('id-ID')}
											</td>
											<td data-label="Total Token" class="font-mono text-xs">
												{Number(row.total_tokens ?? 0).toLocaleString('id-ID')}
											</td>
											<td data-label="Latensi" class="font-mono text-xs">
												{row.avg_latency_ms ? `${Math.round(Number(row.avg_latency_ms))} ms` : '0 ms'}
											</td>
										</tr>
									{/each}
								</tbody>
							</table>
						</div>
					{/if}
				</section>

			<!-- SECTION: SANITIZED API ERROR LOGS -->
			{:else if activeSection === 'errors'}
				<section aria-labelledby="err-log-heading" class="mt-5 space-y-3">
					<h2 id="err-log-heading" class="text-sm font-bold text-[var(--text-primary)]">
						Pemantauan Kendala & Status API Terbaru
					</h2>
					{#if recentErrors.length === 0}
						<div class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 text-xs text-[var(--success-text)]">
							Tidak ada error API yang tercatat. Seluruh panggilan berjalan normal.
						</div>
					{:else}
						<!-- svelte-ignore a11y_no_noninteractive_tabindex -->
						<div
							class="table-wrapper"
							tabindex="0"
							role="region"
							aria-labelledby="err-log-heading"
						>
							<table>
								<thead>
									<tr>
										<th scope="col">Waktu</th>
										<th scope="col">Fitur</th>
										<th scope="col">Provider</th>
										<th scope="col">Status</th>
										<th scope="col">Keterangan Teknis</th>
									</tr>
								</thead>
								<tbody>
									{#each recentErrors as err (err.id)}
										<tr>
											<td data-label="Waktu" class="text-xs text-[var(--text-muted)]">
												{formatDateShort(err.created_at)}
											</td>
											<td data-label="Fitur" class="font-mono text-xs">{err.feature}</td>
											<td data-label="Provider" class="font-mono text-xs">
												{err.provider_key ?? 'default'}
											</td>
											<td data-label="Status">
												<span class="rounded-[6px] bg-[var(--warn-bg)] px-2 py-0.5 font-mono text-[11px] text-[var(--warn-text)]">
													{err.status} ({err.http_status ?? 500})
												</span>
											</td>
											<td data-label="Keterangan" class="text-xs text-[var(--text-secondary)]">
												{err.error_message ?? err.error_code ?? 'Kendala koneksi provider'}
											</td>
										</tr>
									{/each}
								</tbody>
							</table>
						</div>
					{/if}
				</section>
			{/if}
		{/if}
	{/if}
</div>

<ModalDialog
	bind:open={addProviderModalOpen}
	title="Tambah Provider AI Baru"
	onClose={() => (addProviderModalOpen = false)}
>
	<form onsubmit={handleCreateProvider} class="space-y-3.5">
		<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
			<div>
				<label for="np-key" class="block text-xs font-semibold text-[var(--text-secondary)]">
					Kode Unik Provider (alpha_dash) *
				</label>
				<input
					id="np-key"
					type="text"
					required
					spellcheck="false"
					bind:value={newProvKey}
					placeholder="groq_cloud"
					class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 font-mono text-xs text-[var(--text-primary)]"
				/>
			</div>

			<div>
				<label for="np-name" class="block text-xs font-semibold text-[var(--text-secondary)]">
					Nama Tampilan *
				</label>
				<input
					id="np-name"
					type="text"
					required
					bind:value={newProvName}
					placeholder="Groq Cloud Inference"
					class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
				/>
			</div>
		</div>

		<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
			<div>
				<label for="np-driver" class="block text-xs font-semibold text-[var(--text-secondary)]">
					Driver Protokol *
				</label>
				<select
					id="np-driver"
					bind:value={newProvDriver}
					class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
				>
					<option value="openai_compatible">OpenAI-Compatible (/chat/completions)</option>
					<option value="anthropic">Anthropic Messages (/messages)</option>
				</select>
			</div>

			<div>
				<label for="np-model" class="block text-xs font-semibold text-[var(--text-secondary)]">
					ID Model Default *
				</label>
				<input
					id="np-model"
					type="text"
					required
					spellcheck="false"
					bind:value={newProvModel}
					placeholder="llama-3.3-70b-versatile"
					class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 font-mono text-xs text-[var(--text-primary)]"
				/>
			</div>
		</div>

		<div>
			<label for="np-url" class="block text-xs font-semibold text-[var(--text-secondary)]">
				Base URL API *
			</label>
			<input
				id="np-url"
				type="url"
				required
				spellcheck="false"
				bind:value={newProvUrl}
				placeholder="https://api.groq.com/openai/v1"
				class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 font-mono text-xs text-[var(--text-primary)]"
			/>
		</div>

		<div>
			<label for="np-apikey" class="block text-xs font-semibold text-[var(--text-secondary)]">
				API Key (Opsional, dapat diisi nanti)
			</label>
			<input
				id="np-apikey"
				type="password"
				autocomplete="off"
				spellcheck="false"
				bind:value={newProvApiKey}
				class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 font-mono text-xs text-[var(--text-primary)]"
			/>
		</div>

		{#if createProviderError}
			<p role="alert" class="text-xs text-[var(--danger-text)]">{createProviderError}</p>
		{/if}

		<div class="flex justify-end gap-2.5 pt-2">
			<button
				type="button"
				onclick={() => (addProviderModalOpen = false)}
				class="rounded-[10px] border border-[var(--border-subtle)] px-3.5 py-2 text-xs font-semibold text-[var(--text-primary)] cursor-pointer"
			>
				Batal
			</button>
			<button
				type="submit"
				disabled={creatingProvider}
				class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white cursor-pointer"
			>
				{creatingProvider ? 'Menyimpan...' : 'Simpan Provider'}
			</button>
		</div>
	</form>
</ModalDialog>
