<?php

namespace App\Services;

use App\Models\User;

class WorkspaceDataVisibility
{
    public function filter(mixed $data, User $user): mixed
    {
        if (!is_array($data) || $user->accessRole() === 'owner') return $data;
        $financial = $user->canDo('reports.financial');
        foreach ($data as $key => $value) {
            $restricted = !$financial && preg_match('/(^|_)(cost|profit|margin|valuation)(_|$)|^default_cost$|^purchaseValue$|^purchase_value$|^waste_cost$|cogs|^stock_purchases$|^waste$/i', (string) $key);
            if ($restricted || (!$financial && !$user->canDo('expenses.manage') && in_array($key, ['expenses', 'operating_expenses', 'today_expenses'], true)) || ($key === 'notification_preferences' && !$user->canDo('telegram.settings'))) unset($data[$key]);
            else $data[$key] = $this->filter($value, $user);
        }
        return $data;
    }
}
