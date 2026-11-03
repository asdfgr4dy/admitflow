<?php
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeName = $escape($recipientName);
$safeUrl = $escape($resetUrl);
$heading = $resend ? 'Your account setup link' : 'You are invited to AdmitFlow';
$subject = $resend ? 'Your AdmitFlow account setup link' : 'Invitation to AdmitFlow';
$intro = $resend
    ? 'A new secure setup link was requested for your AdmitFlow staff account.'
    : 'An administrator created an AdmitFlow staff account for you. Choose your own password to activate sign-in.';
return [
    'subject' => $subject,
    'text' => "Hello {$recipientName},\n\n{$intro} Use this secure, one-time link within {$expiresMinutes} minutes to choose your password:\n{$resetUrl}\n\nThis link is bound to the email address receiving this message. If you were not expecting this invitation, contact your admissions office.\n\nAdmitFlow Admissions Office",
    'html' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $escape($heading) . '</title></head><body style="margin:0;background:#f2f6f4;color:#172c3c;font-family:Arial,Helvetica,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f6f4;padding:28px 12px"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border-top:5px solid #20384b"><tr><td style="padding:28px 32px 12px"><p style="margin:0;color:#55715f;font-size:12px;font-weight:bold;letter-spacing:1px">ADMITFLOW · ADMISSIONS OFFICE</p><h1 style="margin:12px 0 0;font-size:25px;line-height:1.25;color:#20384b">' . $escape($heading) . '</h1></td></tr><tr><td style="padding:8px 32px 32px;font-size:16px;line-height:1.65"><p>Hello ' . $safeName . ',</p><p>' . $escape($intro) . ' Use this secure, one-time link within ' . $expiresMinutes . ' minutes:</p><p style="margin:28px 0"><a href="' . $safeUrl . '" style="display:inline-block;background:#55715f;color:#fff;text-decoration:none;padding:13px 20px;font-weight:bold">Set your password</a></p><p>If the button does not work, open this link:<br><a href="' . $safeUrl . '" style="color:#20384b;overflow-wrap:anywhere">' . $safeUrl . '</a></p><p>This link is bound to the email address receiving this message. If you were not expecting this invitation, contact your admissions office.</p><p style="margin:28px 0 0;color:#66737b;font-size:13px">AdmitFlow Admissions Office</p></td></tr></table></td></tr></table></body></html>',
];