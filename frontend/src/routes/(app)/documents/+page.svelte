<script lang="ts">
	import { goto } from '$app/navigation';
	import { ApiError, apiFetch } from '$lib/api';
	import Icon from '$lib/components/Icon.svelte';
	import ModalDialog from '$lib/components/ModalDialog.svelte';
	import { markdownAction } from '$lib/markdown';
	import { appState } from '$lib/state.svelte';
	import type { DocumentItem } from '$lib/types';
	import { onMount } from 'svelte';

	interface DocumentChunkPreview {
		id: number;
		chunk_index: number;
		heading: string | null;
		content: string;
		token_estimate: number;
	}

	let documents = $state<DocumentItem[]>([]);
	let loadingList = $state(true);
	let listError = $state<string | null>(null);

	// Upload state
	let uploadTitle = $state('');
	let selectedFile = $state<File | null>(null);
	let fileInputEl: HTMLInputElement | undefined = $state();
	let uploading = $state(false);
	let uploadError = $state<string | null>(null);

	// Selected document inspection state
	let activeDoc = $state<DocumentItem | null>(null);
	let activeChunks = $state<DocumentChunkPreview[]>([]);
	let loadingDetail = $state(false);
	let detailError = $state<string | null>(null);

	// AI Summary state
	let summaryFocus = $state(
		'Buat ringkasan eksekutif terstruktur, poin utama, data penting, dan kesimpulan.'
	);
	let summarizing = $state(false);
	let summaryError = $state<{ message: string; code?: string } | null>(null);

	// Multi-document comparison state
	let compareUuids = $state<string[]>([]);
	let comparePrompt = $state(
		'Bandingkan persamaan utama, perbedaan kunci, angka penting, dan kesimpulan antar dokumen ini.'
	);
	let comparing = $state(false);
	let compareResult = $state<string | null>(null);
	let compareError = $state<{ message: string; code?: string } | null>(null);

	// Delete confirmation dialog
	let docToDelete = $state<DocumentItem | null>(null);
	let deleteDialogOpen = $state(false);
	let deleting = $state(false);

	function formatBytes(bytes: number): string {
		if (bytes < 1024) return `${bytes} B`;
		if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
		return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
	}

	async function loadDocuments(): Promise<void> {
		loadingList = true;
		listError = null;
		try {
			const res = await apiFetch<{ documents: DocumentItem[] }>('/api/documents');
			documents = res.documents;
			if (documents.length > 0 && !activeDoc) {
				await inspectDocument(documents[0]);
			}
		} catch (err) {
			listError =
				err instanceof ApiError
					? err.message
					: 'Gagal mengambil daftar dokumen Anda. Silakan muat ulang.';
		} finally {
			loadingList = false;
		}
	}

	function handleFilePicked(e: Event): void {
		const target = e.currentTarget as HTMLInputElement;
		const f = target.files?.[0] ?? null;
		uploadError = null;
		if (!f) {
			selectedFile = null;
			return;
		}

		const ext = f.name.split('.').pop()?.toLowerCase() ?? '';
		if (!['pdf', 'docx', 'txt', 'csv'].includes(ext)) {
			uploadError = 'Format file harus PDF, DOCX, TXT, atau CSV.';
			selectedFile = null;
			target.value = '';
			return;
		}
		if (f.size > 10 * 1024 * 1024) {
			uploadError = 'Ukuran maksimum file adalah 10 MB.';
			selectedFile = null;
			target.value = '';
			return;
		}
		selectedFile = f;
	}

	async function handleUpload(e: SubmitEvent): Promise<void> {
		e.preventDefault();
		if (!selectedFile) {
			uploadError = 'Pilih file dokumen terlebih dahulu.';
			return;
		}

		uploading = true;
		uploadError = null;
		try {
			const fd = new FormData();
			fd.append('file', selectedFile);
			if (uploadTitle.trim()) {
				fd.append('title', uploadTitle.trim());
			}

			const res = await apiFetch<{ message: string; document: DocumentItem }>('/api/documents', {
				method: 'POST',
				body: fd
			});

			documents = [res.document, ...documents];
			selectedFile = null;
			uploadTitle = '';
			if (fileInputEl) fileInputEl.value = '';
			appState.notify('Dokumen berhasil diunggah dan diekstrak.', 'success');
			await inspectDocument(res.document);
		} catch (err) {
			uploadError =
				err instanceof ApiError ? err.message : 'Gagal mengunggah file ke server.';
		} finally {
			uploading = false;
		}
	}

	async function inspectDocument(doc: DocumentItem): Promise<void> {
		activeDoc = doc;
		loadingDetail = true;
		detailError = null;
		summaryError = null;
		try {
			const res = await apiFetch<{ document: DocumentItem; chunks: DocumentChunkPreview[] }>(
				`/api/documents/${doc.uuid}`
			);
			activeDoc = res.document;
			activeChunks = res.chunks;
			documents = documents.map((d) => (d.uuid === res.document.uuid ? res.document : d));
		} catch (err) {
			detailError =
				err instanceof ApiError ? err.message : 'Gagal memuat isi potongan dokumen.';
		} finally {
			loadingDetail = false;
		}
	}

	async function runAiSummary(): Promise<void> {
		if (!activeDoc) return;
		summarizing = true;
		summaryError = null;
		try {
			const res = await apiFetch<{ ai_summary: string; document: DocumentItem }>(
				`/api/documents/${activeDoc.uuid}/summarize`,
				{
					method: 'POST',
					body: JSON.stringify({ focus: summaryFocus })
				}
			);
			activeDoc = res.document;
			documents = documents.map((d) => (d.uuid === res.document.uuid ? res.document : d));
			appState.notify('Rangkuman terstruktur selesai dibuat.', 'success');
		} catch (err) {
			if (err instanceof ApiError) {
				summaryError = { message: err.message, code: err.errorCode };
			} else {
				summaryError = { message: 'Gagal membuat rangkuman AI saat ini.' };
			}
		} finally {
			summarizing = false;
		}
	}

	function toggleCompareSelection(uuid: string): void {
		if (compareUuids.includes(uuid)) {
			compareUuids = compareUuids.filter((id) => id !== uuid);
		} else {
			if (compareUuids.length >= 4) {
				appState.notify('Maksimal 4 dokumen dapat dibandingkan sekaligus.', 'info');
				return;
			}
			compareUuids = [...compareUuids, uuid];
		}
	}

	async function runComparison(): Promise<void> {
		if (compareUuids.length < 2) {
			compareError = { message: 'Centang minimal 2 dokumen berstatus Siap untuk dibandingkan.' };
			return;
		}
		comparing = true;
		compareError = null;
		compareResult = null;
		try {
			const res = await apiFetch<{ comparison: string }>('/api/documents/compare', {
				method: 'POST',
				body: JSON.stringify({
					document_uuids: compareUuids,
					prompt: comparePrompt
				})
			});
			compareResult = res.comparison;
			appState.notify('Perbandingan lintas dokumen selesai.', 'success');
		} catch (err) {
			if (err instanceof ApiError) {
				compareError = { message: err.message, code: err.errorCode };
			} else {
				compareError = { message: 'Gagal membandingkan dokumen.' };
			}
		} finally {
			comparing = false;
		}
	}

	async function askInChat(docsToAttach: DocumentItem[]): Promise<void> {
		const readyDocs = docsToAttach.filter((d) => d.status === 'ready');
		if (readyDocs.length === 0) {
			appState.notify('Pilih dokumen yang sudah berstatus Siap.', 'error');
			return;
		}
		const titles = readyDocs.map((d) => `"${d.title}"`).join(', ');
		appState.pendingStarter = {
			mode: 'document',
			prompt: `Berdasarkan dokumen ${titles}, jelaskan poin-poin terpenting, temuan data utama, serta hal yang perlu ditindaklanjuti.`,
			documentIds: readyDocs.map((d) => d.id)
		};
		await goto('/');
	}

	function promptDelete(doc: DocumentItem): void {
		docToDelete = doc;
		deleteDialogOpen = true;
	}

	async function confirmDelete(): Promise<void> {
		if (!docToDelete) return;
		deleting = true;
		try {
			const targetUuid = docToDelete.uuid;
			await apiFetch(`/api/documents/${targetUuid}`, { method: 'DELETE' });
			documents = documents.filter((d) => d.uuid !== targetUuid);
			compareUuids = compareUuids.filter((id) => id !== targetUuid);
			if (activeDoc?.uuid === targetUuid) {
				activeDoc = documents[0] ?? null;
				activeChunks = [];
				if (activeDoc) {
					void inspectDocument(activeDoc);
				}
			}
			deleteDialogOpen = false;
			docToDelete = null;
			appState.notify('Dokumen berhasil dihapus.', 'info');
		} catch (err) {
			appState.notify(
				err instanceof ApiError ? err.message : 'Gagal menghapus dokumen.',
				'error'
			);
		} finally {
			deleting = false;
		}
	}

	onMount(() => {
		void loadDocuments();
	});
