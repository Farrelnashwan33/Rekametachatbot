<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AssistantController extends Controller
{
    public function catalog(): JsonResponse
    {
        return response()->json([
            'assistants' => [
                [
                    'id' => 'academic',
                    'mode' => 'academic',
                    'name' => 'Academic Assistant',
                    'category' => 'Pendidikan & Riset',
                    'summary' => 'Penjelasan materi kuliah langkah demi langkah, pembuktian matematika, diskusi konseptual, dan pembuatan latihan soal.',
                    'route' => '/',
                    'prompts' => [
                        [
                            'label' => 'Bedah Soal Matematika Bertahap',
                            'prompt' => 'Bantu saya memahami cara menyelesaikan persamaan diferensial orde satu berikut langkah demi langkah beserta alasan tiap tahapannya: dy/dx + 2xy = x.',
                        ],
                        [
                            'label' => 'Rangkuman Materi Kuliah & Peta Konsep',
                            'prompt' => 'Jelaskan konsep normalisasi database dari 1NF hingga BCNF beserta contoh kasus tabel transaksi nyata dan alasan kapan denormalisasi diperlukan.',
                        ],
                        [
                            'label' => 'Buat Latihan Soal & Pembahasan',
                            'prompt' => 'Buatkan 5 soal latihan tingkat menengah tentang Struktur Data Graf (BFS, DFS, Dijkstra) lengkap dengan kunci pembahasan bertahap di bagian akhir.',
                        ],
                    ],
                ],
                [
                    'id' => 'cv',
                    'mode' => 'cv',
                    'name' => 'CV Intelligence Studio',
                    'category' => 'Karier & Rekrutmen',
                    'summary' => 'Susun CV profesional, impor PDF/DOCX, audit keterbacaan ATS, sesuaikan dengan lowongan kerja, buat cover letter, dan ekspor PDF.',
                    'route' => '/cv',
                    'prompts' => [
                        [
                            'label' => 'Buka Studio Pembuat & Audit CV',
                            'prompt' => '',
                            'direct_route' => '/cv',
                        ],
                        [
                            'label' => 'Konsultasi Wawancara & Penajaman Bullet CV',
                            'prompt' => 'Bantu saya mengubah deskripsi tugas kerja harian saya menjadi poin pencapaian terukur dengan metode STAR tanpa melebih-lebihkan fakta.',
                        ],
                    ],
                ],
                [
                    'id' => 'document',
                    'mode' => 'document',
                    'name' => 'Document Intelligence',
                    'category' => 'Analisis File',
                    'summary' => 'Unggah PDF, DOCX, TXT, atau CSV untuk diringkas, ditanyakan isinya secara presisi, atau dibandingkan lintas dokumen.',
                    'route' => '/documents',
                    'prompts' => [
                        [
                            'label' => 'Kelola & Bandingkan Dokumen',
                            'prompt' => '',
                            'direct_route' => '/documents',
                        ],
                        [
                            'label' => 'Tanya Jawab Berbasis Dokumen',
                            'prompt' => 'Ringkas poin keputusan utama, kewajiban tiap pihak, dan tenggat waktu dari dokumen yang saya lampirkan.',
                        ],
                    ],
                ],
                [
                    'id' => 'coding',
                    'mode' => 'coding',
                    'name' => 'Software & Debugging Lab',
                    'category' => 'Pemrograman',
                    'summary' => 'Penulisan arsitektur kode bersih, analisis error stack trace, optimasi query SQL, dan review keamanan aplikasi.',
                    'route' => '/',
                    'prompts' => [
                        [
                            'label' => 'Analisis Error & Debugging Kode',
                            'prompt' => 'Jelaskan penyebab utama masalah N+1 query di ORM backend dan tunjukkan cara memperbaikinya beserta perbandingan query SQL yang dihasilkan.',
                        ],
                        [
                            'label' => 'Review Keamanan & Refactoring',
                            'prompt' => 'Bantu saya meninjau fungsi autentikasi dan upload file berikut agar tahan terhadap injeksi, path traversal, dan kebocoran data.',
                        ],
                    ],
                ],
                [
                    'id' => 'creative',
                    'mode' => 'creative',
                    'name' => 'Creative & Official Writing',
                    'category' => 'Penulisan & Konten',
                    'summary' => 'Penulisan artikel mendalam, proposal proyek, surat resmi, email kerja, kerangka presentasi, caption media sosial, dan perancangan prompt.',
                    'route' => '/',
                    'prompts' => [
                        [
                            'label' => 'Susun Proposal Proyek / Bisnis',
                            'prompt' => 'Bantu saya menyusun kerangka proposal implementasi sistem inventaris gudang untuk perusahaan ritel menengah: latar belakang masalah, ruang lingkup, tahapan kerja, dan indikator keberhasilan.',
                        ],
                        [
                            'label' => 'Tulis Surat Resmi & Email Profesional',
                            'prompt' => 'Buatkan draf surat undangan resmi permohonan narasumber seminar teknologi kampus dengan bahasa baku yang sopan dan jelas.',
                        ],
                        [
                            'label' => 'Rencana Konten & Caption Instagram',
                            'prompt' => 'Susun 5 ide konten edukasi finansial mahasiswa untuk karusel Instagram beserta struktur slide dan caption yang komunikatif.',
                        ],
                        [
                            'label' => 'Kerangka Slide Presentasi & Prompt AI',
                            'prompt' => 'Buatkan struktur 10 slide presentasi laporan kuartalan tim produk lengkap dengan poin bicara utama di setiap slide.',
                        ],
                    ],
                ],
            ],
        ]);
    }
}

