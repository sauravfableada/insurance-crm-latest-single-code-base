<?php

namespace App\Services;

use App\Models\{Customer, User, Policy, FamilyGroup, ClaimTracker, ClientDocument, Investment, Endorsement, Task, Project};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CustomerDashboard
{
    public function build(Customer $customer, User $user, int $days = 60): array
    {
        $can = fn (string $module) => $user->isAdmin() || $user->hasMatrixPermission('view_'.$module);
        $policyMembersAvailable = !$can('policies') || Schema::hasTable('policy_members');
        $policyRelations = ['holder', 'policyType', 'company', 'pdfHistories'];
        if ($policyMembersAvailable) $policyRelations[] = 'members';
        $policyQuery = fn () => Policy::query()->whereNull('deleted_at')
            ->when(!$user->isAdmin() || $user->type === 'sub_agents', fn ($q) => $q->where(
                fn ($owned) => $owned->where('user_id', $user->id)->orWhere('created_by', $user->id)
            ));

        $policies = $can('policies') ? $policyQuery()->where('holder_id', $customer->id)
            ->with($policyRelations)->orderByDesc('id')->get() : collect();

        // Use explicit group links, never names or telephone numbers, to associate family records.
        $groups = $can('family_groups') ? FamilyGroup::with(['members', 'holder'])
            ->where(fn ($q) => $q->where('holder_id', $customer->id)->orWhereIn('id', $policies->pluck('family_group_id')->filter()))
            ->when(!$user->isAdmin(), fn ($q) => $q->where('created_by', $user->id))
            ->get() : collect();
        $familyPolicies = $can('policies') && $groups->isNotEmpty()
            ? $policyQuery()->whereIn('family_group_id', $groups->modelKeys())->where('holder_id', '!=', $customer->id)
                ->with($policyRelations)->orderByDesc('id')->get()
            : collect();
        $allPolicies = $policies->concat($familyPolicies);
        if (!$policyMembersAvailable) {
            // Prevent Blade from lazy-loading an optional table before its migration is deployed.
            $allPolicies->each(fn ($policy) => $policy->setRelation('members', new \Illuminate\Database\Eloquent\Collection));
        }
        $current = $this->currentPolicies($allPolicies);
        $ownCurrent = $current->where('holder_id', $customer->id);
        $renewals = $this->upcomingRenewals($allPolicies, $days);

        $claims = $can('policies') ? ClaimTracker::with(['policy', 'insuredMember', 'proposer'])
            ->where(fn ($q) => $q->where('proposer_id', $customer->id)->orWhereIn('policy_id', $allPolicies->pluck('id')))
            ->when(!$user->isAdmin(), fn ($q) => $q->where(fn ($owned) => $owned->where('created_by', $user->id)
                ->orWhereIn('policy_id', $allPolicies->pluck('id'))))
            ->latest()->get() : collect();
        $documents = $can('documents') ? ClientDocument::with(['documentType', 'documentName', 'files'])
            ->where('client_id', $customer->id)->when(!$user->isAdmin(), fn ($q) => $q->where('createdby', $user->id))->latest()->get() : collect();
        $endorsements = $can('endorsements') ? Endorsement::with('policy')
            ->where(fn ($q) => $q->where('policy_holder_id', $customer->id)->orWhereIn('policy_id', $policies->pluck('id')))
            ->when(!$user->isAdmin(), fn ($q) => $q->where('created_by', $user->id))->latest()->get() : collect();
        // Investments have a family group / investment holder link, not a customer foreign key.
        $investments = $can('investments') && $groups->isNotEmpty() ? Investment::whereIn('family_group_id', $groups->modelKeys())
            ->when(!$user->isAdmin(), fn ($q) => $q->where('created_by', $user->id))->latest()->get() : collect();
        $invoices = $can('invoices') ? $customer->invoices()->with(['currency', 'payments'])->latest()->get() : collect();
        $tickets = $can('tickets') ? $customer->supportTickets()->when(!$user->isAdmin(), fn ($q) => $q->where('created_by', $user->id))->latest()->get() : collect();
        $tasks = $can('tasks') ? $this->ownedWork(Task::query(), $user)
            ->where(fn ($q) => $q->where(fn ($direct) => $direct->where('related_type', 'customer')->where('related_id', $customer->id))
                ->orWhereHas('project', fn ($project) => $project->where('customer_id', $customer->id)))
            ->with('assignedUser')->orderBy('due_date')->get() : collect();
        $projects = $can('projects') ? $this->ownedWork(Project::query(), $user)->where('customer_id', $customer->id)->latest()->get() : collect();

        $summary = [
            'policies' => $ownCurrent->count(),
            'active' => $ownCurrent->filter(fn ($p) => $p->start_date && $p->end_date && substr($p->start_date, 0, 10) <= today()->toDateString()
                && substr($p->end_date, 0, 10) >= today()->toDateString())->count(),
            'renewals' => $renewals->count(),
            'expired' => $ownCurrent->filter(fn ($p) => $p->end_date && substr($p->end_date, 0, 10) < today()->toDateString())->count(),
            'premium' => $ownCurrent->sum('premium_amount'),
            'claims' => $claims->count(),
            'members' => $groups->sum(fn ($g) => $g->members->count()),
        ];
        return compact('policies', 'familyPolicies', 'groups', 'renewals', 'claims', 'documents', 'endorsements',
            'investments', 'invoices', 'tickets', 'tasks', 'projects', 'summary', 'days', 'can', 'policyMembersAvailable');
    }

    public function currentPolicies(Collection $policies): Collection
    {
        $parentIds = $policies->flatMap(fn ($policy) => [$policy->renewal_of_policy_id, $policy->renew_id])
            ->filter()->map(fn ($id) => (int) $id);
        return $policies->reject(fn ($policy) => $parentIds->contains((int) $policy->id));
    }

    public function upcomingRenewals(Collection $policies, int $days): Collection
    {
        $today = today()->toDateString();
        $until = today()->addDays($days)->toDateString();
        return $this->currentPolicies($policies)->filter(function ($policy) use ($today, $until) {
            $end = substr((string) $policy->end_date, 0, 10);
            return $end !== '' && $end >= $today && $end <= $until;
        })->sortBy('end_date');
    }

    private function ownedWork(Builder $query, User $user): Builder
    {
        return $query->when(!$user->isAdmin(), fn ($q) => $q->where(fn ($owned) => $owned
            ->where('user_id', $user->id)->orWhere('created_by', $user->id)->orWhere('assigned_user_id', $user->id)));
    }
}
