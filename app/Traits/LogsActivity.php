<?php

namespace App\Traits;

use App\Models\AuditTrail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Log;

trait LogsActivity
{
    /**
     * Log activity
     */
    protected function logActivity($action, $module, $recordId = null, $recordType = null, $oldValues = null, $newValues = null)
    {
        try {
            $user = Auth::user();
            if (!$user) return;
            
            AuditTrail::create([
                'shop_id' => $user->shop_id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'user_role' => $user->role,
                'action' => $action,
                'module' => $module,
                'record_id' => $recordId,
                'record_type' => $recordType,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'device' => $this->getDeviceType(),
            ]);
        } catch (\Exception $e) {
            // Log error but don't stop execution
            Log::error('Failed to log activity: ' . $e->getMessage());
        }
    }
    
    /**
     * Get device type from user agent
     */
    protected function getDeviceType()
    {
        $userAgent = Request::userAgent();
        if (str_contains($userAgent, 'Mobile')) return 'Mobile';
        if (str_contains($userAgent, 'Tablet')) return 'Tablet';
        return 'Desktop';
    }
    
    /**
     * Log model events automatically
     */
    public static function bootLogsActivity()
    {
        static::created(function ($model) {
            $model->logActivity('create', $model->getTable(), $model->id, get_class($model), null, $model->getAttributes());
        });
        
        static::updated(function ($model) {
            $model->logActivity('update', $model->getTable(), $model->id, get_class($model), $model->getOriginal(), $model->getChanges());
        });
        
        static::deleted(function ($model) {
            $model->logActivity('delete', $model->getTable(), $model->id, get_class($model), $model->getOriginal(), null);
        });
    }
}