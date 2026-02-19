<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Helpers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditLogController extends BaseController
{
    private const LOG_TABLE = 'bow_tbl_account_logs';
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;

    private ?array $logColumnMap = null;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'user_id' => ['nullable', 'integer'],
            'event_code' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ]);

        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, (int) ($validated['page'] ?? 1));

        $appliedFilters = [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            'event_code' => $validated['event_code'] ?? null,
            'status' => $validated['status'] ?? null,
        ];

        if (!Schema::hasTable(self::LOG_TABLE)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'items' => [],
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => 0,
                        'last_page' => 1,
                    ],
                    'filters' => [
                        'applied' => $appliedFilters,
                        'users' => $this->resolveUsers(),
                        'events' => [],
                        'statuses' => [],
                    ],
                ],
            ]);
        }

        $query = DB::table(self::LOG_TABLE . ' as l');
        $query->select($this->resolveSelectColumns());

        if ($this->hasLogColumn('created_at')) {
            if (!empty($validated['date_from'])) {
                $query->whereDate('l.created_at', '>=', $validated['date_from']);
            }

            if (!empty($validated['date_to'])) {
                $query->whereDate('l.created_at', '<=', $validated['date_to']);
            }
        }

        if ($this->hasLogColumn('user_id') && !empty($validated['user_id'])) {
            $query->where('l.user_id', (int) $validated['user_id']);
        }

        if ($this->hasLogColumn('event_code') && !empty($validated['event_code'])) {
            $query->where('l.event_code', $validated['event_code']);
        }

        if ($this->hasLogColumn('action_status') && !empty($validated['status'])) {
            $query->where('l.action_status', strtoupper((string) $validated['status']));
        }

        if ($this->hasLogColumn('created_at')) {
            $query->orderByDesc('l.created_at');
        } elseif ($this->hasLogColumn('id')) {
            $query->orderByDesc('l.id');
        } elseif ($this->hasLogColumn('log_id')) {
            $query->orderByDesc('l.log_id');
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $paginator->items(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'filters' => [
                    'applied' => $appliedFilters,
                    'users' => $this->resolveUsers(),
                    'events' => $this->resolveEventOptions(),
                    'statuses' => $this->resolveStatusOptions(),
                ],
            ],
        ]);
    }

    private function resolveSelectColumns(): array
    {
        $columns = [
            'id',
            'log_id',
            'user_id',
            'username',
            'role',
            'event_group',
            'event_code',
            'action_status',
            'entity_table',
            'entity_id',
            'description',
            'request_method',
            'request_path',
            'ip_address',
            'created_at',
        ];

        $selectColumns = [];
        foreach ($columns as $column) {
            if ($this->hasLogColumn($column)) {
                $selectColumns[] = 'l.' . $column;
            }
        }

        return empty($selectColumns) ? ['l.*'] : $selectColumns;
    }

    private function resolveUsers(): array
    {
        if (!Schema::hasTable('users')) {
            return [];
        }

        $hasName = Schema::hasColumn('users', 'name');
        $hasUsername = Schema::hasColumn('users', 'username');
        $hasEmail = Schema::hasColumn('users', 'email');

        $query = DB::table('users')->select('id');

        if ($hasName) {
            $query->addSelect('name');
        }

        if ($hasUsername) {
            $query->addSelect('username');
        } elseif ($hasEmail) {
            $query->addSelect('email');
        }

        if ($hasName) {
            $query->orderBy('name');
        } elseif ($hasUsername) {
            $query->orderBy('username');
        } elseif ($hasEmail) {
            $query->orderBy('email');
        } else {
            $query->orderBy('id');
        }

        return $query->get()->map(function ($user) use ($hasEmail, $hasUsername) {
            $name = trim((string) ($user->name ?? ''));
            $username = trim((string) ($user->username ?? ($hasEmail ? ($user->email ?? '') : '')));

            $label = $name !== '' ? $name : $username;
            if ($label === '') {
                $label = 'User #' . $user->id;
            } elseif ($name !== '' && $username !== '' && strcasecmp($name, $username) !== 0) {
                $label .= ' (' . $username . ')';
            }

            return [
                'value' => (int) $user->id,
                'label' => $label,
            ];
        })->values()->all();
    }

    private function resolveEventOptions(): array
    {
        if (!$this->hasLogColumn('event_code')) {
            return [];
        }

        $query = DB::table(self::LOG_TABLE . ' as l')
            ->whereNotNull('l.event_code')
            ->where('l.event_code', '<>', '')
            ->distinct()
            ->orderBy('l.event_code');

        if ($this->hasLogColumn('event_group')) {
            $query->select('l.event_code', 'l.event_group');
        } else {
            $query->select('l.event_code');
        }

        return $query->get()->map(function ($row) {
            $eventCode = (string) ($row->event_code ?? '');
            $eventGroup = (string) ($row->event_group ?? '');
            $label = $eventCode;

            if ($eventGroup !== '') {
                $label .= ' (' . $eventGroup . ')';
            }

            return [
                'value' => $eventCode,
                'label' => $label,
            ];
        })->values()->all();
    }

    private function resolveStatusOptions(): array
    {
        if (!$this->hasLogColumn('action_status')) {
            return [];
        }

        return DB::table(self::LOG_TABLE . ' as l')
            ->whereNotNull('l.action_status')
            ->where('l.action_status', '<>', '')
            ->select('l.action_status')
            ->distinct()
            ->orderBy('l.action_status')
            ->get()
            ->map(function ($row) {
                $status = strtoupper((string) $row->action_status);

                return [
                    'value' => $status,
                    'label' => $status,
                ];
            })
            ->values()
            ->all();
    }

    private function hasLogColumn(string $column): bool
    {
        return isset($this->getLogColumnMap()[$column]);
    }

    private function getLogColumnMap(): array
    {
        if ($this->logColumnMap !== null) {
            return $this->logColumnMap;
        }

        $columns = Schema::getColumnListing(self::LOG_TABLE);
        $this->logColumnMap = array_fill_keys($columns, true);

        return $this->logColumnMap;
    }
}
