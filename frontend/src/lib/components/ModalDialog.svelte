<script lang="ts">
	import type { Snippet } from 'svelte';
	import Icon from './Icon.svelte';

	interface Props {
		open: boolean;
		title: string;
		onClose: () => void;
		drawer?: boolean;
		children: Snippet;
	}

	let { open = $bindable(false), title, onClose, drawer = false, children }: Props = $props();

	let dialogEl: HTMLDialogElement | undefined = $state();
	const titleId = `dlg-${Math.random().toString(36).slice(2, 9)}`;

	$effect(() => {
		if (!dialogEl) return;
		if (open && !dialogEl.open) {
			dialogEl.showModal();
		} else if (!open && dialogEl.open) {
			dialogEl.close();
		}
	});

	function setupLightDismissFallback(node: HTMLDialogElement) {
		if ('closedBy' in HTMLDialogElement.prototype) return;

		let pressedOutside = false;
		const isOutside = (e: PointerEvent) => {
			const r = node.getBoundingClientRect();
			return e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
		};

		const onDown = (e: PointerEvent) => {
			if (e.target === node) {
				pressedOutside = isOutside(e);
			} else {
				pressedOutside = false;
			}
		};

		const onUp = (e: PointerEvent) => {
			if (pressedOutside && e.target === node && isOutside(e)) {
				node.close();
			}
			pressedOutside = false;
		};

		node.addEventListener('pointerdown', onDown);
		node.addEventListener('pointerup', onUp);

		return {
			destroy() {
				node.removeEventListener('pointerdown', onDown);
				node.removeEventListener('pointerup', onUp);
			}
		};
	}

	function handleNativeClose() {
		if (dialogEl) {
			dialogEl.returnValue = '';
		}
		open = false;
		onClose();
	}
</script>

<!-- Native <dialog> opened via showModal() without redundant role="dialog" or aria-modal -->
<dialog
	bind:this={dialogEl}
	use:setupLightDismissFallback
	{...({ closedby: 'any' } as Record<string, string>)}
	aria-labelledby={titleId}
	onclose={handleNativeClose}
	class={drawer ? 'mobile-drawer' : ''}
>
	<div class="flex h-full flex-col">
		<div
			class="flex items-center justify-between border-b border-[var(--border-subtle)] px-5 py-4"
		>
			<h2 id={titleId} class="text-base font-semibold text-[var(--text-primary)]">{title}</h2>
			<button
				type="button"
				onclick={() => dialogEl?.close()}
				aria-label="Tutup dialog"
				class="inline-flex h-8 w-8 items-center justify-center rounded-[10px] text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
			>
				<Icon name="close" class="h-4 w-4" />
			</button>
		</div>
		<div class="flex-1 overflow-y-auto p-5">
			{@render children()}
		</div>
	</div>
</dialog>

