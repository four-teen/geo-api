<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordAuditLog
{
    private const LOG_TABLE = 'bow_tbl_account_logs';
    private const SESSION_EVENT_CODE = 'SESSION_ACTIVITY';
    private const SESSION_ENTITY_TABLE = 'auth_session';
    private const MAX_DESCRIPTION_LENGTH = 60000;

    private const IMPORTANT_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'accessToken',
        'remember_token',
    ];

    private const IGNORED_DETAIL_KEYS = [
        '_token',
        '_method',
        'page',
        'per_page',
        'created_at',
        'updated_at',
    ];

    private const RESOURCE_KEYS = [
        'accounts' => ['username', 'name', 'designation', 'role', 'is_active', 'barangay_scope'],
        'account' => ['username', 'name', 'designation', 'role', 'is_active', 'barangay_scope'],
        'barangay' => ['barangay_name', 'status'],
        'purok' => ['purok_name', 'barangay_id', 'status'],
        'patient' => [
            'last_name',
            'first_name',
            'middle_name',
            'sex',
            'barangay_id',
            'purok_id',
            'status',
            'is_pwd',
            'is_senior',
            'is_hpn',
            'is_dm',
            'is_ekonsulta_member',
        ],
        'physician' => ['last_name', 'first_name', 'middle_name', 'license_no'],
        'medicine' => ['medicine_name', 'generic_name', 'quantity', 'status'],
        'prescription' => ['patient_id', 'physician_id', 'release_status', 'date_released'],
    ];

    private const RESOURCE_ID_KEYS = [
        'accounts' => ['id', 'user_id'],
        'account' => ['id', 'user_id'],
        'barangay' => ['barangay_id', 'id'],
        'purok' => ['purok_id', 'id'],
        'patient' => ['patient_id', 'id'],
        'physician' => ['physician_id', 'id'],
        'medicine' => ['medicine_id', 'id'],
        'prescription' => ['prescription_id', 'id'],
    ];

    private const PRIMARY_KEYS = [
        'users' => 'id',
        'bow_tbl_barangays' => 'barangay_id',
        'bow_tbl_puroks' => 'purok_id',
        'bow_tbl_patients' => 'patient_id',
        'bow_tbl_physicians' => 'physician_id',
        'bow_tbl_medicines' => 'medicine_id',
        'bow_tbl_prescriptions' => 'prescription_id',
    ];

    private static ?bool $logTableExists = null;
    private static ?array $logTableColumns = null;

    public function handle(Request $request, Closure $next)
    {
        $startedAt = microtime(true);
        $beforeSnapshot = $this->captureBeforeSnapshot($request);

        try {
            $response = $next($request);
            $this->persistAuditLog($request, $response, null, $startedAt, $beforeSnapshot);

            return $response;
        } catch (Throwable $exception) {
            $this->persistAuditLog($request, null, $exception, $startedAt, $beforeSnapshot);
            throw $exception;
        }
    }

    private function persistAuditLog(
        Request $request,
        ?Response $response,
        ?Throwable $exception,
        float $startedAt,
        ?array $beforeSnapshot = null
    ): void {
        if (!$this->hasLogTable()) {
            return;
        }

        $statusCode = $response?->getStatusCode() ?? 500;
        $actionStatus = ($exception === null && $statusCode < 400) ? 'SUCCESS' : 'FAILED';

        [$eventGroup, $eventCode, $resourceName] = $this->resolveEvent($request);
        [$userId, $username, $role] = $this->resolveActor($request, $response);

        $routeParams = $request->route()?->parameters() ?? [];
        $requestData = $this->sanitizeData($request->all());
        $queryData = $this->sanitizeData($request->query());
        $responseData = $this->extractResponseData($response);
        $responseMessage = $this->extractResponseMessage($response);
        $entityId = $this->resolveEntityId(
            $this->resolveRouteEntityId($routeParams),
            is_array($requestData) ? $requestData : [],
            $responseData,
            $resourceName
        );

        $oldValues = $beforeSnapshot;
        $newValues = null;
        if (in_array(strtoupper($request->method()), self::IMPORTANT_METHODS, true)) {
            $newValues = is_array($requestData) ? $requestData : null;
        }

        $extraData = [
            'status_code' => $statusCode,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'last_event_code' => $eventCode,
            'last_event_group' => $eventGroup,
        ];

        if (!empty($responseMessage)) {
            $extraData['response_message'] = $responseMessage;
        }

        if ($exception) {
            $extraData['exception'] = class_basename($exception);
            $extraData['error'] = $exception->getMessage();
        }

        $summary = $this->buildImportantSummary(
            $request,
            $eventCode,
            $resourceName,
            $actionStatus,
            is_array($requestData) ? $requestData : [],
            $responseData,
            $responseMessage,
            is_array($oldValues) ? $oldValues : null,
            $entityId
        );

        if (
            !empty($summary) &&
            $userId !== null &&
            $this->shouldSummarizeIntoSession($request, $eventCode, $actionStatus)
        ) {
            $sessionKey = $this->resolveSessionKey($request, $responseData, $eventCode, $actionStatus);
            if (!empty($sessionKey)) {
                $this->upsertSessionSummary(
                    $request,
                    (int) $userId,
                    $username,
                    $role,
                    $sessionKey,
                    $summary,
                    $actionStatus,
                    $eventCode,
                    $queryData,
                    $oldValues,
                    $newValues,
                    $extraData
                );
                return;
            }
        }

        if (!$this->shouldStoreStandaloneRow($request, $eventCode, $actionStatus)) {
            return;
        }

        $description = $summary ?: (strtoupper($request->method()) . ' /' . trim($request->path(), '/'));
        if ($actionStatus === 'FAILED' && !empty($responseMessage)) {
            $description .= ' (' . $responseMessage . ')';
        }

        try {
            $this->insertAuditRow([
                'user_id' => $userId,
                'username' => $username ? mb_substr((string) $username, 0, 255) : null,
                'role' => $role ? mb_substr((string) $role, 0, 50) : null,
                'event_group' => mb_substr((string) $eventGroup, 0, 50),
                'event_code' => mb_substr((string) $eventCode, 0, 80),
                'action_status' => $actionStatus,
                'entity_table' => $this->resolveEntityTable($resourceName),
                'entity_id' => $entityId ? mb_substr((string) $entityId, 0, 64) : null,
                'description' => mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH),
                'request_method' => mb_substr(strtoupper($request->method()), 0, 10),
                'request_path' => mb_substr('/' . trim($request->path(), '/'), 0, 255),
                'request_query' => empty($queryData) ? null : $this->encodeJson($queryData),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'old_values' => empty($oldValues) ? null : $this->encodeJson($oldValues),
                'new_values' => empty($newValues) ? null : $this->encodeJson($newValues),
                'extra_data' => $this->encodeJson($extraData),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $ignored) {
            // Never break API responses due to audit logging failure.
        }
    }

    private function upsertSessionSummary(
        Request $request,
        int $userId,
        ?string $username,
        ?string $role,
        string $sessionKey,
        string $summary,
        string $actionStatus,
        string $eventCode,
        mixed $queryData,
        mixed $oldValues,
        mixed $newValues,
        array $extraData
    ): void {
        try {
            $existing = DB::table(self::LOG_TABLE)
                ->where('user_id', $userId)
                ->where('event_code', self::SESSION_EVENT_CODE)
                ->where('entity_id', $sessionKey)
                ->orderByDesc($this->hasLogColumn('created_at') ? 'created_at' : ($this->hasLogColumn('id') ? 'id' : 'entity_id'))
                ->first();

            if ($existing) {
                $existingExtra = $this->decodeJson($existing->extra_data ?? null);
                $actionCount = (int) (($existingExtra['action_count'] ?? 0) + 1);
                $currentStatus = strtoupper((string) ($existing->action_status ?? 'SUCCESS'));
                $nextStatus = ($currentStatus === 'FAILED' || $actionStatus === 'FAILED')
                    ? 'FAILED'
                    : 'SUCCESS';

                $mergedExtra = array_merge($existingExtra, $extraData, [
                    'action_count' => $actionCount,
                    'last_event_code' => $eventCode,
                    'last_event_at' => now()->toDateTimeString(),
                ]);

                $updatePayload = [
                    'username' => $username ? mb_substr((string) $username, 0, 255) : null,
                    'role' => $role ? mb_substr((string) $role, 0, 50) : null,
                    'action_status' => $nextStatus,
                    'description' => $this->appendSummary((string) ($existing->description ?? ''), $summary),
                    'request_method' => mb_substr(strtoupper($request->method()), 0, 10),
                    'request_path' => mb_substr('/' . trim($request->path(), '/'), 0, 255),
                    'request_query' => empty($queryData) ? null : $this->encodeJson($queryData),
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                    'old_values' => empty($oldValues) ? null : $this->encodeJson($oldValues),
                    'new_values' => empty($newValues) ? null : $this->encodeJson($newValues),
                    'extra_data' => $this->encodeJson($mergedExtra),
                    'updated_at' => now(),
                ];

                $this->updateExistingSessionRow($existing, $updatePayload, $userId, $sessionKey);
                return;
            }

            $insertPayload = [
                'user_id' => $userId,
                'username' => $username ? mb_substr((string) $username, 0, 255) : null,
                'role' => $role ? mb_substr((string) $role, 0, 50) : null,
                'event_group' => 'SESSION',
                'event_code' => self::SESSION_EVENT_CODE,
                'action_status' => $actionStatus,
                'entity_table' => self::SESSION_ENTITY_TABLE,
                'entity_id' => mb_substr($sessionKey, 0, 64),
                'description' => mb_substr($summary, 0, self::MAX_DESCRIPTION_LENGTH),
                'request_method' => mb_substr(strtoupper($request->method()), 0, 10),
                'request_path' => mb_substr('/' . trim($request->path(), '/'), 0, 255),
                'request_query' => empty($queryData) ? null : $this->encodeJson($queryData),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'old_values' => empty($oldValues) ? null : $this->encodeJson($oldValues),
                'new_values' => empty($newValues) ? null : $this->encodeJson($newValues),
                'extra_data' => $this->encodeJson(array_merge($extraData, [
                    'action_count' => 1,
                    'last_event_code' => $eventCode,
                    'last_event_at' => now()->toDateTimeString(),
                ])),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $this->insertAuditRow($insertPayload);
        } catch (Throwable $ignored) {
            // Never break API responses due to audit logging failure.
        }
    }

    private function updateExistingSessionRow(
        object $existing,
        array $payload,
        int $userId,
        string $sessionKey
    ): void {
        $filtered = $this->filterPayloadByLogColumns($payload);
        if (empty($filtered)) {
            return;
        }

        $query = DB::table(self::LOG_TABLE);
        if ($this->hasLogColumn('id') && isset($existing->id)) {
            $query->where('id', $existing->id);
        } elseif ($this->hasLogColumn('log_id') && isset($existing->log_id)) {
            $query->where('log_id', $existing->log_id);
        } else {
            $query->where('user_id', $userId)
                ->where('event_code', self::SESSION_EVENT_CODE)
                ->where('entity_id', $sessionKey);
        }

        $query->update($filtered);
    }

    private function insertAuditRow(array $payload): void
    {
        $filtered = $this->filterPayloadByLogColumns($payload);
        if (empty($filtered)) {
            return;
        }

        DB::table(self::LOG_TABLE)->insert($filtered);
    }

    private function shouldSummarizeIntoSession(
        Request $request,
        string $eventCode,
        string $actionStatus
    ): bool {
        if ($actionStatus === 'FAILED') {
            return true;
        }

        if (in_array($eventCode, ['LOGIN', 'LOGOUT', 'CHANGE_PASSWORD'], true)) {
            return true;
        }

        return in_array(strtoupper($request->method()), self::IMPORTANT_METHODS, true);
    }

    private function shouldStoreStandaloneRow(
        Request $request,
        string $eventCode,
        string $actionStatus
    ): bool {
        if ($actionStatus === 'FAILED') {
            return true;
        }

        if (in_array($eventCode, ['LOGIN', 'LOGOUT', 'CHANGE_PASSWORD'], true)) {
            return true;
        }

        return in_array(strtoupper($request->method()), self::IMPORTANT_METHODS, true);
    }

    private function buildImportantSummary(
        Request $request,
        string $eventCode,
        string $resourceName,
        string $actionStatus,
        array $requestData,
        array $responseData,
        ?string $responseMessage,
        ?array $oldValues,
        ?string $entityId
    ): ?string {
        if (!$this->shouldSummarizeIntoSession($request, $eventCode, $actionStatus)) {
            return null;
        }

        if ($eventCode === 'LOGIN') {
            return $actionStatus === 'SUCCESS' ? 'login' : 'failed login';
        }

        if ($eventCode === 'LOGOUT') {
            return $actionStatus === 'SUCCESS' ? 'logout' : 'failed logout';
        }

        if ($eventCode === 'CHANGE_PASSWORD') {
            return $actionStatus === 'SUCCESS' ? 'change password' : 'failed change password';
        }

        $resolvedEntityId = $entityId ?: $this->pickEntityIdFromResponse($responseData, $resourceName);
        $entityRef = $this->buildEntityReference($resourceName, $resolvedEntityId);
        $method = strtoupper($request->method());

        $summary = null;
        if ($method === 'POST') {
            $details = $this->summarizeAddedValues($resourceName, $requestData);
            $summary = 'add ' . $entityRef;
            if ($details !== '') {
                $summary .= ' [' . $details . ']';
            }
        } elseif (in_array($method, ['PUT', 'PATCH'], true)) {
            $details = $this->summarizeChangedValues($resourceName, $requestData, $oldValues);
            $summary = $eventCode === 'RELEASE_PRESCRIPTION'
                ? 'release ' . $entityRef
                : 'update ' . $entityRef;

            if ($details !== '') {
                $summary .= ' [' . $details . ']';
            }
        } elseif ($method === 'DELETE') {
            $details = $this->summarizeDeleteValues($resourceName, $oldValues);
            $summary = 'delete ' . $entityRef;
            if ($details !== '') {
                $summary .= ' [' . $details . ']';
            }
        }

        if ($summary === null) {
            return null;
        }

        if ($actionStatus === 'FAILED' && stripos($summary, 'failed ') !== 0) {
            $summary = 'failed ' . $summary;
        }

        if ($actionStatus === 'FAILED' && !empty($responseMessage)) {
            $summary .= ' (' . mb_substr((string) $responseMessage, 0, 120) . ')';
        }

        return mb_substr($summary, 0, 500);
    }

    private function hasLogTable(): bool
    {
        if (self::$logTableExists !== null) {
            return self::$logTableExists;
        }

        self::$logTableExists = Schema::hasTable(self::LOG_TABLE);
        if (!self::$logTableExists) {
            self::$logTableColumns = [];
            return false;
        }

        try {
            $columns = Schema::getColumnListing(self::LOG_TABLE);
            self::$logTableColumns = array_fill_keys($columns, true);
        } catch (Throwable $ignored) {
            self::$logTableColumns = [];
        }

        return self::$logTableExists;
    }

    private function hasLogColumn(string $column): bool
    {
        return isset($this->getLogColumnMap()[$column]);
    }

    private function getLogColumnMap(): array
    {
        $this->hasLogTable();
        return self::$logTableColumns ?? [];
    }

    private function filterPayloadByLogColumns(array $payload): array
    {
        $columns = $this->getLogColumnMap();
        if (empty($columns)) {
            return [];
        }

        $filtered = [];
        foreach ($payload as $key => $value) {
            if (isset($columns[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function resolveEvent(Request $request): array
    {
        $path = trim($request->path(), '/');
        $path = preg_replace('#^api/#', '', $path) ?? $path;
        $segments = array_values(array_filter(explode('/', $path), fn ($segment) => $segment !== ''));

        $first = strtolower($segments[0] ?? 'api');
        $second = strtolower($segments[1] ?? 'operation');
        $third = strtolower($segments[2] ?? '');
        $method = strtoupper($request->method());

        if (in_array($second, ['login', 'logout'], true)) {
            return ['AUTH', strtoupper($second), 'auth'];
        }

        if ($first === 'admin' && $second === 'account' && $third === 'change-password') {
            return ['AUTH', 'CHANGE_PASSWORD', 'account'];
        }

        $eventGroup = match ($first) {
            'bow' => 'BOW',
            'admin' => 'ADMIN',
            'collector' => 'COLLECTOR',
            'terminal' => 'TERMINAL',
            'private_transaction' => 'PRIVATE_TRANSACTION',
            'public-slaughter', 'public_slaughter' => 'PUBLIC_SLAUGHTER',
            default => strtoupper($first),
        };

        $resourceName = preg_match('/^\d+$/', $second) ? ($segments[2] ?? 'operation') : $second;
        $resourceCode = strtoupper(str_replace(['-', '.'], '_', $resourceName));

        $actionPrefix = match ($method) {
            'POST' => 'CREATE',
            'PUT', 'PATCH' => 'UPDATE',
            'DELETE' => 'DELETE',
            default => 'READ',
        };

        if ($resourceName === 'prescription' && $third === 'release') {
            $actionPrefix = 'RELEASE';
        } elseif ($resourceName === 'medicine' && $third === 'status') {
            $actionPrefix = 'TOGGLE_STATUS';
        }

        return [$eventGroup, $actionPrefix . '_' . $resourceCode, $resourceName];
    }

    private function resolveEntityTable(string $resourceName): ?string
    {
        $map = [
            'accounts' => 'users',
            'account' => 'users',
            'barangay' => 'bow_tbl_barangays',
            'purok' => 'bow_tbl_puroks',
            'patient' => 'bow_tbl_patients',
            'physician' => 'bow_tbl_physicians',
            'medicine' => 'bow_tbl_medicines',
            'prescription' => 'bow_tbl_prescriptions',
        ];

        return $map[strtolower($resourceName)] ?? null;
    }

    private function resolveRouteEntityId(array $routeParams): ?string
    {
        foreach ($routeParams as $value) {
            if (is_scalar($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function resolveEntityId(
        ?string $routeEntityId,
        array $requestData,
        array $responseData,
        string $resourceName
    ): ?string {
        if ($routeEntityId !== null && $routeEntityId !== '') {
            return $routeEntityId;
        }

        $fromResponse = $this->pickEntityIdFromResponse($responseData, $resourceName);
        if ($fromResponse !== null && $fromResponse !== '') {
            return $fromResponse;
        }

        return $this->pickEntityIdFromArray($requestData, $resourceName);
    }

    private function pickEntityIdFromResponse(array $responseData, string $resourceName): ?string
    {
        $dataBlock = $responseData['data'] ?? null;
        if (is_array($dataBlock) && $this->isAssocArray($dataBlock)) {
            $id = $this->pickEntityIdFromArray($dataBlock, $resourceName);
            if ($id !== null) {
                return $id;
            }
        }

        return $this->pickEntityIdFromArray($responseData, $resourceName);
    }

    private function pickEntityIdFromArray(array $data, string $resourceName): ?string
    {
        $resource = strtolower($resourceName);
        $candidates = self::RESOURCE_ID_KEYS[$resource] ?? [];
        $generic = [
            'id',
            'user_id',
            'patient_id',
            'prescription_id',
            'medicine_id',
            'physician_id',
            'barangay_id',
            'purok_id',
        ];

        foreach (array_values(array_unique(array_merge($candidates, $generic))) as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if (is_scalar($value) && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function summarizeAddedValues(string $resourceName, array $requestData): string
    {
        $fields = $this->extractImportantFields($resourceName, $requestData);
        if (empty($fields)) {
            return '';
        }

        $parts = [];
        foreach ($fields as $key => $value) {
            $parts[] = $this->prettyKey($key) . '=' . $this->valueToString($value);
            if (count($parts) >= 5) {
                break;
            }
        }

        return implode('; ', $parts);
    }

    private function summarizeChangedValues(
        string $resourceName,
        array $requestData,
        ?array $oldValues
    ): string {
        $fields = $this->extractImportantFields($resourceName, $requestData);
        if (empty($fields)) {
            return '';
        }

        $parts = [];
        foreach ($fields as $key => $newValue) {
            if (is_array($newValue) && isset($newValue['count'])) {
                $parts[] = $this->prettyKey($key) . ': ' . $this->valueToString($newValue);
                continue;
            }

            $oldValue = is_array($oldValues) ? ($oldValues[$key] ?? null) : null;
            if ($this->normalizeComparableValue($oldValue) === $this->normalizeComparableValue($newValue)) {
                continue;
            }

            $parts[] = $this->prettyKey($key) . ': '
                . $this->valueToString($oldValue)
                . ' -> '
                . $this->valueToString($newValue);

            if (count($parts) >= 5) {
                break;
            }
        }

        return implode('; ', $parts);
    }

    private function summarizeDeleteValues(string $resourceName, ?array $oldValues): string
    {
        if (empty($oldValues)) {
            return '';
        }

        $fields = $this->extractImportantFields($resourceName, $oldValues);
        if (empty($fields)) {
            return '';
        }

        $parts = [];
        foreach ($fields as $key => $value) {
            $parts[] = $this->prettyKey($key) . '=' . $this->valueToString($value);
            if (count($parts) >= 4) {
                break;
            }
        }

        return implode('; ', $parts);
    }

    private function extractImportantFields(string $resourceName, array $data): array
    {
        $resource = strtolower($resourceName);
        $allowedKeys = self::RESOURCE_KEYS[$resource] ?? [];
        $fields = [];

        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if ($this->isSensitiveKey($key) || $this->isIgnoredDetailKey($key)) {
                continue;
            }

            if (!empty($allowedKeys) && !in_array($key, $allowedKeys, true) && !in_array($key, ['items', 'prescription_items'], true)) {
                continue;
            }

            if (is_array($value)) {
                if (in_array($key, ['items', 'prescription_items'], true)) {
                    $fields[$key] = ['count' => count($value)];
                }
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $fields[$key] = $value;
            }
        }

        if (!empty($fields) || !empty($allowedKeys)) {
            return $fields;
        }

        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if ($this->isSensitiveKey($key) || $this->isIgnoredDetailKey($key)) {
                continue;
            }
            if (!(is_scalar($value) || $value === null)) {
                continue;
            }
            $fields[$key] = $value;
            if (count($fields) >= 5) {
                break;
            }
        }

        return $fields;
    }

    private function buildEntityReference(string $resourceName, ?string $entityId): string
    {
        $resource = $this->singularResource($resourceName);
        if ($entityId === null || $entityId === '') {
            return $resource;
        }

        return $resource . '#' . $entityId;
    }

    private function singularResource(string $resourceName): string
    {
        $normalized = strtolower(trim(str_replace(['_', '-'], ' ', $resourceName)));
        $map = [
            'accounts' => 'account',
            'patients' => 'patient',
            'physicians' => 'physician',
            'medicines' => 'medicine',
            'barangays' => 'barangay',
            'puroks' => 'purok',
            'prescriptions' => 'prescription',
        ];

        return $map[$normalized] ?? $normalized;
    }

    private function appendSummary(string $existing, string $next): string
    {
        $existing = trim($existing);
        $next = trim($next);

        if ($existing === '') {
            return mb_substr($next, 0, self::MAX_DESCRIPTION_LENGTH);
        }

        $suffix = ', ' . $next;
        if (str_ends_with($existing, $suffix) || $existing === $next) {
            return mb_substr($existing, 0, self::MAX_DESCRIPTION_LENGTH);
        }

        $combined = $existing . $suffix;
        if (mb_strlen($combined) <= self::MAX_DESCRIPTION_LENGTH) {
            return $combined;
        }

        return '... ' . mb_substr($combined, -1 * (self::MAX_DESCRIPTION_LENGTH - 4));
    }

    private function captureBeforeSnapshot(Request $request): ?array
    {
        $method = strtoupper($request->method());
        if (!in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        [, , $resourceName] = $this->resolveEvent($request);
        $table = $this->resolveEntityTable($resourceName);
        if (empty($table) || !Schema::hasTable($table)) {
            return null;
        }

        $routeParams = $request->route()?->parameters() ?? [];
        $entityId = $this->resolveRouteEntityId($routeParams);
        if ($entityId === null || $entityId === '') {
            return null;
        }

        $primaryKey = $this->resolvePrimaryKey($table);
        if ($primaryKey === null || !Schema::hasColumn($table, $primaryKey)) {
            return null;
        }

        $row = DB::table($table)->where($primaryKey, $entityId)->first();
        if (!$row) {
            return null;
        }

        return $this->sanitizeData((array) $row);
    }

    private function resolvePrimaryKey(string $table): ?string
    {
        if (isset(self::PRIMARY_KEYS[$table])) {
            return self::PRIMARY_KEYS[$table];
        }

        if (Schema::hasColumn($table, 'id')) {
            return 'id';
        }

        return null;
    }

    private function resolveSessionKey(
        Request $request,
        array $responseData,
        string $eventCode,
        string $actionStatus
    ): ?string {
        $token = null;

        if ($eventCode === 'LOGIN' && $actionStatus === 'SUCCESS') {
            $token = $this->extractTokenFromResponse($responseData);
        }

        if (empty($token)) {
            $token = $request->bearerToken();
        }

        if (!is_string($token) || trim($token) === '') {
            return null;
        }

        return hash('sha256', trim($token));
    }

    private function extractTokenFromResponse(array $responseData): ?string
    {
        $dataBlock = $responseData['data'] ?? null;
        if (is_array($dataBlock) && isset($dataBlock['token']) && is_string($dataBlock['token'])) {
            return trim($dataBlock['token']);
        }

        if (isset($responseData['token']) && is_string($responseData['token'])) {
            return trim($responseData['token']);
        }

        return null;
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $ignored) {
            return null;
        }
    }

    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function prettyKey(string $key): string
    {
        return strtolower(str_replace('_', ' ', $key));
    }

    private function valueToString(mixed $value): string
    {
        if (is_array($value)) {
            if (isset($value['count'])) {
                return (string) ((int) $value['count']) . ' item(s)';
            }
            return '[array]';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $string = trim((string) $value);
        $string = preg_replace('/\s+/', ' ', $string) ?? $string;
        if (mb_strlen($string) > 60) {
            return mb_substr($string, 0, 57) . '...';
        }

        return $string;
    }

    private function normalizeComparableValue(mixed $value): string
    {
        if (is_array($value)) {
            return $this->encodeJson($value) ?? '';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            return (string) ($value + 0);
        }

        return strtolower(trim((string) $value));
    }

    private function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }

    private function isIgnoredDetailKey(string $key): bool
    {
        return in_array(strtolower($key), self::IGNORED_DETAIL_KEYS, true);
    }

    private function isAssocArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function resolveActor(Request $request, ?Response $response): array
    {
        $user = $request->user();
        if ($user) {
            $userRole = data_get($user, 'role');
            if (empty($userRole)) {
                $userRole = strtolower(class_basename($user));
            }

            return [
                data_get($user, 'id'),
                data_get($user, 'username') ?: data_get($user, 'name') ?: data_get($user, 'email'),
                $userRole,
            ];
        }

        $responseData = $this->extractResponseData($response);
        $dataBlock = is_array($responseData['data'] ?? null) ? $responseData['data'] : [];

        return [
            $dataBlock['id'] ?? null,
            $dataBlock['username'] ?? $request->input('username') ?? $request->input('email'),
            $dataBlock['role'] ?? null,
        ];
    }

    private function extractResponseData(?Response $response): array
    {
        if (!$response) {
            return [];
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            return is_array($data) ? $data : [];
        }

        $content = $response->getContent();
        if (!$content) {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function extractResponseMessage(?Response $response): ?string
    {
        $data = $this->extractResponseData($response);
        $message = $data['message'] ?? $data['error'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    private function sanitizeData(mixed $data): mixed
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                    $sanitized[$key] = '***';
                    continue;
                }
                $sanitized[$key] = $this->sanitizeData($value);
            }

            return $sanitized;
        }

        if (is_object($data)) {
            if ($data instanceof \JsonSerializable) {
                return $this->sanitizeData($data->jsonSerialize());
            }

            return method_exists($data, '__toString')
                ? (string) $data
                : get_class($data);
        }

        return $data;
    }
}
