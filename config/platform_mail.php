<?php
return [
 'driver' => env('PLATFORM_MAIL_DRIVER', 'log'),
 'from_address' => env('PLATFORM_MAIL_FROM_ADDRESS'),
 'from_name' => env('PLATFORM_MAIL_FROM_NAME'),
 'smtp' => ['host'=>env('PLATFORM_MAIL_SMTP_HOST'),'port'=>env('PLATFORM_MAIL_SMTP_PORT'),'encryption'=>env('PLATFORM_MAIL_SMTP_ENCRYPTION'),'username'=>env('PLATFORM_MAIL_SMTP_USERNAME'),'password'=>env('PLATFORM_MAIL_SMTP_PASSWORD')],
 'postmark' => ['server_token'=>env('PLATFORM_MAIL_POSTMARK_SERVER_TOKEN'),'message_stream'=>env('PLATFORM_MAIL_POSTMARK_MESSAGE_STREAM')],
];
