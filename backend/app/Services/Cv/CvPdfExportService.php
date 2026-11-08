<?php

namespace App\Services\Cv;

use App\Models\CvProfile;
use Dompdf\Dompdf;
use Dompdf\Options;

class CvPdfExportService
{
    public function renderPdfBinary(CvProfile $cv, ?string $templateOverride = null): string
    {
        $template = $templateOverride ?: ($cv->template ?: 'executive');
        $html = $this->renderHtml($cv, $template);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', $template === 'ats_clean' ? 'Times-Roman' : 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function renderHtml(CvProfile $cv, string $template = 'executive'): string
    {
        $e = fn (?string $val) => htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $contactParts = array_values(array_filter([
            $cv->email ? $e($cv->email) : null,
            $cv->phone ? $e($cv->phone) : null,
            $cv->location ? $e($cv->location) : null,
            $cv->linkedin ? $e($cv->linkedin) : null,
            $cv->website ? $e($cv->website) : null,
        ]));

        $styles = match ($template) {
            'ats_clean' => '
                body { font-family: "Times New Roman", Times, serif; color: #111827; font-size: 11pt; line-height: 1.42; margin: 28px 34px; }
                .header { text-align: center; border-bottom: 1px solid #111827; padding-bottom: 10px; margin-bottom: 14px; }
                .name { font-size: 20pt; font-weight: bold; margin: 0; letter-spacing: 0.3px; }
                .headline { font-size: 11.5pt; margin: 4px 0; color: #374151; }
                .contact { font-size: 9.5pt; color: #374151; }
                h2 { font-size: 11.5pt; text-transform: uppercase; border-bottom: 1px solid #9CA3AF; padding-bottom: 3px; margin: 14px 0 8px; letter-spacing: 0.8px; }
                .item { margin-bottom: 10px; }
                .item-head { font-weight: bold; font-size: 11pt; }
                .item-sub { font-style: italic; color: #374151; font-size: 10pt; margin-bottom: 3px; }
                ul { margin: 4px 0 6px 18px; padding: 0; }
                li { margin-bottom: 2.5px; }
                .skills-list { font-size: 10.5pt; }
            ',
            'modern_minimal' => '
                body { font-family: Helvetica, Arial, sans-serif; color: #0F172A; font-size: 10pt; line-height: 1.48; margin: 26px 32px; }
                .header { border-left: 4px solid #2563EB; padding-left: 14px; margin-bottom: 18px; }
                .name { font-size: 21pt; font-weight: bold; margin: 0; color: #0F172A; }
                .headline { font-size: 11pt; color: #1D4ED8; margin: 3px 0 6px; font-weight: bold; }
                .contact { font-size: 9pt; color: #475569; }
                h2 { font-size: 10pt; text-transform: uppercase; color: #1D4ED8; margin: 16px 0 7px; letter-spacing: 1px; }
                .item { margin-bottom: 11px; }
                .item-head { font-weight: bold; font-size: 10.5pt; color: #0F172A; }
                .item-sub { color: #475569; font-size: 9.2pt; margin-bottom: 4px; }
                ul { margin: 4px 0 6px 16px; padding: 0; }
                li { margin-bottom: 3px; }
                .skill-chip { display: inline-block; background: #EFF6FF; color: #1E3A8A; border: 1px solid #BFDBFE; border-radius: 4px; padding: 2px 7px; margin: 0 5px 5px 0; font-size: 9pt; }
            ',
            default => '
                body { font-family: Helvetica, Arial, sans-serif; color: #0F172A; font-size: 10.2pt; line-height: 1.45; margin: 0; padding: 0; }
                .header { background: #0F172A; color: #F8FAFC; padding: 24px 34px 20px; margin-bottom: 16px; }
                .name { font-size: 21pt; font-weight: bold; margin: 0; color: #FFFFFF; }
                .headline { font-size: 11pt; color: #93C5FD; margin: 4px 0 8px; }
                .contact { font-size: 9pt; color: #CBD5E1; }
                .body-wrap { padding: 0 34px 26px; }
                h2 { font-size: 10.5pt; text-transform: uppercase; color: #0F172A; border-bottom: 2px solid #2563EB; padding-bottom: 3px; margin: 14px 0 8px; letter-spacing: 0.8px; }
                .item { margin-bottom: 10px; }
                .item-head { font-weight: bold; font-size: 10.5pt; color: #0F172A; }
                .item-sub { color: #475569; font-size: 9.3pt; margin-bottom: 3px; }
                ul { margin: 4px 0 6px 16px; padding: 0; }
                li { margin-bottom: 3px; }
                .skills-list { font-size: 9.8pt; color: #1E293B; }
            ',
        };

        $bodyStart = $template === 'executive' ? '<div class="body-wrap">' : '';
        $bodyEnd = $template === 'executive' ? '</div>' : '';

        $summaryHtml = '';
        if (! empty($cv->summary)) {
            $summaryHtml = '<h2>Ringkasan Profesional</h2><p style="margin:0 0 8px;">'.nl2br($e($cv->summary)).'</p>';
        }

        $expHtml = '';
        $experiences = is_array($cv->experiences) ? $cv->experiences : [];
        if (! empty($experiences)) {
            $expHtml .= '<h2>Pengalaman Kerja</h2>';
            foreach ($experiences as $exp) {
                $role = $e($exp['role'] ?? '');
                $company = $e($exp['company'] ?? '');
                $dates = trim(($exp['start_date'] ?? '').(! empty($exp['end_date']) ? ' - '.$exp['end_date'] : ''));
                $loc = $e($exp['location'] ?? '');
                $subParts = array_values(array_filter([$company, $loc, $e($dates)]));

                $expHtml .= '<div class="item">';
                $expHtml .= '<div class="item-head">'.$role.'</div>';
                if (! empty($subParts)) {
                    $expHtml .= '<div class="item-sub">'.implode(' | ', $subParts).'</div>';
                }
                $highlights = is_array($exp['highlights'] ?? null) ? array_filter($exp['highlights'], fn ($h) => trim((string) $h) !== '') : [];
                if (! empty($highlights)) {
                    $expHtml .= '<ul>';
                    foreach ($highlights as $bullet) {
                        $expHtml .= '<li>'.$e((string) $bullet).'</li>';
                    }
                    $expHtml .= '</ul>';
                }
                $expHtml .= '</div>';
            }
        }

        $eduHtml = '';
        $educations = is_array($cv->educations) ? $cv->educations : [];
        if (! empty($educations)) {
            $eduHtml .= '<h2>Pendidikan</h2>';
            foreach ($educations as $edu) {
                $degree = $e($edu['degree'] ?? '');
                $inst = $e($edu['institution'] ?? '');
                $year = $e($edu['year'] ?? trim(($edu['start_year'] ?? '').(! empty($edu['end_year']) ? ' - '.$edu['end_year'] : '')));
                $notes = $e($edu['notes'] ?? '');
                $subParts = array_values(array_filter([$inst, $year]));

                $eduHtml .= '<div class="item">';
                $eduHtml .= '<div class="item-head">'.$degree.'</div>';
                if (! empty($subParts)) {
                    $eduHtml .= '<div class="item-sub">'.implode(' | ', $subParts).'</div>';
                }
                if ($notes !== '') {
                    $eduHtml .= '<div style="font-size:9.5pt;">'.$notes.'</div>';
                }
                $eduHtml .= '</div>';
            }
        }

        $skillsHtml = '';
        $skills = is_array($cv->skills) ? array_values(array_filter($cv->skills, fn ($s) => is_string($s) && trim($s) !== '')) : [];
        if (! empty($skills)) {
            $skillsHtml .= '<h2>Keahlian Inti</h2>';
            if ($template === 'modern_minimal') {
                $skillsHtml .= '<div>';
                foreach ($skills as $s) {
                    $skillsHtml .= '<span class="skill-chip">'.$e($s).'</span>';
                }
                $skillsHtml .= '</div>';
            } else {
                $skillsHtml .= '<div class="skills-list">'.implode(' &bull; ', array_map($e, $skills)).'</div>';
            }
        }

        $certHtml = '';
        $certs = is_array($cv->certifications) ? $cv->certifications : [];
        if (! empty($certs)) {
            $certHtml .= '<h2>Sertifikasi</h2><ul>';
            foreach ($certs as $cert) {
                $line = $e($cert['name'] ?? '');
                if (! empty($cert['issuer'])) {
                    $line .= ' - '.$e($cert['issuer']);
                }
                if (! empty($cert['year'])) {
                    $line .= ' ('.$e((string) $cert['year']).')';
                }
                if ($line !== '') {
                    $certHtml .= '<li>'.$line.'</li>';
                }
            }
            $certHtml .= '</ul>';
        }

        return '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>'.$e($cv->full_name).' - CV</title>
<style>'.$styles.'</style>
</head>
<body>
    <div class="header">
        <h1 class="name">'.$e($cv->full_name).'</h1>
        '.(! empty($cv->headline) ? '<div class="headline">'.$e($cv->headline).'</div>' : '').'
        '.(! empty($contactParts) ? '<div class="contact">'.implode(' &nbsp;|&nbsp; ', $contactParts).'</div>' : '').'
    </div>
    '.$bodyStart.'
    '.$summaryHtml.'
    '.$expHtml.'
    '.$eduHtml.'
    '.$skillsHtml.'
    '.$certHtml.'
    '.$bodyEnd.'
</body>
</html>';
    }
}

