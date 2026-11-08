<script lang="ts">
	import { ApiError, apiDownloadBlob, apiFetch } from '$lib/api';
	import Icon from '$lib/components/Icon.svelte';
	import ModalDialog from '$lib/components/ModalDialog.svelte';
	import { appState } from '$lib/state.svelte';
	import type {
		CvCertification,
		CvEducation,
		CvExperience,
		CvProfile
	} from '$lib/types';
	import { onMount } from 'svelte';

	interface TailoringResult {
		match_score_estimate?: number;
		matched_keywords?: string[];
		missing_keywords_to_verify?: string[];
		tailored_summary?: string;
		experience_adjustments?: { role?: string; suggestion?: string }[];
		honest_gap_notes?: string;
	}

	let cvs = $state<CvProfile[]>([]);
	let loadingList = $state(true);
	let listError = $state<string | null>(null);

	let activeCv = $state<CvProfile | null>(null);
	let activeTab = $state<'editor' | 'ats' | 'tailor' | 'preview'>('editor');

	// Editable draft state synced with activeCv
	let draftTitle = $state('CV Profesional');
	let draftTemplate = $state<'executive' | 'ats_clean' | 'modern_minimal'>('executive');
	let draftFullName = $state('');
	let draftHeadline = $state('');
	let draftEmail = $state('');
	let draftPhone = $state('');
	let draftLocation = $state('');
	let draftLinkedin = $state('');
	let draftWebsite = $state('');
	let draftSummary = $state('');
	let draftExperiences = $state<CvExperience[]>([]);
	let draftEducations = $state<CvEducation[]>([]);
	let draftSkills = $state<string[]>([]);
	let skillInput = $state('');
	let draftCertifications = $state<CvCertification[]>([]);
	let draftJobDescription = $state('');
	let draftCompanyName = $state('');
	let draftCoverLanguage = $state<'id' | 'en'>('id');
	let draftCoverLetter = $state('');

	let saving = $state(false);
	let creating = $state(false);
	let importing = $state(false);
	let exportingPdf = $state(false);
	let runningAiReview = $state(false);
	let runningTailor = $state(false);
	let runningCoverLetter = $state(false);

	let aiActionError = $state<{ message: string; code?: string } | null>(null);
	let tailoringResult = $state<TailoringResult | null>(null);

	let importInputEl: HTMLInputElement | undefined = $state();
	let deleteModalOpen = $state(false);
	let deletingCv = $state(false);

	const templateOptions: {
		id: 'executive' | 'ats_clean' | 'modern_minimal';
		label: string;
		desc: string;
	}[] = [
		{
			id: 'executive',
			label: 'Executive Navy',
			desc: 'Header tegas Midnight Navy untuk peran manajerial, riset, dan spesialis.'
		},
		{
			id: 'ats_clean',
			label: 'ATS Standard Clean',
			desc: 'Satu kolom klasik tanpa ornamen, optimal untuk sistem parser rekrutmen.'
		},
		{
			id: 'modern_minimal',
			label: 'Modern Minimal',
			desc: 'Tipografi kontemporer dengan aksen biru elektrik yang bersih.'
		}
	];

	function hydrateDraftFromCv(cv: CvProfile): void {
		activeCv = cv;
		draftTitle = cv.title || 'CV Profesional';
		draftTemplate = cv.template || 'executive';
		draftFullName = cv.full_name || '';
		draftHeadline = cv.headline ?? '';
		draftEmail = cv.email ?? '';
		draftPhone = cv.phone ?? '';
		draftLocation = cv.location ?? '';
		draftLinkedin = cv.linkedin ?? '';
		draftWebsite = cv.website ?? '';
		draftSummary = cv.summary ?? '';
		draftExperiences = (cv.experiences ?? []).map((exp) => ({
			role: exp.role ?? '',
			company: exp.company ?? '',
			location: exp.location ?? '',
			start_date: exp.start_date ?? '',
			end_date: exp.end_date ?? '',
			highlights: Array.isArray(exp.highlights) && exp.highlights.length > 0 ? [...exp.highlights] : ['']
		}));
		draftEducations = (cv.educations ?? []).map((edu) => ({
			degree: edu.degree ?? '',
			institution: edu.institution ?? '',
			year: edu.year ?? '',
			notes: edu.notes ?? ''
		}));
		draftSkills = [...(cv.skills ?? [])];
		draftCertifications = (cv.certifications ?? []).map((c) => ({
			name: c.name ?? '',
			issuer: c.issuer ?? '',
			year: c.year ?? ''
		}));
		draftJobDescription = cv.target_job_description ?? '';
		draftCoverLetter = cv.cover_letter ?? '';
		aiActionError = null;
	}

	async function loadCvs(): Promise<void> {
		loadingList = true;
		listError = null;
		try {
			const res = await apiFetch<{ cvs: CvProfile[] }>('/api/cvs');
			cvs = res.cvs;
			if (cvs.length > 0 && !activeCv) {
				hydrateDraftFromCv(cvs[0]);
			}
		} catch (err) {
			listError = err instanceof ApiError ? err.message : 'Gagal memuat daftar CV Anda.';
		} finally {
			loadingList = false;
		}
	}

	async function createNewCv(): Promise<void> {
		creating = true;
		aiActionError = null;
		try {
			const defaultName = appState.user?.name || 'Nama Lengkap';
			const defaultEmail = appState.user?.email || '';
			const res = await apiFetch<{ cv: CvProfile }>('/api/cvs', {
				method: 'POST',
				body: JSON.stringify({
					title: `CV ${defaultName}`,
					template: 'executive',
					full_name: defaultName,
					email: defaultEmail,
					experiences: [],
					educations: [],
					skills: [],
					certifications: []
				})
			});
			cvs = [res.cv, ...cvs];
			hydrateDraftFromCv(res.cv);
			activeTab = 'editor';
			appState.notify('Draf CV baru dibuat. Silakan isi data riwayat Anda.', 'success');
		} catch (err) {
			appState.notify(err instanceof ApiError ? err.message : 'Gagal membuat CV baru.', 'error');
		} finally {
			creating = false;
		}
	}

	async function handleImportFile(e: Event): Promise<void> {
		const input = e.currentTarget as HTMLInputElement;
		const file = input.files?.[0];
		if (!file) return;

		const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
		if (!['pdf', 'docx'].includes(ext)) {
			appState.notify('Impor CV hanya menerima format PDF atau DOCX.', 'error');
			input.value = '';
			return;
		}

		importing = true;
		aiActionError = null;
		try {
			const fd = new FormData();
			fd.append('file', file);
			const res = await apiFetch<{ message: string; cv: CvProfile }>('/api/cvs/import', {
				method: 'POST',
				body: fd
			});
			cvs = [res.cv, ...cvs];
			hydrateDraftFromCv(res.cv);
			activeTab = 'ats';
			appState.notify('File CV berhasil diekstrak dan diaudit.', 'success');
		} catch (err) {
			appState.notify(
				err instanceof ApiError ? err.message : 'Gagal membaca file CV unggahan.',
				'error'
			);
		} finally {
			importing = false;
			input.value = '';
		}
	}

	function buildPayload() {
		const cleanedExperiences = draftExperiences
			.filter((e) => e.role.trim() !== '')
			.map((e) => ({
				role: e.role.trim(),
				company: e.company.trim(),
				location: e.location.trim(),
				start_date: e.start_date.trim(),
				end_date: e.end_date.trim(),
				highlights: e.highlights.map((h) => h.trim()).filter(Boolean)
			}));

		const cleanedEducations = draftEducations
			.filter((ed) => ed.degree.trim() !== '')
			.map((ed) => ({
				degree: ed.degree.trim(),
				institution: ed.institution.trim(),
				year: ed.year.trim(),
				notes: ed.notes.trim()
			}));

		const cleanedCertifications = draftCertifications
			.filter((c) => c.name.trim() !== '')
			.map((c) => ({
				name: c.name.trim(),
				issuer: c.issuer.trim(),
				year: c.year.trim()
			}));

		return {
			title: draftTitle.trim() || 'CV Profesional',
			template: draftTemplate,
			full_name: draftFullName.trim() || appState.user?.name || 'Nama Lengkap',
			headline: draftHeadline.trim() || null,
			email: draftEmail.trim() || null,
			phone: draftPhone.trim() || null,
			location: draftLocation.trim() || null,
			linkedin: draftLinkedin.trim() || null,
			website: draftWebsite.trim() || null,
			summary: draftSummary.trim() || null,
			experiences: cleanedExperiences,
			educations: cleanedEducations,
			skills: draftSkills.map((s) => s.trim()).filter(Boolean),
			certifications: cleanedCertifications,
			target_job_description: draftJobDescription.trim() || null,
			cover_letter: draftCoverLetter.trim() || null
		};
	}

	async function saveActiveCv(silent = false): Promise<boolean> {
		if (!activeCv) return false;
		if (draftFullName.trim().length < 2) {
			appState.notify('Nama lengkap minimal terdiri dari 2 karakter.', 'error');
			return false;
		}

		saving = true;
		try {
			const res = await apiFetch<{ cv: CvProfile }>(`/api/cvs/${activeCv.uuid}`, {
				method: 'PUT',
				body: JSON.stringify(buildPayload())
			});
			activeCv = res.cv;
			cvs = cvs.map((c) => (c.uuid === res.cv.uuid ? res.cv : c));
			if (!silent) {
				appState.notify('CV disimpan dan skor ATS diperbarui.', 'success');
			}
			return true;
		} catch (err) {
			appState.notify(
				err instanceof ApiError ? err.message : 'Gagal menyimpan perubahan CV.',
				'error'
			);
			return false;
		} finally {
			saving = false;
		}
	}

	async function handleExportPdf(): Promise<void> {
		if (!activeCv) return;
		const saved = await saveActiveCv(true);
		if (!saved) return;

		exportingPdf = true;
		try {
			const { blob, filename } = await apiDownloadBlob(
				`/api/cvs/${activeCv.uuid}/export-pdf?template=${encodeURIComponent(draftTemplate)}`
			);
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = filename;
			document.body.appendChild(a);
			a.click();
			a.remove();
			setTimeout(() => URL.revokeObjectURL(url), 3000);
			appState.notify(`PDF berhasil diunduh (${filename}).`, 'success');
		} catch (err) {
			appState.notify(err instanceof ApiError ? err.message : 'Gagal mengekspor PDF.', 'error');
		} finally {
			exportingPdf = false;
		}
	}

	async function runAiCvReview(): Promise<void> {
		if (!activeCv) return;
		await saveActiveCv(true);
		runningAiReview = true;
		aiActionError = null;
		try {
			const res = await apiFetch<{ cv: CvProfile }>(`/api/cvs/${activeCv.uuid}/ai-review`, {
				method: 'POST',
				body: JSON.stringify({ apply_summary: false })
			});
			activeCv = res.cv;
			cvs = cvs.map((c) => (c.uuid === res.cv.uuid ? res.cv : c));
			appState.notify('Evaluasi AI dan saran ringkasan profesional selesai.', 'success');
		} catch (err) {
			if (err instanceof ApiError) {
				aiActionError = { message: err.message, code: err.errorCode };
				if (err.payload?.cv) {
					activeCv = err.payload.cv as CvProfile;
				}
			} else {
				aiActionError = { message: 'Gagal menjalankan evaluasi AI.' };
			}
		} finally {
			runningAiReview = false;
		}
	}

	async function runTailorToJob(): Promise<void> {
		if (!activeCv) return;
		if (draftJobDescription.trim().length < 20) {
			aiActionError = {
				message: 'Tempelkan deskripsi lowongan kerja minimal 20 karakter terlebih dahulu.'
			};
			return;
		}
		await saveActiveCv(true);
		runningTailor = true;
		aiActionError = null;
		try {
			const res = await apiFetch<{ tailoring: TailoringResult; cv: CvProfile }>(
				`/api/cvs/${activeCv.uuid}/tailor`,
				{
					method: 'POST',
					body: JSON.stringify({
						job_description: draftJobDescription.trim(),
						apply_summary: false
					})
				}
			);
			tailoringResult = res.tailoring;
			activeCv = res.cv;
			cvs = cvs.map((c) => (c.uuid === res.cv.uuid ? res.cv : c));
			appState.notify('Analisis kecocokan lowongan kerja selesai.', 'success');
		} catch (err) {
			if (err instanceof ApiError) {
				aiActionError = { message: err.message, code: err.errorCode };
			} else {
				aiActionError = { message: 'Gagal menyesuaikan CV dengan lowongan.' };
			}
		} finally {
			runningTailor = false;
		}
	}

	async function runGenerateCoverLetter(): Promise<void> {
		if (!activeCv) return;
		if (draftJobDescription.trim().length < 20) {
			aiActionError = {
				message: 'Isi deskripsi lowongan minimal 20 karakter sebelum membuat cover letter.'
			};
			return;
		}
		await saveActiveCv(true);
		runningCoverLetter = true;
		aiActionError = null;
		try {
			const res = await apiFetch<{ cover_letter: string; cv: CvProfile }>(
				`/api/cvs/${activeCv.uuid}/cover-letter`,
				{
					method: 'POST',
					body: JSON.stringify({
						job_description: draftJobDescription.trim(),
						company_name: draftCompanyName.trim(),
						language: draftCoverLanguage
					})
				}
			);
			draftCoverLetter = res.cover_letter;
			activeCv = res.cv;
			cvs = cvs.map((c) => (c.uuid === res.cv.uuid ? res.cv : c));
			appState.notify('Draf cover letter berhasil dibuat.', 'success');
		} catch (err) {
			if (err instanceof ApiError) {
				aiActionError = { message: err.message, code: err.errorCode };
			} else {
				aiActionError = { message: 'Gagal membuat cover letter.' };
			}
		} finally {
			runningCoverLetter = false;
		}
	}

	async function confirmDeleteActiveCv(): Promise<void> {
		if (!activeCv) return;
		deletingCv = true;
		try {
			const targetUuid = activeCv.uuid;
			await apiFetch(`/api/cvs/${targetUuid}`, { method: 'DELETE' });
			cvs = cvs.filter((c) => c.uuid !== targetUuid);
			deleteModalOpen = false;
			if (cvs.length > 0) {
				hydrateDraftFromCv(cvs[0]);
			} else {
				activeCv = null;
			}
			appState.notify('Profil CV dihapus.', 'info');
		} catch (err) {
			appState.notify(err instanceof ApiError ? err.message : 'Gagal menghapus CV.', 'error');
		} finally {
			deletingCv = false;
		}
	}

	function addExperience(): void {
		draftExperiences = [
			...draftExperiences,
			{
				role: '',
				company: '',
				location: '',
				start_date: '',
				end_date: 'Sekarang',
				highlights: ['']
			}
		];
	}

	function removeExperience(index: number): void {
		draftExperiences = draftExperiences.filter((_, i) => i !== index);
	}

	function addHighlight(expIdx: number): void {
		draftExperiences[expIdx].highlights = [...draftExperiences[expIdx].highlights, ''];
	}

	function removeHighlight(expIdx: number, hlIdx: number): void {
		draftExperiences[expIdx].highlights = draftExperiences[expIdx].highlights.filter(
			(_, i) => i !== hlIdx
		);
	}

	function addEducation(): void {
		draftEducations = [
			...draftEducations,
			{ degree: '', institution: '', year: '', notes: '' }
		];
	}

	function removeEducation(index: number): void {
		draftEducations = draftEducations.filter((_, i) => i !== index);
	}

	function addSkillFromInput(): void {
		const parts = skillInput
			.split(',')
			.map((s) => s.trim())
			.filter(Boolean);
		if (parts.length === 0) return;
		const next = new Set([...draftSkills, ...parts]);
		draftSkills = Array.from(next).slice(0, 50);
		skillInput = '';
	}

	function removeSkill(skill: string): void {
		draftSkills = draftSkills.filter((s) => s !== skill);
	}

	function addCertification(): void {
		draftCertifications = [...draftCertifications, { name: '', issuer: '', year: '' }];
	}

	function removeCertification(index: number): void {
		draftCertifications = draftCertifications.filter((_, i) => i !== index);
	}

	async function copyCoverLetter(): Promise<void> {
		if (!draftCoverLetter.trim()) return;
		try {
			await navigator.clipboard.writeText(draftCoverLetter);
			appState.notify('Cover letter disalin ke papan klip.', 'success');
		} catch {
			appState.notify('Gagal menyalin teks.', 'error');
		}
	}

	onMount(() => {
		void loadCvs();
	});
