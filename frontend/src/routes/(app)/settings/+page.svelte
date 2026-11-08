<script lang="ts">
	import { goto } from '$app/navigation';
	import { ApiError, apiFetch, setStoredToken } from '$lib/api';
	import Icon from '$lib/components/Icon.svelte';
	import ModalDialog from '$lib/components/ModalDialog.svelte';
	import { appState } from '$lib/state.svelte';
	import { themeManager } from '$lib/theme.svelte';
	import type { ThemeMode, User, UserMemory, UserPreference } from '$lib/types';
	import { onMount } from 'svelte';

	// Profile form state
	let profileName = $state('');
	let profileEmail = $state('');
	let profileBio = $state('');
	let savingProfile = $state(false);
	let profileError = $state<string | null>(null);

	// Preferences state
	let prefTheme = $state<ThemeMode>('system');
	let prefLanguage = $state<'id' | 'en'>('id');
	let prefStyle = $state<'balanced' | 'concise' | 'detailed' | 'academic' | 'casual'>('balanced');
	let prefVoice = $state(true);
	let prefMemoryEnabled = $state(true);
	let prefMemoryAutoSave = $state(false);
	let savingPrefs = $state(false);

	// Password form state
	let currentPassword = $state('');
	let newPassword = $state('');
	let showPasswords = $state(false);
	let savingPassword = $state(false);
	let passwordError = $state<string | null>(null);

	// Personal Memory Manager state
	let memories = $state<UserMemory[]>([]);
	let loadingMemories = $state(true);
	let memoryError = $state<string | null>(null);

	let newMemCategory = $state<UserMemory['category']>('preference');
	let newMemKey = $state('');
	let newMemValue = $state('');
	let newMemImportance = $state(3);
	let addingMemory = $state(false);

	let editingMemId = $state<number | null>(null);
	let editMemKey = $state('');
	let editMemValue = $state('');
	let editMemCategory = $state<UserMemory['category']>('preference');
	let editMemImportance = $state(3);

	// Privacy deletion dialog state
	type DeleteScope = 'conversations' | 'memories' | 'documents' | 'all_data' | 'delete_account';
	let pendingDeleteScope = $state<DeleteScope | null>(null);
	let deleteConfirmPassword = $state('');
	let deleteModalOpen = $state(false);
	let executingDelete = $state(false);
	let deleteError = $state<string | null>(null);

	const categoryLabels: Record<UserMemory['category'], string> = {
		preference: 'Preferensi',
		profile: 'Profil',
		goal: 'Tujuan',
		project: 'Proyek',
		academic: 'Akademik',
		skill: 'Keahlian'
	};

	const pendingSuggestions = $derived(memories.filter((m) => !m.is_confirmed));
	const confirmedMemories = $derived(memories.filter((m) => m.is_confirmed));

	function syncFromUser(): void {
		if (!appState.user) return;
		profileName = appState.user.name;
		profileEmail = appState.user.email;
		profileBio = appState.user.bio ?? '';

		const p = appState.user.preference;
		prefTheme = p?.theme ?? themeManager.selectedMode;
		prefLanguage = p?.language ?? 'id';
		prefStyle = p?.communication_style ?? 'balanced';
		prefVoice = p?.voice_enabled ?? true;
		prefMemoryEnabled = p?.memory_enabled ?? true;
		prefMemoryAutoSave = p?.memory_auto_save ?? false;
	}

	async function loadMemories(): Promise<void> {
		loadingMemories = true;
		memoryError = null;
		try {
			const res = await apiFetch<{
				memory_enabled: boolean;
				memory_auto_save: boolean;
				memories: UserMemory[];
			}>('/api/memories');
			memories = res.memories;
			prefMemoryEnabled = res.memory_enabled;
			prefMemoryAutoSave = res.memory_auto_save;
		} catch (err) {
			memoryError = err instanceof ApiError ? err.message : 'Gagal memuat memori personal.';
		} finally {
			loadingMemories = false;
		}
	}

	async function handleSaveProfile(e: SubmitEvent): Promise<void> {
		e.preventDefault();
		savingProfile = true;
		profileError = null;
		try {
			const res = await apiFetch<{ user: User }>('/api/profile', {
				method: 'PUT',
				body: JSON.stringify({
					name: profileName.trim(),
					email: profileEmail.trim(),
					bio: profileBio.trim() || null
				})
			});
			appState.user = res.user;
			appState.notify('Profil akun berhasil diperbarui.', 'success');
		} catch (err) {
			profileError = err instanceof ApiError ? err.message : 'Gagal menyimpan profil.';
		} finally {
			savingProfile = false;
		}
	}

	async function handleSavePreferences(): Promise<void> {
		savingPrefs = true;
		try {
			themeManager.setMode(prefTheme);
			const res = await apiFetch<{ preference: UserPreference }>('/api/profile/preferences', {
				method: 'PUT',
				body: JSON.stringify({
					theme: prefTheme,
					language: prefLanguage,
					communication_style: prefStyle,
					voice_enabled: prefVoice,
					memory_enabled: prefMemoryEnabled,
					memory_auto_save: prefMemoryAutoSave
				})
			});
			if (appState.user) {
				appState.user = { ...appState.user, preference: res.preference };
			}
			appState.notify('Preferensi kerja berhasil disimpan.', 'success');
		} catch (err) {
			appState.notify(
				err instanceof ApiError ? err.message : 'Gagal menyimpan preferensi.',
				'error'
			);
		} finally {
			savingPrefs = false;
		}
	}

	async function handleChangePassword(e: SubmitEvent): Promise<void> {
		e.preventDefault();
		passwordError = null;
		if (newPassword.length < 8) {
			passwordError = 'Kata sandi baru wajib minimal 8 karakter.';
			return;
		}
		savingPassword = true;
		try {
			await apiFetch('/api/profile/password', {
				method: 'PUT',
				body: JSON.stringify({
					current_password: currentPassword,
					password: newPassword
				})
			});
			currentPassword = '';
			newPassword = '';
			appState.notify('Kata sandi berhasil diperbarui.', 'success');
		} catch (err) {
			passwordError = err instanceof ApiError ? err.message : 'Gagal memperbarui kata sandi.';
		} finally {
			savingPassword = false;
		}
	}

	async function handleAddMemory(e: SubmitEvent): Promise<void> {
		e.preventDefault();
		if (newMemKey.trim().length < 2 || newMemValue.trim().length < 2) {
			appState.notify('Isi topik dan rincian memori minimal 2 karakter.', 'error');
			return;
		}
		addingMemory = true;
		try {
			const res = await apiFetch<{ memory: UserMemory }>('/api/memories', {
				method: 'POST',
				body: JSON.stringify({
					category: newMemCategory,
					memory_key: newMemKey.trim(),
					memory_value: newMemValue.trim(),
					importance: newMemImportance
				})
			});
			memories = [res.memory, ...memories];
			newMemKey = '';
			newMemValue = '';
			newMemImportance = 3;
			appState.notify('Memori personal ditambahkan.', 'success');
		} catch (err) {
			appState.notify(err instanceof ApiError ? err.message : 'Gagal menambah memori.', 'error');
		} finally {
			addingMemory = false;
		}
	}

	function startEditMemory(mem: UserMemory): void {
		editingMemId = mem.id;
		editMemCategory = mem.category;
		editMemKey = mem.memory_key;
		editMemValue = mem.memory_value;
		editMemImportance = mem.importance;
	}

	async function saveEditMemory(id: number): Promise<void> {
		try {
			const res = await apiFetch<{ memory: UserMemory }>(`/api/memories/${id}`, {
				method: 'PATCH',
				body: JSON.stringify({
					category: editMemCategory,
					memory_key: editMemKey.trim(),
					memory_value: editMemValue.trim(),
					importance: editMemImportance,
					is_confirmed: true
				})
			});
			memories = memories.map((m) => (m.id === id ? res.memory : m));
			editingMemId = null;
			appState.notify('Memori diperbarui.', 'success');
		} catch (err) {
			appState.notify(err instanceof ApiError ? err.message : 'Gagal memperbarui memori.', 'error');
		}
	}

	async function approveSuggestion(mem: UserMemory): Promise<void> {
		try {
			const res = await apiFetch<{ memory: UserMemory }>(`/api/memories/${mem.id}`, {
				method: 'PATCH',
				body: JSON.stringify({ is_confirmed: true, is_active: true })
			});
			memories = memories.map((m) => (m.id === mem.id ? res.memory : m));
			appState.notify('Memori disetujui dan diaktifkan.', 'success');
		} catch {
			appState.notify('Gagal menyetujui memori.', 'error');
		}
	}

	async function toggleMemoryActive(mem: UserMemory): Promise<void> {
		try {
			const res = await apiFetch<{ memory: UserMemory }>(`/api/memories/${mem.id}`, {
				method: 'PATCH',
				body: JSON.stringify({ is_active: !mem.is_active })
			});
			memories = memories.map((m) => (m.id === mem.id ? res.memory : m));
		} catch {
			appState.notify('Gagal mengubah status memori.', 'error');
		}
	}

	async function deleteSingleMemory(id: number): Promise<void> {
		try {
			await apiFetch(`/api/memories/${id}`, { method: 'DELETE' });
			memories = memories.filter((m) => m.id !== id);
			appState.notify('Memori dihapus.', 'info');
		} catch {
			appState.notify('Gagal menghapus memori.', 'error');
		}
	}

	function openPrivacyModal(scope: DeleteScope): void {
		pendingDeleteScope = scope;
		deleteConfirmPassword = '';
		deleteError = null;
		deleteModalOpen = true;
	}

	async function executePrivacyDeletion(): Promise<void> {
		if (!pendingDeleteScope) return;
		executingDelete = true;
		deleteError = null;
		try {
			await apiFetch('/api/profile/data', {
				method: 'DELETE',
				body: JSON.stringify({
					scope: pendingDeleteScope,
					password:
						pendingDeleteScope === 'delete_account' ? deleteConfirmPassword : undefined
				})
			});

			if (pendingDeleteScope === 'delete_account') {
				setStoredToken(null);
				appState.user = null;
				appState.conversations = [];
				deleteModalOpen = false;
				await goto('/login');
				return;
			}

			if (pendingDeleteScope === 'conversations' || pendingDeleteScope === 'all_data') {
				appState.conversations = [];
			}
			if (pendingDeleteScope === 'memories' || pendingDeleteScope === 'all_data') {
				memories = [];
			}

			deleteModalOpen = false;
			pendingDeleteScope = null;
			appState.notify('Data yang dipilih telah dihapus secara permanen.', 'info');
		} catch (err) {
			deleteError = err instanceof ApiError ? err.message : 'Gagal menghapus data.';
		} finally {
			executingDelete = false;
		}
	}

	onMount(() => {
		syncFromUser();
		void loadMemories();
	});
