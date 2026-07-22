<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowVoterImport;
use App\Services\Bow\VoterImport\VoterImportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoterImportController extends Controller
{
    public function __construct(private readonly VoterImportService $service)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['DRAFT', 'READY', 'COMMITTED', 'SUPERSEDED'])],
            'barangay_id' => ['nullable', 'integer', 'exists:bow_tbl_barangays,barangay_id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->service->list($request->user(), $validated);
        $data = collect($paginator->items())
            ->map(fn (BowVoterImport $import) => $this->service->serializeImport($import));

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function preview(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'target_barangay_id' => ['nullable', 'integer', 'exists:bow_tbl_barangays,barangay_id'],
        ]);

        $import = $this->service->preview(
            $validated['file'],
            $request->user(),
            isset($validated['target_barangay_id']) ? (int) $validated['target_barangay_id'] : null
        );

        return response()->json([
            'success' => true,
            'message' => 'Document parsed. Review address mappings before committing.',
            'data' => $this->service->detail($import, $request->user()),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $import = BowVoterImport::query()->findOrFail($id);
        return response()->json([
            'success' => true,
            'data' => $this->service->detail($import, $request->user()),
        ]);
    }

    public function autoResolve(Request $request, int $id)
    {
        $import = BowVoterImport::query()->findOrFail($id);
        $this->service->autoResolveDraft($import, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'All addresses were automatically matched or prepared as new puroks.',
            'data' => $this->service->detail($import->fresh(), $request->user()),
        ]);
    }

    public function progress(Request $request, string $token)
    {
        $request->merge(['progress_token' => $token]);
        $validated = $request->validate([
            'progress_token' => ['required', 'uuid'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->commitProgress($validated['progress_token']),
        ]);
    }

    public function rows(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['READY', 'WARNING', 'ERROR', 'REVIEW_REQUIRED'])],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $import = BowVoterImport::query()->findOrFail($id);
        $paginator = $this->service->rows($import, $request->user(), $validated);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function mappings(Request $request, int $id)
    {
        $validated = $request->validate([
            'mappings' => ['required', 'array', 'min:1', 'max:1000'],
            'mappings.*.address_key' => ['required', 'string', 'max:255'],
            'mappings.*.purok_id' => ['nullable', 'integer'],
            'mappings.*.new_purok_name' => ['nullable', 'string', 'max:150'],
            'mappings.*.mark_unassigned' => ['nullable', 'boolean'],
            'mappings.*.remember_alias' => ['nullable', 'boolean'],
        ]);
        $import = BowVoterImport::query()->findOrFail($id);
        $this->service->saveMappings($import, $request->user(), $validated['mappings']);

        return response()->json([
            'success' => true,
            'message' => 'Address mappings saved.',
            'data' => $this->service->detail($import->fresh(), $request->user()),
        ]);
    }

    public function commit(Request $request, int $id)
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['REPLACE_BARANGAY', 'MERGE'])],
            'confirmation' => ['required', 'string', 'max:150'],
            'progress_token' => ['required', 'uuid'],
        ]);
        $import = BowVoterImport::query()->findOrFail($id);
        $summary = $this->service->commit(
            $import,
            $request->user(),
            $validated['mode'],
            $validated['confirmation'],
            $validated['progress_token']
        );

        return response()->json([
            'success' => true,
            'message' => 'Voter import committed successfully.',
            'data' => $summary,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $import = BowVoterImport::query()->findOrFail($id);
        $this->service->deleteDraft($import, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Draft import and its private upload were removed.',
        ]);
    }

    public function destroyBarangay(Request $request, int $id)
    {
        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'max:150'],
        ]);
        $import = BowVoterImport::query()->findOrFail($id);
        $summary = $this->service->deleteBarangayImportData(
            $import,
            $request->user(),
            $validated['confirmation']
        );

        return response()->json([
            'success' => true,
            'message' => 'Barangay voter import data was removed successfully.',
            'data' => $summary,
        ]);
    }
}
