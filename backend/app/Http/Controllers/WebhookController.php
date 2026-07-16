<?php
namespace App\Http\Controllers;
use App\Models\{Webhook, WebhookDelivery};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Str;

class WebhookController extends Controller {
    public function index(): JsonResponse { return response()->json(['webhooks'=>Webhook::withCount('deliveries')->get()]); }
    public function store(Request $request): JsonResponse {
        $data = $request->validate(['url'=>'required|url','secret'=>'required|string|min:10','events'=>'required|array']);
        return response()->json(['webhook'=>Webhook::create(['id'=>Str::uuid()]+$data)], 201);
    }
    public function update(Request $request, string $id): JsonResponse {
        $wh = Webhook::findOrFail($id); $wh->update($request->only(['url','secret','events','is_active']));
        return response()->json(['webhook'=>$wh->fresh()]);
    }
    public function destroy(string $id): JsonResponse { Webhook::findOrFail($id)->delete(); return response()->json(['message'=>'Deleted.']); }
    public function test(string $id): JsonResponse {
        $wh = Webhook::findOrFail($id);
        \App\Jobs\WebhookDeliveryJob::dispatch($wh,'webhook.test',['timestamp'=>now()->toIso8601String(),'message'=>'Test payload from ExamEdge Pro']);
        return response()->json(['message'=>'Test event queued.']);
    }
    public function logs(string $id): JsonResponse { return response()->json(['logs'=>WebhookDelivery::where('webhook_id',$id)->latest()->take(50)->get()]); }
}
