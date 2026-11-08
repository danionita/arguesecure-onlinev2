<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\PresenceChannel;

/**
 * Every real-time broadcast event (ui.md §6) lands on the same presence channel a client
 * joined in routes/channels.php — there is deliberately no other channel
 * (security.md §8: "no global channel. Nothing broadcasts to an 'everyone'
 * channel, ever."). Authorization already happened at subscribe time; this
 * is just naming the same channel again on the send side.
 */
trait BroadcastsToAssessmentChannel
{
    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('assessment.'.$this->assessmentUuid)];
    }
}
