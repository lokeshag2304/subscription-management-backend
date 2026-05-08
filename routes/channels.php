<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('private-agent.{agentId}', function ($agentId) {
     \Log::info('Broadcast Auth Attempt', [
        'agentId' => $agentId,
        'header' => request()->header('X-AGENT-ID'),
    ]);
    return true;
    // return request()->header('X-AGENT-ID') == $agentId;
});

