<?php

namespace Ogp\UiApi\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AccessControlFilter
{
    private array $userRoles = [];

    private ?string $userOrg = null;

    private bool $rolesEnabled;

    private bool $orgsEnabled;

    public function __construct(Request $request)
    {
        $this->rolesEnabled = (bool) config('uiapi.access_control_roles', true);
        $this->orgsEnabled  = (bool) config('uiapi.access_control_orgs', true);

        if ($this->rolesEnabled) {
            $this->userRoles = $this->resolveRoles($request);
        }
        if ($this->orgsEnabled) {
            $this->userOrg = $this->resolveOrg($request);
        }
    }

    /**
     * Filter top-level view config sections by access control keys.
     */
    public function filterSections(array $viewCfg): array
    {
        if (! $this->rolesEnabled && ! $this->orgsEnabled) {
            return $viewCfg;
        }

        return array_filter($viewCfg, function ($section): bool {
            if (! is_array($section)) {
                return true;
            }

            return $this->passes($section);
        });
    }

    /**
     * Check whether a single associative object passes access control.
     * Used for nested objects (e.g. actions, delete) in buildSectionPayload.
     */
    public function canAccess(array $item): bool
    {
        if (! $this->rolesEnabled && ! $this->orgsEnabled) {
            return true;
        }

        return $this->passes($item);
    }

    /**
     * Filter a sequential list (fields, filters) and strip access keys from each item.
     */
    public function filterList(array $list): array
    {
        if (! $this->rolesEnabled && ! $this->orgsEnabled) {
            return $list;
        }

        $filtered = array_values(array_filter($list, function ($item): bool {
            if (! is_array($item)) {
                return true;
            }

            return $this->passes($item);
        }));

        return array_map(fn (array $item): array => $this->strip($item), $filtered);
    }

    private function passes(array $item): bool
    {
        if ($this->rolesEnabled) {
            $deny  = isset($item['denyRoles'])  ? (array) $item['denyRoles']  : null;
            $allow = isset($item['allowRoles']) ? (array) $item['allowRoles'] : null;

            if ($deny && array_intersect($deny, $this->userRoles)) {
                return false;
            }
            if ($allow && ! array_intersect($allow, $this->userRoles)) {
                return false;
            }
        }

        if ($this->orgsEnabled) {
            $denyOrgs  = isset($item['denyOrgs'])  ? (array) $item['denyOrgs']  : null;
            $allowOrgs = isset($item['allowOrgs']) ? (array) $item['allowOrgs'] : null;

            if ($denyOrgs && $this->userOrg && in_array($this->userOrg, $denyOrgs, true)) {
                return false;
            }
            if ($allowOrgs && (! $this->userOrg || ! in_array($this->userOrg, $allowOrgs, true))) {
                return false;
            }
        }

        return true;
    }

    private function strip(array $item): array
    {
        unset($item['allowRoles'], $item['denyRoles'], $item['allowOrgs'], $item['denyOrgs']);

        return $item;
    }

    private function resolveRoles(Request $request): array
    {
        $resolver = config('uiapi.roles_resolver');
        if (is_callable($resolver)) {
            return (array) $resolver($request);
        }

        $user = $request->user();
        if (! $user) {
            return [];
        }
        if (isset($user->roles)) {
            $roles = $user->roles;

            return $roles instanceof Collection
                ? $roles->pluck('name')->toArray()
                : (array) $roles;
        }
        if (isset($user->role)) {
            return [(string) $user->role];
        }

        return [];
    }

    private function resolveOrg(Request $request): ?string
    {
        $resolver = config('uiapi.org_resolver');
        if (is_callable($resolver)) {
            return $resolver($request);
        }

        $user = $request->user();

        return $user?->org_id ?? $user?->organization_id ?? null;
    }
}
