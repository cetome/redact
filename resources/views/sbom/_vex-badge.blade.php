{{-- The VEX status of one statement; justification, detail, action and where it came from on hover. --}}
<span class="a-badge {{ \App\Sbom\Vex\VexStatus::TONES[$statement->status] ?? 'a-badge-neutral' }}"
      title="{{ trim($statement->explanation()."\n".__('sbom.vex.from', ['file' => $statement->source_file, 'date' => $statement->imported_at?->isoFormat('D MMM YYYY') ?? '—', 'name' => $statement->importer?->name ?? '—'])) }}">
    <x-lucide-shield-check aria-hidden="true" />VEX · {{ __('sbom.vex.status.'.$statement->status) }}
</span>
