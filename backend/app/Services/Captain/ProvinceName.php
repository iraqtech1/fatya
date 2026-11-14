<?php

namespace App\Services\Captain;

class ProvinceName
{
    public function name(?string $value): string
    {
        $normalize = static fn (string $name): string => preg_replace('/[\s\p{P}\x{0640}\x{064B}-\x{065F}]+/u', '', str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'محافظة'], ['ا', 'ا', 'ا', 'ي', 'ه', ''], mb_strtolower(trim($name))));
        $name = $normalize($value ?? '');
        foreach ([
            ['بغداد', 'Baghdad', 'بەغدا'],
            ['الأنبار', 'الرمادي', 'الرمادي (الانبار)', 'Anbar', 'Al Anbar'],
            ['نينوى', 'الموصل', 'نينوى ( الموصل)', 'Nineveh', 'Ninewa', 'Mosul'],
            ['البصرة', 'Basra', 'Basrah'],
            ['بابل', 'الحلة', 'Babylon', 'Babil'],
            ['كربلاء', 'Karbala'],
            ['النجف', 'Najaf'],
            ['واسط', 'الكوت', 'Wasit'],
            ['ميسان', 'العمارة', 'Maysan'],
            ['المثنى', 'السماوة', 'Muthanna', 'Al Muthanna'],
            ['ذي قار', 'الناصرية', 'Dhi Qar', 'Thi Qar'],
            ['القادسية', 'الديوانية', 'Qadisiyah', 'Al Qadisiyah'],
            ['ديالى', 'Diyala'],
            ['صلاح الدين', 'Salah Al Din', 'Salahuddin'],
            ['كركوك', 'Kirkuk', 'کەرکووک'],
            ['أربيل', 'Erbil', 'Hawler', 'هەولێر'],
            ['السليمانية', 'Sulaymaniyah', 'Sulaimani', 'سلێمانی'],
            ['دهوك', 'Duhok', 'Dohuk', 'دهۆک'],
            ['حلبجة', 'Halabja', 'هەڵەبجە'],
        ] as $aliases) {
            if (in_array($name, array_map($normalize, $aliases), true)) {
                return $normalize($aliases[0]);
            }
        }

        return $name;
    }
}
