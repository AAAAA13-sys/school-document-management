<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Reset your DMS password · PLP Documents</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f6f8f4; color: #1f3028; font-family: Arial, Helvetica, sans-serif; line-height: 1.6">
    <div style="display: none; max-height: 0; overflow: hidden">Instructions to reset your PLP Document Management System password.</div>
    <table role="presentation" style="width: 100%; border-collapse: collapse">
        <tr>
            <td align="center" style="padding: 24px 12px">
                <table role="presentation" style="width: 100%; max-width: 600px; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05)">
                    <tr>
                        <td style="padding: 28px 24px; background-color: #103c2e; color: #ffffff">
                            <p style="margin: 0; font-size: 24px; font-weight: bold; letter-spacing: -0.5px">d. &nbsp; PLP Documents</p>
                            <p style="margin: 6px 0 0; font-size: 12px; letter-spacing: 1px; color: #f4cd42; font-weight: 600">DOCUMENT MANAGEMENT SYSTEM</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 24px">
                            <h1 style="margin: 0 0 16px; font-size: 22px; line-height: 1.3; color: #103c2e">Reset your password</h1>
                            <p style="margin: 0 0 16px; font-size: 15px; color: #2d3748">
                                Hello <strong>{{ $user->name ?? 'User' }}</strong>,
                            </p>
                            <p style="margin: 0 0 20px; font-size: 15px; color: #4a5568">
                                We received a request to reset the password for your account associated with <strong>{{ $email }}</strong>. Click the button below to choose a new password:
                            </p>
                            <table role="presentation" style="border-collapse: collapse; margin: 28px 0">
                                <tr>
                                    <td style="background-color: #176347; border-radius: 6px">
                                        <a
                                            href="{{ $resetUrl }}"
                                            style="display: inline-block; padding: 14px 28px; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 15px; letter-spacing: 0.2px"
                                            >Reset Password</a
                                        >
                                    </td>
                                </tr>
                            </table>
                            <div style="padding: 16px; background-color: #fff6cd; border-left: 4px solid #f4cd42; border-radius: 4px; margin-bottom: 24px">
                                <strong style="color: #634a00; font-size: 14px">Single-use link · Expires in {{ $expiresIn }} minutes</strong>
                                <p style="margin: 4px 0 0; font-size: 13px; color: #745600">
                                    If you did not request a password reset, you can safely ignore this email. Your existing password will remain active and secure.
                                </p>
                            </div>
                            <p style="margin: 0 0 8px; font-size: 13px; color: #718096">If the button above does not work, copy and paste this link into your web browser:</p>
                            <p style="margin: 0 0 20px; font-size: 13px; overflow-wrap: anywhere; word-break: break-all">
                                <a href="{{ $resetUrl }}" style="color: #176347; text-decoration: underline">{{ $resetUrl }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 24px; border-top: 1px solid #dbe3da; color: #53665a; font-size: 12px; background-color: #fafbf9">
                            Pamantasan ng Lungsod ng Pasig · Central Document Repository<br />
                            For authorized personnel only. Activity and security events are logged.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

