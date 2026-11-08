<script lang="ts">
	import { ApiError, apiFetch } from '$lib/api';

	let email = $state('');
	let submitting = $state(false);
	let successMsg = $state<string | null>(null);
	let devResetLink = $state<string | null>(null);
	let errorMsg = $state<string | null>(null);

	async function handleForgot(e: Event) {
		e.preventDefault();
		submitting = true;
		errorMsg = null;
		successMsg = null;
		devResetLink = null;

		try {
			const res = await apiFetch<{ message: string; local_dev_reset_token?: string }>(
				'/api/auth/forgot-password',
				{
					method: 'POST',
					body: JSON.stringify({ email: email.trim() })
				}
			);
			successMsg = res.message;
			if (res.local_dev_reset_token) {
				devResetLink = `/reset-password?email=${encodeURIComponent(email.trim())}&token=${encodeURIComponent(res.local_dev_reset_token)}`;
			}
		} catch (err) {
			errorMsg = err instanceof ApiError ? err.message : 'Gagal mengirim permintaan reset.';
		} finally {
			submitting = false;
		}
	}
</script>

<svelte:head>
	<title>Lupa Kata Sandi | Rekametachat</title>
</svelte:head>

<div class="rounded-[18px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 sm:p-8">
	<h1 id="main-stage" tabindex="-1" class="text-2xl font-bold text-[var(--text-primary)]">
		Atur Ulang Kata Sandi
	</h1>
	<p class="mt-1.5 text-sm text-[var(--text-secondary)]">
		Masukkan alamat email akun Anda untuk menerima tautan pengaturan ulang kata sandi.
	</p>

	{#if errorMsg}
		<div
			role="alert"
			class="mt-4 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--danger-bg)] px-3.5 py-2.5 text-xs font-medium text-[var(--danger-text)]"
		>
			{errorMsg}
		</div>
	{/if}

	{#if successMsg}
		<div
			role="status"
			class="mt-4 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--success-bg)] px-3.5 py-3 text-xs text-[var(--success-text)]"
		>
			<p class="font-semibold">{successMsg}</p>
			{#if devResetLink}
				<p class="mt-2">
					Mode pengembangan lokal terdeteksi (log mailer):
					<a href={devResetLink} class="underline font-semibold">
						Buka halaman atur ulang kata sandi langsung
					</a>
				</p>
			{/if}
		</div>
	{/if}

	<form onsubmit={handleForgot} class="mt-6 space-y-4">
		<div>
			<label
				for="forgot-email"
				class="block text-xs font-semibold text-[var(--text-primary)] mb-1.5"
			>
				Alamat Email Terdaftar
			</label>
			<input
				id="forgot-email"
				name="email"
				type="email"
				autocomplete="username"
				spellcheck="false"
				required
				bind:value={email}
				placeholder="nama@domain.com"
				class="w-full rounded-[10px] border border-[var(--border-control)] bg-[var(--bg-canvas)] px-3.5 py-2.5 text-sm text-[var(--text-primary)]"
			/>
		</div>

		<button
			type="submit"
			disabled={submitting}
			class="w-full rounded-[10px] bg-[var(--accent-blue)] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[var(--accent-blue-hover)] disabled:opacity-50 cursor-pointer"
		>
			{submitting ? 'Memproses...' : 'Kirim Tautan Atur Ulang'}
		</button>
	</form>

	<p class="mt-6 text-center text-xs text-[var(--text-secondary)]">
		<a href="/login" class="font-semibold text-[var(--accent-link)] hover:underline">
			Kembali ke halaman masuk
		</a>
	</p>
</div>

