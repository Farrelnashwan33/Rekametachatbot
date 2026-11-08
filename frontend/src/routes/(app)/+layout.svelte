<script lang="ts">
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { apiFetch } from '$lib/api';
	import BrandMark from '$lib/components/BrandMark.svelte';
	import Icon, { type IconName } from '$lib/components/Icon.svelte';
	import ModalDialog from '$lib/components/ModalDialog.svelte';
	import { appState } from '$lib/state.svelte';
	import { themeManager } from '$lib/theme.svelte';
	import type { Conversation } from '$lib/types';
	import type { LayoutProps } from './$types';

	let { children }: LayoutProps = $props();

	let showHistoryPane = $state(true);
	let mobileMenuOpen = $state(false);
	let searchQuery = $state('');
	let searchTimeout: ReturnType<typeof setTimeout> | null = null;

	$effect(() => {
		if (appState.authChecked && !appState.user) {
			void goto('/login');
		}
	});

	const navItems = $derived.by(() => {
		const base: { href: string; label: string; icon: IconName }[] = [
			{ href: '/', label: 'Chat', icon: 'chat' },
			{ href: '/explore', label: 'Explore AI', icon: 'compass' },
			{ href: '/cv', label: 'CV Studio', icon: 'cv' },
			{ href: '/documents', label: 'Documents', icon: 'document' },
			{ href: '/settings', label: 'Settings', icon: 'settings' }
		];
		if (appState.user?.role === 'admin') {
			base.push({ href: '/admin', label: 'Admin', icon: 'shield' });
		}
		return base;
	});

	function isActivePath(href: string, pathname: string): boolean {
		if (href === '/') {
			return pathname === '/' || pathname.startsWith('/c/');
		}
		return pathname === href || pathname.startsWith(`${href}/`);
	}

	function userInitials(name?: string): string {
		if (!name) return 'RM';
		return name
			.trim()
			.split(/\s+/)
			.slice(0, 2)
			.map((p) => p[0]?.toUpperCase() ?? '')
			.join('');
	}

	const groupedConversations = $derived.by(() => {
		const pinned: Conversation[] = [];
		const today: Conversation[] = [];
		const yesterday: Conversation[] = [];
		const earlier: Conversation[] = [];

		const now = new Date();
		const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
		const startOfYesterday = startOfToday - 86_400_000;

		for (const conv of appState.conversations) {
			if (conv.is_pinned) {
				pinned.push(conv);
				continue;
			}
			const ts = new Date(conv.last_message_at ?? conv.updated_at).getTime();
			if (ts >= startOfToday) {
				today.push(conv);
			} else if (ts >= startOfYesterday) {
				yesterday.push(conv);
			} else {
				earlier.push(conv);
			}
		}

		return [
			{ label: 'Disematkan', items: pinned },
			{ label: 'Hari Ini', items: today },
			{ label: 'Kemarin', items: yesterday },
			{ label: 'Sebelumnya', items: earlier }
		].filter((g) => g.items.length > 0);
	});

	function handleSearchInput() {
		if (searchTimeout) clearTimeout(searchTimeout);
		searchTimeout = setTimeout(() => {
			void appState.refreshConversations(searchQuery);
		}, 250);
	}

	async function togglePinConversation(conv: Conversation) {
		try {
			const res = await apiFetch<{ conversation: Conversation }>(
				`/api/conversations/${conv.uuid}`,
				{
					method: 'PATCH',
					body: JSON.stringify({ is_pinned: !conv.is_pinned })
				}
			);
			appState.upsertConversation(res.conversation);
		} catch {
			appState.notify('Gagal memperbarui sematan.', 'error');
		}
	}

	async function deleteConversation(conv: Conversation) {
		try {
			await apiFetch(`/api/conversations/${conv.uuid}`, { method: 'DELETE' });
			appState.removeConversation(conv.uuid);
			appState.notify('Percakapan dihapus.', 'info');
			if (page.url.pathname === `/c/${conv.uuid}`) {
				await goto('/');
			}
		} catch {
			appState.notify('Gagal menghapus percakapan.', 'error');
		}
	}
</script>

