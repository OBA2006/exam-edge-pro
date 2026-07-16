<?php
namespace App\Services;
use App\Models\Webhook;
use App\Jobs\WebhookDeliveryJob;

class WebhookService {
    public function dispatch(string $event, array $payload): void {
        Webhook::where('is_active',true)->whereJsonContains('events',$event)->get()
            ->each(fn($wh) => WebhookDeliveryJob::dispatch($wh,$event,$payload)->delay(now()->addSeconds(2)));
    }
}
