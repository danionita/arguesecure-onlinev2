<?php

namespace App\Http\Controllers;

use App\Domain\History\Exceptions\NothingToUndoException;
use App\Domain\History\Exceptions\UndoConflictException;
use App\Domain\History\UndoService;
use App\Http\Requests\History\PeekUndoRequest;
use App\Http\Requests\History\UndoRequest;
use App\Models\Assessment;
use App\Models\AssessmentOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * The single linear per-assessment undo stack (ui.md §3.6, schema.md
 * §3.10). Every action here is fronted by a FormRequest whose authorize()
 * calls AssessmentPolicy via the `update` ability — no exceptions
 * (CLAUDE.md) — and any editor may undo, not only the operation's author.
 */
class UndoController extends Controller
{
    public function __construct(private readonly UndoService $undo) {}

    /** The prompt data for "Undo Dana's deletion of 'Phishing'?" before the user confirms. */
    public function show(PeekUndoRequest $request, Assessment $assessment): JsonResponse
    {
        return response()->json(['operation' => $this->operationToArray($this->undo->peek($assessment))]);
    }

    public function undo(UndoRequest $request, Assessment $assessment): JsonResponse
    {
        try {
            $operation = $this->undo->undo($assessment, $request->user());
        } catch (NothingToUndoException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (UndoConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['operation' => $this->operationToArray($operation)]);
    }

    public function redo(UndoRequest $request, Assessment $assessment): JsonResponse
    {
        try {
            $operation = $this->undo->redo($assessment, $request->user());
        } catch (NothingToUndoException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (UndoConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['operation' => $this->operationToArray($operation)]);
    }

    /** @return array<string, mixed>|null */
    private function operationToArray(?AssessmentOperation $operation): ?array
    {
        if ($operation === null) {
            return null;
        }

        $operation->loadMissing('user');

        return [
            'id' => $operation->id,
            'kind' => $operation->kind,
            'summary' => $operation->summary,
            'author' => $operation->user?->name,
            'created_at' => $this->toJson($operation->getAttribute('created_at')),
            'undone_at' => $this->toJson($operation->getAttribute('undone_at')),
        ];
    }

    /**
     * `created_at`/`undone_at` are cast to `datetime` on the model
     * (AssessmentOperation::casts()), but that Laravel-11-style
     * method-based cast declaration isn't picked up by static analysis
     * here, so ->created_at?->toJSON() reads as "call toJSON() on a
     * string" to PHPStan even though it is a Carbon instance at runtime.
     * getAttribute() (untyped) plus this explicit runtime check sidesteps
     * that without touching app/Models/** (outside this package's
     * ownership).
     */
    private function toJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? Carbon::instance($value)->toJSON()
            : (string) $value;
    }
}
