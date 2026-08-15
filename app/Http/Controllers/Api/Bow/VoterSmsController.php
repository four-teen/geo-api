<?php

namespace App\Http\Controllers\Api\Bow;

use App\Http\Controllers\Controller;
use App\Models\BowRecipient;
use App\Services\PhilSmsService;
use App\Support\BowScope;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VoterSmsController extends Controller
{
    public function store(Request $request, int $id, PhilSmsService $philSms)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:160'],
        ]);

        $recipient = BowRecipient::query()->findOrFail($id);
        $this->ensureRecipientAccess($request, $recipient);

        $phoneNumber = trim((string) $recipient->phone_number);
        if ($phoneNumber === '') {
            throw ValidationException::withMessages([
                'phone_number' => ['This voter does not have a phone number.'],
            ]);
        }

        try {
            $result = $philSms->send($phoneNumber, trim((string) $validated['message']));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'phone_number' => [$exception->getMessage()],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'SMS sent successfully.',
            'data' => $result,
        ]);
    }

    private function ensureRecipientAccess(Request $request, BowRecipient $recipient): void
    {
        $allowedBarangayIds = BowScope::allowedBarangayIds($request->user());
        if ($allowedBarangayIds === null) {
            return;
        }

        $barangayId = (int) ($recipient->barangay ?? 0);
        if ($barangayId <= 0 || !in_array($barangayId, $allowedBarangayIds, true)) {
            throw new HttpException(403, 'You are not allowed to access this voter.');
        }
    }
}
