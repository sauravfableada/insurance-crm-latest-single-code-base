<?php

namespace App\Services;

use App\Models\{Policy, Customer, Lead, FollowUp, Task, Meeting, ClaimTracker, CommissionEntry, Investment, User};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{Schema, Route};

class InsuranceAssistant
{
    public function topics(): array
    {
        return [
            'overview' => ['Insurance overview', null, null, null],
            'renewals' => ['Renewals', Policy::class, 'policies', 'policies.legacy.index'],
            'policies' => ['Policies', Policy::class, 'policies', 'policies.legacy.index'],
            'claims' => ['Claims', ClaimTracker::class, 'claim_tracker', 'claim-tracker.index'],
            'commissions' => ['Commissions', CommissionEntry::class, 'commission_rules', 'commission-rules.entries.index'],
            'customers' => ['Clients', Customer::class, 'customers', 'masters.customers.index'],
            'leads' => ['Leads', Lead::class, 'leads', 'leads.index'],
            'followups' => ['Follow-ups', FollowUp::class, 'followups', 'followups.index'],
            'tasks' => ['Tasks', Task::class, 'tasks', 'tasks.index'],
            'meetings' => ['Meetings', Meeting::class, 'meetings', 'meetings.index'],
            'investments' => ['Investments', Investment::class, 'investments', 'investment.index'],
        ];
    }

    public function allowed(string $topic, User $user): bool
    {
        $module = $this->topics()[$topic][2] ?? null;
        if (!$module) return $topic === 'overview';
        $visibility = app(MenuVisibility::class);
        if (array_key_exists($module, $visibility->catalog()) && !$visibility->enabled($module)) return false;
        return $user->isAdmin() || $user->hasMatrixPermission('view_' . $module);
    }

    public function intent(string $message): array
    {
        $text = strtolower(trim($message));
        $topic = null;
        if (preg_match('/^fetch_(\w+?)_(today|this_week|this_month|all|next_30_days|expired)$/', $text, $m)) {
            return [$m[1], $m[2], ''];
        }
        foreach ([
            'renewals' => '/renew|expir|laps/', 'claims' => '/claim/',
            'commissions' => '/commission|pay.?out|pay.?in/', 'policies' => '/polic|premium/',
            'customers' => '/client|customer/', 'leads' => '/lead/',
            'followups' => '/follow.?up/', 'tasks' => '/task/', 'meetings' => '/meeting/',
            'investments' => '/invest/', 'overview' => '/overview|summary|dashboard|report/',
        ] as $key => $pattern) {
            if (preg_match($pattern, $text)) { $topic = $key; break; }
        }
        $period = match (true) {
            str_contains($text, 'expired'), str_contains($text, 'lapsed') => 'expired',
            str_contains($text, 'today') => 'today',
            str_contains($text, 'this week') => 'this_week',
            str_contains($text, 'this month') => 'this_month',
            default => $topic === 'renewals' ? 'next_30_days' : 'all',
        };
        if ($topic === 'renewals' && preg_match('/next\s+(7|15|30|60|90)\s+days?/', $text, $m)) $period = 'next_' . $m[1] . '_days';
        return [$topic, $period, $text];
    }

    public function answer(string $message, User $user): array
    {
        [$topic, $period, $text] = $this->intent($message);
        if (!$topic || !isset($this->topics()[$topic])) return ['reply' => 'Choose a topic or try “renewals next 30 days”, “expired policies”, “pending claims”, “unpaid commissions”, or “follow-ups today”. You can find a policy with “policy number ABC123” or a client with “find client Ravi”.'];
        if (!$this->allowed($topic, $user)) return ['reply' => 'This module is disabled or your account does not have permission to view it.'];
        if ($topic === 'overview') {
            $data = [];
            foreach (['renewals', 'policies', 'claims', 'customers', 'leads', 'tasks'] as $key) {
                if (!$this->allowed($key, $user)) continue;
                $query = $this->query($key, $user);
                if (!$query) continue;
                if ($key === 'renewals') $this->dates($query, 'end_date', 'next_30_days');
                $data[] = ['name' => $this->topics()[$key][0], 'status' => $key === 'renewals' ? 'Next 30 days' : 'All records', 'total' => $query->count(), 'url' => $this->url($key)];
            }
            return ['reply' => 'Your live insurance overview. Counts include only records you can access.', 'data' => $data];
        }
        $query = $this->query($topic, $user);
        if (!$query) return ['reply' => 'This module is not available in this installation yet.'];
        $table = $query->getModel()->getTable();
        $columns = Schema::getColumnListing($table);
        $date = match ($topic) {
            'renewals' => 'end_date', 'policies' => $period === 'expired' ? 'end_date' : 'created_at',
            'followups' => 'follow_up_at', 'tasks' => 'due_date', 'meetings' => 'scheduled_at',
            'commissions' => 'entry_date', 'claims' => 'claim_file_received_date', default => 'created_at',
        };
        if ($period !== 'all') {
            if (!in_array($date, $columns)) return ['reply' => 'The date field needed for this filter is not available. Open the module to review these records.'];
            $this->dates($query, $date, $period);
        }
        $status = $topic === 'claims' ? 'claim_status' : ($topic === 'commissions' ? 'payment_status' : 'status');
        if (in_array($status, $columns)) {
            if (preg_match('/\b(pending|unpaid|paid|active|inactive|completed|approved|rejected)\b/', $text, $m)) {
                $value = $m[1] === 'unpaid' ? 'pending' : $m[1];
                $query->whereRaw('LOWER(' . $status . ') = ?', [$value]);
            }
        }
        if (preg_match('/(?:policy\s+(?:number|no\.?|#)|(?:find|search)\s+(?:client|customer)|(?:client|customer)\s+named)\s+(.+)/i', $text, $m)) {
            $field = in_array($topic, ['policies', 'renewals']) ? 'policy_number_no' : 'name';
            if (in_array($field, $columns)) $query->where($field, 'like', '%' . trim($m[1]) . '%');
        }
        $total = (clone $query)->count();
        $records = $query->orderBy(in_array($date, $columns) ? $date : 'id', in_array($topic, ['renewals', 'tasks', 'followups', 'meetings']) ? 'asc' : 'desc')->limit(10)->get();
        $data = $records->map(fn ($record) => $this->format($topic, $record))->all();
        $range = str_replace('_', ' ', $period);
        return ['reply' => $total ? "{$this->topics()[$topic][0]} · {$range}: {$total} matching records. Showing " . count($data) . '. Open the full list for more details.' : "No matching {$this->topics()[$topic][0]} found ({$range}). Try a wider date range.", 'data' => $data, 'url' => $this->url($topic), 'total' => $total];
    }

