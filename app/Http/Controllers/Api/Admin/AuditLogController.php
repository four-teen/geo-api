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
    private const STAFF_ROLES = ['staff', 'voter_editor'];
    private const PAGE_OPTIONS = [
        ['value' => '/', 'label' => 'Login'],
        ['value' => '/staff/dashboard', 'label' => 'Staff Dashboard'],
        ['value' => '/voters', 'label' => 'Voter Masterlist'],
        ['value' => '/recipients', 'label' => 'Voter Masterlist (legacy route)'],
        ['value' => '/barangays', 'label' => 'Locations & Precincts'],
        ['value' => '/tribes', 'label' => 'Tribes'],
        ['value' => '/religions', 'label' => 'Religions'],
    ];

    private ?array $logColumnMap = null;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'user_id' => ['nullable', 'integer'],
            'role' => ['nullable', 'in:staff,voter_editor'],
            'event_type' => ['nullable', 'in:LOGIN,LOGOUT,PAGE_VIEW,ACTION'],
            'event_code' => ['nullable', 'string', 'max:80'],
            'page_path' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:100'],
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
            'role' => $validated['role'] ?? null,
            'event_type' => $validated['event_type'] ?? null,
            'event_code' => $validated['event_code'] ?? null,
            'page_path' => $validated['page_path'] ?? null,
            'status' => $validated['status'] ?? null,
            'search' => $validated['search'] ?? null,
        ];

        if (!Schema::hasTable(self::LOG_TABLE)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'items' => [],
                    'summary' => [
                        'total' => 0,
                        'successful' => 0,
                        'failed' => 0,
                        'logins' => 0,
                        'page_views' => 0,
                        'actions' => 0,
                    ],
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $perPage,
                        'total' => 0,
                        'last_page' => 1,
                    ],
                    'filters' => [
                        'applied' => $appliedFilters,
                        'users' => $this->resolveUsers(),
                        'roles' => $this->resolveRoleOptions(),
                        'event_types' => $this->resolveEventTypeOptions(),
                        'pages' => self::PAGE_OPTIONS,
                        'events' => [],
                        'statuses' => [],
                    ],
                ],
            ]);
        }

        $query = DB::table(self::LOG_TABLE . ' as l');
        $query->select($this->resolveSelectColumns());

        if ($this->hasLogColumn('role')) {
            $query->whereIn('l.role', self::STAFF_ROLES);
        }

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

        if ($this->hasLogColumn('role') && !empty($validated['role'])) {
            $query->where('l.role', $validated['role']);
        }

        if ($this->hasLogColumn('event_code') && !empty($validated['event_type'])) {
            $this->applyEventTypeFilter($query, (string) $validated['event_type']);
        }

        if ($this->hasLogColumn('event_code') && !empty($validated['event_code'])) {
            $query->where('l.event_code', $validated['event_code']);
        }

        if ($this->hasLogColumn('action_status') && !empty($validated['status'])) {
            $query->where('l.action_status', strtoupper((string) $validated['status']));
        }

        if ($this->hasLogColumn('request_path') && !empty($validated['page_path'])) {
            $query->where('l.request_path', $validated['page_path']);
        }

        if (!empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($inner) use ($search) {
                foreach (['username', 'description', 'entity_id', 'request_path'] as $column) {
                    if (!$this->hasLogColumn($column)) {
                        continue;
                    }

                    if ($column === 'username') {
                        $inner->where('l.' . $column, 'like', '%' . $search . '%');
                    } else {
                        $inner->orWhere('l.' . $column, 'like', '%' . $search . '%');
                    }
                }
            });
        }

        $summary = $this->resolveSummary($query);

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
                'items' => collect($paginator->items())
                    ->map(fn ($item) => $this->formatLogItem($item))
                    ->values()
                    ->all(),
                'summary' => $summary,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'filters' => [
                    'applied' => $appliedFilters,
                    'users' => $this->resolveUsers(),
                    'roles' => $this->resolveRoleOptions(),
                    'event_types' => $this->resolveEventTypeOptions(),
                    'pages' => self::PAGE_OPTIONS,
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

        if (Schema::hasColumn('users', 'role')) {
            $query->whereIn('role', self::STAFF_ROLES);
        }

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
        return [
            ['value' => 'SUCCESS', 'label' => 'Success'],
            ['value' => 'FAILED', 'label' => 'Failed'],
        ];
    }

    private function applyEventTypeFilter($query, string $eventType): void
    {
        if ($eventType === 'ACTION') {
            $query->whereNotIn('l.event_code', ['LOGIN', 'LOGOUT', 'PAGE_VIEW']);
            return;
        }

        $query->where('l.event_code', $eventType);
    }

    private function resolveSummary($query): array
    {
        $count = fn ($builder) => (int) $builder->count();

        $total = $count(clone $query);
        $successful = $this->hasLogColumn('action_status')
            ? $count((clone $query)->where('l.action_status', 'SUCCESS'))
            : $total;
        $failed = $this->hasLogColumn('action_status')
            ? $count((clone $query)->where('l.action_status', 'FAILED'))
            : 0;
        $logins = $this->hasLogColumn('event_code')
            ? $count((clone $query)->where('l.event_code', 'LOGIN'))
            : 0;
        $pageViews = $this->hasLogColumn('event_code')
            ? $count((clone $query)->where('l.event_code', 'PAGE_VIEW'))
            : 0;
        $actions = $this->hasLogColumn('event_code')
            ? $count((clone $query)->whereNotIn('l.event_code', ['LOGIN', 'LOGOUT', 'PAGE_VIEW']))
            : 0;

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'logins' => $logins,
            'page_views' => $pageViews,
            'actions' => $actions,
        ];
    }

    private function formatLogItem(object $item): array
    {
        $formatted = (array) $item;
        $eventCode = strtoupper((string) ($formatted['event_code'] ?? 'ACTION'));

        $formatted['event_type'] = in_array($eventCode, ['LOGIN', 'LOGOUT', 'PAGE_VIEW'], true)
            ? $eventCode
            : 'ACTION';
        $formatted['page_name'] = $this->resolvePageLabel((string) ($formatted['request_path'] ?? ''));
        $formatted['role_label'] = ($formatted['role'] ?? '') === 'voter_editor'
            ? 'Voter Records Editor'
            : 'Staff';

        return $formatted;
    }

    private function resolvePageLabel(string $path): string
    {
        foreach (self::PAGE_OPTIONS as $option) {
            if ($option['value'] === $path) {
                return $option['label'];
            }
        }

        if (str_contains($path, '/bow/voters') || str_contains($path, '/bow/recipients')) {
            return 'Voter Masterlist';
        }

        if (str_contains($path, '/admin/login')) {
            return 'Login';
        }

        return $path !== '' ? $path : 'System';
    }

    private function resolveRoleOptions(): array
    {
        return [
            ['value' => 'staff', 'label' => 'Staff'],
            ['value' => 'voter_editor', 'label' => 'Voter Records Editor'],
        ];
    }

    private function resolveEventTypeOptions(): array
    {
        return [
            ['value' => 'LOGIN', 'label' => 'Login'],
            ['value' => 'LOGOUT', 'label' => 'Logout'],
            ['value' => 'PAGE_VIEW', 'label' => 'Page accessed'],
            ['value' => 'ACTION', 'label' => 'Action performed'],
        ];
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
