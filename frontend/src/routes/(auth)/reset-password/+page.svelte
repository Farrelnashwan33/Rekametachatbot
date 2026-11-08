<script lang="ts">
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { ApiError, apiFetch } from '$lib/api';
	import { appState } from '$lib/state.svelte';
	import { onMount } from 'svelte';

	let email = $state('');
	let token = $state('');
	let password = $state('');
	let showPassword = $state(false);
	let submitting = $state(false);
	let errorMsg = $state<string | null>(null);

	onMount(() => {
		email = page.url.searchParams.get('email') ?? '';
		token = page.url.searchParams.get('token') ?? '';
	});

	async function handleReset(e: Event) {
		e.preventDefault();
		submitting = true;
		errorMsg = null;

		try {
			const res = await apiFetch<{ message: string }>('/api/auth/reset-password', {
				method: 'POST',
				body: JSON.stringify({
					email: email.trim(),
					token: token.trim(),
					password
				})
			});
			appState.notify(res.message, 'success');
			await goto('/login');
		} catch (err) {
			errorMsg = err instanceof ApiError ? err.message : 'Gagal memperbarui kata sandi.';
		} finally {
			submitting = false;
		}
	}
</script>

<svelte:head>
	<title>Kata Sandi Baru | Rekametachat</title>
</svelte:head>

<div class="rounded-[18px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 sm:p-8">
	<h1 id="main-stage" tabindex="-1" class="text-2xl font-bold text-[var(--text-primary)]">
		Simpan Kata Sandi Baru
	</h1>
	<p class="mt-1.5 text-sm text-[var(--text-secondary)]">
		Pastikan token reset dan alamat email sesuai sebelum menyimpan kata sandi baru.
	</p>

	{#if errorMsg}
		<div
			role="alert"
			class="mt-4 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--danger-bg)] px-3.5 py-2.5 text-xs font-medium text-[var(--danger-text)]"
		>
			{errorMsg}
		</div>
	{/if}

	<form onsubmit={handleReset} class="mt-6 space-y-4">
		<div>
			<label
				for="reset-email"
				class="block text-xs font-semibold text-[var(--text-primary)] mb-1.5"
			>
				Alamat Email
			</label>
			<input
				id="reset-email"
				name="email"
				type="email"
				autocomplete="username"
				spellcheck="false"
				required
				bind:value={email}
				class="w-full rounded-[10px] border border-[var(--border-control)] bg-[var(--bg-canvas)] px-3.5 py-2.5 text-sm text-[var(--text-primary)]"
			/>
		</div>

		<div>
			<label
				for="reset-token"
				class="block text-xs font-semibold text-[var(--text-primary)] mb-1.5"
			>
				Token Pengaturan Ulang
			</label>
			<input
				id="reset-token"
				name="token"
				type="text"
				spellcheck="false"
				required
				bind:value={token}
				class="w-full rounded-[10px] border border-[var(--border-control)] bg-[var(--bg-canvas)] px-3.5 py-2.5 font-mono text-xs text-[var(--text-primary)]"
			/>
		</div>

		<div>
			<label
				for="reset-password"
				class="block text-xs font-semibold text-[var(--text-primary)] mb-1.5"
			>
				Kata Sandi Baru (min. 8 karakter)
			</label>
			<div class="relative flex items-center">
				<input
					id="reset-password"
					name="new-password"
					type={showPassword ? 'text' : 'password'}
					autocomplete="new-password"
					minlength="8"
					required
					bind:value={password}
					class="w-full rounded-[10px] border border-[var(--border-control)] bg-[var(--bg-canvas)] py-2.5 ps-3.5 pe-24 text-sm text-[var(--text-primary)]"
				/>
				<button
					type="button"
					onclick={() => (showPassword = !showPassword)}
					aria-pressed={showPassword}
					class="absolute end-2 rounded-[6px] px-2 py-1 text-xs font-medium text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
				>
					{showPassword ? 'Sembunyikan' : 'Tampilkan'}
				</button>
			</div>
		</div>

		<button
			type="submit"
			disabled={submitting}
			class="w-full rounded-[10px] bg-[var(--accent-blue)] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[var(--accent-blue-hover)] disabled:opacity-50 cursor-pointer"
		>
			{submitting ? 'Menyimpan...' : 'Perbarui Kata Sandi'}
		</button>
	</form>
</div>

