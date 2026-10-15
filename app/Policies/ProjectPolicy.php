<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\ChannelScope;
use App\Support\Erp;

/**
 * M10 «Проекты» (legacy prjIsClient/prjIsAdmin and the checks of the card, L31117–31725). Every «CRM» role works with projects,
 * the head of the shop and the dealers included. A salon or dealer account is the customer: it sees the projects of its channel,
 * edits a request until it is calculated and decides on a sent calculation; staff calculate, send and manage. Only the
 * director deletes a project. Staff see the projects of the channels ChannelScope allows them.
 */
class ProjectPolicy
{
    /** Legacy prjIsClient: the role has a sales channel. */
    public static function isClient(User $user): bool
    {
        return Erp::channelOf($user) !== null;
    }

    /** Legacy prjIsAdmin: the director. */
    public static function isAdmin(User $user): bool
    {
        return $user->role === 'director';
    }

    public function viewAny(User $user): bool
    {
        return Erp::canAccess($user, 'crm');
    }

    public function create(User $user): bool
    {
        return Erp::canAccess($user, 'crm');
    }

    public function view(User $user, Project $project): bool
    {
        return Erp::canAccess($user, 'crm') && $project->active && ChannelScope::allows($user, $project->channel);
    }

    /** Legacy canHead: the customer edits the request only while it has not been calculated. */
    public function updateHead(User $user, Project $project): bool
    {
        return $this->view($user, $project) && (! self::isClient($user) || $project->status === 'request');
    }

    /** Executors, calculations, sending: staff only. */
    public function manage(User $user, Project $project): bool
    {
        return $this->view($user, $project) && ! self::isClient($user);
    }

    /** Legacy prjClientDecide: the customer accepts or declines a sent calculation. */
    public function decide(User $user, Project $project): bool
    {
        return $this->view($user, $project) && self::isClient($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->view($user, $project) && self::isAdmin($user) && ! self::isClient($user);
    }
}