</script>

<svelte:head>
	<title>Documents | Rekametachat</title>
</svelte:head>

<div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
	<header class="border-b border-[var(--border-subtle)] pb-5">
		<div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
			<div>
				<p class="text-xs font-semibold tracking-wide text-[var(--accent-link)] uppercase">
					Pustaka & Ekstraksi Konteks
				</p>
				<h1
					id="main-stage"
					tabindex="-1"
					class="mt-1 text-2xl font-bold tracking-tight text-[var(--text-primary)] sm:text-3xl"
				>
					Ruang Analisis Dokumen
				</h1>
				<p class="mt-1 text-sm text-[var(--text-secondary)]">
					Unggah PDF, DOCX, TXT, atau CSV (maksimum 10 MB) untuk dibaca isinya, diringkas secara
					terstruktur, ditanyakan di ruang percakapan, atau dibandingkan lintas dokumen.
				</p>
			</div>

			{#if compareUuids.length >= 2}
				<button
					type="button"
					onclick={() => {
						const chosen = documents.filter((d) => compareUuids.includes(d.uuid));
						void askInChat(chosen);
					}}
					class="inline-flex items-center gap-2 rounded-[10px] bg-[var(--accent-blue)] px-3.5 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
				>
					<Icon name="chat" class="h-4 w-4" />
					<span>Diskusikan {compareUuids.length} Dokumen di Chat</span>
				</button>
			{/if}
		</div>
	</header>

	<!-- Upload Form Card -->
	<section
		aria-labelledby="upload-doc-heading"
		class="mt-6 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5"
	>
		<h2 id="upload-doc-heading" class="text-sm font-bold text-[var(--text-primary)]">
			Unggah Dokumen Baru
		</h2>
		<p class="mt-0.5 text-xs text-[var(--text-muted)]">
			Setiap file disimpan secara privat dan hanya dapat diakses oleh akun Anda.
		</p>

		<form onsubmit={handleUpload} class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-12 md:items-end">
			<div class="md:col-span-5">
				<label for="doc-file-input" class="block text-xs font-semibold text-[var(--text-secondary)]">
					Pilih File (PDF, DOCX, TXT, CSV)
				</label>
				<input
					bind:this={fileInputEl}
					id="doc-file-input"
					type="file"
					accept=".pdf,.docx,.txt,.csv"
					onchange={handleFilePicked}
					class="mt-1 block w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)] file:me-3 file:rounded-[6px] file:border-0 file:bg-[var(--accent-blue)] file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-white"
				/>
			</div>

			<div class="md:col-span-5">
				<label for="doc-title-input" class="block text-xs font-semibold text-[var(--text-secondary)]">
					Judul Dokumen (Opsional)
				</label>
				<input
					id="doc-title-input"
					type="text"
					maxlength="180"
					bind:value={uploadTitle}
					placeholder="Misal: Laporan Keuangan Q3 2026"
					class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
				/>
			</div>

			<div class="md:col-span-2">
				<button
					type="submit"
					disabled={uploading}
					class="w-full rounded-[10px] bg-[var(--accent-blue)] px-4 py-2.5 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] disabled:opacity-60 cursor-pointer"
				>
					{uploading ? 'Memproses...' : 'Unggah File'}
				</button>
			</div>
		</form>

		{#if uploading}
			<div class="mt-3">
				<progress class="w-full" aria-label="Mengunggah dan mengekstrak teks dokumen"></progress>
			</div>
		{/if}

		{#if uploadError}
			<p role="alert" class="mt-3 rounded-[10px] bg-[var(--danger-bg)] px-3 py-2 text-xs text-[var(--danger-text)]">
				{uploadError}
			</p>
		{/if}
	</section>

	<!-- Multi-Document Comparison Panel (visible when >= 2 docs exist) -->
	{#if documents.length >= 2}
		<section
			aria-labelledby="compare-heading"
			class="mt-5 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5"
		>
			<div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
				<div>
					<h2 id="compare-heading" class="text-sm font-bold text-[var(--text-primary)]">
						Perbandingan Beberapa Dokumen ({compareUuids.length}/4 dipilih)
					</h2>
					<p class="text-xs text-[var(--text-muted)]">
						Centang 2 hingga 4 dokumen pada daftar di bawah, lalu jalankan analisis perbandingan.
					</p>
				</div>
			</div>

			<div class="mt-3 flex flex-col gap-2.5 sm:flex-row">
				<label for="compare-focus-input" class="visually-hidden">Fokus perbandingan dokumen</label>
				<input
					id="compare-focus-input"
					type="text"
					bind:value={comparePrompt}
					placeholder="Instruksi perbandingan..."
					class="flex-1 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
				/>
				<button
					type="button"
					disabled={comparing || compareUuids.length < 2}
					onclick={() => runComparison()}
					class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] disabled:opacity-50 cursor-pointer"
				>
					{comparing ? 'Membandingkan...' : 'Bandingkan Dokumen'}
				</button>
			</div>

			{#if comparing}
				<div class="mt-3">
					<progress class="w-48" aria-label="Membandingkan isi dokumen"></progress>
				</div>
			{/if}

			{#if compareError}
				<div
					role="alert"
					class="mt-3 rounded-[10px] bg-[var(--warn-bg)] p-3.5 text-xs text-[var(--warn-text)]"
				>
					<p class="font-semibold">{compareError.message}</p>
					{#if compareError.code === 'AI_PROVIDER_NOT_CONFIGURED' && appState.user?.role === 'admin'}
						<a href="/admin" class="mt-1.5 inline-block font-semibold underline">
							Buka Panel Admin untuk mengisi API Key Provider AI
						</a>
					{/if}
				</div>
			{/if}

			{#if compareResult}
				<div class="mt-4 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-4">
					<div class="mb-2 flex items-center justify-between">
						<span class="text-xs font-bold text-[var(--text-primary)]">Hasil Perbandingan</span>
						<button
							type="button"
							onclick={() => (compareResult = null)}
							class="text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)] cursor-pointer"
						>
							Tutup Hasil
						</button>
					</div>
					<div class="markdown-body" use:markdownAction={compareResult}></div>
				</div>
			{/if}
		</section>
	{/if}

	<!-- Main 2-Column Document List & Detail Workspace -->
	<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
		<!-- Left Column: User's Document Vault (5 cols) -->
		<section aria-labelledby="vault-list-heading" class="lg:col-span-5">
			<div class="mb-3 flex items-center justify-between">
				<h2 id="vault-list-heading" class="text-sm font-bold text-[var(--text-primary)]">
					Daftar Dokumen Anda ({documents.length})
				</h2>
				<button
					type="button"
					onclick={() => loadDocuments()}
					class="inline-flex items-center gap-1 rounded-[6px] px-2 py-1 text-xs text-[var(--text-secondary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
				>
					<Icon name="refresh" class="h-3.5 w-3.5" />
					<span>Segarkan</span>
				</button>
			</div>

			{#if loadingList}
				<div
					class="flex flex-col items-center justify-center rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] py-12"
				>
					<progress class="w-36" aria-label="Memuat daftar dokumen"></progress>
					<p class="mt-2 text-xs text-[var(--text-muted)]">Memuat dokumen tersimpan...</p>
				</div>
			{:else if listError}
				<div
					role="alert"
					class="rounded-[14px] border border-[var(--danger-text)]/30 bg-[var(--danger-bg)] p-4 text-xs text-[var(--danger-text)]"
				>
					<p class="font-semibold">{listError}</p>
					<button
						type="button"
						onclick={() => loadDocuments()}
						class="mt-2.5 rounded-[10px] bg-[var(--accent-blue)] px-3 py-1.5 font-semibold text-white cursor-pointer"
					>
						Muat Ulang
					</button>
				</div>
			{:else if documents.length === 0}
				<div
					class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 text-center"
				>
					<p class="text-sm font-semibold text-[var(--text-primary)]">Belum ada dokumen</p>
					<p class="mt-1 text-xs text-[var(--text-muted)]">
						Unggah file PDF, DOCX, TXT, atau CSV pada formulir di atas untuk mengekstrak teks dan
						memulai tanya jawab berbasis dokumen.
					</p>
				</div>
			{:else}
				<ul class="space-y-2.5" role="list">
					{#each documents as doc (doc.uuid)}
						{@const isSelected = activeDoc?.uuid === doc.uuid}
						{@const isChecked = compareUuids.includes(doc.uuid)}
						<li
							class="rounded-[14px] border p-3.5 transition-colors {isSelected
								? 'border-[var(--accent-link)] bg-[var(--bg-elevated)]'
								: 'border-[var(--border-subtle)] bg-[var(--bg-surface)]'}"
						>
							<div class="flex items-start justify-between gap-2.5">
								<div class="flex min-w-0 flex-1 items-start gap-2.5">
									<input
										id="chk-compare-{doc.uuid}"
										type="checkbox"
										checked={isChecked}
										disabled={doc.status !== 'ready'}
										onchange={() => toggleCompareSelection(doc.uuid)}
										aria-label="Pilih {doc.title} untuk dibandingkan"
										class="mt-1 h-4 w-4 shrink-0 rounded"
									/>
									<button
										type="button"
										onclick={() => inspectDocument(doc)}
										class="min-w-0 flex-1 text-start cursor-pointer"
									>
										<div class="flex flex-wrap items-center gap-1.5">
											<span
												class="rounded-[6px] bg-[var(--accent-violet-bg)] px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase text-[var(--accent-violet-text)]"
											>
												{doc.extension}
											</span>
											<span
												class="rounded-[6px] px-1.5 py-0.5 text-[10px] font-semibold {doc.status ===
												'ready'
													? 'bg-[var(--success-bg)] text-[var(--success-text)]'
													: doc.status === 'failed'
														? 'bg-[var(--danger-bg)] text-[var(--danger-text)]'
														: 'bg-[var(--warn-bg)] text-[var(--warn-text)]'}"
											>
												{doc.status === 'ready'
													? 'Siap'
													: doc.status === 'failed'
														? 'Gagal'
														: 'Diproses'}
											</span>
										</div>
										<p class="mt-1 truncate text-xs font-bold text-[var(--text-primary)]">
											{doc.title}
										</p>
										<p class="mt-0.5 text-[11px] text-[var(--text-muted)]">
											{formatBytes(doc.file_size)} · {doc.word_count.toLocaleString('id-ID')} kata ·
											{doc.chunk_count} segmen
										</p>
									</button>
								</div>

								<button
									type="button"
									onclick={() => promptDelete(doc)}
									aria-label="Hapus dokumen {doc.title}"
									class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] text-[var(--text-muted)] hover:bg-[var(--danger-bg)] hover:text-[var(--danger-text)] cursor-pointer"
								>
									<Icon name="trash" class="h-4 w-4" />
								</button>
							</div>
						</li>
					{/each}
				</ul>
			{/if}
		</section>

		<!-- Right Column: Document Reader, Summary & Chunk Inspector (7 cols) -->
		<section aria-labelledby="doc-inspector-heading" class="lg:col-span-7">
			{#if !activeDoc}
				<div
					class="flex h-full min-h-[280px] flex-col items-center justify-center rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-8 text-center"
				>
					<Icon name="document" class="h-8 w-8 text-[var(--text-muted)]" />
					<h2 id="doc-inspector-heading" class="mt-3 text-sm font-bold text-[var(--text-primary)]">
						Panel Detail Dokumen
					</h2>
					<p class="mt-1 max-w-sm text-xs text-[var(--text-muted)]">
						Pilih salah satu dokumen di sebelah kiri atau unggah dokumen baru untuk melihat
						struktur isi, membuat rangkuman AI, atau membuka sesi tanya jawab.
					</p>
				</div>
			{:else}
				<div
					class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-5"
				>
					<div class="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--border-subtle)] pb-4">
						<div class="min-w-0">
							<span class="font-mono text-[11px] uppercase text-[var(--accent-link)]">
								{activeDoc.original_filename}
							</span>
							<h2
								id="doc-inspector-heading"
								class="mt-0.5 text-lg font-bold text-[var(--text-primary)]"
							>
								{activeDoc.title}
							</h2>
							<p class="mt-0.5 text-xs text-[var(--text-muted)]">
								Ukuran {formatBytes(activeDoc.file_size)}
								{#if activeDoc.page_count}
									· {activeDoc.page_count} halaman
								{/if}
								· {activeDoc.word_count.toLocaleString('id-ID')} kata · {activeDoc.chunk_count} segmen konteks
							</p>
						</div>

						{#if activeDoc.status === 'ready'}
							<button
								type="button"
								onclick={() => activeDoc && askInChat([activeDoc])}
								class="inline-flex items-center gap-2 rounded-[10px] bg-[var(--accent-blue)] px-3.5 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
							>
								<Icon name="chat" class="h-4 w-4" />
								<span>Tanya Dokumen di Chat</span>
							</button>
						{/if}
					</div>

					{#if activeDoc.status === 'failed'}
						<div
							role="alert"
							class="rounded-[10px] bg-[var(--danger-bg)] p-3.5 text-xs text-[var(--danger-text)]"
						>
							{activeDoc.error_message ?? 'Gagal mengekstrak teks dari dokumen ini.'}
						</div>
					{/if}

					<!-- Deterministic Metadata & Structure Overview -->
					{#if activeDoc.structured_summary}
						<div class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-4 space-y-3">
							<h3 class="text-xs font-bold text-[var(--text-primary)]">
								 Gambaran Struktur Terdeteksi
							</h3>

							{#if activeDoc.structured_summary.csv_columns?.length}
								<div>
									<p class="text-[11px] font-semibold text-[var(--text-secondary)]">
										Kolom CSV ({activeDoc.structured_summary.csv_rows ?? 0} baris data):
									</p>
									<div class="mt-1.5 flex flex-wrap gap-1.5">
										{#each activeDoc.structured_summary.csv_columns as col (col)}
											<span
												class="rounded-[6px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2 py-0.5 font-mono text-[11px] text-[var(--text-primary)]"
											>
												{col}
											</span>
										{/each}
									</div>
								</div>
							{/if}

							{#if activeDoc.structured_summary.detected_sections?.length}
								<div>
									<p class="text-[11px] font-semibold text-[var(--text-secondary)]">
										Subjudul / Bagian Utama:
									</p>
									<ul class="mt-1 list-inside list-disc text-xs text-[var(--text-secondary)]">
										{#each activeDoc.structured_summary.detected_sections.slice(0, 8) as sec (sec)}
											<li>{sec}</li>
										{/each}
									</ul>
								</div>
							{/if}

							{#if activeDoc.structured_summary.excerpt}
								<div>
									<p class="text-[11px] font-semibold text-[var(--text-secondary)]">
										Cuplikan Pembuka:
									</p>
									<p class="mt-1 text-xs leading-relaxed text-[var(--text-muted)]">
										{activeDoc.structured_summary.excerpt}
									</p>
								</div>
							{/if}
						</div>
					{/if}

					<!-- AI Structured Summary Generator -->
					{#if activeDoc.status === 'ready'}
						<div class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-4 space-y-3">
							<div class="flex flex-wrap items-center justify-between gap-2">
								<h3 class="text-xs font-bold text-[var(--text-primary)]">
									Rangkuman Terstruktur AI
								</h3>
								<button
									type="button"
									disabled={summarizing}
									onclick={() => runAiSummary()}
									class="rounded-[10px] bg-[var(--accent-blue)] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] disabled:opacity-50 cursor-pointer"
								>
									{summarizing
										? 'Menyusun Rangkuman...'
										: activeDoc.structured_summary?.ai_summary
											? 'Perbarui Rangkuman AI'
											: 'Buat Rangkuman AI'}
								</button>
							</div>

							<div>
								<label
									for="summary-focus-input"
									class="block text-[11px] font-medium text-[var(--text-secondary)]"
								>
									Fokus Rangkuman:
								</label>
								<input
									id="summary-focus-input"
									type="text"
									bind:value={summaryFocus}
									class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-1.5 text-xs text-[var(--text-primary)]"
								/>
							</div>

							{#if summarizing}
								<progress class="w-40" aria-label="Menyusun rangkuman AI"></progress>
							{/if}

							{#if summaryError}
								<div
									role="alert"
									class="rounded-[10px] bg-[var(--warn-bg)] p-3 text-xs text-[var(--warn-text)]"
								>
									<p class="font-semibold">{summaryError.message}</p>
									{#if summaryError.code === 'AI_PROVIDER_NOT_CONFIGURED' && appState.user?.role === 'admin'}
										<a href="/admin" class="mt-1 inline-block font-semibold underline">
											Konfigurasi API Key Provider di Panel Admin
										</a>
									{/if}
								</div>
							{/if}

							{#if activeDoc.structured_summary?.ai_summary}
								<div
									class="markdown-body rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-3.5"
									use:markdownAction={activeDoc.structured_summary.ai_summary}
								></div>
							{/if}
						</div>
					{/if}

					<!-- Extracted Text Segments -->
					<div>
						<h3 class="text-xs font-bold text-[var(--text-primary)]">
							Potongan Teks Terindeks ({activeChunks.length})
						</h3>
						{#if loadingDetail}
							<div class="py-6 text-center">
								<progress class="w-36" aria-label="Memuat segmen dokumen"></progress>
							</div>
						{:else if detailError}
							<p role="alert" class="mt-2 text-xs text-[var(--danger-text)]">{detailError}</p>
						{:else if activeChunks.length === 0}
							<p class="mt-2 text-xs text-[var(--text-muted)]">
								Tidak ada segmen teks yang tersimpan untuk dokumen ini.
							</p>
						{:else}
							<div class="mt-2.5 max-h-80 space-y-2 overflow-y-auto pr-1">
								{#each activeChunks as chunk (chunk.id)}
									<article
										class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-3"
									>
										<div class="flex items-center justify-between text-[11px] text-[var(--text-muted)]">
											<span class="font-semibold text-[var(--text-secondary)]">
												Segmen #{chunk.chunk_index + 1}
												{chunk.heading ? ` · ${chunk.heading}` : ''}
											</span>
											<span class="font-mono">~{chunk.token_estimate} token</span>
										</div>
										<p class="mt-1.5 whitespace-pre-line text-xs leading-relaxed text-[var(--text-primary)]">
											{chunk.content}
										</p>
									</article>
								{/each}
							</div>
						{/if}
					</div>
				</div>
			{/if}
		</section>
	</div>
</div>

<ModalDialog
	bind:open={deleteDialogOpen}
	title="Hapus Dokumen"
	onClose={() => {
		deleteDialogOpen = false;
		docToDelete = null;
	}}
>
	<div class="space-y-4">
		<p class="text-xs leading-relaxed text-[var(--text-secondary)]">
			Apakah Anda yakin ingin menghapus dokumen
			<strong class="text-[var(--text-primary)]">{docToDelete?.title ?? ''}</strong> beserta seluruh
			indeks teksnya secara permanen?
		</p>
		<div class="flex justify-end gap-2.5">
			<button
				type="button"
				onclick={() => {
					deleteDialogOpen = false;
					docToDelete = null;
				}}
				class="rounded-[10px] border border-[var(--border-subtle)] px-3.5 py-2 text-xs font-semibold text-[var(--text-primary)] cursor-pointer"
			>
				Batal
			</button>
			<button
				type="button"
				disabled={deleting}
				onclick={() => confirmDelete()}
				class="rounded-[10px] bg-[var(--danger-bg)] px-3.5 py-2 text-xs font-semibold text-[var(--danger-text)] cursor-pointer"
			>
				{deleting ? 'Menghapus...' : 'Hapus Permanen'}
			</button>
		</div>
	</div>
</ModalDialog>

