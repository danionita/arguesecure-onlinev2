<?php

namespace App\Http\Requests\Assessment;

use App\Domain\References\ControlId;
use App\Domain\References\ReferenceValidator;
use App\Models\Assessment;
use App\Models\Node;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adds an external reference to a node from the node modal's References tab
 * (ui.md §7, schema.md §3.12). `identifier` is normalized to uppercase in
 * prepareForValidation() *before* the pattern rule runs, so a user typing
 * lowercase "cve-2024-3094" is canonicalized rather than rejected — the
 * normalized value is what both the pattern check and the per-node
 * uniqueness check run against, and what ultimately gets stored.
 *
 * `url` is only accepted for kind `url`/`other` (security.md §7: "the URL
 * is derived from the identifier, never taken from input, except for the
 * `url` kind"). For every other kind the `url` field is validated as
 * absent/ignored — the controller never reads it for a derived kind, so
 * even a client that sends one cannot influence the stored link; the URL
 * that ends up in the database always comes from
 * ReferenceValidator::deriveUrl(), which has no parameter to accept one.
 */
class StoreNodeReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');
        /** @var Node $node */
        $node = $this->route('node');

        if (! $this->user()?->can('update', $node)) {
            return false;
        }

        // Belt-and-suspenders IDOR check, matching UpdateNodeRequest's own
        // (security.md §3.3): a node uuid from a different assessment must
        // 403, not accept a reference cross-assessment.
        return $node->assessment_id === $assessment->id;
    }

    protected function prepareForValidation(): void
    {
        $kind = $this->input('kind');
        $identifier = $this->input('identifier');

        if (is_string($kind) && is_string($identifier)) {
            $this->merge([
                'identifier' => ReferenceValidator::normalizeIdentifier($kind, $identifier),
            ]);
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var Node $node */
        $node = $this->route('node');

        return [
            'kind' => [
                'required',
                'string',
                Rule::in(ReferenceValidator::kinds()),
                // A control is what a defence implements (PLAN-2.7 D64), and
                // the coverage view reads defences only: a citation anywhere
                // else would be one it silently never shows.
                function (string $attribute, mixed $value, Closure $fail) use ($node): void {
                    if (in_array($value, ReferenceValidator::DEFENCE_ONLY_KINDS, true) && $node->type !== 'defence') {
                        $fail('Only a defence can cite a '.strtolower(ReferenceValidator::label((string) $value)).'.');
                    }
                },
            ],
            'identifier' => [
                'required_unless:kind,url',
                'nullable',
                'string',
                'max:64',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $kind = $this->input('kind');
                    if (! is_string($kind) || $kind === 'url' || ! is_string($value)) {
                        return;
                    }
                    if ($kind === 'control' && ! ReferenceValidator::isValidIdentifier($kind, $value)) {
                        $fail('Cite a control as FRAMEWORK:CONTROL, for example ISO27001-2022:A.5.23, with one of '
                            .implode(', ', ControlId::frameworks()).'.');
                    } elseif (! ReferenceValidator::isValidIdentifier($kind, $value)) {
                        $fail('That does not look like a valid '.ReferenceValidator::label($kind).' identifier.');
                    }
                },
                Rule::unique('node_references', 'identifier')->where(
                    fn ($query) => $query->where('node_id', $node->id)
                        ->where('kind', $this->input('kind'))
                ),
            ],
            // Only meaningful for kind = url/other; StoreNodeReferenceRequest's
            // sibling controller ignores this field entirely for every other
            // kind, so a value sent alongside e.g. kind=cve is validated but
            // never persisted.
            'url' => [
                'required_if:kind,url,other',
                'nullable',
                'string',
                'max:2048',
                'regex:#^https?://#i',
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