{#if !appState.authChecked}
	<div class="flex min-h-dvh items-center justify-center bg-[var(--bg-canvas)]">
		<div class="text-center">
			<progress class="w-44" aria-label="Memuat ruang kerja Rekametachat"></progress>
			<p class="mt-2 text-xs text-[var(--text-muted)]">Menyiapkan ruang kerja Rekametachat...</p>
		</div>
	</div>
{:else if appState.user}
	<div class="flex h-dvh w-full overflow-hidden bg-[var(--bg-canvas)]">
		<!-- Desktop Primary Sidebar Rail (Midnight Navy inspired by reference image) -->
		<aside
			class="hidden w-[212px] shrink-0 flex-col justify-between border-r border-[#1e293b] bg-[#080d1a] p-3.5 text-[#e2e8f0] md:flex"
		>
			<div class="space-y-4">
				<div class="flex items-center justify-between px-1">
					<a href="/" class="min-w-0">
						<BrandMark lightText={true} />
					</a>
					<button
						type="button"
						onclick={() => (showHistoryPane = !showHistoryPane)}
						aria-label={showHistoryPane ? 'Sembunyikan panel riwayat' : 'Tampilkan panel riwayat'}
						aria-expanded={showHistoryPane}
						class="inline-flex h-8 w-8 items-center justify-center rounded-[10px] text-[#94a3b8] hover:bg-[#15203b] hover:text-white cursor-pointer"
					>
						<Icon name="sidebar" class="h-4 w-4" />
					</button>
				</div>

				<a
					href="/"
					onclick={() => {
						appState.pendingStarter = null;
					}}
					class="flex w-full items-center justify-center gap-2 rounded-[10px] bg-[#2563eb] px-3 py-2.5 text-xs font-semibold text-white transition-colors hover:bg-[#1d4ed8]"
				>
					<Icon name="plus" class="h-4 w-4" />
					<span>New Chat</span>
				</a>

				<nav aria-label="Menu utama" class="space-y-1">
					{#each navItems as item (item.href)}
						{@const active = isActivePath(item.href, page.url.pathname)}
						<a
							href={item.href}
							aria-current={active ? 'page' : undefined}
							class="flex items-center gap-2.5 rounded-[10px] px-3 py-2 text-xs font-medium transition-colors {active
								? 'bg-[#182444] text-white font-semibold'
								: 'text-[#cbd5e1] hover:bg-[#111a33] hover:text-white'}"
						>
							<Icon
								name={item.icon}
								class="h-4 w-4 {active ? 'text-[#60a5fa]' : 'text-[#94a3b8]'}"
							/>
							<span>{item.label}</span>
						</a>
					{/each}
				</nav>
			</div>

			<!-- Bottom Profile & Theme Controls -->
			<div class="space-y-2 border-t border-[#1e293b] pt-3">
				<div class="flex items-center justify-between px-1">
					<span class="text-[11px] text-[#94a3b8]">Tema Tampilan</span>
					<button
						type="button"
						onclick={() => themeManager.toggleComfort()}
						aria-label={themeManager.resolvedScheme === 'dark'
							? 'Ganti ke tema terang'
							: 'Ganti ke tema gelap'}
						class="inline-flex items-center gap-1.5 rounded-[6px] bg-[#131d36] px-2.5 py-1 text-[11px] font-medium text-[#e2e8f0] hover:bg-[#1d2c50] cursor-pointer"
					>
						<Icon name={themeManager.resolvedScheme === 'dark' ? 'sun' : 'moon'} class="h-3.5 w-3.5" />
						<span>{themeManager.resolvedScheme === 'dark' ? 'Terang' : 'Gelap'}</span>
					</button>
				</div>

				<div class="flex items-center justify-between gap-2 rounded-[10px] bg-[#10182d] p-2">
					<a href="/settings" class="flex min-w-0 items-center gap-2.5">
						<div
							aria-hidden="true"
							class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] bg-[#2563eb] text-xs font-bold text-white"
						>
							{userInitials(appState.user.name)}
						</div>
						<div class="min-w-0">
							<p class="truncate text-xs font-semibold text-white">{appState.user.name}</p>
							<p class="truncate text-[11px] text-[#94a3b8]">{appState.user.email}</p>
						</div>
					</a>
					<button
						type="button"
						onclick={() => appState.logout()}
						aria-label="Keluar dari akun"
						class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] text-[#94a3b8] hover:bg-[#3b1219] hover:text-[#fecaca] cursor-pointer"
					>
						<Icon name="logout" class="h-4 w-4" />
					</button>
				</div>
			</div>
		</aside>

		<!-- Desktop Secondary Conversation History Panel (Today / Yesterday / Earlier) -->
		{#if showHistoryPane}
			<aside
				aria-label="Panel riwayat percakapan"
				class="hidden w-[256px] shrink-0 flex-col border-r border-[var(--border-subtle)] bg-[var(--bg-surface)] lg:flex"
			>
				<div class="border-b border-[var(--border-subtle)] p-3.5">
					<div class="flex items-center justify-between mb-2.5">
						<span class="text-xs font-bold text-[var(--text-primary)]">Riwayat Percakapan</span>
						<span class="text-[11px] text-[var(--text-muted)]">
							{appState.conversations.length}
						</span>
					</div>
					<div class="relative flex items-center">
						<Icon
							name="search"
							class="pointer-events-none absolute start-2.5 h-3.5 w-3.5 text-[var(--text-muted)]"
						/>
						<label for="conv-search-desktop" class="visually-hidden">Cari percakapan</label>
						<input
							id="conv-search-desktop"
							type="search"
							enterkeyhint="search"
							bind:value={searchQuery}
							oninput={handleSearchInput}
							placeholder="Cari riwayat..."
							class="w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] py-1.5 ps-8 pe-2.5 text-xs text-[var(--text-primary)]"
						/>
					</div>
				</div>

				<nav aria-label="Riwayat percakapan" class="flex-1 overflow-y-auto p-2.5 space-y-4">
					{#if appState.loadingConversations && appState.conversations.length === 0}
						<div class="px-2 py-6 text-center">
							<progress class="w-28" aria-label="Memuat daftar percakapan"></progress>
						</div>
					{:else if groupedConversations.length === 0}
						<p class="px-2 py-6 text-center text-xs text-[var(--text-muted)]">
							{searchQuery.trim()
								? 'Tidak ada percakapan yang cocok dengan pencarian.'
								: 'Belum ada riwayat percakapan. Mulai chat baru untuk menyimpan diskusi Anda.'}
						</p>
					{:else}
						{#each groupedConversations as group (group.label)}
							<div>
								<p class="px-2 pb-1 text-[11px] font-semibold text-[var(--text-muted)]">
									{group.label}
								</p>
								<ul class="space-y-1" role="list">
									{#each group.items as conv (conv.uuid)}
										{@const activeConv = page.url.pathname === `/c/${conv.uuid}`}
										<li
											class="group flex items-center justify-between gap-1 rounded-[10px] px-2 py-1.5 {activeConv
												? 'bg-[var(--bg-elevated)] font-semibold'
												: 'hover:bg-[var(--bg-elevated)]'}"
										>
											<a
												href="/c/{conv.uuid}"
												class="min-w-0 flex-1 truncate text-xs text-[var(--text-primary)]"
											>
												{conv.title}
											</a>
											<div class="flex shrink-0 items-center opacity-75 group-hover:opacity-100">
												<button
													type="button"
													onclick={() => togglePinConversation(conv)}
													aria-label={conv.is_pinned
														? `Lepas sematan ${conv.title}`
														: `Sematkan ${conv.title}`}
													class="inline-flex h-6 w-6 items-center justify-center rounded-[6px] {conv.is_pinned
														? 'text-[var(--accent-link)]'
														: 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'} cursor-pointer"
												>
													<Icon name="pin" class="h-3 w-3" />
												</button>
												<button
													type="button"
													onclick={() => deleteConversation(conv)}
													aria-label={`Hapus percakapan ${conv.title}`}
													class="inline-flex h-6 w-6 items-center justify-center rounded-[6px] text-[var(--text-muted)] hover:text-[var(--danger-text)] cursor-pointer"
												>
													<Icon name="trash" class="h-3 w-3" />
												</button>
											</div>
										</li>
									{/each}
								</ul>
							</div>
						{/each}
					{/if}
				</nav>
			</aside>
		{/if}

		<!-- Right Main Workspace Column -->
		<div class="flex min-w-0 flex-1 flex-col">
			<!-- Mobile Top Bar -->
			<div
				class="flex items-center justify-between border-b border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3.5 py-2.5 md:hidden"
			>
				<button
					type="button"
					onclick={() => (mobileMenuOpen = true)}
					aria-label="Buka menu navigasi dan riwayat"
					class="inline-flex h-10 w-10 items-center justify-center rounded-[10px] border border-[var(--border-subtle)] text-[var(--text-primary)]"
				>
					<Icon name="menu" class="h-5 w-5" />
				</button>

				<a href="/">
					<BrandMark />
				</a>

				<a
					href="/"
					aria-label="Percakapan Baru"
					class="inline-flex h-10 w-10 items-center justify-center rounded-[10px] bg-[var(--accent-blue)] text-white"
				>
					<Icon name="plus" class="h-5 w-5" />
				</a>
			</div>

			<main class="flex-1 min-h-0 overflow-y-auto">
				{@render children()}
			</main>
		</div>
	</div>

	<!-- Mobile Navigation & History Drawer -->
	<ModalDialog
		bind:open={mobileMenuOpen}
		drawer={true}
		title="Menu Rekametachat"
		onClose={() => (mobileMenuOpen = false)}
	>
		<div class="space-y-5">
			<a
				href="/"
				onclick={() => (mobileMenuOpen = false)}
				class="flex w-full items-center justify-center gap-2 rounded-[10px] bg-[var(--accent-blue)] px-3 py-2.5 text-xs font-semibold text-white"
			>
				<Icon name="plus" class="h-4 w-4" />
				<span>New Chat</span>
			</a>

			<nav aria-label="Menu utama seluler" class="space-y-1">
				{#each navItems as item (item.href)}
					{@const active = isActivePath(item.href, page.url.pathname)}
					<a
						href={item.href}
						onclick={() => (mobileMenuOpen = false)}
						class="tap-target flex items-center gap-2.5 rounded-[10px] px-3 py-2.5 text-sm font-medium {active
							? 'bg-[var(--bg-elevated)] font-semibold text-[var(--text-primary)]'
							: 'text-[var(--text-secondary)]'}"
					>
						<Icon name={item.icon} class="h-4 w-4" />
						<span>{item.label}</span>
					</a>
				{/each}
			</nav>

			<div class="border-t border-[var(--border-subtle)] pt-4">
				<p class="mb-2 text-xs font-semibold text-[var(--text-muted)]">Riwayat Terbaru</p>
				{#if appState.conversations.length === 0}
					<p class="text-xs text-[var(--text-muted)]">Belum ada riwayat percakapan.</p>
				{:else}
					<ul class="space-y-1" role="list">
						{#each appState.conversations.slice(0, 15) as conv (conv.uuid)}
							<li>
								<a
									href="/c/{conv.uuid}"
									onclick={() => (mobileMenuOpen = false)}
									class="tap-target block truncate rounded-[10px] px-2.5 py-2 text-xs text-[var(--text-primary)] hover:bg-[var(--bg-elevated)]"
								>
									{conv.title}
								</a>
							</li>
						{/each}
					</ul>
				{/if}
			</div>

			<div class="flex items-center justify-between border-t border-[var(--border-subtle)] pt-4">
				<button
					type="button"
					onclick={() => themeManager.toggleComfort()}
					class="rounded-[10px] border border-[var(--border-subtle)] px-3 py-2 text-xs font-medium text-[var(--text-primary)]"
				>
					Tema: {themeManager.resolvedScheme === 'dark' ? 'Gelap' : 'Terang'}
				</button>
				<button
					type="button"
					onclick={() => {
						mobileMenuOpen = false;
						void appState.logout();
					}}
					class="rounded-[10px] bg-[var(--danger-bg)] px-3 py-2 text-xs font-semibold text-[var(--danger-text)]"
				>
					Keluar
				</button>
			</div>
		</div>
	</ModalDialog>
{/if}

