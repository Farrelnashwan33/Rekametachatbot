<script lang="ts">
	import { goto } from '$app/navigation';
	import { apiFetch, ApiError } from '$lib/api';
	import Icon, { type IconName } from '$lib/components/Icon.svelte';
	import { appState } from '$lib/state.svelte';
	import type { AssistantCatalogItem, AssistantMode } from '$lib/types';
	import { onMount } from 'svelte';

	let assistants = $state<AssistantCatalogItem[]>([]);
	let loading = $state(true);
	let errorMessage = $state<string | null>(null);
	let selectedCategory = $state('Semua');
	let query = $state('');

	const categories = $derived.by(() => {
		const unique = new Set<string>();
		for (const item of assistants) {
			unique.add(item.category);
		}
		return ['Semua', ...Array.from(unique)];
	});

	const filteredAssistants = $derived.by(() => {
		const q = query.trim().toLowerCase();
		return assistants.filter((item) => {
			if (selectedCategory !== 'Semua' && item.category !== selectedCategory) {
				return false;
			}
			if (!q) return true;
			const inHeader =
				item.name.toLowerCase().includes(q) ||
				item.summary.toLowerCase().includes(q) ||
				item.category.toLowerCase().includes(q);
			const inPrompts = item.prompts.some(
				(p) => p.label.toLowerCase().includes(q) || p.prompt.toLowerCase().includes(q)
			);
			return inHeader || inPrompts;
		});
	});

	function modeIcon(mode: AssistantMode): IconName {
		switch (mode) {
			case 'academic':
				return 'academic';
			case 'cv':
				return 'cv';
			case 'document':
				return 'document';
			case 'coding':
				return 'code';
			case 'creative':
				return 'pen';
			default:
				return 'compass';
		}
	}

	async function loadCatalog(): Promise<void> {
		loading = true;
		errorMessage = null;
		try {
			const res = await apiFetch<{ assistants: AssistantCatalogItem[] }>(
				'/api/assistants/catalog'
			);
			assistants = res.assistants;
		} catch (err) {
			errorMessage =
				err instanceof ApiError
					? err.message
					: 'Gagal memuat katalog asisten. Periksa koneksi jaringan Anda.';
		} finally {
			loading = false;
		}
	}

	async function launchPrompt(
		item: AssistantCatalogItem,
		starter: { label: string; prompt: string; direct_route?: string }
	): Promise<void> {
		if (starter.direct_route) {
			await goto(starter.direct_route);
			return;
		}
		appState.pendingStarter = {
			mode: item.mode,
			prompt: starter.prompt
		};
		await goto('/');
	}

	async function openBlankMode(item: AssistantCatalogItem): Promise<void> {
		if (item.route && item.route !== '/') {
			await goto(item.route);
			return;
		}
		appState.pendingStarter = {
			mode: item.mode,
			prompt: ''
		};
		await goto('/');
	}

	onMount(() => {
		void loadCatalog();
	});
</script>

<svelte:head>
	<title>Explore AI | Rekametachat</title>
</svelte:head>

