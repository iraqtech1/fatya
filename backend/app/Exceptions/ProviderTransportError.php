<?php

namespace App\Exceptions;

class ProviderTransportError extends ApiError
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('تعذّر تأكيد رد الشركة؛ راجع سجل التنفيذ دون إعادة الشراء', 502);
    }
}
