<script lang="ts">
	import { page } from '$app/state';
	import ChatWorkspace from '$lib/components/ChatWorkspace.svelte';

	const uuid = $derived(page.params.uuid ?? null);
</script>

<svelte:head>
	<title>Percakapan | Rekametachat</title>
</svelte:head>

<ChatWorkspace conversationUuid={uuid} />