<div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
	<header class="border-b border-[var(--border-subtle)] pb-5">
		<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
			<div class="max-w-2xl">
				<p class="text-xs font-semibold tracking-wide text-[var(--accent-link)] uppercase">
					Direktori Mode Kerja
				</p>
				<h1
					id="main-stage"
					tabindex="-1"
					class="mt-1 text-2xl font-bold tracking-tight text-[var(--text-primary)] sm:text-3xl"
				>
					Explore AI
				</h1>
				<p class="mt-1.5 text-sm text-[var(--text-secondary)]">
					Pilih asisten khusus untuk diskusi akademik bertahap, pemrograman dan debugging,
					penulisan naskah resmi, evaluasi CV ATS, atau bedah dokumen.
				</p>
			</div>

			<div class="w-full lg:w-72">
				<label for="explore-search" class="visually-hidden">Cari topik atau asisten</label>
				<div class="relative flex items-center">
					<Icon
						name="search"
						class="pointer-events-none absolute start-3 h-4 w-4 text-[var(--text-muted)]"
					/>
					<input
						id="explore-search"
						type="search"
						enterkeyhint="search"
						bind:value={query}
						placeholder="Cari topik, misal: SQL, matematika, surat..."
						class="w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] py-2 ps-9 pe-3 text-xs text-[var(--text-primary)]"
					/>
				</div>
			</div>
		</div>

		{#if categories.length > 1}
			<div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="Filter kategori asisten">
				{#each categories as cat (cat)}
					<button
						type="button"
						onclick={() => (selectedCategory = cat)}
						aria-pressed={selectedCategory === cat}
						class="rounded-[6px] px-3 py-1.5 text-xs font-medium transition-colors cursor-pointer {selectedCategory ===
						cat
							? 'bg-[var(--accent-blue)] text-white'
							: 'border border-[var(--border-subtle)] bg-[var(--bg-surface)] text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)]'}"
					>
						{cat}
					</button>
				{/each}
			</div>
		{/if}
	</header>

	<div class="mt-6">
		{#if loading}
			<div
				class="flex flex-col items-center justify-center rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] py-14"
			>
				<progress class="w-44" aria-label="Memuat daftar mode asisten"></progress>
				<p class="mt-2.5 text-xs text-[var(--text-muted)]">Memuat katalog mode kerja...</p>
			</div>
		{:else if errorMessage}
			<div
				role="alert"
				class="rounded-[14px] border border-[var(--danger-text)]/30 bg-[var(--danger-bg)] p-5 text-[var(--danger-text)]"
			>
				<p class="text-sm font-semibold">Katalog tidak dapat dimuat</p>
				<p class="mt-1 text-xs">{errorMessage}</p>
				<button
					type="button"
					onclick={() => loadCatalog()}
					class="mt-3 rounded-[10px] bg-[var(--accent-blue)] px-3.5 py-2 text-xs font-semibold text-white cursor-pointer"
				>
					Coba Lagi
				</button>
			</div>
		{:else if filteredAssistants.length === 0}
			<div
				class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-8 text-center"
			>
				<p class="text-sm font-semibold text-[var(--text-primary)]">
					Tidak ada mode kerja yang cocok
				</p>
				<p class="mt-1 text-xs text-[var(--text-muted)]">
					Ubah kata kunci pencarian atau tampilkan seluruh kategori.
				</p>
				<button
					type="button"
					onclick={() => {
						query = '';
						selectedCategory = 'Semua';
					}}
					class="mt-4 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-elevated)] px-3.5 py-2 text-xs font-semibold text-[var(--text-primary)] cursor-pointer"
				>
					Reset Filter
				</button>
			</div>
		{:else}
			<!-- Asymmetric 12-column bento layout (7 / 5 / 5 / 7 / 12) -->
			<div class="grid grid-cols-1 gap-5 lg:grid-cols-12">
				{#each filteredAssistants as item, idx (item.id)}
					{@const spanClass =
						idx === 0
							? 'lg:col-span-7'
							: idx === 1
								? 'lg:col-span-5'
								: idx === 2
									? 'lg:col-span-5'
									: idx === 3
										? 'lg:col-span-7'
										: 'lg:col-span-12'}
					<section
						aria-labelledby="asst-heading-{item.id}"
						class="{spanClass} flex flex-col justify-between rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5"
					>
						<div>
							<div class="flex flex-wrap items-start justify-between gap-3">
								<div class="flex items-center gap-3">
									<div
										class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] bg-[var(--accent-violet-bg)] text-[var(--accent-violet-text)]"
									>
										<Icon name={modeIcon(item.mode)} class="h-5 w-5" />
									</div>
									<div>
										<span
											class="inline-block rounded-[6px] bg-[var(--bg-elevated)] px-2 py-0.5 text-[11px] font-semibold text-[var(--text-secondary)]"
										>
											{item.category}
										</span>
										<h2
											id="asst-heading-{item.id}"
											class="mt-0.5 text-base font-bold text-[var(--text-primary)]"
										>
											{item.name}
										</h2>
									</div>
								</div>

								<button
									type="button"
									onclick={() => openBlankMode(item)}
									class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-1.5 text-xs font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
								>
									{item.route !== '/' ? 'Buka Studio' : 'Mulai Mode Ini'}
								</button>
							</div>

							<p class="mt-3 text-xs leading-relaxed text-[var(--text-secondary)]">
								{item.summary}
							</p>

							<div class="mt-4 space-y-2">
								<p class="text-[11px] font-semibold text-[var(--text-muted)]">
									Skenario Siap Pakai:
								</p>
								<div
									class="grid grid-cols-1 gap-2 {idx === 4 ? 'md:grid-cols-2' : ''}"
								>
									{#each item.prompts as starter (starter.label)}
										<button
											type="button"
											onclick={() => launchPrompt(item, starter)}
											class="group flex w-full flex-col items-start rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3.5 py-2.5 text-start transition-colors hover:border-[var(--accent-link)] hover:bg-[var(--bg-elevated)] cursor-pointer"
										>
											<span
												class="text-xs font-semibold text-[var(--text-primary)] group-hover:text-[var(--accent-link)]"
											>
												{starter.label}
											</span>
											{#if starter.prompt}
												<span class="mt-1 line-clamp-2 text-[11px] text-[var(--text-muted)]">
													{starter.prompt}
												</span>
											{:else if starter.direct_route}
												<span class="mt-1 text-[11px] text-[var(--accent-link)]">
													Buka ruang kerja khusus {starter.direct_route}
												</span>
											{/if}
										</button>
									{/each}
								</div>
							</div>
						</div>
					</section>
				{/each}
			</div>
		{/if}
	</div>
</div>

