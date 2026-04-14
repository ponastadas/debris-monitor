<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    /**
     * List audit log entries with optional filtering.
     *
     * Supported query params:
     *   action    — exact action string (e.g. "login.failed")
     *   admin_id  — filter to a specific admin_account_id
     *   from      — created_at >= this date (YYYY-MM-DD)
     *   to        — created_at <= end of this date (YYYY-MM-DD)
     *   page      — pagination (50 per page)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'action'   => ['nullable', 'string', 'max:64'],
            'admin_id' => ['nullable', 'integer'],
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date'],
        ]);

        $logs = AdminAuditLog::with('admin')
            ->when($request->action,   fn ($q, $a)  => $q->forAction($a))
            ->when($request->admin_id, fn ($q, $id) => $q->forActor((int) $id))
            ->when($request->from,     fn ($q, $d)  => $q->whereDate('created_at', '>=', $d))
            ->when($request->to,       fn ($q, $d)  => $q->whereDate('created_at', '<=', $d))
            ->latest('created_at')
            ->paginate(50)
            ->through(fn (AdminAuditLog $log) => [
                'id'          => $log->id,
                'admin_id'    => $log->admin_account_id,
                'admin_email' => $log->admin?->email,
                'admin_name'  => $log->admin?->name,
                'action'      => $log->action,
                'target_type' => $log->target_type,
                'target_id'   => $log->target_id,
                'metadata'    => $log->metadata,   // safe: metadata must never contain secrets (enforced at write time)
                'ip'          => $log->ip,
                'created_at'  => $log->created_at->toIso8601String(),
            ]);

        return $this->success($logs);
    }
}
