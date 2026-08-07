<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Api\Helpers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ActivityController extends BaseController
{
    private const LOG_TABLE = 'bow_tbl_account_logs';

    private const STAFF_ROLES = ['staff', 'voter_editor'];

    private const PAGE_LABELS = [
        '/staff/dashboard' => 'Staff Dashboard',
        '/voters' => 'Voter Masterlist',
        '/recipients' => 'Voter Masterlist',
        '/barangays' => 'Locations & Precincts',
        '/tribes' => 'Tribes',
        '/religions' => 'Religions',
    ];

    private const ACTIONS = [
        'OPEN_CREATE_VOTER_FORM' => ['Opened the add voter form', null],
        'OPEN_VOTER_EDIT' => ['Opened voter record for editing', 'bow_tbl_recipients'],
        'VIEW_VOTER_DETAILS' => ['Viewed voter details', 'bow_tbl_recipients'],
        'OPEN_HOUSEHOLD_MANAGER' => ['Opened voter household management', 'bow_tbl_recipients'],
        'OPEN_VOTER_MAP' => ['Opened voter residence in Google Maps', 'bow_tbl_recipients'],
        'OPEN_FORM_COORDINATES_MAP' => ['Opened entered residence coordinates in Google Maps', 'bow_tbl_recipients'],
        'DOWNLOAD_VOTER_QR' => ['Downloaded voter registry QR', 'bow_tbl_recipients'],
    ];

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = strtolower(trim((string) data_get($user, 'role')));

        if (!$user || !in_array($role, self::STAFF_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only staff activity can be recorded by this endpoint.',
            ], 403);
        }

        $validated = $request->validate([
            'event_type' => ['required', 'in:PAGE_VIEW,ACTION'],
            'page_path' => ['required', 'string', 'max:255'],
            'action_code' => ['nullable', 'required_if:event_type,ACTION', 'string', 'max:80'],
            'entity_id' => ['nullable', 'string', 'max:64'],
        ]);

        $pagePath = $this->normalizePagePath((string) $validated['page_path']);
        if (!isset(self::PAGE_LABELS[$pagePath])) {
            return response()->json([
                'success' => false,
                'message' => 'This staff page is not available for activity logging.',
            ], 422);
        }

        $eventType = (string) $validated['event_type'];
        $pageLabel = self::PAGE_LABELS[$pagePath];
        $actionCode = null;
        $entityTable = null;
        $entityId = null;

        if ($eventType === 'PAGE_VIEW') {
            $description = 'Accessed ' . $pageLabel;
        } else {
            $actionCode = strtoupper(trim((string) ($validated['action_code'] ?? '')));
            if (!isset(self::ACTIONS[$actionCode])) {
                return response()->json([
                    'success' => false,
                    'message' => 'This staff action is not available for activity logging.',
                ], 422);
            }

            [$description, $entityTable] = self::ACTIONS[$actionCode];
            $entityId = trim((string) ($validated['entity_id'] ?? '')) ?: null;
            if ($entityId !== null && $entityTable !== null) {
                $description .= ' #' . $entityId;
            }
        }

        if (!Schema::hasTable(self::LOG_TABLE)) {
            return response()->json([
                'success' => false,
                'message' => 'The activity log table is not available.',
            ], 503);
        }

        $payload = [
            'user_id' => data_get($user, 'id'),
            'username' => mb_substr((string) (data_get($user, 'username') ?: data_get($user, 'name') ?: data_get($user, 'email')), 0, 255),
            'role' => mb_substr($role, 0, 50),
            'event_group' => 'STAFF_ACTIVITY',
            'event_code' => $eventType,
            'action_status' => 'SUCCESS',
            'entity_table' => $entityTable,
            'entity_id' => $entityId,
            'description' => mb_substr($description, 0, 500),
            'request_method' => 'CLIENT',
            'request_path' => $pagePath,
            'request_query' => null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'old_values' => null,
            'new_values' => null,
            'extra_data' => json_encode([
                'page_name' => $pageLabel,
                'action_code' => $actionCode,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $columns = array_fill_keys(Schema::getColumnListing(self::LOG_TABLE), true);
        $filtered = array_filter(
            $payload,
            fn ($value, $key) => isset($columns[$key]),
            ARRAY_FILTER_USE_BOTH
        );

        DB::table(self::LOG_TABLE)->insert($filtered);

        return response()->json([
            'success' => true,
            'message' => 'Staff activity recorded.',
        ], 201);
    }

    private function normalizePagePath(string $pagePath): string
    {
        $path = parse_url(trim($pagePath), PHP_URL_PATH);
        $normalized = '/' . trim(is_string($path) ? $path : '', '/');

        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
}
