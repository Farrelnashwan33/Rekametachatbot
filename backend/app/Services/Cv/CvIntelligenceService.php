<?php

namespace App\Services\Cv;

use App\Models\CvProfile;
use App\Models\User;
use App\Services\AI\AIProviderManager;

class CvIntelligenceService
{
    public function __construct(private readonly AIProviderManager $aiManager) {}

    /**
     * Extract structured CV fields strictly from real parsed document text without inventing facts.
     *
     * @return array<string, mixed>
     */
    public function extractStructuredCvFromText(string $rawText, string $fallbackTitle = 'CV Hasil Impor'): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $rawText)), fn ($l) => $l !== '' && ! preg_match('/^\[Halaman\s+\d+\]$/u', $l)));

        $fullName = $lines[0] ?? 'Nama Lengkap';
        if (mb_strlen($fullName) > 80) {
            $fullName = mb_substr($fullName, 0, 80);
        }

        $email = null;
        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $rawText, $m)) {
            $email = $m[0];
        }

        $phone = null;
        if (preg_match('/(\+?\d[\d\s().\-]{7,18}\d)/', $rawText, $m)) {
            $phone = trim($m[1]);
        }

        $linkedin = null;
        if (preg_match('#(https?://(?:www\.)?linkedin\.com/in/[^\s|,;]+|linkedin\.com/in/[^\s|,;]+)#i', $rawText, $m)) {
            $linkedin = $m[1];
        }

        $website = null;
        if (preg_match('#(https?://(?!www\.linkedin\.com)[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}[^\s|,;]*)#i', $rawText, $m)) {
            $website = $m[1];
        }

        $sections = $this->segmentCvSections($lines);

        $skills = [];
        if (! empty($sections['skills'])) {
            $joinedSkills = implode(', ', $sections['skills']);
            $parts = preg_split('/[,•|;\n\/]+/u', $joinedSkills) ?: [];
            foreach ($parts as $part) {
                $clean = trim($part, " \t\n\r-•*:");
                if (mb_strlen($clean) >= 2 && mb_strlen($clean) <= 45) {
                    $skills[] = $clean;
                }
            }
            $skills = array_slice(array_values(array_unique($skills)), 0, 30);
        }

        $experiences = $this->parseExperienceLines($sections['experience'] ?? []);
        $educations = $this->parseEducationLines($sections['education'] ?? []);
        $certifications = $this->parseCertificationLines($sections['certifications'] ?? []);

        $summary = ! empty($sections['summary'])
            ? mb_substr(implode(' ', $sections['summary']), 0, 1200)
            : null;

        return [
            'title' => $fallbackTitle,
            'full_name' => $fullName,
            'headline' => isset($lines[1]) && mb_strlen($lines[1]) <= 90 && ! str_contains($lines[1], '@') ? $lines[1] : null,
            'email' => $email,
            'phone' => $phone,
            'location' => null,
            'linkedin' => $linkedin,
            'website' => $website,
            'summary' => $summary,
            'experiences' => $experiences,
            'educations' => $educations,
            'skills' => $skills,
            'certifications' => $certifications,
            'projects' => [],
            'languages' => [],
        ];
    }

    /**
     * Evaluate ATS compatibility and structural completeness deterministically, grounded in actual CV content.
     *
     * @return array<string, mixed>
     */
    public function evaluateAtsAndStructure(CvProfile $cv): array
    {
        $breakdown = [];
        $suggestions = [];
        $strengths = [];

        // 1. Contact Completeness (15 pts)
        $contactScore = 0;
        if (! empty($cv->full_name)) {
            $contactScore += 4;
        }
        if (! empty($cv->email)) {
            $contactScore += 4;
        } else {
            $suggestions[] = 'Tambahkan alamat email profesional yang aktif agar rekruter dan sistem ATS dapat menghubungi Anda.';
        }
        if (! empty($cv->phone)) {
            $contactScore += 4;
        } else {
            $suggestions[] = 'Cantumkan nomor telepon/WhatsApp aktif dengan kode negara (mis. +62).';
        }
        if (! empty($cv->linkedin) || ! empty($cv->location)) {
            $contactScore += 3;
        } else {
            $suggestions[] = 'Sertakan domisili kota dan tautan profil LinkedIn atau portofolio.';
        }
        $breakdown['contact'] = ['score' => $contactScore, 'max' => 15, 'label' => 'Kelengkapan Kontak'];

        // 2. Professional Summary (15 pts)
        $summaryLen = mb_strlen(trim((string) $cv->summary));
        $summaryScore = 0;
        if ($summaryLen >= 140 && $summaryLen <= 650) {
            $summaryScore = 15;
            $strengths[] = 'Ringkasan profesional memiliki panjang ideal (2 sampai 4 kalimat padat).';
        } elseif ($summaryLen >= 50) {
            $summaryScore = 10;
            $suggestions[] = 'Pertajam ringkasan profesional menjadi 150 sampai 450 karakter yang merangkum spesialisasi inti dan dampak kerja.';
        } else {
            $suggestions[] = 'Tambahkan Professional Summary di bagian atas CV untuk memperjelas posisi target Anda.';
        }
        $breakdown['summary'] = ['score' => $summaryScore, 'max' => 15, 'label' => 'Ringkasan Profesional'];

        // 3. Work Experience & Quantified Impact (35 pts)
        $experiences = is_array($cv->experiences) ? $cv->experiences : [];
        $expScore = 0;
        $metricBullets = 0;
        $totalBullets = 0;

        if (count($experiences) > 0) {
            $expScore += 15;
            foreach ($experiences as $exp) {
                $highlights = is_array($exp['highlights'] ?? null) ? $exp['highlights'] : [];
                foreach ($highlights as $bullet) {
                    $b = trim((string) $bullet);
                    if ($b === '') {
                        continue;
                    }
                    $totalBullets++;
                    if (preg_match('/(\d+%|\d+\+|\bRp\s?\d+|\$\d+|\d+\s*(?:pengguna|klien|proyek|tim|jam|hari|bulan|x\b|kali))/iu', $b)) {
                        $metricBullets++;
                    }
                }
            }

            if ($totalBullets >= 3) {
                $expScore += 10;
            } else {
                $suggestions[] = 'Uraikan minimal 3 poin pencapaian spesifik pada pengalaman kerja menggunakan kata kerja aktif.';
            }

            if ($metricBullets >= 2) {
                $expScore += 10;
                $strengths[] = "Terdapat {$metricBullets} poin pengalaman kerja yang memuat indikator terukur/angka.";
            } elseif ($metricBullets === 1) {
                $expScore += 5;
                $suggestions[] = 'Tambahkan metrik terukur (persentase, skala proyek, atau efisiensi waktu) pada poin pengalaman lainnya berdasarkan data nyata Anda.';
            } else {
                $suggestions[] = 'Sertakan ukuran dampak nyata pada pengalaman kerja (contoh: jumlah pengguna, efisiensi waktu, atau cakupan proyek) tanpa mengarang angka.';
            }
        } else {
            $suggestions[] = 'Tambahkan riwayat pengalaman kerja, magang, organisasi, atau proyek relevan.';
        }
        $breakdown['experience'] = ['score' => $expScore, 'max' => 35, 'label' => 'Pengalaman & Dampak Kerja'];

        // 4. Education (15 pts)
        $educations = is_array($cv->educations) ? $cv->educations : [];
        $eduScore = count($educations) > 0 ? 15 : 0;
        if ($eduScore === 15) {
            $strengths[] = ' Bagian pendidikan terstruktur dengan jelas untuk pembacaan ATS.';
        } else {
            $suggestions[] = 'Lengkapi riwayat pendidikan formal beserta institusi dan tahun studi.';
        }
        $breakdown['education'] = ['score' => $eduScore, 'max' => 15, 'label' => 'Struktur Pendidikan'];

        // 5. Skills & ATS Keywords (20 pts)
        $skills = is_array($cv->skills) ? array_filter($cv->skills, fn ($s) => is_string($s) && trim($s) !== '') : [];
        $skillCount = count($skills);
        $skillScore = 0;
        if ($skillCount >= 6) {
            $skillScore = 20;
            $strengths[] = "Memuat {$skillCount} kata kunci keahlian teknis/fungsional yang mudah dipindai ATS.";
        } elseif ($skillCount >= 3) {
            $skillScore = 12;
            $suggestions[] = 'Tambahkan hingga 6 sampai 12 keahlian spesifik (tools, metode, bahasa) yang benar-benar Anda kuasai.';
        } else {
            $suggestions[] = 'Isi daftar keahlian inti agar pencocokan kata kunci ATS terhadap deskripsi lowongan bekerja optimal.';
        }
        $breakdown['skills'] = ['score' => $skillScore, 'max' => 20, 'label' => 'Kata Kunci Keahlian ATS'];

        $totalScore = (int) array_sum(array_column($breakdown, 'score'));

        $readabilityLevel = match (true) {
            $totalScore >= 80 => 'Sangat Siap ATS',
            $totalScore >= 60 => 'Cukup Baik, Perlu Penajaman',
            default => 'Perlu Melengkapi Struktur Inti',
        };

        return [
            'score' => $totalScore,
            'readability_level' => $readabilityLevel,
            'breakdown' => $breakdown,
            'strengths' => array_values($strengths),
            'suggestions' => array_values($suggestions),
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    public function generateAiReviewAndSummary(User $user, CvProfile $cv): array
    {
        $cvPayload = $this->serializeCvForPrompt($cv);

        $messages = [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Anda adalah spesialis rekrutmen dan analis CV ATS profesional.',
                    'ATURAN MUTLAK: DILARANG KERAS mengarang perusahaan, jabatan, gelar pendidikan, sertifikasi, tahun, atau metrik angka yang TIDAK tertulis di data CV pengguna.',
                    'Kembalikan HANYA objek JSON valid tanpa markdown fence dengan kunci:',
                    '{"professional_summary": "string ringkasan profesional berdasarkan fakta pengguna", "executive_feedback": "string evaluasi menyeluruh", "ats_keyword_tips": ["tip 1", "tip 2"], "bullet_improvements": [{"original": "...", "improved": "..."}]}',
                ]),
            ],
            [
                'role' => 'user',
                'content' => "Evaluasi dan buatkan professional summary yang jujur dari data CV berikut:\n\n".$cvPayload,
            ],
        ];

        $result = $this->aiManager->completeForUser($user, $messages, 'cv_review', options: ['temperature' => 0.4]);
        $decoded = $this->extractJsonObject($result['content']);

        return [
            'professional_summary' => (string) ($decoded['professional_summary'] ?? trim($result['content'])),
            'executive_feedback' => (string) ($decoded['executive_feedback'] ?? ''),
            'ats_keyword_tips' => is_array($decoded['ats_keyword_tips'] ?? null) ? $decoded['ats_keyword_tips'] : [],
            'bullet_improvements' => is_array($decoded['bullet_improvements'] ?? null) ? $decoded['bullet_improvements'] : [],
            'provider_key' => $result['provider_key'],
            'model' => $result['model'],
        ];
    }

    public function tailorCvToJob(User $user, CvProfile $cv, string $jobDescription): array
    {
        $cvPayload = $this->serializeCvForPrompt($cv);

        $messages = [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Anda adalah konsultan karier senior. Tugas Anda menyelaraskan penekanan ringkasan dan poin CV pengguna dengan deskripsi lowongan kerja.',
                    'ATURAN MUTLAK: Jangan pernah menambahkan pengalaman kerja palsu, keahlian yang tidak dimiliki pengguna, sertifikasi fiktif, atau gelar palsu. Jika ada syarat lowongan yang belum ada di CV pengguna, masukkan ke bagian missing_keywords sebagai catatan jujur untuk pengguna.',
                    'Kembalikan HANYA objek JSON valid dengan kunci:',
                    '{"tailored_summary": "string", "matched_keywords": ["..."], "missing_keywords": ["..."], "tailoring_notes": ["..."]}',
                ]),
            ],
            [
                'role' => 'user',
                'content' => "DATA CV PENGGUNA:\n{$cvPayload}\n\nDESKRIPSI LOWONGAN TARGET:\n{$jobDescription}",
            ],
        ];

        $result = $this->aiManager->completeForUser($user, $messages, 'cv_tailor', options: ['temperature' => 0.4]);
        $decoded = $this->extractJsonObject($result['content']);

        return [
            'tailored_summary' => (string) ($decoded['tailored_summary'] ?? trim($result['content'])),
            'matched_keywords' => is_array($decoded['matched_keywords'] ?? null) ? $decoded['matched_keywords'] : [],
            'missing_keywords' => is_array($decoded['missing_keywords'] ?? null) ? $decoded['missing_keywords'] : [],
            'tailoring_notes' => is_array($decoded['tailoring_notes'] ?? null) ? $decoded['tailoring_notes'] : [],
            'provider_key' => $result['provider_key'],
            'model' => $result['model'],
        ];
    }

    public function generateCoverLetter(User $user, CvProfile $cv, string $jobDescription, string $companyName = '', string $language = 'id'): array
    {
        $cvPayload = $this->serializeCvForPrompt($cv);
        $langLabel = $language === 'en' ? 'Bahasa Inggris profesional' : 'Bahasa Indonesia formal dan profesional';

        $messages = [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    "Anda menulis Cover Letter (Surat Lamaran Kerja) dalam {$langLabel}.",
                    'ATURAN MUTLAK: Gunakan HANYA pengalaman, pendidikan, dan keahlian nyata yang tertulis pada data CV pengguna. Jangan pernah mengarang riwayat kerja atau pencapaian fiktif. Hindari karakter em dash.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => "Perusahaan Tujuan: ".($companyName ?: 'Perusahaan Bapak/Ibu')."\n\nDeskripsi Lowongan:\n{$jobDescription}\n\nData CV Pengguna:\n{$cvPayload}",
            ],
        ];

        $result = $this->aiManager->completeForUser($user, $messages, 'cover_letter', options: ['temperature' => 0.5]);

        return [
            'cover_letter' => trim($result['content']),
            'provider_key' => $result['provider_key'],
            'model' => $result['model'],
        ];
    }

    private function serializeCvForPrompt(CvProfile $cv): string
    {
        return json_encode([
            'full_name' => $cv->full_name,
            'headline' => $cv->headline,
            'location' => $cv->location,
            'summary' => $cv->summary,
            'experiences' => $cv->experiences ?? [],
            'educations' => $cv->educations ?? [],
            'skills' => $cv->skills ?? [],
            'certifications' => $cv->certifications ?? [],
            'projects' => $cv->projects ?? [],
            'languages' => $cv->languages ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJsonObject(string $raw): array
    {
        $trimmed = trim($raw);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $trimmed, $m)) {
            $trimmed = $m[1];
        } elseif (preg_match('/(\{.*\})/is', $trimmed, $m)) {
            $trimmed = $m[1];
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<string, array<int, string>>
     */
    private function segmentCvSections(array $lines): array
    {
        $buckets = [
            'summary' => [],
            'experience' => [],
            'education' => [],
            'skills' => [],
            'certifications' => [],
        ];

        $current = 'summary';
        foreach (array_slice($lines, 1) as $line) {
            $lower = mb_strtolower(trim($line, " :-\t#*"));
            if (preg_match('/^(ringkasan|profil|tentang saya|professional summary|summary|profile|about)$/u', $lower)) {
                $current = 'summary';
                continue;
            }
            if (preg_match('/^(pengalaman kerja|pengalaman|riwayat kerja|work experience|experience|employment)$/u', $lower)) {
                $current = 'experience';
                continue;
            }
            if (preg_match('/^(pendidikan|riwayat pendidikan|education|academic background)$/u', $lower)) {
                $current = 'education';
                continue;
            }
            if (preg_match('/^(keahlian|keterampilan|kompetensi|skills|technical skills|core competencies)$/u', $lower)) {
                $current = 'skills';
                continue;
            }
            if (preg_match('/^(sertifikasi|lisensi|pelatihan|certifications|certificates|licenses)$/u', $lower)) {
                $current = 'certifications';
                continue;
            }

            if (str_contains($line, '@') && empty($buckets['summary'])) {
                continue;
            }

            $buckets[$current][] = $line;
        }

        return $buckets;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function parseExperienceLines(array $lines): array
    {
        if (empty($lines)) {
            return [];
        }

        $items = [];
        $current = null;

        foreach ($lines as $line) {
            $isBullet = (bool) preg_match('/^[-•*▪]\s*(.+)$/u', $line, $bm);
            if ($isBullet) {
                if ($current === null) {
                    $current = [
                        'role' => 'Pengalaman Kerja',
                        'company' => '',
                        'location' => '',
                        'start_date' => '',
                        'end_date' => '',
                        'highlights' => [],
                    ];
                }
                $current['highlights'][] = trim($bm[1]);
                continue;
            }

            if ($current !== null && empty($current['company']) && mb_strlen($line) <= 90) {
                $current['company'] = $line;
                continue;
            }

            if ($current !== null) {
                $items[] = $current;
            }

            $current = [
                'role' => mb_substr($line, 0, 100),
                'company' => '',
                'location' => '',
                'start_date' => '',
                'end_date' => '',
                'highlights' => [],
            ];
        }

        if ($current !== null) {
            $items[] = $current;
        }

        return array_slice($items, 0, 12);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function parseEducationLines(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            $clean = trim($line, " -•*\t");
            if ($clean === '') {
                continue;
            }
            $items[] = [
                'degree' => mb_substr($clean, 0, 120),
                'institution' => '',
                'year' => '',
                'notes' => '',
            ];
        }

        return array_slice($items, 0, 6);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function parseCertificationLines(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            $clean = trim($line, " -•*\t");
            if ($clean === '') {
                continue;
            }
            $items[] = [
                'name' => mb_substr($clean, 0, 140),
                'issuer' => '',
                'year' => '',
            ];
        }

        return array_slice($items, 0, 10);
    }
}

