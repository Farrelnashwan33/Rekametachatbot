<script lang="ts">
	import { goto } from '$app/navigation';
	import { ApiError, apiFetch, setStoredToken } from '$lib/api';
	import { appState } from '$lib/state.svelte';
	import type { AiStatus, User } from '$lib/types';

	let email = $state('');
	let password = $state('');
	let showPassword = $state(false);
	let submitting = $state(false);
	let errorMsg = $state<string | null>(null);

	async function handleLogin(e: Event) {
		e.preventDefault();
		errorMsg = null;
		submitting = true;

		try {
			const res = await apiFetch<{ token: string; user: User; ai_status: AiStatus }>(
				'/api/auth/login',
				{
					method: 'POST',
					body: JSON.stringify({ email: email.trim(), password })
				}
			);

			setStoredToken(res.token);
			appState.user = res.user;
			appState.aiStatus = res.ai_status;
			await appState.refreshConversations();
			await goto('/');
		} catch (err) {
			errorMsg = err instanceof ApiError ? err.message : 'Gagal menghubungi server autentikasi.';
		} finally {
			submitting = false;
		}
	}
</script>

<svelte:head>
	<title>Masuk | Rekametachat</title>
</svelte:head>

<div class="rounded-[18px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 sm:p-8">
	<h1 id="main-stage" tabindex="-1" class="text-2xl font-bold text-[var(--text-primary)]">
		Masuk ke Akun Anda
	</h1>
	<p class="mt-1.5 text-sm text-[var(--text-secondary)]">
		Lanjutkan percakapan, dokumen, dan penyusunan CV Anda.
	</p>

	{#if errorMsg}
		<div
			role="alert"
			class="mt-4 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--danger-bg)] px-3.5 py-2.5 text-xs font-medium text-[var(--danger-text)]"
		>
			{errorMsg}
		</div>
	{/if}

	<form onsubmit={handleLogin} class="mt-6 space-y-4">
		<div>
			<label for="login-email" class="block text-xs font-semibold text-[var(--text-primary)] mb-1.5">
				Alamat Email
			</label>
			<input
				id="login-email"
				name="email"
				type="email"
				autocomplete="username"
				spellcheck="false"
				enterkeyhint="next"
				required
				bind:value={email}
				placeholder="nama@domain.com"
				class="w-full rounded-[10px] border border-[var(--border-control)] bg-[var(--bg-canvas)] px-3.5 py-2.5 text-sm text-[var(--text-primary)]"
			/>
		</div>

		<div>
			<div class="flex items-center justify-between mb-1.5">
				<label for="login-password" class="text-xs font-semibold text-[var(--text-primary)]">
					Kata Sandi
				</label>
				<a
					href="/forgot-password"
					class="text-xs font-medium text-[var(--accent-link)] hover:underline"
				>
					Lupa kata sandi?
				</a>
			</div>
			<div class="relative flex items-center">
				<input
					id="login-password"
					name="password"
					type={showPassword ? 'text' : 'password'}
					autocomplete="current-password"
					enterkeyhint="done"
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
			class="w-full rounded-[10px] bg-[var(--accent-blue)] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[var(--accent-blue-hover)] disabled:opacity-50 cursor-pointer"
		>
			{submitting ? 'Memverifikasi...' : 'Masuk ke Rekametachat'}
		</button>
	</form>

	<p class="mt-6 text-center text-xs text-[var(--text-secondary)]">
		Belum memiliki akun?
		<a href="/register" class="font-semibold text-[var(--accent-link)] hover:underline">
			Buat akun baru
		</a>
	</p>
</div>

