<?php
declare(strict_types=1);

if (!function_exists('uwc_transport_mail')) {
    function uwc_transport_mail(string $to, string $subject, string $message, string $headers): bool
    {
        if (uwc_smtp_is_enabled()) {
            try {
                return uwc_smtp_send($to, $subject, $message, $headers);
            } catch (Throwable $e) {
                // Fall back to PHP mail() so forms keep working if SMTP is temporarily unavailable.
                return @mail($to, $subject, $message, $headers);
            }
        }

        return @mail($to, $subject, $message, $headers);
    }

    function uwc_smtp_is_enabled(): bool
    {
        return defined('UWC_SMTP_ENABLED')
            && UWC_SMTP_ENABLED
            && defined('UWC_SMTP_HOST')
            && trim((string) UWC_SMTP_HOST) !== ''
            && defined('UWC_SMTP_USERNAME')
            && trim((string) UWC_SMTP_USERNAME) !== ''
            && defined('UWC_SMTP_PASSWORD')
            && (string) UWC_SMTP_PASSWORD !== '';
    }

    function uwc_smtp_send(string $to, string $subject, string $message, string $headers): bool
    {
        $host = trim((string) UWC_SMTP_HOST);
        $port = defined('UWC_SMTP_PORT') ? (int) UWC_SMTP_PORT : 587;
        $encryption = defined('UWC_SMTP_ENCRYPTION') ? strtolower(trim((string) UWC_SMTP_ENCRYPTION)) : 'tls';
        $username = (string) UWC_SMTP_USERNAME;
        $password = (string) UWC_SMTP_PASSWORD;
        $timeout = defined('UWC_SMTP_TIMEOUT') ? (int) UWC_SMTP_TIMEOUT : 20;

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, $timeout);

        try {
            uwc_smtp_expect($socket, [220]);
            uwc_smtp_command($socket, 'EHLO united-wc.com', [250]);

            if ($encryption === 'tls') {
                uwc_smtp_command($socket, 'STARTTLS', [220]);
                $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('SMTP STARTTLS failed.');
                }
                uwc_smtp_command($socket, 'EHLO united-wc.com', [250]);
            }

            uwc_smtp_command($socket, 'AUTH LOGIN', [334]);
            uwc_smtp_command($socket, base64_encode($username), [334]);
            uwc_smtp_command($socket, base64_encode($password), [235]);

            $fromEmail = uwc_extract_email_from_headers($headers);
            if ($fromEmail === '') {
                $fromEmail = $username;
            }

            uwc_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            uwc_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            uwc_smtp_command($socket, 'DATA', [354]);

            $raw = uwc_build_raw_smtp_message($to, $subject, $message, $headers);
            fwrite($socket, $raw . "\r\n.\r\n");
            uwc_smtp_expect($socket, [250]);

            uwc_smtp_command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }

        return true;
    }

    function uwc_build_raw_smtp_message(string $to, string $subject, string $body, string $headers): string
    {
        $headerLines = [];
        $headerLines[] = 'To: ' . $to;
        $headerLines[] = 'Subject: ' . $subject;
        $headerLines[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
        $headerLines[] = 'X-Mailer: UWC PHP Mailer';

        $headers = trim($headers);
        if ($headers !== '') {
            foreach (preg_split("/\r\n|\n|\r/", $headers) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $headerLines[] = $line;
            }
        }

        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
        $bodyLines = explode("\n", $normalizedBody);
        foreach ($bodyLines as &$line) {
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line; // SMTP dot-stuffing
            }
        }
        unset($line);

        return implode("\r\n", $headerLines) . "\r\n\r\n" . implode("\r\n", $bodyLines);
    }

    function uwc_extract_email_from_headers(string $headers): string
    {
        foreach (preg_split("/\r\n|\n|\r/", $headers) as $line) {
            if (stripos($line, 'From:') === 0) {
                if (preg_match('/<([^>]+)>/', $line, $matches)) {
                    return trim($matches[1]);
                }

                $value = trim(substr($line, 5));
                return trim($value, " \t\"'");
            }
        }

        return '';
    }

    function uwc_smtp_command($socket, string $command, array $expectedCodes): string
    {
        fwrite($socket, $command . "\r\n");
        return uwc_smtp_expect($socket, $expectedCodes);
    }

    function uwc_smtp_expect($socket, array $expectedCodes): string
    {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            if (strlen($line) < 4) {
                continue;
            }

            $code = (int) substr($line, 0, 3);
            $continuation = $line[3] ?? ' ';
            if ($continuation === '-') {
                continue;
            }

            if (!in_array($code, $expectedCodes, true)) {
                throw new RuntimeException('SMTP unexpected response [' . $code . ']: ' . trim($response));
            }

            return $response;
        }

        throw new RuntimeException('SMTP connection closed unexpectedly.');
    }
}

