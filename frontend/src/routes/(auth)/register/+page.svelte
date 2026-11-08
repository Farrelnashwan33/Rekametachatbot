<script lang="ts">
	import { goto } from '$app/navigation';
	import { ApiError, apiFetch, setStoredToken } from '$lib/api';
	import { appState } from '$lib/state.svelte';
	import type { AiStatus, User } from '$lib/types';

	let name = $state('');
	let email = $state('');
	let password = $state('');
	let showPassword = $state(false);
	let submitting = $state(false);
	let errorMsg = $state<string | null>(null);

	async function handleRegister(e: Event) {
		e.preventDefault();
		errorMsg = null;
		submitting = true;

		try {
			const res = await apiFetch<{ token: string; user: User; ai_status: AiStatus }>(
				'/api/auth/register',
				{
					method: 'POST',
					body: JSON.stringify({
						name: name.trim(),
						email: email.trim(),
						password
					})
				}
			);

			setStoredToken(res.token);
			appState.user = res.user;
			appState.aiStatus = res.ai_status;
			await goto('/');
		} catch (err) {
			if (err instanceof ApiError && err.fieldErrors) {
				const firstField = Object.values(err.fieldErrors)[0]?.[0];
				errorMsg = firstField ?? err.message;
			} else {
				errorMsg = err instanceof ApiError ? err.message : 'Gagal mendaftarkan akun.';
			}
		} finally {
			submitting = false;
		}
	}
</script>

<svelte:head>
	<title>Daftar Akun | Rekametachat</title>
</svelte:head>

<div class="rounded-[18px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 sm:p-8">
	<h1 id="main-stage" tabindex="-1" class="text-2xl font-bold text-[var(--text-primary)]">
		Buat Akun Rekametachat
	</h1>
	<p class="mt-1.5 text-sm text-[var(--text-secondary)]">
		Simpan riwayat percakapan, dokumen pribadi, dan draf CV Anda secara aman.
	</p>

	{#if errorMsg}
		<div
			role="alert"
			class="mt-4 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--danger-bg)] px-3.5 py-2.5 text-xs font-medium text-[var(--danger-text)]"
		>
			{errorMsg}
		</div>
	{/if}

	<form onsubmit={handleRegister} class="mt-6 space-y-4">
		<div>
			<label for="reg-name" class="block text-xs font-semibold text-[var(--text-primary)] mb-1.5">
				Nama Lengkap
			</label>
			<input
				id="reg-name"
				name="name"
				type="text"
				autocomplete="name"
				enterkeyhint="next"
				required
				minlength="2"
				bind:value={name}
				placeholder="Contoh: Nadia Putri"
				class="w-full rounded-[10px] border border-[var(--border-control)] bg-[var(--bg-canvas)] px-3.5 py-2.5 text-sm text-[var(--text-primary)]"
			/>
		</div>

		<div>
			<label for="reg-email" class="block text-xs font-semibold text-[var(--text-primary)] mb-1.5">
				Alamat Email
			</label>
			<input
				id="reg-email"
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
			<label
				for="reg-password"
				class="block text-xs font-semibold text-[var(--text-primary)] mb-1"
			>
				Kata Sandi Baru
			</label>
			<p id="reg-pwd-hint" class="text-[11px] text-[var(--text-muted)] mb-1.5">
				Minimal 8 karakter. Pengelola sandi browser dapat menyarankan sandi unik.
			</p>
			<div class="relative flex items-center">
				<input
					id="reg-password"
					name="new-password"
					type={showPassword ? 'text' : 'password'}
					autocomplete="new-password"
					aria-describedby="reg-pwd-hint"
					enterkeyhint="done"
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
			class="w-full rounded-[10px] bg-[var(--accent-blue)] px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[var(--accent-blue-hover)] disabled:opacity-50 cursor-pointer"
		>
			{submitting ? 'Membuat Akun...' : 'Buat Akun'}
		</button>
	</form>

	<p class="mt-6 text-center text-xs text-[var(--text-secondary)]">
		Sudah memiliki akun?
		<a href="/login" class="font-semibold text-[var(--accent-link)] hover:underline">
			Masuk di sini
		</a>
	</p>
</div>
