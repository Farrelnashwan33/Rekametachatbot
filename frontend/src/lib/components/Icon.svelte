<script lang="ts">
	export type IconName =
		| 'chat'
		| 'plus'
		| 'compass'
		| 'document'
		| 'cv'
		| 'academic'
		| 'code'
		| 'pen'
		| 'settings'
		| 'shield'
		| 'search'
		| 'paperclip'
		| 'mic'
		| 'send'
		| 'stop'
		| 'copy'
		| 'refresh'
		| 'edit'
		| 'trash'
		| 'pin'
		| 'bookmark'
		| 'thumb-up'
		| 'thumb-down'
		| 'sun'
		| 'moon'
		| 'menu'
		| 'close'
		| 'check'
		| 'alert'
		| 'download'
		| 'logout'
		| 'sidebar';

	interface Props {
		name: IconName;
		class?: string;
	}

	let { name, class: className = 'h-4 w-4' }: Props = $props();
</script>

<svg
	aria-hidden="true"
	viewBox="0 0 24 24"
	fill="none"
	stroke="currentColor"
	stroke-width="1.85"
	stroke-linecap="round"
	stroke-linejoin="round"
	class={className}
>
	{#if name === 'chat'}
		<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
	{:else if name === 'plus'}
		<line x1="12" y1="5" x2="12" y2="19" />
		<line x1="5" y1="12" x2="19" y2="12" />
	{:else if name === 'compass'}
		<circle cx="12" cy="12" r="9" />
		<polygon points="15.5,8.5 13.5,13.5 8.5,15.5 10.5,10.5" />
	{:else if name === 'document'}
		<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
		<polyline points="14 2 14 8 20 8" />
		<line x1="8" y1="13" x2="16" y2="13" />
		<line x1="8" y1="17" x2="13" y2="17" />
	{:else if name === 'cv'}
		<rect x="3" y="4" width="18" height="16" rx="2" />
		<circle cx="9" cy="10" r="2" />
		<path d="M6 16c.6-1.5 1.8-2.2 3-2.2s2.4.7 3 2.2" />
		<line x1="14" y1="9" x2="18" y2="9" />
		<line x1="14" y1="13" x2="18" y2="13" />
	{:else if name === 'academic'}
		<polygon points="12 3 2 8 12 13 22 8 12 3" />
		<path d="M6 10.5V16c0 1.7 2.7 3 6 3s6-1.3 6-3v-5.5" />
	{:else if name === 'code'}
		<polyline points="16 18 22 12 16 6" />
		<polyline points="8 6 2 12 8 18" />
	{:else if name === 'pen'}
		<path d="M12 20h9" />
		<path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
	{:else if name === 'settings'}
		<circle cx="12" cy="12" r="3" />
		<path
			d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.08a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.08a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"
		/>
	{:else if name === 'shield'}
		<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
	{:else if name === 'search'}
		<circle cx="11" cy="11" r="7" />
		<line x1="20" y1="20" x2="16.2" y2="16.2" />
	{:else if name === 'paperclip'}
		<path
			d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"
		/>
	{:else if name === 'mic'}
		<rect x="9" y="2" width="6" height="11" rx="3" />
		<path d="M5 10a7 7 0 0 0 14 0" />
		<line x1="12" y1="17" x2="12" y2="21" />
		<line x1="8" y1="21" x2="16" y2="21" />
	{:else if name === 'send'}
		<line x1="22" y1="2" x2="11" y2="13" />
		<polygon points="22 2 15 22 11 13 2 9 22 2" />
	{:else if name === 'stop'}
		<rect x="6" y="6" width="12" height="12" rx="2" fill="currentColor" />
	{:else if name === 'copy'}
		<rect x="9" y="9" width="13" height="13" rx="2" />
		<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
	{:else if name === 'refresh'}
		<polyline points="23 4 23 10 17 10" />
		<path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
	{:else if name === 'edit'}
		<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
		<path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z" />
	{:else if name === 'trash'}
		<polyline points="3 6 5 6 21 6" />
		<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
	{:else if name === 'pin'}
		<line x1="12" y1="17" x2="12" y2="22" />
		<path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z" />
	{:else if name === 'bookmark'}
		<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z" />
	{:else if name === 'thumb-up'}
		<path
			d="M7 10v12M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"
		/>
	{:else if name === 'thumb-down'}
		<path
			d="M17 14V2M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z"
		/>
	{:else if name === 'sun'}
		<circle cx="12" cy="12" r="4" />
		<line x1="12" y1="2" x2="12" y2="4" />
		<line x1="12" y1="20" x2="12" y2="22" />
		<line x1="4.93" y1="4.93" x2="6.34" y2="6.34" />
		<line x1="17.66" y1="17.66" x2="19.07" y2="19.07" />
		<line x1="2" y1="12" x2="4" y2="12" />
		<line x1="20" y1="12" x2="22" y2="12" />
		<line x1="4.93" y1="19.07" x2="6.34" y2="17.66" />
		<line x1="17.66" y1="6.34" x2="19.07" y2="4.93" />
	{:else if name === 'moon'}
		<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
	{:else if name === 'menu'}
		<line x1="3" y1="6" x2="21" y2="6" />
		<line x1="3" y1="12" x2="21" y2="12" />
		<line x1="3" y1="18" x2="21" y2="18" />
	{:else if name === 'close'}
		<line x1="18" y1="6" x2="6" y2="18" />
		<line x1="6" y1="6" x2="18" y2="18" />
	{:else if name === 'check'}
		<polyline points="20 6 9 17 4 12" />
	{:else if name === 'alert'}
		<circle cx="12" cy="12" r="10" />
		<line x1="12" y1="8" x2="12" y2="12" />
		<line x1="12" y1="16" x2="12.01" y2="16" />
	{:else if name === 'download'}
		<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
		<polyline points="7 10 12 15 17 10" />
		<line x1="12" y1="15" x2="12" y2="3" />
	{:else if name === 'logout'}
		<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
		<polyline points="16 17 21 12 16 7" />
		<line x1="21" y1="12" x2="9" y2="12" />
	{:else if name === 'sidebar'}
		<rect x="3" y="3" width="18" height="18" rx="2" />
		<line x1="9" y1="3" x2="9" y2="21" />
	{/if}
</svg>

