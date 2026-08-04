<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowRecipient;
use App\Support\BowScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HouseholdController extends Controller
{
    public function show(Request $request, int $id)
    {
        $recipient = $this->findAccessibleRecipient($request, $id);

        return response()->json([
            'success' => true,
            'data' => $this->householdForRecipient($recipient->recipient_id),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $recipient = $this->findAccessibleRecipient($request, $id);
        $validated = $request->validate([
            'members' => ['present', 'array', 'max:100'],
            'members.*.recipient_id' => ['required', 'integer', 'distinct'],
            'members.*.relationship_to_head' => ['nullable', 'string', 'max:80'],
        ]);

        $membersByRecipientId = collect($validated['members'])
            ->keyBy(fn (array $member) => (int) $member['recipient_id']);
        $existingHouseholdId = DB::table('bow_tbl_household_members')
            ->where('recipient_id', $recipient->recipient_id)
            ->value('household_id');
        $household = $existingHouseholdId
            ? DB::table('bow_tbl_households')->where('household_id', $existingHouseholdId)->first()
            : null;

        if ($existingHouseholdId && !$household) {
            DB::table('bow_tbl_household_members')->where('recipient_id', $recipient->recipient_id)->delete();
            $existingHouseholdId = null;
        }

        $headRecipientId = $household
            ? (int) $household->household_head_recipient_id
            : (int) $recipient->recipient_id;
        $membersByRecipientId->put($headRecipientId, [
            'recipient_id' => $headRecipientId,
            'relationship_to_head' => 'Household head',
        ]);

        $recipientIds = $membersByRecipientId->keys()->map(fn ($memberId) => (int) $memberId)->values()->all();
        $selectedRecipients = BowRecipient::query()
            ->whereIn('recipient_id', $recipientIds)
            ->get(['recipient_id', 'barangay']);

        if ($selectedRecipients->count() !== count($recipientIds)) {
            throw ValidationException::withMessages([
                'members' => ['One or more selected voter records no longer exist.'],
            ]);
        }

        $selectedRecipients->each(fn (BowRecipient $selectedRecipient) => $this->ensureRecipientAccess($request, $selectedRecipient));

        $assignedElsewhere = DB::table('bow_tbl_household_members')
            ->whereIn('recipient_id', $recipientIds)
            ->when($existingHouseholdId, fn ($query) => $query->where('household_id', '<>', $existingHouseholdId))
            ->exists();

        if ($assignedElsewhere) {
            throw ValidationException::withMessages([
                'members' => ['A selected voter already belongs to another household. Remove or transfer that voter first.'],
            ]);
        }

        $now = now();
        $householdId = DB::transaction(function () use ($existingHouseholdId, $headRecipientId, $membersByRecipientId, $now) {
            $householdId = $existingHouseholdId;

            if (!$householdId) {
                $householdId = DB::table('bow_tbl_households')->insertGetId([
                    'household_head_recipient_id' => $headRecipientId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('bow_tbl_households')->where('household_id', $householdId)->update(['updated_at' => $now]);
                DB::table('bow_tbl_household_members')->where('household_id', $householdId)->delete();
            }

            $rows = $membersByRecipientId->map(function (array $member, int $memberRecipientId) use ($householdId, $headRecipientId, $now) {
                return [
                    'household_id' => $householdId,
                    'recipient_id' => $memberRecipientId,
                    'relationship_to_head' => $memberRecipientId === $headRecipientId
                        ? 'Household head'
                        : $this->relationshipLabel($member['relationship_to_head'] ?? null),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->values()->all();

            DB::table('bow_tbl_household_members')->insert($rows);

            return $householdId;
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Household updated successfully.',
            'data' => $this->householdById($householdId),
        ]);
    }

    private function householdForRecipient(int $recipientId): ?array
    {
        $householdId = DB::table('bow_tbl_household_members')
            ->where('recipient_id', $recipientId)
            ->value('household_id');

        return $householdId ? $this->householdById((int) $householdId) : null;
    }

    private function householdById(int $householdId): ?array
    {
        $household = DB::table('bow_tbl_households')
            ->where('household_id', $householdId)
            ->first();

        if (!$household) {
            return null;
        }

        $relationships = DB::table('bow_tbl_household_members')
            ->where('household_id', $householdId)
            ->pluck('relationship_to_head', 'recipient_id');
        $recipients = BowRecipient::query()
            ->whereIn('recipient_id', $relationships->keys()->all())
            ->get()
            ->keyBy('recipient_id');
        $headRecipientId = (int) $household->household_head_recipient_id;
        $members = $recipients->map(function (BowRecipient $recipient) use ($relationships, $headRecipientId) {
            $recipientId = (int) $recipient->recipient_id;

            return [
                'recipient_id' => $recipientId,
                'voters_id_number' => $recipient->voters_id_number,
                'first_name' => $recipient->first_name,
                'middle_name' => $recipient->middle_name,
                'last_name' => $recipient->last_name,
                'extension' => $recipient->extension,
                'relationship_to_head' => $recipientId === $headRecipientId
                    ? 'Household head'
                    : (string) ($relationships[$recipientId] ?? 'Other'),
                'is_head' => $recipientId === $headRecipientId,
            ];
        })->values()->all();

        usort($members, function (array $left, array $right): int {
            if ($left['is_head'] !== $right['is_head']) {
                return $left['is_head'] ? -1 : 1;
            }

            return strcasecmp(
                trim(sprintf('%s %s', $left['last_name'], $left['first_name'])),
                trim(sprintf('%s %s', $right['last_name'], $right['first_name']))
            );
        });

        return [
            'household_id' => (int) $household->household_id,
            'household_head_recipient_id' => $headRecipientId,
            'members' => $members,
        ];
    }

    private function findAccessibleRecipient(Request $request, int $recipientId): BowRecipient
    {
        $recipient = BowRecipient::query()->findOrFail($recipientId);
        $this->ensureRecipientAccess($request, $recipient);

        return $recipient;
    }

    private function ensureRecipientAccess(Request $request, BowRecipient $recipient): void
    {
        $allowedBarangayIds = BowScope::allowedBarangayIds($request->user());
        if ($allowedBarangayIds === null) {
            return;
        }

        if (!in_array((int) $recipient->barangay, $allowedBarangayIds, true)) {
            throw new HttpException(403, 'You are not allowed to access this voter.');
        }
    }

    private function relationshipLabel(?string $relationship): string
    {
        $normalized = trim((string) $relationship);

        return $normalized !== '' ? $normalized : 'Other household member';
    }
}
