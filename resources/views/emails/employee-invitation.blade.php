<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Your PLP Documents invitation</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f6f8f4; color: #1f3028; font-family: Arial, Helvetica, sans-serif; line-height: 1.6">
    <div style="display: none; max-height: 0; overflow: hidden">You have been invited to the PLP Document Management System.</div>
    <table role="presentation" style="width: 100%; border-collapse: collapse">
        <tr>
            <td align="center" style="padding: 24px 12px">
                <table role="presentation" style="width: 100%; max-width: 600px; border-collapse: collapse; background-color: #ffffff">
                    <tr>
                        <td style="padding: 28px 24px; background-color: #103c2e; color: #ffffff">
                            <p style="margin: 0; font-size: 24px; font-weight: bold">d. &nbsp; PLP Documents</p>
                            <p style="margin: 6px 0 0; font-size: 12px; letter-spacing: 1px; color: #f4cd42">DOCUMENT MANAGEMENT SYSTEM</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px 24px">
                            <h1 style="margin: 0 0 16px; font-size: 24px; line-height: 1.3">Welcome to your document workspace</h1>
                            <p>Your administrator has invited you to join the Document Management System of Pamantasan ng Lungsod ng Pasig.</p>
                            <p>This invitation is for <strong>{{ $invitedEmail }}</strong>. Complete registration to choose your own password and access your assigned workspace.</p>
                            <table role="presentation" style="border-collapse: collapse; margin: 24px 0">
                                <tr>
                                    <td style="background-color: #176347; border-radius: 6px">
                                        <a
                                            href="{{ $registrationUrl }}"
                                            style="display: inline-block; padding: 14px 24px; color: #ffffff; text-decoration: none; font-weight: bold"
                                            >Complete Registration</a
                                        >
                                    </td>
                                </tr>
                            </table>
                            <p style="
                                    padding: 16px;
                                    background-color: #fff6cd;
                                "><strong>Expires {{ $expiresAt }}</strong><br />This link can be used once. Keep it private and do not forward this email.</p>
                            <p>If the button does not work, copy this complete address into your browser:</p>
                            <p style="
                                    font-size: 13px;
                                    overflow-wrap: anywhere;
                                    word-break: break-all;
                                "><a href="{{ $registrationUrl }}" style="color: #176347">{{ $registrationUrl }}</a></p>
                            <p>If your invitation has expired, contact your DMS administrator or IT support to request a new invitation.</p>
                            <p>If you did not expect this invitation, do not complete registration. Contact your administrator or IT support to verify it.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 24px; border-top: 1px solid #dbe3da; color: #53665a; font-size: 12px">
                            Pamantasan ng Lungsod ng Pasig<br />For authorized personnel only. No password is included in this email.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
