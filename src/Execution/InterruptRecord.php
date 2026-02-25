<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Execution;

use Illuminate\Database\Eloquent\Model;

class InterruptRecord extends Model
{
    protected $table = 'agenticgraph_interrupts';

    protected $fillable = [
        'execution_id',
        'checkpoint_id',
        'graph_name',
        'node_name',
        'reason',
        'interrupt_payload',
        'status',
        'resolution',
        'resolved_at',
        'resolved_by',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'interrupt_payload' => 'array',
        'resolution' => 'array',
        'metadata' => 'array',
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function scopePending(mixed $query): mixed
    {
        return $query->where('status', 'pending');
    }

    public function scopeResolving(mixed $query): mixed
    {
        return $query->where('status', 'resolving');
    }

    public function scopeResolved(mixed $query): mixed
    {
        return $query->where('status', 'resolved');
    }

    public function scopeExpired(mixed $query): mixed
    {
        return $query->where('status', 'expired');
    }

    public function scopeForExecution(mixed $query, string $executionId): mixed
    {
        return $query->where('execution_id', $executionId);
    }

    public function scopeForGraph(mixed $query, string $graphName): mixed
    {
        return $query->where('graph_name', $graphName);
    }

    public function scopeForUser(mixed $query, string $userId): mixed
    {
        return $query->where('metadata->user_id', $userId);
    }

    public function interruptId(): ?int
    {
        $id = $this->getAttribute('id');
        if (is_int($id)) {
            return $id;
        }
        if (is_numeric($id)) {
            return (int) $id;
        }

        return null;
    }

    public function executionId(): ?string
    {
        $value = $this->getAttribute('execution_id');

        return is_string($value) ? $value : null;
    }

    public function checkpointId(): ?string
    {
        $value = $this->getAttribute('checkpoint_id');

        return is_string($value) ? $value : null;
    }

    public function graphName(): ?string
    {
        $value = $this->getAttribute('graph_name');

        return is_string($value) ? $value : null;
    }

    public function nodeName(): ?string
    {
        $value = $this->getAttribute('node_name');

        return is_string($value) ? $value : null;
    }

    public function reason(): ?string
    {
        $value = $this->getAttribute('reason');

        return is_string($value) ? $value : null;
    }

    public function status(): ?string
    {
        $value = $this->getAttribute('status');

        return is_string($value) ? $value : null;
    }

    public function interruptPayload(): mixed
    {
        $payload = $this->getAttribute('interrupt_payload');

        return is_array($payload) ? $payload : null;
    }

    public function metadata(): mixed
    {
        $metadata = $this->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }

    public function markResolving(): self
    {
        $this->update([
            'status' => 'resolving',
        ]);

        return $this->refresh();
    }

    public function markPending(mixed $metadata = null): self
    {
        $existing = $this->metadata();
        $incoming = is_array($metadata) ? $metadata : [];

        $this->update([
            'status' => 'pending',
            'metadata' => $incoming === [] ? $existing : array_merge($existing, $incoming),
        ]);

        return $this->refresh();
    }

    public function resolve(mixed $resolution, ?string $by = null): self
    {
        $payload = is_array($resolution) ? $resolution : [$resolution];

        $this->update([
            'status' => 'resolved',
            'resolution' => $payload,
            'resolved_by' => $by,
            'resolved_at' => now(),
        ]);

        return $this->refresh();
    }

    public function newEloquentBuilder($query)
    {
        return parent::newEloquentBuilder($query);
    }
}
