<?php

namespace App\Http\Requests\Admin;

use App\Domain\Accounts\CsvUserImporter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepts either pasted CSV text or an uploaded file, never both required.
 * The uploaded file is read into memory and discarded — security.md §7:
 * "never save the file to disk, never accept a filesystem path from
 * input." A 1 MB cap keeps a workshop-sized roster (hundreds of rows)
 * comfortably inside the limit while bounding memory use.
 */
class ImportPreviewRequest extends FormRequest
{
    private ?string $normalisedCsv = null;

    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'csv_text' => ['required_without:csv_file', 'nullable', 'string', 'max:1048576'],
            'csv_file' => ['required_without:csv_text', 'nullable', 'file', 'mimes:csv,txt', 'max:1024'],
        ];
    }

    public function csvContent(): string
    {
        // The controller asks twice (to parse, and to hand the text back to the
        // preview page); normalising a megabyte once is enough.
        return $this->normalisedCsv ??= CsvUserImporter::normalise(
            $this->hasFile('csv_file')
                ? (string) file_get_contents($this->file('csv_file')->getRealPath())
                : (string) $this->input('csv_text'),
        );
    }
}
