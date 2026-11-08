<script lang="ts">
	import { goto } from '$app/navigation';
	import BrandMark from '$lib/components/BrandMark.svelte';
	import Icon from '$lib/components/Icon.svelte';
	import { appState } from '$lib/state.svelte';
	import { themeManager } from '$lib/theme.svelte';
	import type { LayoutProps } from './$types';

	let { children }: LayoutProps = $props();

	$effect(() => {
		if (appState.authChecked && appState.user) {
			void goto('/');
		}
	});
</script>

<div class="grid min-h-dvh lg:grid-cols-[1.05fr_1fr]">
	<!-- Left Brand Panel (Midnight Navy structural identity) -->
	<aside
		class="hidden flex-col justify-between bg-[#0a0f1e] p-10 text-[#f8fafc] lg:flex border-r border-[#243256]"
	>
		<div>
			<BrandMark showTagline={true} lightText={true} />
		</div>

		<div class="max-w-md space-y-5">
			<p
				class="inline-block rounded-[6px] bg-[#18223f] px-2.5 py-1 text-xs font-semibold text-[#93c5fd]"
			>
				Ruang Kerja AI Terpadu
			</p>
			<h2 class="text-3xl font-bold leading-tight tracking-tight text-white">
				Satu ruang untuk diskusi, analisis dokumen, penyusunan CV, dan studi akademik.
			</h2>
			<ul class="space-y-3 text-sm text-[#cbd5e1]">
				<li class="flex items-start gap-2.5">
					<Icon name="chat" class="mt-0.5 h-4 w-4 shrink-0 text-[#60a5fa]" />
					<span>Percakapan kontekstual Bahasa Indonesia & Inggris dengan memori yang Anda kendalikan.</span>
				</li>
				<li class="flex items-start gap-2.5">
					<Icon name="document" class="mt-0.5 h-4 w-4 shrink-0 text-[#60a5fa]" />
					<span>Unggah PDF, DOCX, TXT, dan CSV untuk diringkas serta dibandingkan isinya.</span>
				</li>
				<li class="flex items-start gap-2.5">
					<Icon name="cv" class="mt-0.5 h-4 w-4 shrink-0 text-[#60a5fa]" />
					<span>Susun CV berbasis fakta nyata, evaluasi ATS, dan ekspor langsung ke PDF.</span>
				</li>
			</ul>
		</div>

		<div class="text-xs text-[#94a3b8]">
			Rekametachat Workspace. Data percakapan dan dokumen dilindungi per akun pengguna.
		</div>
	</aside>

	<!-- Right Form Stage -->
	<div class="flex flex-col justify-between bg-[var(--bg-canvas)] p-6 sm:p-10">
		<div class="flex items-center justify-between">
			<div class="lg:hidden">
				<BrandMark showTagline={true} />
			</div>
			<div class="hidden lg:block"></div>
			<button
				type="button"
				onclick={() => themeManager.toggleComfort()}
				aria-label={themeManager.resolvedScheme === 'dark'
					? 'Beralih ke mode terang'
					: 'Beralih ke mode gelap'}
				class="inline-flex h-9 w-9 items-center justify-center rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
			>
				<Icon name={themeManager.resolvedScheme === 'dark' ? 'sun' : 'moon'} class="h-4 w-4" />
			</button>
		</div>

		<main class="mx-auto w-full max-w-md py-8">
			{@render children()}
		</main>

		<footer class="text-center text-xs text-[var(--text-muted)]">
			Your Intelligent AI Companion
		</footer>
	</div>
</div>

