<?php
// src/SimpleSMTP.php

class SimpleSMTP {
    private $host;
    private $port;
    private $username;
    private $password;
    private $timeout = 30;
    private $socket;

    public function __construct($host, $port, $username, $password) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    public function send($to, $subject, $htmlBody, $attachments = []) {
        // 1. Connect
        $protocol = ($this->port == 465) ? 'ssl://' : '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $this->socket = @stream_socket_client(
            $protocol . $this->host . ":" . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$this->socket) throw new Exception("Error al conectar al host SMTP $this->host: $errstr ($errno)");
        
        $this->readResponse(); // Read banner

        // 2. Handshake
        $this->sendCommand("EHLO " . gethostname());
        
        if ($this->port == 587) {
            $this->sendCommand("STARTTLS");
            // Use ANY_CLIENT to support TLS 1.2+ which is required by Gmail
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }

            if (!stream_socket_enable_crypto($this->socket, true, $cryptoMethod)) {
                throw new Exception("Fallo en la negociación TLS. Esto puede deberse a que PHP no confía en el certificado del servidor o a una versión de TLS no compatible.");
            }
            $this->sendCommand("EHLO " . gethostname());
        }

        // 3. Auth
        $this->sendCommand("AUTH LOGIN");
        $this->sendCommand(base64_encode($this->username));
        $this->sendCommand(base64_encode($this->password));

        // 4. Mail Envelope
        $this->sendCommand("MAIL FROM: <{$this->username}>");
        $this->sendCommand("RCPT TO: <$to>");
        $this->sendCommand("DATA");

        // 5. Build MIME Message
        $boundary = md5(time());
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "From: Kyvid Flow <{$this->username}>\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: $subject\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
        $headers .= "\r\n";

        $headers .= "--$boundary\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $headers .= chunk_split(base64_encode($htmlBody)) . "\r\n";

        foreach ($attachments as $att) {
            $headers .= "--$boundary\r\n";
            $headers .= "Content-Type: {$att['type']}; name=\"{$att['name']}\"\r\n";
            $headers .= "Content-Disposition: attachment; filename=\"{$att['name']}\"\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $headers .= chunk_split(base64_encode($att['content'])) . "\r\n";
        }

        $headers .= "--$boundary--\r\n";
        $headers .= "."; // End of Data

        // 6. Send Content
        fputs($this->socket, $headers . "\r\n");
        $this->readResponse();

        // 7. Quit
        $this->sendCommand("QUIT");
        @fclose($this->socket);

        return true;
    }

    private function sendCommand($cmd) {
        fputs($this->socket, $cmd . "\r\n");
        $res = $this->readResponse();
        // Basic check: 2xx or 3xx are usually ok
        if (!preg_match('/^[23]/', $res)) {
            throw new Exception("SMTP Command failed [$cmd]: $res");
        }
        return $res;
    }

    private function readResponse() {
        $response = "";
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (substr($line, 3, 1) == " ") break;
        }
        return $response;
    }
}
