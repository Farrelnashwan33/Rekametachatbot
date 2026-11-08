<script lang="ts">
	import '../app.css';
	import { appState } from '$lib/state.svelte';
	import { onMount } from 'svelte';
	import type { LayoutProps } from './$types';

	let { children }: LayoutProps = $props();

	onMount(() => {
		void appState.bootstrap();
	});
</script>

<svelte:head>
	<title>Rekametachat | Your Intelligent AI Companion</title>
</svelte:head>

<a href="#main-stage" class="skip-link visually-hidden">Lewati ke konten utama</a>

<!-- Centralized polite screen-reader announcer -->
<div aria-live="polite" aria-atomic="true" class="visually-hidden">
	{appState.politeAnnouncement}
</div>

{#if appState.toast}
	<div
		class="fixed bottom-5 right-5 z-50 max-w-sm rounded-[14px] border border-[var(--border-subtle)] px-4 py-3 text-xs font-medium shadow-lg {appState
			.toast.kind === 'error'
			? 'bg-[var(--danger-bg)] text-[var(--danger-text)]'
			: appState.toast.kind === 'success'
				? 'bg-[var(--success-bg)] text-[var(--success-text)]'
				: 'bg-[var(--bg-surface)] text-[var(--text-primary)]'}"
	>
		{appState.toast.message}
	</div>
{/if}

{@render children()}
