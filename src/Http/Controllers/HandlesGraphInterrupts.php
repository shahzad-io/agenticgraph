<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shahzad\AgenticGraph\Execution\InterruptRecord;
use Shahzad\AgenticGraph\Facades\AgenticGraph;

trait HandlesGraphInterrupts
{
    protected function interruptQuery(Request $request, mixed $query): mixed
    {
        return $query;
    }

    public function listInterrupts(Request $request): JsonResponse
    {
        $query = InterruptRecord::query();

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $executionId = $request->query('execution_id');
        if (is_string($executionId) && $executionId !== '') {
            $query->where('execution_id', $executionId);
        }

        $graphName = $request->query('graph_name');
        if (is_string($graphName) && $graphName !== '') {
            $query->where('graph_name', $graphName);
        }

        $nodeName = $request->query('node_name');
        if (is_string($nodeName) && $nodeName !== '') {
            $query->where('node_name', $nodeName);
        }

        $userId = $request->query('user_id');
        if (is_string($userId) && $userId !== '') {
            $query->where('metadata->user_id', $userId);
        }

        $query = $this->interruptQuery($request, $query);

        $limit = (int) $request->query('limit', 50);
        if ($limit < 1) {
            $limit = 50;
        }
        $limit = min($limit, 200);

        $interrupts = $query->latest('id')->limit($limit)->get();

        return response()->json([
            'data' => $interrupts,
        ]);
    }

    public function showInterrupt(string $executionId): JsonResponse
    {
        $record = InterruptRecord::query()
            ->forExecution($executionId)
            ->latest('id')
            ->first();

        if (! $record) {
            return response()->json(['message' => 'Interrupt not found.'], 404);
        }

        return response()->json([
            'data' => $record,
        ]);
    }

    public function resolveInterrupt(string $executionId, Request $request): JsonResponse
    {
        $input = $request->input('input');
        $result = AgenticGraph::resume($executionId, $input);

        // Check if interrupt was resolved (job completed synchronously) or is still pending
        $interruptStatus = $result->interrupt?->status();
        $stillPending = $interruptStatus === 'pending';

        return response()->json([
            'execution_id' => $result->handle->id(),
            'status' => $result->handle->status()->name,
            'already_resolved' => $result->alreadyResolved,
            'interrupt_still_pending' => $stillPending,
            'interrupt' => $result->interrupt?->toArray(),
        ]);
    }
}

final class InterruptController
{
    use HandlesGraphInterrupts;
}
