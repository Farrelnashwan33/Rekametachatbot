<script lang="ts">
	interface Props {
		compact?: boolean;
		showTagline?: boolean;
		lightText?: boolean;
	}

	let { compact = false, showTagline = false, lightText = false }: Props = $props();
</script>

<div class="inline-flex items-center gap-2.5 select-none">
	<div
		class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-[#1d4ed8] text-white"
		aria-hidden="true"
	>
		<svg viewBox="0 0 28 28" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2">
			<polygon points="14,3 25,14 14,25 3,14" stroke-linejoin="round" />
			<circle cx="14" cy="14" r="3.5" fill="currentColor" stroke="none" />
			<line x1="14" y1="3" x2="14" y2="9" />
			<line x1="14" y1="19" x2="14" y2="25" />
		</svg>
	</div>
	{#if !compact}
		<div class="min-w-0 leading-tight">
			<div
				class="truncate text-[15px] font-bold tracking-tight {lightText
					? 'text-[#f8fafc]'
					: 'text-[var(--text-primary)]'}"
			>
				Rekametachat
			</div>
			{#if showTagline}
				<div
					class="truncate text-[11px] font-medium {lightText
						? 'text-[#cbd5e1]'
						: 'text-[var(--text-muted)]'}"
				>
					Your Intelligent AI Companion
				</div>
			{/if}
		</div>
	{/if}
</div>

