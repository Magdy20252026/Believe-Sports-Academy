# Believe-Sports-Academy

## Android WebView apps

يوجد مشروع أندرويد جديد داخل:

`/home/runner/work/Believe-Sports-Academy/Believe-Sports-Academy/android-webview`

المشروع يحتوي على 3 نكهات Build Flavors جاهزة:

- `players` لبوابة اللاعبين
- `admins` لبوابة الإداريين
- `trainers` لبوابة المدربين

كل نكهة تفتح رابط البوابة المناسب تلقائيًا:

- اللاعبين: `https://believe-sports-academy.gt.tc/admin/player_portal_login.php`
- الإداريين: `https://believe-sports-academy.gt.tc/admin/admin_portal_login.php`
- المدربين: `https://believe-sports-academy.gt.tc/admin/trainer_portal_login.php`

## تخصيص اسم التطبيق

عدّل اسم كل تطبيق من الملفات التالية:

- `android-webview/app/src/players/res/values/strings.xml`
- `android-webview/app/src/admins/res/values/strings.xml`
- `android-webview/app/src/trainers/res/values/strings.xml`

القيمة المستخدمة هي `app_name`.

## تخصيص شعار التطبيق PNG

استبدل ملف الشعار PNG لكل تطبيق من الملفات التالية بنفس الاسم:

- `android-webview/app/src/players/res/drawable-nodpi/app_logo.png`
- `android-webview/app/src/admins/res/drawable-nodpi/app_logo.png`
- `android-webview/app/src/trainers/res/drawable-nodpi/app_logo.png`

## الإشعارات

تم إضافة دعم إشعارات أندرويد داخل تطبيق الـ WebView عبر جسر JavaScript باسم:

`AndroidBridge.showNotification(title, message)`

وتم ربط بوابات اللاعبين والإداريين والمدربين بحيث ترسل إشعارًا محليًا داخل التطبيق عند ظهور إشعار جديد في البوابة.

## بناء ملفات APK

من داخل مجلد المشروع:

```bash
cd /home/runner/work/Believe-Sports-Academy/Believe-Sports-Academy/android-webview
./gradlew assemblePlayersDebug
./gradlew assembleAdminsDebug
./gradlew assembleTrainersDebug
```

ولإنتاج نسخ Release:

```bash
./gradlew assemblePlayersRelease
./gradlew assembleAdminsRelease
./gradlew assembleTrainersRelease
```
