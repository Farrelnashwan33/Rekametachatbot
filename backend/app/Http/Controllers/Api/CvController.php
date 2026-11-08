<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AIProviderException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentChunksJob;
use App\Models\CvProfile;
use App\Models\Document;
use App\Models\User;
use App\Services\Cv\CvIntelligenceService;
use App\Services\Cv\CvPdfExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CvController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cvs = CvProfile::where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(['cvs' => $cvs]);
    }

    public function store(Request $request, CvIntelligenceService $cvService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $this->validateCvPayload($request);

        $cv = new CvProfile(array_merge([
            'user_id' => $user->id,
            'title' => 'CV Profesional',
            'template' => 'executive',
        ], $validated));
        $cv->save();

        $evaluation = $cvService->evaluateAtsAndStructure($cv);
        $cv->update([
            'ats_score' => $evaluation['score'],
            'ats_evaluation' => $evaluation,
        ]);

        return response()->json([
            'message' => 'Profil CV berhasil dibuat.',
            'cv' => $cv->fresh(),
        ], 201);
    }

    public function importFromFile(Request $request, CvIntelligenceService $cvService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $maxKb = (int) config('rekametachat.max_document_upload_kb', 10240);
        $request->validate([
            'file' => ['required', 'file', "max:{$maxKb}", 'mimes:pdf,docx'],
        ]);

        $uploaded = $request->file('file');
        $extension = strtolower($uploaded->getClientOriginalExtension() ?: '');
        $diskName = (string) config('filesystems.document_disk', 'local');
        if ($diskName === 'r2' && empty(config('filesystems.disks.r2.key'))) {
            $diskName = 'local';
        }

        $uuid = (string) Str::uuid();
        $storagePath = "documents/{$user->id}/{$uuid}.{$extension}";
        $rawBytes = file_get_contents($uploaded->getRealPath());
        if ($rawBytes === false) {
            return response()->json(['message' => 'Gagal membaca file CV.'], 422);
        }

        Storage::disk($diskName)->put($storagePath, $rawBytes);

        $originalName = $uploaded->getClientOriginalName();
        $baseTitle = pathinfo($originalName, PATHINFO_FILENAME) ?: 'CV Impor';

        $document = Document::create([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'title' => 'CV: '.mb_substr($baseTitle, 0, 160),
            'original_filename' => mb_substr($originalName, 0, 250),
            'mime_type' => $uploaded->getMimeType() ?: 'application/octet-stream',
            'extension' => $extension,
            'disk' => $diskName,
            'storage_path' => $storagePath,
            'file_size' => $uploaded->getSize() ?: strlen($rawBytes),
            'file_hash' => hash('sha256', $rawBytes),
            'status' => 'processing',
        ]);

        ProcessDocumentChunksJob::dispatchSync($document->id);
        $document->refresh();

        if ($document->status !== 'ready' || empty($document->extracted_text)) {
            return response()->json([
                'message' => $document->error_message ?: 'Gagal mengekstrak teks dari file CV tersebut.',
            ], 422);
        }

        $extractedFields = $cvService->extractStructuredCvFromText($document->extracted_text, 'CV '.mb_substr($baseTitle, 0, 120));
        $cv = CvProfile::create(array_merge($extractedFields, [
            'user_id' => $user->id,
            'source_document_id' => $document->id,
            'template' => 'executive',
        ]));

        $evaluation = $cvService->evaluateAtsAndStructure($cv);
        $cv->update([
            'ats_score' => $evaluation['score'],
            'ats_evaluation' => $evaluation,
        ]);

        return response()->json([
            'message' => 'File CV berhasil dibaca dan dievaluasi.',
            'cv' => $cv->fresh(),
        ], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cv = CvProfile::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        return response()->json(['cv' => $cv]);
    }

    public function update(Request $request, string $uuid, CvIntelligenceService $cvService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cv = CvProfile::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $this->validateCvPayload($request, true);
        $cv->fill($validated);
        $cv->save();

        $evaluation = $cvService->evaluateAtsAndStructure($cv);
        $existingAiFeedback = $cv->ats_evaluation['ai_review'] ?? null;
        if ($existingAiFeedback) {
            $evaluation['ai_review'] = $existingAiFeedback;
        }

        $cv->update([
            'ats_score' => $evaluation['score'],
            'ats_evaluation' => $evaluation,
        ]);

        return response()->json([
            'message' => 'CV berhasil disimpan dan skor ATS diperbarui.',
            'cv' => $cv->fresh(),
        ]);
    }

    public function aiReview(Request $request, string $uuid, CvIntelligenceService $cvService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cv = CvProfile::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $structural = $cvService->evaluateAtsAndStructure($cv);

        try {
            $aiReview = $cvService->generateAiReviewAndSummary($user, $cv);
        } catch (AIProviderException $e) {
            $cv->update([
                'ats_score' => $structural['score'],
                'ats_evaluation' => $structural,
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'cv' => $cv->fresh(),
            ], $e->httpStatus);
        }

        $structural['ai_review'] = $aiReview;
        $updates = [
            'ats_score' => $structural['score'],
            'ats_evaluation' => $structural,
        ];
        if ($request->boolean('apply_summary', false) && ! empty($aiReview['professional_summary'])) {
            $updates['summary'] = $aiReview['professional_summary'];
        }
        $cv->update($updates);

        return response()->json([
            'message' => 'Evaluasi mendalam CV selesai.',
            'ai_review' => $aiReview,
            'cv' => $cv->fresh(),
        ]);
    }

    public function tailor(Request $request, string $uuid, CvIntelligenceService $cvService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cv = CvProfile::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'job_description' => ['required', 'string', 'min:20', 'max:12000'],
            'apply_summary' => ['sometimes', 'boolean'],
        ]);

        $cv->update(['target_job_description' => trim($validated['job_description'])]);

        try {
            $tailored = $cvService->tailorCvToJob($user, $cv, trim($validated['job_description']));
        } catch (AIProviderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->httpStatus);
        }

        if (! empty($validated['apply_summary']) && ! empty($tailored['tailored_summary'])) {
            $cv->update(['summary' => $tailored['tailored_summary']]);
        }

        return response()->json([
            'message' => 'Analisis penyesuaian lowongan selesai.',
            'tailoring' => $tailored,
            'cv' => $cv->fresh(),
        ]);
    }

    public function coverLetter(Request $request, string $uuid, CvIntelligenceService $cvService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cv = CvProfile::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'job_description' => ['required', 'string', 'min:20', 'max:12000'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'language' => ['sometimes', 'string', Rule::in(['id', 'en'])],
        ]);

        try {
            $generated = $cvService->generateCoverLetter(
                $user,
                $cv,
                trim($validated['job_description']),
                trim((string) ($validated['company_name'] ?? '')),
                (string) ($validated['language'] ?? 'id')
            );
        } catch (AIProviderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ], $e->httpStatus);
        }

        $cv->update([
            'target_job_description' => trim($validated['job_description']),
            'cover_letter' => $generated['cover_letter'],
        ]);

        return response()->json([
            'message' => 'Cover letter berhasil dibuat.',
            'cover_letter' => $generated['cover_letter'],
            'cv' => $cv->fresh(),
        ]);
    }

    public function exportPdf(Request $request, string $uuid, CvPdfExportService $pdfExporter): Response
    {
        /** @var User $user */
        $user = $request->user();

        $cv = CvProfile::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $template = $request->query('template');
        $allowedTemplates = ['executive', 'ats_clean', 'modern_minimal'];
        $chosenTemplate = is_string($template) && in_array($template, $allowedTemplates, true)
            ? $template
            : ($cv->template ?: 'executive');

        $pdfBytes = $pdfExporter->renderPdfBinary($cv, $chosenTemplate);
        $safeSlug = Str::slug($cv->full_name ?: 'cv-rekametachat') ?: 'cv-rekametachat';

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$safeSlug}-{$chosenTemplate}.pdf\"",
            'Content-Length' => (string) strlen($pdfBytes),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cv = CvProfile::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $cv->delete();

        return response()->json([
            'message' => 'CV berhasil dihapus.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCvPayload(Request $request, bool $partial = false): array
    {
        $reqRule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:180'],
            'template' => ['sometimes', 'string', Rule::in(['executive', 'ats_clean', 'modern_minimal'])],
            'full_name' => [$reqRule, 'string', 'min:2', 'max:140'],
            'headline' => ['nullable', 'string', 'max:180'],
            'email' => ['nullable', 'string', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'location' => ['nullable', 'string', 'max:140'],
            'website' => ['nullable', 'string', 'max:250'],
            'linkedin' => ['nullable', 'string', 'max:250'],
            'summary' => ['nullable', 'string', 'max:4000'],
            'experiences' => ['nullable', 'array', 'max:20'],
            'experiences.*.role' => ['required_with:experiences', 'string', 'max:140'],
            'experiences.*.company' => ['nullable', 'string', 'max:140'],
            'experiences.*.location' => ['nullable', 'string', 'max:120'],
            'experiences.*.start_date' => ['nullable', 'string', 'max:40'],
            'experiences.*.end_date' => ['nullable', 'string', 'max:40'],
            'experiences.*.highlights' => ['nullable', 'array', 'max:15'],
            'experiences.*.highlights.*' => ['nullable', 'string', 'max:600'],
            'educations' => ['nullable', 'array', 'max:12'],
            'educations.*.degree' => ['required_with:educations', 'string', 'max:160'],
            'educations.*.institution' => ['nullable', 'string', 'max:160'],
            'educations.*.year' => ['nullable', 'string', 'max:40'],
            'educations.*.notes' => ['nullable', 'string', 'max:400'],
            'skills' => ['nullable', 'array', 'max:50'],
            'skills.*' => ['string', 'max:80'],
            'certifications' => ['nullable', 'array', 'max:20'],
            'certifications.*.name' => ['required_with:certifications', 'string', 'max:180'],
            'certifications.*.issuer' => ['nullable', 'string', 'max:140'],
            'certifications.*.year' => ['nullable', 'string', 'max:30'],
            'projects' => ['nullable', 'array', 'max:15'],
            'languages' => ['nullable', 'array', 'max:12'],
            'target_job_description' => ['nullable', 'string', 'max:12000'],
            'cover_letter' => ['nullable', 'string', 'max:15000'],
        ]);
    }
}

