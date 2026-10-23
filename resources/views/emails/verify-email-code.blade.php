<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تأیید ایمیل</title>
</head>
<body style="margin:0;padding:32px;background:#f4f6f8;font-family:Tahoma,Arial,sans-serif;color:#17212b;direction:rtl;text-align:right">
    <main style="max-width:520px;margin:0 auto;padding:28px;background:#fff;border-radius:16px">
        <h1 style="margin-top:0;font-size:20px">تأیید نشانی ایمیل</h1>
        <p>{{ $userName }} عزیز،</p>
        <p>برای تأیید ایمیل حساب پهناور کار، کد زیر را در برنامه وارد کنید:</p>
        <p style="margin:24px 0;text-align:center;font-size:30px;font-weight:bold;letter-spacing:8px;direction:ltr">
            {{ $code }}
        </p>
        <p>این کد تا ۱۰ دقیقه معتبر است. اگر شما این درخواست را نداده‌اید، این پیام را نادیده بگیرید.</p>
    </main>
</body>
</html>