    private function query(string $topic, User $user): ?Builder
    {
        $model = $this->topics()[$topic][1];
        $query = $model::query();
        $table = $query->getModel()->getTable();
        if (!Schema::hasTable($table)) return null;
        $columns = Schema::getColumnListing($table);
        if (in_array('deleted_at', $columns)) $query->whereNull($table . '.deleted_at');
        if (in_array($topic, ['policies', 'renewals'])) $query->currentTerms()->with(['holder', 'company', 'policyType']);
        if ($topic === 'claims') $query->with('policy.holder');
        if ($topic === 'commissions') $query->with('policy');
        if (!$user->isAdmin()) {
            if ($topic === 'customers') return $query->visibleTo($user);
            $owners = array_values(array_intersect(['user_id', 'created_by', 'assigned_user_id'], $columns));
            if (!$owners) return $query->whereRaw('1 = 0');
            $query->where(function ($q) use ($owners, $user, $table) {
                foreach ($owners as $column) $q->orWhere($table . '.' . $column, $user->id);
            });
        }
        return $query;
    }

    private function dates(Builder $query, string $column, string $period): void
    {
        if ($period === 'expired') { $query->whereDate($column, '<', today()); return; }
        [$start, $end] = match ($period) {
            'today' => [today(), today()->endOfDay()],
            'this_week' => [now()->startOfWeek(), now()->endOfWeek()],
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [today(), today()->addDays((int) (explode('_', $period)[1] ?? 30))->endOfDay()],
        };
        $query->whereDate($column, '>=', $start->toDateString())->whereDate($column, '<=', $end->toDateString());
    }

    private function format(string $topic, $record): array
    {
        $data = ['id' => $record->id, 'name' => $record->name ?? $record->title ?? $record->purpose ?? $record->document_number ?? ($this->topics()[$topic][0] . ' #' . $record->id), 'status' => $record->status ?? $record->claim_status ?? $record->payment_status ?? null, 'url' => $this->url($topic), 'type' => $topic];
        $detailRoute = match ($topic) {
            'customers' => 'masters.customers.show', 'claims' => 'claim-tracker.show',
            'investments' => 'investment.show', 'leads' => 'leads.show',
            'followups' => 'followups.show', 'tasks' => 'tasks.show', 'meetings' => 'meetings.show',
            default => null,
        };
        if ($detailRoute && Route::has($detailRoute)) $data['url'] = route($detailRoute, $record->id);
        foreach (['email', 'phone', 'priority', 'due_date', 'scheduled_at', 'follow_up_at'] as $field) $data[$field === 'follow_up_at' ? 'follow_up_date' : $field] = $record->getAttribute($field);
        if (in_array($topic, ['policies', 'renewals'])) {
            $data = array_merge($data, ['name' => $record->policy_number_no ?: 'Policy #' . $record->id, 'customer_name' => $record->holder?->name, 'company_name' => $record->company?->name, 'policy_type' => $record->policyType?->name, 'premium' => $record->premium_amount, 'start_date' => $record->start_date, 'end_date' => $record->end_date]);
            if ($record->end_date) $data['status'] = \Illuminate\Support\Carbon::parse($record->end_date)->lt(today()) ? 'Expired' : 'Current term';
            if (Route::has('policies.legacy.view')) $data['url'] = route('policies.legacy.view', $record->id);
        }
        if ($topic === 'claims') $data += ['customer_name' => $record->policy?->holder?->name, 'policy_number' => $record->policy?->policy_number_no, 'amount' => $record->claim_amount];
        if ($topic === 'commissions') $data += ['policy_number' => $record->policy?->policy_number_no, 'amount' => $record->commission_amount, 'premium' => $record->premium_amount];
        return $data;
    }

    private function url(string $topic): ?string
    {
        $route = $this->topics()[$topic][3];
        return $route && Route::has($route) ? route($route) : null;
    }
}
