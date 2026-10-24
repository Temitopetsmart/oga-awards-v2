<?php
declare(strict_types=1);

namespace App;

/** Factory-default permissions for each built-in role (used by "Reset to default"). Generated from V2 permissions.js. */
final class RoleDefaults
{
    public const PERMISSIONS = [
        'editor' => ['dashboard.view', 'nominees.view', 'nominees.create', 'nominees.edit', 'nominees.approve', 'categories.view', 'categories.manage', 'votes.view', 'results.view', 'committee.view', 'messages.view', 'messages.manage', 'occasions.view', 'occasions.manage'],
        'publicity' => ['dashboard.view', 'nominees.view', 'categories.view', 'results.view', 'messages.view', 'occasions.view', 'occasions.manage'],
        'treasurer' => ['dashboard.view', 'votes.view', 'votes.export', 'finance.view', 'finance.export', 'finance.reconcile', 'tickets.view', 'tickets.export', 'results.view'],
        'secretary' => ['dashboard.view', 'committee.view', 'committee.manage', 'committee.review', 'messages.view', 'messages.manage'],
        'nominations' => ['dashboard.view', 'nominees.view', 'nominees.create', 'nominees.edit', 'nominees.approve', 'nominees.export', 'categories.view', 'categories.manage', 'messages.view'],
        'ticketing' => ['dashboard.view', 'tickets.view', 'tickets.manageTypes', 'tickets.override', 'tickets.export', 'scanner.use'],
        'gate' => ['scanner.use'],
        'auditor' => ['dashboard.view', 'nominees.view', 'nominees.export', 'categories.view', 'votes.view', 'votes.export', 'results.view', 'finance.view', 'finance.export', 'tickets.view', 'tickets.export', 'committee.view', 'messages.view', 'occasions.view', 'settings.view', 'editions.view', 'team.view', 'audit.view'],
    ];
}