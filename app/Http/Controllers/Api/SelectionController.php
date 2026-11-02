<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidSelectionTokenException;
use App\Exceptions\SelectionTokenForbiddenException;
use App\Http\Requests\SelectSuggestionRequest;
use App\Models\Record;
use App\Services\Usage\SelectionTokenService;
use App\Services\Usage\UsageRecorder;
use Illuminate\Http\JsonResponse;

/**
 * Handles the only billable endpoint: selecting a suggestion.
 *
 * The request is accepted only with a valid, unexpired, client-bound signed
 * token. Usage recording is idempotent per token.
 */
final class SelectionController extends ApiController
{
    /**
     * @param SelectionTokenService $tokens Token verifier.
     * @param UsageRecorder $recorder Idempotent usage recorder.
     */
    public function __construct(
        private readonly SelectionTokenService $tokens,
        private readonly UsageRecorder $recorder,
    ) {}

    /**
     * Verify the token, return the record and record one billable usage.
     *
     * @param SelectSuggestionRequest $request Validated request.
     * @return JsonResponse
     */
    public function __invoke(SelectSuggestionRequest $request): JsonResponse
    {
        $client = $this->client($request);

        try {
            $claims = $this->tokens->verify(
                (string) $request->validated('selection_token'),
                $client->id,
            );
        } catch (SelectionTokenForbiddenException $exception) {
            $this->logRejection($client->id, $exception);

            return response()->json(['message' => 'Selection token belongs to another client.'], 403);
        } catch (InvalidSelectionTokenException $exception) {
            $this->logRejection($client->id, $exception);

            return response()->json(['message' => 'Invalid or expired selection token.'], 422);
        }

        $record = Record::query()->find($claims->recordId);

        if ($record === null) {
            return response()->json(['message' => 'Selected record no longer exists.'], 404);
        }

        $usage = $this->recorder->record($client, $claims, $request);

        return response()->json([
            'data' => [
                'id' => (int) $record->id,
                'content' => (string) $record->content,
            ],
            'usage' => [
                'counted' => $usage->counted,
                'billable_usage_count' => $usage->billableUsageCount,
            ],
        ]);
    }

    /**
     * Log a rejected selection without exposing the token itself.
     *
     * @param int $clientId Authenticated client id.
     * @param InvalidSelectionTokenException $exception Rejection reason.
     * @return void
     */
    private function logRejection(int $clientId, InvalidSelectionTokenException $exception): void
    {
        logger()->info('Selection token rejected', [
            'api_client_id' => $clientId,
            'reason' => $exception->getMessage(),
        ]);
    }
}
