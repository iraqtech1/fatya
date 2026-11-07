<?php

namespace App\Services;

use App\Exceptions\ApiError;

class OrderVisibility
{
    /** Hide from administrative lists only; never cancel, refund or erase a sale. */
    public function hide(array &$state, mixed $ids, mixed $confirmed): array
    {
        app(AdminAccess::class)->require('orders.view', 'orders.delete');
        if ($confirmed !== true) {
            throw new ApiError('يجب تأكيد حذف الطلبات من القائمة');
        }
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) < 1 || count($ids) > 100) {
            throw new ApiError('حدد من طلب واحد إلى 100 طلب');
        }
        foreach ($ids as $id) {
            if (! is_string($id) || $id === '' || strlen($id) > 100) {
                throw new ApiError('رقم طلب غير صحيح');
            }
        }
        $ids = array_values(array_unique($ids));
        $indices = array_flip(array_column($state['orders'], 'id'));
        // Validate the entire batch before changing any record.
        foreach ($ids as $id) {
            if (! array_key_exists($id, $indices)) {
                throw new ApiError('أحد الطلبات غير موجود حدّث القائمة وحاول مجدداً', 404);
            }
        }
        $changed = [];
        $time = now()->toISOString();
        foreach ($ids as $id) {
            $index = $indices[$id];
            if (empty($state['orders'][$index]['adminHiddenAt'])) {
                $state['orders'][$index]['adminHiddenAt'] = $time;
                $changed[] = $id;
            }
        }

        return $changed;
    }
}