</script>

<svelte:head>
	<title>Settings | Rekametachat</title>
</svelte:head>

<div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
	<header class="border-b border-[var(--border-subtle)] pb-5">
		<p class="text-xs font-semibold tracking-wide text-[var(--accent-link)] uppercase">
			Akun, Memori & Privasi
		</p>
		<h1
			id="main-stage"
			tabindex="-1"
			class="mt-1 text-2xl font-bold tracking-tight text-[var(--text-primary)] sm:text-3xl"
		>
			Pengaturan & Memori Personal
		</h1>
		<p class="mt-1 text-sm text-[var(--text-secondary)]">
			Kelola profil akun, tema antarmuka, gaya komunikasi asisten, daftar memori personal yang
			diizinkan, serta kontrol penghapusan data.
		</p>
	</header>

	<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
		<!-- Left Column: Profile, Preferences & Password (5 cols) -->
		<div class="lg:col-span-5 space-y-6">
			<!-- Profile Form -->
			<section
				aria-labelledby="profile-heading"
				class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5"
			>
				<h2 id="profile-heading" class="text-sm font-bold text-[var(--text-primary)]">
					Profil Pengguna
				</h2>
				<form onsubmit={handleSaveProfile} class="mt-4 space-y-3.5">
					<div>
						<label for="prof-name" class="block text-xs font-semibold text-[var(--text-secondary)]">
							Nama Lengkap
						</label>
						<input
							id="prof-name"
							type="text"
							autocomplete="name"
							required
							bind:value={profileName}
							class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
						/>
					</div>

					<div>
						<label for="prof-email" class="block text-xs font-semibold text-[var(--text-secondary)]">
							Alamat Email
						</label>
						<input
							id="prof-email"
							type="email"
							autocomplete="username"
							spellcheck="false"
							required
							bind:value={profileEmail}
							class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
						/>
					</div>

					<div>
						<label for="prof-bio" class="block text-xs font-semibold text-[var(--text-secondary)]">
							Peran / Fokus Singkat (Opsional)
						</label>
						<textarea
							id="prof-bio"
							rows="2"
							maxlength="500"
							bind:value={profileBio}
							placeholder="Misal: Mahasiswa Ilmu Komputer & Analis Data"
							class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-2.5 text-xs text-[var(--text-primary)]"
						></textarea>
					</div>

					{#if profileError}
						<p role="alert" class="text-xs text-[var(--danger-text)]">{profileError}</p>
					{/if}

					<button
						type="submit"
						disabled={savingProfile}
						class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
					>
						{savingProfile ? 'Menyimpan...' : 'Simpan Profil'}
					</button>
				</form>
			</section>

			<!-- Display & Interaction Preferences -->
			<section
				aria-labelledby="pref-heading"
				class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-4"
			>
				<h2 id="pref-heading" class="text-sm font-bold text-[var(--text-primary)]">
					Tema & Gaya Interaksi
				</h2>

				<div>
					<span class="block text-xs font-semibold text-[var(--text-secondary)]">
						Tema Warna Aplikasi
					</span>
					<div class="mt-1.5 grid grid-cols-3 gap-2" role="group" aria-label="Pilih tema warna">
						{#each [
							{
								id: 'system',
								label: `Sistem (${themeManager.systemIsDark ? 'Gelap' : 'Terang'})`
							},
							{ id: 'light', label: 'Terang' },
							{ id: 'dark', label: 'Gelap' }
						] as opt (opt.id)}
							<button
								type="button"
								onclick={() => {
									prefTheme = opt.id as ThemeMode;
									themeManager.setMode(prefTheme);
								}}
								aria-pressed={prefTheme === opt.id}
								class="rounded-[10px] border px-2.5 py-2 text-xs font-semibold cursor-pointer {prefTheme ===
								opt.id
									? 'border-[var(--accent-link)] bg-[var(--bg-elevated)] text-[var(--text-primary)]'
									: 'border-[var(--border-subtle)] bg-[var(--bg-canvas)] text-[var(--text-secondary)]'}"
							>
								{opt.label}
							</button>
						{/each}
					</div>
				</div>

				<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
					<div>
						<label for="pref-lang" class="block text-xs font-semibold text-[var(--text-secondary)]">
							Bahasa Utama Respons
						</label>
						<select
							id="pref-lang"
							bind:value={prefLanguage}
							class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
						>
							<option value="id">Bahasa Indonesia</option>
							<option value="en">English</option>
						</select>
					</div>

					<div>
						<label for="pref-style" class="block text-xs font-semibold text-[var(--text-secondary)]">
							Gaya Komunikasi AI
						</label>
						<select
							id="pref-style"
							bind:value={prefStyle}
							class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
						>
							<option value="balanced">Seimbang & Jelas</option>
							<option value="concise">Ringkas & Langsung</option>
							<option value="detailed">Mendalam & Terstruktur</option>
							<option value="academic">Akademik & Metodis</option>
							<option value="casual">Santai & Komunikatif</option>
						</select>
					</div>
				</div>

				<label class="flex items-center gap-2.5 text-xs text-[var(--text-primary)] cursor-pointer">
					<input type="checkbox" bind:checked={prefVoice} class="h-4 w-4 rounded" />
					<span>Tampilkan tombol dikte suara (Web Speech) pada kotak chat</span>
				</label>

				<button
					type="button"
					disabled={savingPrefs}
					onclick={() => handleSavePreferences()}
					class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
				>
					{savingPrefs ? 'Menyimpan...' : 'Simpan Preferensi'}
				</button>
			</section>

			<!-- Change Password -->
			<section
				aria-labelledby="pwd-heading"
				class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5"
			>
				<div class="flex items-center justify-between">
					<h2 id="pwd-heading" class="text-sm font-bold text-[var(--text-primary)]">
						Ubah Kata Sandi
					</h2>
					<button
						type="button"
						onclick={() => (showPasswords = !showPasswords)}
						class="text-xs font-semibold text-[var(--accent-link)] cursor-pointer"
					>
						{showPasswords ? 'Sembunyikan' : 'Tampilkan Sandi'}
					</button>
				</div>

				<form onsubmit={handleChangePassword} class="mt-4 space-y-3.5">
					<div>
						<label
							for="curr-password"
							class="block text-xs font-semibold text-[var(--text-secondary)]"
						>
							Kata Sandi Saat Ini
						</label>
						<input
							id="curr-password"
							type={showPasswords ? 'text' : 'password'}
							autocomplete="current-password"
							required
							bind:value={currentPassword}
							class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
						/>
					</div>

					<div>
						<label
							for="new-password"
							class="block text-xs font-semibold text-[var(--text-secondary)]"
						>
							Kata Sandi Baru (Minimal 8 Karakter)
						</label>
						<input
							id="new-password"
							type={showPasswords ? 'text' : 'password'}
							autocomplete="new-password"
							minlength="8"
							required
							bind:value={newPassword}
							class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
						/>
					</div>

					{#if passwordError}
						<p role="alert" class="text-xs text-[var(--danger-text)]">{passwordError}</p>
					{/if}

					<button
						type="submit"
						disabled={savingPassword}
						class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
					>
						{savingPassword ? 'Memperbarui...' : 'Perbarui Kata Sandi'}
					</button>
				</form>
			</section>
		</div>

		<!-- Right Column: AI Personal Memory & Privacy Controls (7 cols) -->
		<div class="lg:col-span-7 space-y-6">
			<section
				aria-labelledby="memory-heading"
				class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-5"
			>
				<div class="border-b border-[var(--border-subtle)] pb-4">
					<h2 id="memory-heading" class="text-base font-bold text-[var(--text-primary)]">
						Memori Personal AI
					</h2>
					<p class="mt-1 text-xs leading-relaxed text-[var(--text-secondary)]">
						Rekametachat hanya menyertakan memori yang sesuai dengan topik percakapan Anda, bukan
						mengirim seluruh riwayat tanpa batas. Anda memegang kendali penuh untuk menyetujui,
						menyunting, menonaktifkan, atau menghapusnya.
					</p>

					<div class="mt-3.5 space-y-2.5 rounded-[10px] bg-[var(--bg-canvas)] p-3.5">
						<label class="flex items-center justify-between gap-3 text-xs cursor-pointer">
							<span class="font-semibold text-[var(--text-primary)]">
								Gunakan memori relevan saat AI menjawab percakapan
							</span>
							<input
								type="checkbox"
								bind:checked={prefMemoryEnabled}
								onchange={() => handleSavePreferences()}
								class="h-4 w-4 rounded"
							/>
						</label>

						<label class="flex items-center justify-between gap-3 text-xs cursor-pointer">
							<span class="text-[var(--text-secondary)]">
								Simpan fakta percakapan secara otomatis tanpa konfirmasi manual
							</span>
							<input
								type="checkbox"
								bind:checked={prefMemoryAutoSave}
								onchange={() => handleSavePreferences()}
								class="h-4 w-4 rounded"
							/>
						</label>
					</div>
				</div>

				<!-- Add Manual Memory Form -->
				<form
					onsubmit={handleAddMemory}
					class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-4 space-y-3"
				>
					<p class="text-xs font-bold text-[var(--text-primary)]">Tambah Catatan Memori Baru</p>
					<div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
						<div>
							<label for="mem-cat" class="block text-[11px] font-semibold text-[var(--text-secondary)]">
								Kategori
							</label>
							<select
								id="mem-cat"
								bind:value={newMemCategory}
								class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
							>
								{#each Object.entries(categoryLabels) as [val, label] (val)}
									<option value={val}>{label}</option>
								{/each}
							</select>
						</div>

						<div>
							<label for="mem-key" class="block text-[11px] font-semibold text-[var(--text-secondary)]">
								Topik / Label *
							</label>
							<input
								id="mem-key"
								type="text"
								required
								bind:value={newMemKey}
								placeholder="Misal: Stack Utama"
								class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
							/>
						</div>

						<div>
							<label for="mem-imp" class="block text-[11px] font-semibold text-[var(--text-secondary)]">
								Prioritas (1 sampai 5)
							</label>
							<select
								id="mem-imp"
								bind:value={newMemImportance}
								class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
							>
								<option value={5}>5 (Sangat Penting)</option>
								<option value={4}>4 (Tinggi)</option>
								<option value={3}>3 (Normal)</option>
								<option value={2}>2 (Rendah)</option>
								<option value={1}>1 (Catatan Kecil)</option>
							</select>
						</div>
					</div>

					<div class="flex flex-col gap-2.5 sm:flex-row">
						<label for="mem-val" class="visually-hidden">Isi memori</label>
						<input
							id="mem-val"
							type="text"
							required
							bind:value={newMemValue}
							placeholder="Misal: Lebih menyukai contoh kode TypeScript ketat dan PostgreSQL."
							class="flex-1 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-1.5 text-xs text-[var(--text-primary)]"
						/>
						<button
							type="submit"
							disabled={addingMemory}
							class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-1.5 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
						>
							{addingMemory ? 'Menyimpan...' : 'Simpan Memori'}
						</button>
					</div>
				</form>

				<!-- Memory List States -->
				{#if loadingMemories}
					<div class="py-8 text-center">
						<progress class="w-40" aria-label="Memuat memori personal"></progress>
					</div>
				{:else if memoryError}
					<div role="alert" class="rounded-[10px] bg-[var(--danger-bg)] p-3.5 text-xs text-[var(--danger-text)]">
						<p>{memoryError}</p>
						<button
							type="button"
							onclick={() => loadMemories()}
							class="mt-2 rounded-[6px] bg-[var(--accent-blue)] px-3 py-1 font-semibold text-white cursor-pointer"
						>
							Muat Ulang
						</button>
					</div>
				{:else}
					<!-- Pending Consent Memories -->
					{#if pendingSuggestions.length > 0}
						<div class="space-y-2.5">
							<p class="text-xs font-bold text-[var(--warn-text)]">
								Menunggu Persetujuan Anda ({pendingSuggestions.length})
							</p>
							{#each pendingSuggestions as sug (sug.id)}
								<div
									class="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--warn-bg)] p-3 text-xs text-[var(--warn-text)]"
								>
									<div>
										<span class="font-bold">{sug.memory_key}:</span>
										<span>{sug.memory_value}</span>
									</div>
									<div class="flex gap-2">
										<button
											type="button"
											onclick={() => approveSuggestion(sug)}
											class="rounded-[6px] bg-[var(--accent-blue)] px-2.5 py-1 font-semibold text-white cursor-pointer"
										>
											Setujui
										</button>
										<button
											type="button"
											onclick={() => deleteSingleMemory(sug.id)}
											class="rounded-[6px] border border-current px-2.5 py-1 font-semibold cursor-pointer"
										>
											Hapus
										</button>
									</div>
								</div>
							{/each}
						</div>
					{/if}

					<!-- Confirmed Memories -->
					<div class="space-y-2.5">
						<div class="flex items-center justify-between">
							<p class="text-xs font-bold text-[var(--text-primary)]">
								Daftar Memori Tersimpan ({confirmedMemories.length})
							</p>
							{#if memories.length > 0}
								<button
									type="button"
									onclick={() => openPrivacyModal('memories')}
									class="text-xs font-semibold text-[var(--danger-text)] cursor-pointer"
								>
									Kosongkan Semua
								</button>
							{/if}
						</div>

						{#if confirmedMemories.length === 0}
							<p class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-4 text-xs text-[var(--text-muted)]">
								Belum ada memori personal yang disimpan. Tambahkan preferensi kerja atau topik
								studi di atas agar AI dapat menyesuaikan konteks jawaban pada sesi mendatang.
							</p>
						{:else}
							<ul class="space-y-2.5" role="list">
								{#each confirmedMemories as mem (mem.id)}
									<li
										class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-3.5"
									>
										{#if editingMemId === mem.id}
											<div class="space-y-2.5">
												<div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
													<select
														aria-label="Kategori memori"
														bind:value={editMemCategory}
														class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
													>
														{#each Object.entries(categoryLabels) as [val, label] (val)}
															<option value={val}>{label}</option>
														{/each}
													</select>
													<input
														type="text"
														aria-label="Topik memori"
														bind:value={editMemKey}
														class="sm:col-span-2 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
													/>
												</div>
												<input
													type="text"
													aria-label="Nilai memori"
													bind:value={editMemValue}
													class="w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
												/>
												<div class="flex justify-end gap-2">
													<button
														type="button"
														onclick={() => (editingMemId = null)}
														class="rounded-[6px] border border-[var(--border-subtle)] px-3 py-1 text-xs text-[var(--text-secondary)] cursor-pointer"
													>
														Batal
													</button>
													<button
														type="button"
														onclick={() => saveEditMemory(mem.id)}
														class="rounded-[6px] bg-[var(--accent-blue)] px-3 py-1 text-xs font-semibold text-white cursor-pointer"
													>
														Simpan
													</button>
												</div>
											</div>
										{:else}
											<div class="flex items-start justify-between gap-3">
												<div class="min-w-0 flex-1">
													<div class="flex flex-wrap items-center gap-1.5">
														<span
															class="rounded-[6px] bg-[var(--accent-violet-bg)] px-2 py-0.5 text-[10px] font-bold text-[var(--accent-violet-text)]"
														>
															{categoryLabels[mem.category] ?? mem.category}
														</span>
														<span class="text-xs font-bold text-[var(--text-primary)]">
															{mem.memory_key}
														</span>
														{#if !mem.is_active}
															<span class="rounded-[6px] bg-[var(--bg-elevated)] px-1.5 py-0.5 text-[10px] text-[var(--text-muted)]">
																Nonaktif
															</span>
														{/if}
													</div>
													<p class="mt-1 text-xs leading-relaxed text-[var(--text-secondary)]">
														{mem.memory_value}
													</p>
												</div>

												<div class="flex shrink-0 items-center gap-1">
													<button
														type="button"
														onclick={() => toggleMemoryActive(mem)}
														class="rounded-[6px] border border-[var(--border-subtle)] px-2 py-1 text-[11px] font-medium text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
													>
														{mem.is_active ? 'Nonaktifkan' : 'Aktifkan'}
													</button>
													<button
														type="button"
														onclick={() => startEditMemory(mem)}
														aria-label="Edit memori {mem.memory_key}"
														class="inline-flex h-7 w-7 items-center justify-center rounded-[6px] text-[var(--text-muted)] hover:text-[var(--text-primary)] cursor-pointer"
													>
														<Icon name="edit" class="h-3.5 w-3.5" />
													</button>
													<button
														type="button"
														onclick={() => deleteSingleMemory(mem.id)}
														aria-label="Hapus memori {mem.memory_key}"
														class="inline-flex h-7 w-7 items-center justify-center rounded-[6px] text-[var(--text-muted)] hover:text-[var(--danger-text)] cursor-pointer"
													>
														<Icon name="trash" class="h-3.5 w-3.5" />
													</button>
												</div>
											</div>
										{/if}
									</li>
								{/each}
							</ul>
						{/if}
					</div>
				{/if}
			</section>

			<!-- Privacy & Granular Data Deletion Controls -->
			<section
				aria-labelledby="privacy-heading"
				class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-4"
			>
				<div>
					<h2 id="privacy-heading" class="text-sm font-bold text-[var(--text-primary)]">
						Kontrol Privasi & Penghapusan Data
					</h2>
					<p class="mt-1 text-xs text-[var(--text-muted)]">
						Hapus riwayat percakapan, dokumen terunggah, atau seluruh akun Anda secara permanen.
					</p>
				</div>

				<div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
					<button
						type="button"
						onclick={() => openPrivacyModal('conversations')}
						class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3.5 py-2.5 text-start text-xs font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
					>
						Hapus Seluruh Riwayat Percakapan
					</button>

					<button
						type="button"
						onclick={() => openPrivacyModal('documents')}
						class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3.5 py-2.5 text-start text-xs font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
					>
						Hapus Seluruh Dokumen Terunggah
					</button>

					<button
						type="button"
						onclick={() => openPrivacyModal('all_data')}
						class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3.5 py-2.5 text-start text-xs font-semibold text-[var(--warn-text)] hover:bg-[var(--warn-bg)] cursor-pointer"
					>
						Bersihkan Semua Data Ruang Kerja
					</button>

					<button
						type="button"
						onclick={() => openPrivacyModal('delete_account')}
						class="rounded-[10px] bg-[var(--danger-bg)] px-3.5 py-2.5 text-start text-xs font-semibold text-[var(--danger-text)] cursor-pointer"
					>
						Hapus Akun Secara Permanen
					</button>
				</div>
			</section>
		</div>
	</div>
</div>

<ModalDialog
	bind:open={deleteModalOpen}
	title="Konfirmasi Penghapusan Permanen"
	onClose={() => {
		deleteModalOpen = false;
		pendingDeleteScope = null;
	}}
>
	<div class="space-y-4">
		<p class="text-xs leading-relaxed text-[var(--text-secondary)]">
			{#if pendingDeleteScope === 'conversations'}
				Seluruh riwayat percakapan dan pesan Anda akan dihapus secara permanen dari database.
			{:else if pendingDeleteScope === 'memories'}
				Seluruh catatan memori personal Anda akan dihapus secara permanen.
			{:else if pendingDeleteScope === 'documents'}
				Seluruh file dokumen dan segmen teks milik Anda akan dihapus dari penyimpanan.
			{:else if pendingDeleteScope === 'all_data'}
				Seluruh percakapan, memori, dokumen, dan profil CV Anda akan dikosongkan, sementara akun
				login tetap aktif.
			{:else if pendingDeleteScope === 'delete_account'}
				Akun Anda beserta seluruh data percakapan, memori, CV, dan dokumen akan dihapus secara
				permanen dan tidak dapat dipulihkan.
			{/if}
		</p>

		{#if pendingDeleteScope === 'delete_account'}
			<div>
				<label
					for="del-acc-pwd"
					class="block text-xs font-semibold text-[var(--text-secondary)]"
				>
					Masukkan Kata Sandi Anda untuk Konfirmasi
				</label>
				<input
					id="del-acc-pwd"
					type="password"
					autocomplete="current-password"
					bind:value={deleteConfirmPassword}
					class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
				/>
			</div>
		{/if}

		{#if deleteError}
			<p role="alert" class="text-xs text-[var(--danger-text)]">{deleteError}</p>
		{/if}

		<div class="flex justify-end gap-2.5">
			<button
				type="button"
				onclick={() => {
					deleteModalOpen = false;
					pendingDeleteScope = null;
				}}
				class="rounded-[10px] border border-[var(--border-subtle)] px-3.5 py-2 text-xs font-semibold text-[var(--text-primary)] cursor-pointer"
			>
				Batal
			</button>
			<button
				type="button"
				disabled={executingDelete}
				onclick={() => executePrivacyDeletion()}
				class="rounded-[10px] bg-[var(--danger-bg)] px-3.5 py-2 text-xs font-semibold text-[var(--danger-text)] cursor-pointer"
			>
				{executingDelete ? 'Memproses...' : 'Ya, Hapus Permanen'}
			</button>
		</div>
	</div>
</ModalDialog>
