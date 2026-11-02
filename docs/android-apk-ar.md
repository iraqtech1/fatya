# APK فاتيا

الحزمة `net.fatyh.app` بالإصدار 1.0.0 تفتح `https://fatyh.net/app` باستخدام Trusted Web Activity ومكتبة Google Android Browser Helper 2.7.4. هي واجهة التطبيق الحالية المنشورة على نفس API وقاعدة البيانات، وليست إعادة كتابة أصلية للتطبيق. تحديثات واجهة Vue تصل من الدومين. تحتاج إنترنت ومتصفح أندرويد يدعم TWA مثل Chrome؛ لا تتضمن خادم Laravel أو قاعدة البيانات داخل الهاتف.

أندرويد 7 (API 24) فأحدث، compile/target SDK 36، Java 17 وGradle 8.14 وAndroid Gradle Plugin 8.13.1. للبناء: حدد `ANDROID_HOME` ثم `gradle -p android assembleRelease lintRelease`. الناتج غير الموقّع `android/app/build/outputs/apk/release/app-release-unsigned.apk`، ويوقّع خارج المشروع بـzipalign وapksigner. لا توجد مكتبات native خاصة أو أذونات جهات اتصال أو رسائل أو تخزين شامل.

مفتاح إصدار APK الخاص وكلمة مروره محفوظان خارج Git في مجلد الاستضافة المحلي الخاص `android-signing`. يجب نسخهما احتياطياً بصورة آمنة؛ نفس المفتاح لازم لتحديث النسخ المثبتة. لا تشارك ملف signing.json أو keystore، ولا تضعهما في الاستضافة العامة.

يجب أن يطابق `https://fatyh.net/.well-known/assetlinks.json` اسم الحزمة وبصمة شهادة APK. يتضمن المستودع البصمة العامة فقط. هذا يثبت ارتباط الدومين بالتطبيق لعرضه دون شريط عنوان المتصفح. إذا غيّر Google Play شهادة التوقيع عند النشر، تضاف بصمة App Signing من Play Console إلى الملف قبل توزيع نسخة المتجر.

هذه نسخة تثبيت مباشر. لم تُرفع إلى Google Play، وقبول المتجر وربط الدفع الفعلي وإقرار حسابات الشركات خطوات مستقلة. الإشعارات تستخدم إعداد الويب الموجود وصلاحيات المتصفح/الهاتف؛ التوقيع والبناء وحدهما لا يثبتان وصول إشعار أو عملية دفع على جهاز حقيقي.

مرجع Google: https://developer.chrome.com/docs/android/trusted-web-activity/quick-start
