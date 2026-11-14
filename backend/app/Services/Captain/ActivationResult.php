<?php

namespace App\Services\Captain;

class ActivationResult
{
    public function classify(int $http, ?array $body): array
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $status = strtolower((string) (is_scalar($data['status'] ?? null) ? $data['status'] : ''));
        $success = ($data['success'] ?? null) === true || ($data['activated'] ?? null) === true || in_array($status, ['success', 'succeeded', 'activated'], true);
        $failure = ($data['success'] ?? null) === false || in_array($status, ['failed', 'failure', 'error'], true) || is_string($data['error'] ?? null);
        if ($success && $failure) {
            return ['status' => 'review', 'message' => 'رد الشركة متعارض؛ راجع المزوّد دون إعادة الطلب.'];
        }
        if ($http >= 200 && $http < 300 && $success && ! $failure) {
            return ['status' => 'succeeded', 'message' => 'أكدت الشركة نجاح تفعيل الخدمة.'];
        }
        if ($body !== null && $failure && ($http >= 200 && $http < 300 || in_array($http, [400, 401, 403, 404, 422], true))) {
            return ['status' => 'failed', 'message' => 'رفضت الشركة طلب التفعيل. راجع الكود ومعرّف الجهاز وصلاحية توكن المزوّد.'];
        }
        if (in_array($http, [401, 403], true)) {
            return ['status' => 'failed', 'message' => 'لم يُقبل اتصال التفعيل. راجع توكن المزوّد وسماح الوصول لدى الرابعة.'];
        }

        return ['status' => 'review', 'message' => 'وصل رد دون تأكيد موثق لنجاح التفعيل؛ راجع المزوّد ولا تكرر الطلب.'];
    }
}