</script>

<svelte:head>
	<title>CV Studio | Rekametachat</title>
</svelte:head>

<div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
	<header class="border-b border-[var(--border-subtle)] pb-5">
		<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
			<div>
				<p class="text-xs font-semibold tracking-wide text-[var(--accent-link)] uppercase">
					Karier, Evaluasi ATS & Ekspor PDF
				</p>
				<h1
					id="main-stage"
					tabindex="-1"
					class="mt-1 text-2xl font-bold tracking-tight text-[var(--text-primary)] sm:text-3xl"
				>
					Studio CV & Cover Letter
				</h1>
				<p class="mt-1 text-sm text-[var(--text-secondary)]">
					Susun CV faktual dari pengalaman nyata Anda, impor PDF atau DOCX untuk diaudit skor
					keterbacaan ATS-nya, sesuaikan dengan lowongan, dan unduh PDF siap kirim.
				</p>
			</div>

			<div class="flex flex-wrap items-center gap-2.5">
				<input
					bind:this={importInputEl}
					type="file"
					accept=".pdf,.docx"
					onchange={handleImportFile}
					class="visually-hidden"
					id="cv-import-file"
				/>
				<button
					type="button"
					disabled={importing}
					onclick={() => importInputEl?.click()}
					class="inline-flex items-center gap-2 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3.5 py-2 text-xs font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
				>
					<Icon name="paperclip" class="h-4 w-4" />
					<span>{importing ? 'Membaca File...' : 'Impor CV (PDF/DOCX)'}</span>
				</button>

				<button
					type="button"
					disabled={creating}
					onclick={() => createNewCv()}
					class="inline-flex items-center gap-2 rounded-[10px] bg-[var(--accent-blue)] px-3.5 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
				>
					<Icon name="plus" class="h-4 w-4" />
					<span>{creating ? 'Membuat...' : 'Buat CV Baru'}</span>
				</button>
			</div>
		</div>
	</header>

	{#if importing}
		<div class="mt-4">
			<progress class="w-full" aria-label="Mengekstrak dan mengaudit CV dari file"></progress>
		</div>
	{/if}

	<div class="mt-6">
		{#if loadingList}
			<div
				class="flex flex-col items-center justify-center rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] py-14"
			>
				<progress class="w-40" aria-label="Memuat daftar CV Anda"></progress>
				<p class="mt-2 text-xs text-[var(--text-muted)]">Memuat profil CV tersimpan...</p>
			</div>
		{:else if listError}
			<div
				role="alert"
				class="rounded-[14px] bg-[var(--danger-bg)] p-5 text-xs text-[var(--danger-text)]"
			>
				<p class="font-semibold">{listError}</p>
				<button
					type="button"
					onclick={() => loadCvs()}
					class="mt-3 rounded-[10px] bg-[var(--accent-blue)] px-3.5 py-2 font-semibold text-white cursor-pointer"
				>
					Muat Ulang
				</button>
			</div>
		{:else if cvs.length === 0 || !activeCv}
			<div
				class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-8 text-center"
			>
				<div
					class="mx-auto flex h-12 w-12 items-center justify-center rounded-[10px] bg-[var(--accent-violet-bg)] text-[var(--accent-violet-text)]"
				>
					<Icon name="cv" class="h-6 w-6" />
				</div>
				<h2 class="mt-3 text-base font-bold text-[var(--text-primary)]">
					Belum ada profil CV tersimpan
				</h2>
				<p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-[var(--text-muted)]">
					Mulai dari formulir kosong atau unggah CV lama berformat PDF maupun DOCX. Rekametachat
					hanya menata dan mengevaluasi fakta yang Anda berikan tanpa mengarang riwayat kerja atau
					gelar.
				</p>
				<div class="mt-5 flex flex-wrap justify-center gap-3">
					<button
						type="button"
						onclick={() => createNewCv()}
						class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-2.5 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
					>
						Susun CV dari Awal
					</button>
					<button
						type="button"
						onclick={() => importInputEl?.click()}
						class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-4 py-2.5 text-xs font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
					>
						Unggah CV PDF / DOCX
					</button>
				</div>
			</div>
		{:else}
			<!-- Saved CV Switcher & Top Action Toolbar -->
			<div
				class="flex flex-col gap-3 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4 lg:flex-row lg:items-center lg:justify-between"
			>
				<div class="flex flex-wrap items-center gap-2.5">
					<label for="cv-select" class="text-xs font-semibold text-[var(--text-secondary)]">
						Dokumen Aktif:
					</label>
					<select
						id="cv-select"
						value={activeCv.uuid}
						onchange={(e) => {
							const found = cvs.find((c) => c.uuid === e.currentTarget.value);
							if (found) hydrateDraftFromCv(found);
						}}
						class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-1.5 text-xs font-semibold text-[var(--text-primary)]"
					>
						{#each cvs as item (item.uuid)}
							<option value={item.uuid}>
								{item.title} (ATS {item.ats_score ?? 0}/100)
							</option>
						{/each}
					</select>

					<span
						class="rounded-[6px] px-2.5 py-1 text-xs font-bold {(activeCv.ats_score ?? 0) >= 75
							? 'bg-[var(--success-bg)] text-[var(--success-text)]'
							: (activeCv.ats_score ?? 0) >= 50
								? 'bg-[var(--warn-bg)] text-[var(--warn-text)]'
								: 'bg-[var(--danger-bg)] text-[var(--danger-text)]'}"
					>
						Skor ATS: {activeCv.ats_score ?? 0}/100
					</span>
				</div>

				<div class="flex flex-wrap items-center gap-2">
					<button
						type="button"
						disabled={saving}
						onclick={() => saveActiveCv(false)}
						class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3.5 py-2 text-xs font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
					>
						{saving ? 'Menyimpan...' : 'Simpan & Hitung ATS'}
					</button>

					<button
						type="button"
						disabled={exportingPdf}
						onclick={() => handleExportPdf()}
						class="inline-flex items-center gap-1.5 rounded-[10px] bg-[var(--accent-blue)] px-3.5 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
					>
						<Icon name="download" class="h-3.5 w-3.5" />
						<span>{exportingPdf ? 'Mencetak PDF...' : 'Ekspor PDF'}</span>
					</button>

					<button
						type="button"
						onclick={() => (deleteModalOpen = true)}
						aria-label="Hapus CV aktif"
						class="inline-flex h-9 w-9 items-center justify-center rounded-[10px] text-[var(--text-muted)] hover:bg-[var(--danger-bg)] hover:text-[var(--danger-text)] cursor-pointer"
					>
						<Icon name="trash" class="h-4 w-4" />
					</button>
				</div>
			</div>

			<!-- Studio Mode Tabs -->
			<div
				class="mt-4 flex flex-wrap gap-2 border-b border-[var(--border-subtle)] pb-3"
				role="tablist"
				aria-label="Tahapan Studio CV"
			>
				{#each [
					{ id: 'editor', label: '1. Data & Template CV' },
					{ id: 'ats', label: '2. Audit ATS & Saran AI' },
					{ id: 'tailor', label: '3. Sesuaikan Lowongan & Cover Letter' },
					{ id: 'preview', label: '4. Pratinjau Cetak' }
				] as tab (tab.id)}
					<button
						type="button"
						role="tab"
						aria-selected={activeTab === tab.id}
						onclick={() => (activeTab = tab.id as typeof activeTab)}
						class="rounded-[10px] px-3.5 py-2 text-xs font-semibold transition-colors cursor-pointer {activeTab ===
						tab.id
							? 'bg-[var(--accent-blue)] text-white'
							: 'bg-[var(--bg-surface)] text-[var(--text-secondary)] border border-[var(--border-subtle)] hover:bg-[var(--bg-elevated)]'}"
					>
						{tab.label}
					</button>
				{/each}
			</div>

			{#if aiActionError}
				<div
					role="alert"
					class="mt-4 rounded-[14px] bg-[var(--warn-bg)] p-4 text-xs text-[var(--warn-text)]"
				>
					<p class="font-semibold">{aiActionError.message}</p>
					{#if aiActionError.code === 'AI_PROVIDER_NOT_CONFIGURED' && appState.user?.role === 'admin'}
						<a href="/admin" class="mt-1.5 inline-block font-semibold underline">
							Buka Panel Admin untuk mengaktifkan API Key Provider AI
						</a>
					{/if}
				</div>
			{/if}

			<!-- TAB 1: STRUCTURED CV EDITOR -->
			{#if activeTab === 'editor'}
				<div class="mt-5 space-y-6">
					<!-- Template Picker & Document Title -->
					<section
						aria-labelledby="tpl-heading"
						class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-4"
					>
						<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
							<div>
								<label
									for="cv-title-field"
									class="block text-xs font-semibold text-[var(--text-secondary)]"
								>
									Nama Dokumen Internal
								</label>
								<input
									id="cv-title-field"
									type="text"
									bind:value={draftTitle}
									class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
								/>
							</div>
							<div>
								<span id="tpl-heading" class="block text-xs font-semibold text-[var(--text-secondary)]">
									Template Desain PDF
								</span>
								<div class="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-3">
									{#each templateOptions as tpl (tpl.id)}
										<button
											type="button"
											onclick={() => (draftTemplate = tpl.id)}
											aria-pressed={draftTemplate === tpl.id}
											class="rounded-[10px] border p-2.5 text-start cursor-pointer {draftTemplate ===
											tpl.id
												? 'border-[var(--accent-link)] bg-[var(--bg-elevated)]'
												: 'border-[var(--border-subtle)] bg-[var(--bg-canvas)]'}"
										>
											<p class="text-xs font-bold text-[var(--text-primary)]">{tpl.label}</p>
											<p class="mt-0.5 text-[10px] leading-snug text-[var(--text-muted)]">
												{tpl.desc}
											</p>
										</button>
									{/each}
								</div>
							</div>
						</div>
					</section>

					<!-- Personal Contact Info -->
					<section
						aria-labelledby="personal-heading"
						class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5"
					>
						<h2 id="personal-heading" class="text-sm font-bold text-[var(--text-primary)]">
							Identitas & Kontak
						</h2>
						<div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
							<div>
								<label for="cv-fullname" class="block text-xs font-semibold text-[var(--text-secondary)]">
									Nama Lengkap *
								</label>
								<input
									id="cv-fullname"
									type="text"
									autocomplete="name"
									required
									bind:value={draftFullName}
									class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
								/>
							</div>

							<div>
								<label for="cv-headline" class="block text-xs font-semibold text-[var(--text-secondary)]">
									Headline Jabatan / Bidang
								</label>
								<input
									id="cv-headline"
									type="text"
									bind:value={draftHeadline}
									placeholder="Misal: Full-Stack Software Engineer"
									class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
								/>
							</div>

							<div>
								<label for="cv-email" class="block text-xs font-semibold text-[var(--text-secondary)]">
									Email
								</label>
								<input
									id="cv-email"
									type="email"
									spellcheck="false"
									autocomplete="email"
									bind:value={draftEmail}
									class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
								/>
							</div>

							<div>
								<label for="cv-phone" class="block text-xs font-semibold text-[var(--text-secondary)]">
									Nomor Telepon
								</label>
								<input
									id="cv-phone"
									type="tel"
									autocomplete="tel"
									bind:value={draftPhone}
									placeholder="+62 812..."
									class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
								/>
							</div>

							<div>
								<label for="cv-location" class="block text-xs font-semibold text-[var(--text-secondary)]">
									Domisili
								</label>
								<input
									id="cv-location"
									type="text"
									bind:value={draftLocation}
									placeholder="Jakarta, Indonesia"
									class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
								/>
							</div>

							<div>
								<label for="cv-linkedin" class="block text-xs font-semibold text-[var(--text-secondary)]">
									LinkedIn / Portofolio
								</label>
								<input
									id="cv-linkedin"
									type="text"
									spellcheck="false"
									bind:value={draftLinkedin}
									placeholder="linkedin.com/in/nama"
									class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
								/>
							</div>
						</div>

						<div class="mt-4">
							<label for="cv-summary" class="block text-xs font-semibold text-[var(--text-secondary)]">
								Professional Summary
							</label>
							<textarea
								id="cv-summary"
								rows="4"
								bind:value={draftSummary}
								placeholder="Tuliskan ringkasan 3 sampai 4 kalimat mengenai fokus keahlian, lama pengalaman nyata, dan kontribusi terukur Anda."
								class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-3 text-xs leading-relaxed text-[var(--text-primary)]"
							></textarea>
						</div>
					</section>

					<!-- Work Experience -->
					<section
						aria-labelledby="exp-heading"
						class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-4"
					>
						<div class="flex items-center justify-between">
							<div>
								<h2 id="exp-heading" class="text-sm font-bold text-[var(--text-primary)]">
									Pengalaman Kerja & Organisasi
								</h2>
								<p class="text-xs text-[var(--text-muted)]">
									Cantumkan hanya peran dan capaian yang benar-benar pernah Anda jalani.
								</p>
							</div>
							<button
								type="button"
								onclick={() => addExperience()}
								class="inline-flex items-center gap-1.5 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-1.5 text-xs font-semibold text-[var(--text-primary)] hover:bg-[var(--bg-elevated)] cursor-pointer"
							>
								<Icon name="plus" class="h-3.5 w-3.5" />
								<span>Tambah Pengalaman</span>
							</button>
						</div>

						{#if draftExperiences.length === 0}
							<p class="text-xs text-[var(--text-muted)]">
								Belum ada entri pengalaman kerja. Klik tombol Tambah Pengalaman untuk mengisi.
							</p>
						{:else}
							<div class="space-y-4">
								{#each draftExperiences as exp, idx (idx)}
									<div class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-4 space-y-3">
										<div class="flex items-center justify-between">
											<span class="text-xs font-bold text-[var(--text-primary)]">
												Pengalaman #{idx + 1}
											</span>
											<button
												type="button"
												onclick={() => removeExperience(idx)}
												class="text-xs font-semibold text-[var(--danger-text)] cursor-pointer"
											>
												Hapus Entri
											</button>
										</div>

										<div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
											<div>
												<label
													for="exp-role-{idx}"
													class="block text-[11px] font-semibold text-[var(--text-secondary)]"
												>
													Jabatan / Posisi *
												</label>
												<input
													id="exp-role-{idx}"
													type="text"
													bind:value={exp.role}
													placeholder="Backend Developer"
													class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
												/>
											</div>
											<div>
												<label
													for="exp-company-{idx}"
													class="block text-[11px] font-semibold text-[var(--text-secondary)]"
												>
													Perusahaan / Instansi
												</label>
												<input
													id="exp-company-{idx}"
													type="text"
													bind:value={exp.company}
													placeholder="PT Contoh Teknologi"
													class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
												/>
											</div>
											<div>
												<label
													for="exp-start-{idx}"
													class="block text-[11px] font-semibold text-[var(--text-secondary)]"
												>
													Mulai
												</label>
												<input
													id="exp-start-{idx}"
													type="text"
													bind:value={exp.start_date}
													placeholder="Jan 2024"
													class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
												/>
											</div>
											<div>
												<label
													for="exp-end-{idx}"
													class="block text-[11px] font-semibold text-[var(--text-secondary)]"
												>
													Selesai
												</label>
												<input
													id="exp-end-{idx}"
													type="text"
													bind:value={exp.end_date}
													placeholder="Sekarang"
													class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
												/>
											</div>
										</div>

										<div class="space-y-2">
											<div class="flex items-center justify-between">
												<span class="text-[11px] font-semibold text-[var(--text-secondary)]">
													Poin Kontribusi & Pencapaian Terukur
												</span>
												<button
													type="button"
													onclick={() => addHighlight(idx)}
													class="text-[11px] font-semibold text-[var(--accent-link)] cursor-pointer"
												>
													+ Tambah Poin
												</button>
											</div>
											{#each exp.highlights as _, hlIdx (hlIdx)}
												<div class="flex items-center gap-2">
													<label for="exp-{idx}-hl-{hlIdx}" class="visually-hidden">
														Poin pencapaian {hlIdx + 1}
													</label>
													<input
														id="exp-{idx}-hl-{hlIdx}"
														type="text"
														bind:value={exp.highlights[hlIdx]}
														placeholder="Misal: Memangkas waktu respons endpoint laporan sebesar 35% melalui pengindeksan PostgreSQL."
														class="flex-1 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
													/>
													{#if exp.highlights.length > 1}
														<button
															type="button"
															onclick={() => removeHighlight(idx, hlIdx)}
															aria-label="Hapus poin pencapaian"
															class="rounded-[6px] px-2 py-1 text-xs text-[var(--text-muted)] hover:text-[var(--danger-text)] cursor-pointer"
														>
															Hapus
														</button>
													{/if}
												</div>
											{/each}
										</div>
									</div>
								{/each}
							</div>
						{/if}
					</section>

					<!-- Education & Skills & Certifications in 2 Asymmetric Columns -->
					<div class="grid grid-cols-1 gap-5 lg:grid-cols-12">
						<!-- Education (7 cols) -->
						<section
							aria-labelledby="edu-heading"
							class="lg:col-span-7 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-4"
						>
							<div class="flex items-center justify-between">
								<h2 id="edu-heading" class="text-sm font-bold text-[var(--text-primary)]">
									Pendidikan
								</h2>
								<button
									type="button"
									onclick={() => addEducation()}
									class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-1.5 text-xs font-semibold text-[var(--text-primary)] cursor-pointer"
								>
									+ Tambah Pendidikan
								</button>
							</div>

							{#if draftEducations.length === 0}
								<p class="text-xs text-[var(--text-muted)]">Belum ada riwayat pendidikan.</p>
							{:else}
								<div class="space-y-3">
									{#each draftEducations as edu, idx (idx)}
										<div class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-3.5 space-y-2.5">
											<div class="flex items-center justify-between">
												<span class="text-xs font-bold text-[var(--text-primary)]">
													Pendidikan #{idx + 1}
												</span>
												<button
													type="button"
													onclick={() => removeEducation(idx)}
													class="text-xs text-[var(--danger-text)] cursor-pointer"
												>
													Hapus
												</button>
											</div>
											<div class="grid grid-cols-1 gap-2.5 sm:grid-cols-3">
												<div class="sm:col-span-2">
													<label for="edu-deg-{idx}" class="block text-[11px] font-semibold text-[var(--text-secondary)]">
														Gelar / Jurusan *
													</label>
													<input
														id="edu-deg-{idx}"
														type="text"
														bind:value={edu.degree}
														placeholder="S1 Teknik Informatika"
														class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
													/>
												</div>
												<div>
													<label for="edu-yr-{idx}" class="block text-[11px] font-semibold text-[var(--text-secondary)]">
														Tahun
													</label>
													<input
														id="edu-yr-{idx}"
														type="text"
														bind:value={edu.year}
														placeholder="2020 - 2024"
														class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
													/>
												</div>
											</div>
											<div>
												<label for="edu-inst-{idx}" class="block text-[11px] font-semibold text-[var(--text-secondary)]">
													Institusi / Kampus
												</label>
												<input
													id="edu-inst-{idx}"
													type="text"
													bind:value={edu.institution}
													placeholder="Universitas Indonesia"
													class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
												/>
											</div>
										</div>
									{/each}
								</div>
							{/if}
						</section>

						<!-- Skills & Certifications (5 cols) -->
						<div class="lg:col-span-5 space-y-5">
							<section
								aria-labelledby="skills-heading"
								class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-3"
							>
								<h2 id="skills-heading" class="text-sm font-bold text-[var(--text-primary)]">
									Keahlian (Skills)
								</h2>
								<div class="flex gap-2">
									<label for="skill-add-input" class="visually-hidden">Tambah keahlian</label>
									<input
										id="skill-add-input"
										type="text"
										bind:value={skillInput}
										onkeydown={(e) => {
											if (e.key === 'Enter') {
												e.preventDefault();
												addSkillFromInput();
											}
										}}
										placeholder="Misal: Laravel, PostgreSQL, TypeScript"
										class="flex-1 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-1.5 text-xs text-[var(--text-primary)]"
									/>
									<button
										type="button"
										onclick={() => addSkillFromInput()}
										class="rounded-[10px] bg-[var(--accent-blue)] px-3 py-1.5 text-xs font-semibold text-white cursor-pointer"
									>
										Tambah
									</button>
								</div>

								{#if draftSkills.length > 0}
									<div class="flex flex-wrap gap-1.5 pt-1">
										{#each draftSkills as sk (sk)}
											<span
												class="inline-flex items-center gap-1 rounded-[6px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-2 py-1 text-xs text-[var(--text-primary)]"
											>
												<span>{sk}</span>
												<button
													type="button"
													onclick={() => removeSkill(sk)}
													aria-label="Hapus keahlian {sk}"
													class="text-[var(--text-muted)] hover:text-[var(--danger-text)] cursor-pointer"
												>
													×
												</button>
											</span>
										{/each}
									</div>
								{:else}
									<p class="text-xs text-[var(--text-muted)]">
										Tambahkan minimal 5 keahlian relevan untuk membantu pencocokan kata kunci ATS.
									</p>
								{/if}
							</section>

							<section
								aria-labelledby="cert-heading"
								class="rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-3"
							>
								<div class="flex items-center justify-between">
									<h2 id="cert-heading" class="text-sm font-bold text-[var(--text-primary)]">
										Sertifikasi Resmi
									</h2>
									<button
										type="button"
										onclick={() => addCertification()}
										class="text-xs font-semibold text-[var(--accent-link)] cursor-pointer"
									>
										+ Tambah
									</button>
								</div>

								{#if draftCertifications.length === 0}
									<p class="text-xs text-[var(--text-muted)]">Opsional. Isi jika memiliki sertifikat resmi.</p>
								{:else}
									<div class="space-y-2.5">
										{#each draftCertifications as cert, idx (idx)}
											<div class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-3 space-y-2">
												<div class="flex justify-between">
													<label for="cert-name-{idx}" class="text-[11px] font-semibold text-[var(--text-secondary)]">
														Nama Sertifikasi
													</label>
													<button
														type="button"
														onclick={() => removeCertification(idx)}
														class="text-[11px] text-[var(--danger-text)] cursor-pointer"
													>
														Hapus
													</button>
												</div>
												<input
													id="cert-name-{idx}"
													type="text"
													bind:value={cert.name}
													placeholder="AWS Certified Developer"
													class="w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
												/>
												<div class="grid grid-cols-2 gap-2">
													<input
														type="text"
														aria-label="Penerbit sertifikat {idx + 1}"
														bind:value={cert.issuer}
														placeholder="Penerbit"
														class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
													/>
													<input
														type="text"
														aria-label="Tahun sertifikat {idx + 1}"
														bind:value={cert.year}
														placeholder="Tahun"
														class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1.5 text-xs text-[var(--text-primary)]"
													/>
												</div>
											</div>
										{/each}
									</div>
								{/if}
							</section>
						</div>
					</div>

					<div class="flex justify-end">
						<button
							type="button"
							disabled={saving}
							onclick={() => saveActiveCv(false)}
							class="rounded-[10px] bg-[var(--accent-blue)] px-5 py-2.5 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
						>
							{saving ? 'Menyimpan...' : 'Simpan Seluruh Perubahan CV'}
						</button>
					</div>
				</div>

			<!-- TAB 2: ATS AUDIT & AI REVIEW -->
			{:else if activeTab === 'ats'}
				<div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-12">
					<!-- Structural ATS Score Breakdown (6 cols) -->
					<section
						aria-labelledby="ats-score-heading"
						class="lg:col-span-6 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-4"
					>
						<div class="flex items-center justify-between">
							<div>
								<h2 id="ats-score-heading" class="text-sm font-bold text-[var(--text-primary)]">
									Evaluasi Struktur & Keterbacaan ATS
								</h2>
								<p class="text-xs text-[var(--text-muted)]">
									Tingkat keterbacaan: {activeCv.ats_evaluation?.readability_level ?? 'Belum dihitung'}
								</p>
							</div>
							<div class="text-end">
								<span class="font-mono text-2xl font-bold text-[var(--text-primary)]">
									{activeCv.ats_score ?? 0}
								</span>
								<span class="text-xs text-[var(--text-muted)]">/100</span>
							</div>
						</div>

						{#if activeCv.ats_evaluation?.breakdown}
							<div class="space-y-3 pt-2">
								{#each Object.entries(activeCv.ats_evaluation.breakdown) as [key, item] (key)}
									<div>
										<div class="flex justify-between text-xs">
											<span class="font-medium text-[var(--text-secondary)]">{item.label}</span>
											<span class="font-mono font-semibold text-[var(--text-primary)]">
												{item.score}/{item.max}
											</span>
										</div>
										<div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-[var(--bg-elevated)]">
											<div
												class="h-full rounded-full bg-[var(--accent-blue)]"
												style="width: {Math.min(100, Math.round((item.score / Math.max(1, item.max)) * 100))}%"
											></div>
										</div>
									</div>
								{/each}
							</div>
						{/if}

						{#if activeCv.ats_evaluation?.strengths?.length}
							<div class="rounded-[10px] bg-[var(--success-bg)] p-3.5 text-xs text-[var(--success-text)]">
								<p class="font-bold">Kekuatan Struktur Saat Ini:</p>
								<ul class="mt-1.5 list-inside list-disc space-y-1">
									{#each activeCv.ats_evaluation.strengths as st (st)}
										<li>{st}</li>
									{/each}
								</ul>
							</div>
						{/if}

						{#if activeCv.ats_evaluation?.suggestions?.length}
							<div class="rounded-[10px] bg-[var(--warn-bg)] p-3.5 text-xs text-[var(--warn-text)]">
								<p class="font-bold">Saran Perbaikan Prioritas:</p>
								<ul class="mt-1.5 list-inside list-disc space-y-1">
									{#each activeCv.ats_evaluation.suggestions as sg (sg)}
										<li>{sg}</li>
									{/each}
								</ul>
							</div>
						{/if}
					</section>

					<!-- AI Deep Review & Summary Suggestion (6 cols) -->
					<section
						aria-labelledby="ai-cv-heading"
						class="lg:col-span-6 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-4"
					>
						<div class="flex flex-wrap items-center justify-between gap-2">
							<div>
								<h2 id="ai-cv-heading" class="text-sm font-bold text-[var(--text-primary)]">
									Bedah Kalimat & Professional Summary AI
								</h2>
								<p class="text-xs text-[var(--text-muted)]">
									Menajamkan redaksi poin kerja tanpa menambahkan klaim palsu.
								</p>
							</div>
							<button
								type="button"
								disabled={runningAiReview}
								onclick={() => runAiCvReview()}
								class="rounded-[10px] bg-[var(--accent-blue)] px-3.5 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] disabled:opacity-50 cursor-pointer"
							>
								{runningAiReview ? 'Menganalisis...' : 'Jalankan Evaluasi AI'}
							</button>
						</div>

						{#if runningAiReview}
							<progress class="w-44" aria-label="Menjalankan evaluasi CV dengan AI"></progress>
						{/if}

						{#if activeCv.ats_evaluation?.ai_review}
							{@const rev = activeCv.ats_evaluation.ai_review}
							<div class="space-y-4">
								{#if rev.professional_summary}
									<div class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-3.5 space-y-2">
										<div class="flex items-center justify-between">
											<span class="text-xs font-bold text-[var(--text-primary)]">
												Usulan Professional Summary
											</span>
											<button
												type="button"
												onclick={() => {
													draftSummary = rev.professional_summary;
													void saveActiveCv(false);
												}}
												class="text-xs font-semibold text-[var(--accent-link)] cursor-pointer"
											>
												Gunakan ke CV
											</button>
										</div>
										<p class="text-xs leading-relaxed text-[var(--text-secondary)]">
											{rev.professional_summary}
										</p>
									</div>
								{/if}

								{#if rev.executive_feedback}
									<div class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-3.5">
										<p class="text-xs font-bold text-[var(--text-primary)]">Catatan Evaluator:</p>
										<p class="mt-1 text-xs leading-relaxed text-[var(--text-secondary)]">
											{rev.executive_feedback}
										</p>
									</div>
								{/if}

								{#if rev.bullet_improvements?.length}
									<div class="space-y-2">
										<p class="text-xs font-bold text-[var(--text-primary)]">
											Contoh Penajaman Kalimat Pengalaman:
										</p>
										{#each rev.bullet_improvements as bi, i (i)}
											<div class="rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-3 text-xs space-y-1">
												<p class="text-[var(--text-muted)]">
													<strong>Awal:</strong> {bi.original}
												</p>
												<p class="text-[var(--text-primary)]">
													<strong>Saran:</strong> {bi.improved}
												</p>
											</div>
										{/each}
									</div>
								{/if}
							</div>
						{:else}
							<p class="text-xs leading-relaxed text-[var(--text-muted)]">
								Klik tombol Jalankan Evaluasi AI untuk memperoleh draf Professional Summary dan
								perbaikan kalimat pencapaian berdasarkan data CV Anda saat ini.
							</p>
						{/if}
					</section>
				</div>

			<!-- TAB 3: JOB TAILORING & COVER LETTER -->
			{:else if activeTab === 'tailor'}
				<div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-12">
					<section
						aria-labelledby="jd-heading"
						class="lg:col-span-6 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-4"
					>
						<h2 id="jd-heading" class="text-sm font-bold text-[var(--text-primary)]">
							Sesuaikan CV dengan Deskripsi Lowongan
						</h2>
						<div>
							<label for="jd-textarea" class="block text-xs font-semibold text-[var(--text-secondary)]">
								Deskripsi Lowongan Kerja Target
							</label>
							<textarea
								id="jd-textarea"
								rows="7"
								bind:value={draftJobDescription}
								placeholder="Tempelkan kualifikasi, tanggung jawab, dan daftar teknologi dari pengumuman lowongan kerja yang Anda tuju..."
								class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-3 text-xs leading-relaxed text-[var(--text-primary)]"
							></textarea>
						</div>

						<button
							type="button"
							disabled={runningTailor}
							onclick={() => runTailorToJob()}
							class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] disabled:opacity-50 cursor-pointer"
						>
							{runningTailor ? 'Mencocokkan Kualifikasi...' : 'Analisis Kecocokan CV'}
						</button>

						{#if runningTailor}
							<progress class="w-44" aria-label="Menganalisis kecocokan CV dengan lowongan"></progress>
						{/if}

						{#if tailoringResult}
							<div class="space-y-3 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-4 text-xs">
								{#if tailoringResult.match_score_estimate !== undefined}
									<p class="font-bold text-[var(--text-primary)]">
										Estimasi Kecocokan Profil: {tailoringResult.match_score_estimate}%
									</p>
								{/if}

								{#if tailoringResult.matched_keywords?.length}
									<div>
										<p class="font-semibold text-[var(--success-text)]">Kata Kunci Terpenuhi:</p>
										<div class="mt-1 flex flex-wrap gap-1.5">
											{#each tailoringResult.matched_keywords as kw (kw)}
												<span class="rounded-[6px] bg-[var(--success-bg)] px-2 py-0.5 text-[11px] text-[var(--success-text)]">
													{kw}
												</span>
											{/each}
										</div>
									</div>
								{/if}

								{#if tailoringResult.missing_keywords_to_verify?.length}
									<div>
										<p class="font-semibold text-[var(--warn-text)]">
											Kata Kunci Lowongan yang Belum Tercantum (tambahkan hanya jika Anda benar-benar menguasainya):
										</p>
										<div class="mt-1 flex flex-wrap gap-1.5">
											{#each tailoringResult.missing_keywords_to_verify as mkw (mkw)}
												<span class="rounded-[6px] bg-[var(--warn-bg)] px-2 py-0.5 text-[11px] text-[var(--warn-text)]">
													{mkw}
												</span>
											{/each}
										</div>
									</div>
								{/if}

								{#if tailoringResult.tailored_summary}
									<div class="pt-1">
										<div class="flex items-center justify-between">
											<span class="font-semibold text-[var(--text-primary)]">
												Ringkasan yang Disesuaikan:
											</span>
											<button
												type="button"
												onclick={() => {
													if (tailoringResult?.tailored_summary) {
														draftSummary = tailoringResult.tailored_summary;
														void saveActiveCv(false);
													}
												}}
												class="font-semibold text-[var(--accent-link)] cursor-pointer"
											>
												Terapkan ke CV
											</button>
										</div>
										<p class="mt-1 leading-relaxed text-[var(--text-secondary)]">
											{tailoringResult.tailored_summary}
										</p>
									</div>
								{/if}

								{#if tailoringResult.honest_gap_notes}
									<p class="text-[11px] text-[var(--text-muted)]">
										Catatan Objektif: {tailoringResult.honest_gap_notes}
									</p>
								{/if}
							</div>
						{/if}
					</section>

					<!-- Cover Letter Generator (6 cols) -->
					<section
						aria-labelledby="cl-heading"
						class="lg:col-span-6 rounded-[14px] border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 space-y-4"
					>
						<h2 id="cl-heading" class="text-sm font-bold text-[var(--text-primary)]">
							Pembuat Cover Letter
						</h2>

						<div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
							<div class="sm:col-span-2">
								<label for="cl-company" class="block text-xs font-semibold text-[var(--text-secondary)]">
									Nama Perusahaan Tujuan
								</label>
								<input
									id="cl-company"
									type="text"
									bind:value={draftCompanyName}
									placeholder="Misal: PT Reka Digital Nusantara"
									class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
								/>
							</div>
							<div>
								<label for="cl-lang" class="block text-xs font-semibold text-[var(--text-secondary)]">
									Bahasa Surat
								</label>
								<select
									id="cl-lang"
									bind:value={draftCoverLanguage}
									class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3 py-2 text-xs text-[var(--text-primary)]"
								>
									<option value="id">Bahasa Indonesia</option>
									<option value="en">English</option>
								</select>
							</div>
						</div>

						<div class="flex flex-wrap gap-2">
							<button
								type="button"
								disabled={runningCoverLetter}
								onclick={() => runGenerateCoverLetter()}
								class="rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] disabled:opacity-50 cursor-pointer"
							>
								{runningCoverLetter ? 'Menyusun Surat...' : 'Buat Cover Letter'}
							</button>

							{#if draftCoverLetter.trim()}
								<button
									type="button"
									onclick={() => copyCoverLetter()}
									class="inline-flex items-center gap-1.5 rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] px-3.5 py-2 text-xs font-semibold text-[var(--text-primary)] cursor-pointer"
								>
									<Icon name="copy" class="h-3.5 w-3.5" />
									<span>Salin Surat</span>
								</button>
							{/if}
						</div>

						{#if runningCoverLetter}
							<progress class="w-44" aria-label="Menulis cover letter"></progress>
						{/if}

						<div>
							<label for="cl-editor" class="block text-xs font-semibold text-[var(--text-secondary)]">
								Naskah Cover Letter (Dapat Diedit)
							</label>
							<textarea
								id="cl-editor"
								rows="11"
								bind:value={draftCoverLetter}
								placeholder="Naskah surat lamaran kerja berdasarkan pengalaman nyata Anda akan muncul di sini dan dapat Anda sunting sebelum disimpan."
								class="mt-1 w-full rounded-[10px] border border-[var(--border-subtle)] bg-[var(--bg-canvas)] p-3 text-xs leading-relaxed text-[var(--text-primary)]"
							></textarea>
						</div>
					</section>
				</div>

			<!-- TAB 4: LIVE PRINT PREVIEW -->
			{:else if activeTab === 'preview'}
				<div class="mt-5 space-y-4">
					<div class="flex flex-wrap items-center justify-between gap-3">
						<div class="flex flex-wrap gap-2" role="group" aria-label="Ganti template pratinjau">
							{#each templateOptions as tpl (tpl.id)}
								<button
									type="button"
									onclick={() => (draftTemplate = tpl.id)}
									aria-pressed={draftTemplate === tpl.id}
									class="rounded-[10px] px-3 py-1.5 text-xs font-semibold cursor-pointer {draftTemplate ===
									tpl.id
										? 'bg-[var(--accent-blue)] text-white'
										: 'border border-[var(--border-subtle)] bg-[var(--bg-surface)] text-[var(--text-secondary)]'}"
								>
									{tpl.label}
								</button>
							{/each}
						</div>

						<button
							type="button"
							disabled={exportingPdf}
							onclick={() => handleExportPdf()}
							class="inline-flex items-center gap-2 rounded-[10px] bg-[var(--accent-blue)] px-4 py-2 text-xs font-semibold text-white hover:bg-[var(--accent-blue-hover)] cursor-pointer"
						>
							<Icon name="download" class="h-4 w-4" />
							<span>{exportingPdf ? 'Menyiapkan PDF...' : 'Unduh PDF Sekarang'}</span>
						</button>
					</div>

					<!-- Paper Sheet Preview -->
					<article
						aria-label="Pratinjau dokumen CV"
						class="mx-auto max-w-3xl rounded-[14px] border border-[#cbd5e1] bg-white p-6 text-[#0f172a] shadow-sm sm:p-10"
					>
						<header
							class={draftTemplate === 'executive'
								? 'rounded-[10px] bg-[#0f172a] p-6 text-white'
								: draftTemplate === 'ats_clean'
									? 'border-b-2 border-[#0f172a] pb-4 text-center'
									: 'border-s-4 border-[#1d4ed8] ps-4 pb-2'}
						>
							<h2 class="text-2xl font-bold tracking-tight">
								{draftFullName || 'Nama Lengkap'}
							</h2>
							{#if draftHeadline}
								<p
									class="mt-1 text-sm font-medium {draftTemplate === 'executive'
										? 'text-[#93c5fd]'
										: 'text-[#1d4ed8]'}"
								>
									{draftHeadline}
								</p>
							{/if}
							<p
								class="mt-2 text-xs {draftTemplate === 'executive'
									? 'text-[#cbd5e1]'
									: 'text-[#475569]'}"
							>
								{[draftEmail, draftPhone, draftLocation, draftLinkedin, draftWebsite]
									.filter(Boolean)
									.join('  |  ') || 'Email | Telepon | Domisili'}
							</p>
						</header>

						{#if draftSummary}
							<section class="mt-6">
								<h3 class="border-b border-[#cbd5e1] pb-1 text-xs font-bold tracking-wider uppercase text-[#1e293b]">
									Ringkasan Profesional
								</h3>
								<p class="mt-2 text-xs leading-relaxed text-[#334155]">{draftSummary}</p>
							</section>
						{/if}

						{#if draftExperiences.length > 0}
							<section class="mt-6">
								<h3 class="border-b border-[#cbd5e1] pb-1 text-xs font-bold tracking-wider uppercase text-[#1e293b]">
									Pengalaman Kerja
								</h3>
								<div class="mt-3 space-y-4">
									{#each draftExperiences as exp, i (i)}
										<div>
											<div class="flex flex-wrap justify-between gap-1">
												<p class="text-xs font-bold text-[#0f172a]">
													{exp.role || 'Jabatan'}
													{exp.company ? ` · ${exp.company}` : ''}
												</p>
												<span class="text-xs text-[#475569]">
													{[exp.start_date, exp.end_date].filter(Boolean).join(' - ')}
												</span>
											</div>
											{#if exp.highlights.filter(Boolean).length > 0}
												<ul class="mt-1.5 list-inside list-disc space-y-1 text-xs text-[#334155]">
													{#each exp.highlights.filter(Boolean) as hl, j (j)}
														<li>{hl}</li>
													{/each}
												</ul>
											{/if}
										</div>
									{/each}
								</div>
							</section>
						{/if}

						{#if draftEducations.length > 0}
							<section class="mt-6">
								<h3 class="border-b border-[#cbd5e1] pb-1 text-xs font-bold tracking-wider uppercase text-[#1e293b]">
									Pendidikan
								</h3>
								<div class="mt-2.5 space-y-2.5">
									{#each draftEducations as edu, i (i)}
										<div class="flex flex-wrap justify-between text-xs">
											<div>
												<p class="font-bold text-[#0f172a]">{edu.degree}</p>
												<p class="text-[#334155]">{edu.institution}</p>
											</div>
											<span class="text-[#475569]">{edu.year}</span>
										</div>
									{/each}
								</div>
							</section>
						{/if}

						{#if draftSkills.length > 0}
							<section class="mt-6">
								<h3 class="border-b border-[#cbd5e1] pb-1 text-xs font-bold tracking-wider uppercase text-[#1e293b]">
									Keahlian Utama
								</h3>
								<p class="mt-2 text-xs leading-relaxed text-[#334155]">
									{draftSkills.join(' · ')}
								</p>
							</section>
						{/if}

						{#if draftCertifications.length > 0}
							<section class="mt-6">
								<h3 class="border-b border-[#cbd5e1] pb-1 text-xs font-bold tracking-wider uppercase text-[#1e293b]">
									Sertifikasi
								</h3>
								<ul class="mt-2 list-inside list-disc space-y-1 text-xs text-[#334155]">
									{#each draftCertifications as cert, i (i)}
										<li>
											<strong>{cert.name}</strong>
											{cert.issuer ? ` (${cert.issuer})` : ''}
											{cert.year ? ` · ${cert.year}` : ''}
										</li>
									{/each}
								</ul>
							</section>
						{/if}
					</article>
				</div>
			{/if}
		{/if}
	</div>
</div>

<ModalDialog
	bind:open={deleteModalOpen}
	title="Hapus Profil CV"
	onClose={() => (deleteModalOpen = false)}
>
	<div class="space-y-4">
		<p class="text-xs leading-relaxed text-[var(--text-secondary)]">
			Hapus profil CV <strong class="text-[var(--text-primary)]">{activeCv?.title ?? ''}</strong> secara
			permanen?
		</p>
		<div class="flex justify-end gap-2.5">
			<button
				type="button"
				onclick={() => (deleteModalOpen = false)}
				class="rounded-[10px] border border-[var(--border-subtle)] px-3.5 py-2 text-xs font-semibold text-[var(--text-primary)] cursor-pointer"
			>
				Batal
			</button>
			<button
				type="button"
				disabled={deletingCv}
				onclick={() => confirmDeleteActiveCv()}
				class="rounded-[10px] bg-[var(--danger-bg)] px-3.5 py-2 text-xs font-semibold text-[var(--danger-text)] cursor-pointer"
			>
				{deletingCv ? 'Menghapus...' : 'Hapus CV'}
			</button>
		</div>
	</div>
</ModalDialog>

