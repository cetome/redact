<?php

namespace App\Reports;

/**
 * The ready reports of a version, as CRAted's views: what a document holds
 * follows who reads it (the evidence or the verdicts, never mixed up).
 */
enum ReportKind: string
{
    /** The full technical documentation draft. */
    case Assessment = 'assessment';
    /** E.DT and E.Just only, for the conceptual assessment by a test lab. */
    case Trees = 'trees';
    /** The reviewers' verdicts and comments only. */
    case Review = 'review';

    /** What the document is, on its cover and in its running header ("RED assessment"). */
    public function label(): string
    {
        return __('reports.kinds.'.$this->value.'.label');
    }

    /** The last part of the file name ("Assessment"). */
    public function fileContent(): string
    {
        return __('reports.kinds.'.$this->value.'.file');
    }

    public function description(): string
    {
        return __('reports.kinds.'.$this->value.'.description');
    }

    /** The cover's disclaimer, without the AI sentence and the export line. */
    public function disclaimer(): string
    {
        return __('reports.kinds.'.$this->value.'.disclaimer');
    }

    /** @return list<string> */
    public function formats(): array
    {
        return ['docx', 'xlsx'];
    }
}
