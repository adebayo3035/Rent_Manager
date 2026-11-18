<?php
// tenant/utilities/email_templates.php

/**
 * Render a branded HTML email for the setup flow.
 */
function renderSetupEmail(array $params): string {
    $name      = htmlspecialchars($params['name'] ?? 'there', ENT_QUOTES, 'UTF-8');
    $roleLabel = htmlspecialchars($params['role'] ?? 'Account', ENT_QUOTES, 'UTF-8');
    $setupUrl  = htmlspecialchars($params['setup_url'], ENT_QUOTES, 'UTF-8');
    $logoUrl   = htmlspecialchars($params['logo_url'] ?? '', ENT_QUOTES, 'UTF-8');
    $supportEmail = htmlspecialchars($params['support_email'] ?? 'support@rentflowpro.com', ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Welcome</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Inter,Arial,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:40px 20px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(15,23,42,0.08);">
        <tr><td style="background:#1e3c72;padding:32px 32px 24px;text-align:center;">
          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Welcome to RentFlow Pro</h1>
          <p style="margin:6px 0 0;color:#c7d2fe;font-size:13px;">Your {$roleLabel} account is ready to activate</p>
        </td></tr>
        <tr><td style="padding:32px;">
          <p style="margin:0 0 16px;color:#0f172a;font-size:15px;line-height:1.6;">Hi {$name},</p>
          <p style="margin:0 0 16px;color:#334155;font-size:14px;line-height:1.7;">
            Your {$roleLabel} account on RentFlow Pro has been created. To start using it, you need to set up your password and security question.
          </p>
          <p style="margin:0 0 24px;text-align:center;">
            <a href="{$setupUrl}" style="display:inline-block;background:#1e3c72;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;font-size:14px;">
              Set up my account
            </a>
          </p>
          <p style="margin:0 0 16px;color:#64748b;font-size:13px;line-height:1.6;">
            On the setup page, enter this email address and click <strong>Send code</strong>. We'll email you a 6-digit code. Enter it to verify your email, then set your password and security question.
          </p>
          <div style="background:#f8fafc;border-left:3px solid #1e3c72;padding:14px 16px;border-radius:6px;margin:0 0 16px;">
            <p style="margin:0;color:#334155;font-size:12px;line-height:1.6;">
              <strong>Heads up:</strong> you'll also need your <strong>{$roleLabel} ID</strong> later to log in. It was included in your onboarding paperwork/invoice.
            </p>
          </div>
          <p style="margin:0;color:#64748b;font-size:12px;line-height:1.6;">
            If you didn't expect this email, you can safely ignore it. If you have questions, contact <a href="mailto:{$supportEmail}" style="color:#1e3c72;">{$supportEmail}</a>.
          </p>
        </td></tr>
        <tr><td style="background:#f8fafc;padding:18px 32px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="margin:0;color:#94a3b8;font-size:11px;">© RentFlow Pro — sent to you because an account was created in your name.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
}

/**
 * Render a post-setup confirmation email.
 */
function renderAccountReadyEmail(array $params): string {
    $name    = htmlspecialchars($params['name'] ?? 'there', ENT_QUOTES, 'UTF-8');
    $role    = htmlspecialchars($params['role'] ?? 'Account', ENT_QUOTES, 'UTF-8');
    $loginUrl = htmlspecialchars($params['login_url'], ENT_QUOTES, 'UTF-8');
    $supportEmail = htmlspecialchars($params['support_email'] ?? 'support@rentflowpro.com', ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>You're all set</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Inter,Arial,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:40px 20px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(15,23,42,0.08);">
        <tr><td style="background:#065f46;padding:32px 32px 24px;text-align:center;">
          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">You're all set! 🎉</h1>
          <p style="margin:6px 0 0;color:#a7f3d0;font-size:13px;">Your {$role} account is now active</p>
        </td></tr>
        <tr><td style="padding:32px;">
          <p style="margin:0 0 16px;color:#0f172a;font-size:15px;line-height:1.6;">Hi {$name},</p>
          <p style="margin:0 0 16px;color:#334155;font-size:14px;line-height:1.7;">
            Your password and security question have been set successfully. Your account is now ready to use.
          </p>
          <p style="margin:0 0 24px;text-align:center;">
            <a href="{$loginUrl}" style="display:inline-block;background:#065f46;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;font-size:14px;">
              Sign in to your account
            </a>
          </p>
          <p style="margin:0 0 8px;color:#334155;font-size:13px;font-weight:600;">How to log in next time:</p>
          <p style="margin:0 0 16px;color:#64748b;font-size:13px;line-height:1.7;">
            Use your <strong>email address</strong>, <strong>phone number</strong>, or <strong>{$role} ID</strong> as your username, and the password you just set.
          </p>
          <p style="margin:0;color:#64748b;font-size:12px;line-height:1.6;">
            If you have questions, contact <a href="mailto:{$supportEmail}" style="color:#065f46;">{$supportEmail}</a>.
          </p>
        </td></tr>
        <tr><td style="background:#f8fafc;padding:18px 32px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="margin:0;color:#94a3b8;font-size:11px;">© RentFlow Pro — this email confirms your account is ready.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
}