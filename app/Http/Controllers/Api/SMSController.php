<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SMS;
use App\Models\SmsBalance;
use App\Models\SmsTransaction;
use App\Traits\SMSServiceTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class SMSController extends Controller
{
    use SMSServiceTrait;
    
    /**
     * Send a single SMS
     */
    public function sendSingle(Request $request)
    {
        $request->validate([
            'recipient' => 'required|string',
            'message'   => 'required|string',
            'shop_id'   => 'required|exists:shops,id',
            'customer_id' => 'nullable|exists:customers,id',
        ]);
        
        $result = $this->sendSMSWithResponse(
            [$request->recipient], 
            $request->message, 
            $request->shop_id, 
            $request->customer_id
        );
        
        if ($result && $result['success']) {
            return response()->json([
                'status' => 'success',
                'code' => 200,
                'message' => 'SMS sent successfully',
                'data' => $result
            ]);
        }
        
        return response()->json([
            'status' => 'error',
            'code' => 500,
            'message' => 'Failed to send SMS',
            'error' => $result['error'] ?? 'Unknown error'
        ], 500);
    }
    
    /**
     * Send bulk SMS
     */
    public function sendBulk(Request $request)
    {
        $request->validate([
            'recipients' => 'required|array',
            'recipients.*' => 'required|string',
            'message' => 'required|string',
            'shop_id' => 'required|exists:shops,id',
        ]);
        
        $token = env('SMS_BEARER_TOKEN');
        $shop = \App\Models\Shop::find($request->shop_id);
        $senderId = $shop?->sms_sender_id ?: env('SMS_SENDER_ID');
        if (!$token || !$senderId) {
            return response()->json([
                'success' => false,
                'message' => $senderId
                    ? 'SMS service not configured'
                    : 'No SMS sender ID for this shop. Ask the super admin to provision one.',
            ], 422);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ])->post('https://app.mycsms.com/api/v3/sms/send', [
            'phone'     => $request->recipients,
            'sender_id' => $senderId,
            'message'   => $request->message,
        ]);
        
        if ($response->successful()) {
            $responseData = $response->json();
            $costInfo = $responseData['data']['cost_info'] ?? null;
            $recipientCount = count($request->recipients);
            
            if ($costInfo) {
                SmsTransaction::create([
                    'shop_id' => $request->shop_id,
                    'message_id' => null,
                    'recipient' => null,
                    'cost' => $costInfo['total_cost'] ?? 0,
                    'recipient_count' => $recipientCount,
                    'balance_before' => $costInfo['balance_before'] ?? 0,
                    'balance_after' => $costInfo['balance_after'] ?? 0,
                    'cost_info' => json_encode($costInfo),
                    'type' => 'bulk',
                ]);
                
                foreach ($request->recipients as $index => $recipient) {
                    $result = $responseData['data']['results'][$index] ?? null;
                    SMS::create([
                        'customer_id' => null,
                        'recipient' => $recipient,
                        'message'   => $request->message,
                        'type'      => 'bulk',
                        'status'    => 'sent',
                        'message_id' => $result['message_id'] ?? null,
                        'cost' => ($costInfo['total_cost'] ?? 0) / $recipientCount,
                        'balance_before' => $costInfo['balance_before'] ?? null,
                        'balance_after' => $costInfo['balance_after'] ?? null,
                        'cost_info' => json_encode($costInfo),
                    ]);
                }
                
                $balance = SmsBalance::where('shop_id', $request->shop_id)->first();
                if ($balance) {
                    $balance->update([
                        'balance' => $costInfo['balance_after'],
                        'total_sent' => $balance->total_sent + $recipientCount,
                        'total_cost' => $balance->total_cost + $costInfo['total_cost'],
                        'last_transaction_at' => now(),
                    ]);
                }
            }
            
            return response()->json([
                'status' => 'success',
                'code' => 200,
                'message' => 'Messages sent successfully',
                'data' => $responseData
            ]);
        }
        
        return response()->json([
            'status' => 'error',
            'code' => $response->status(),
            'message' => 'Failed to send bulk SMS',
            'error' => $response->json()
        ], $response->status());
    }
    
    /**
     * Get SMS balance
     */
    public function getSmsBalance($shop_id)
    {
        $balance = SmsBalance::where('shop_id', $shop_id)->first();
        
        return response()->json([
            'status' => 'success',
            'code' => 200,
            'data' => [
                'balance' => $balance ? (float)$balance->balance : 0,
                'currency' => 'credits',
                'total_sent' => $balance ? (int)$balance->total_sent : 0,
                'total_spent' => $balance ? (float)$balance->total_cost : 0,
            ]
        ]);
    }
    
    /**
     * Get SMS history
     */
    public function getHistory(Request $request)
    {
        $user = Auth::user();
        
        $query = SMS::whereHas('customer', function ($q) use ($user) {
            $q->where('shop_id', $user->shop_id);
        })->orWhereNull('customer_id');
        
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        
        $history = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));
        
        return response()->json([
            'status' => 'success',
            'code' => 200,
            'data' => $history
        ]);
    }
}