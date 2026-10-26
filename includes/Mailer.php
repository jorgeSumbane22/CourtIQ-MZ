<?php
declare(strict_types=1);

/**
 * CourtIQMZ - Mailer (envio de emails com SMTP)
 * 
 * @package CourtIQ
 * @version 1.0.0
 */

class Mailer
{
    private static array $config = [
        'host' => 'smtp.gmail.com',        // ALTERAR
        'port' => 587,
        'username' => 'courtiq@email.com', // ALTERAR
        'password' => 'sua_app_password',  // ALTERAR
        'encryption' => 'tls',
        'from_email' => 'noreply@courtiq.com',
        'from_name' => 'CourtIQMZ',
    ];

    /** O envio só funciona depois de trocar os dados de exemplo acima pelos de uma conta SMTP real. */
    public static function isConfigured(): bool
    {
        return self::$config['password'] !== 'sua_app_password' && self::$config['username'] !== 'courtiq@email.com';
    }

    public static function send(string $to, string $subject, string $body, ?string $toName = null): bool
    {
        try {
            // Usar PHPMailer se disponível
            if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
                return self::sendWithPHPMailer($to, $subject, $body, $toName);
            }
            // Fallback para mail() nativo
            return self::sendWithNativeMail($to, $subject, $body, $toName);
        } catch (Exception $e) {
            error_log('Mailer error: ' . $e->getMessage());
            return false;
        }
    }

    private static function sendWithPHPMailer(string $to, string $subject, string $body, ?string $toName): bool
    {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = self::$config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = self::$config['username'];
        $mail->Password = self::$config['password'];
        $mail->SMTPSecure = self::$config['encryption'];
        $mail->Port = self::$config['port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(self::$config['from_email'], self::$config['from_name']);
        $mail->addAddress($to, $toName ?? '');
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;

        return $mail->send();
    }

    private static function sendWithNativeMail(string $to, string $subject, string $body, ?string $toName): bool
    {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . self::$config['from_name'] . " <" . self::$config['from_email'] . ">\r\n";
        
        return mail($to, $subject, $body, $headers);
    }

    public static function template(string $title, string $content, string $buttonText = '', string $buttonUrl = ''): string
    {
        $button = '';
        if ($buttonText && $buttonUrl) {
            $button = "<a href='{$buttonUrl}' style='display:inline-block;padding:14px 28px;background:#006643;color:#fff;text-decoration:none;border-radius:12px;font-weight:700;margin-top:20px;'>{$buttonText}</a>";
        }
        
        return "
        <!DOCTYPE html>
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='margin:0;padding:0;background:#0a0d0c;font-family:Arial,sans-serif;'>
            <table width='100%' cellpadding='0' cellspacing='0' style='background:#0a0d0c;padding:40px 20px;'>
                <tr><td align='center'>
                    <table width='600' cellpadding='0' cellspacing='0' style='background:#111614;border-radius:12px;border:1px solid #26302b;border-top:4px solid #fcd116;overflow:hidden;'>
                        <tr><td style='padding:32px;text-align:center;background:#006643;'>
                            <h1 style='color:#fff;margin:0;font-size:28px;'>🏀 CourtIQMZ</h1>
                        </td></tr>
                        <tr><td style='padding:32px;color:#e5e7eb;font-size:16px;line-height:1.6;'>
                            <h2 style='color:#3fd08f;margin:0 0 16px;'>{$title}</h2>
                            <div>{$content}</div>
                            {$button}
                        </td></tr>
                        <tr><td style='padding:20px;text-align:center;background:#0f1419;color:#8b949e;font-size:12px;'>
                            © " . date('Y') . " CourtIQMZ - Todos os direitos reservados
                        </td></tr>
                    </table>
                </td></tr>
            </table>
        </body>
        </html>";
    }
}