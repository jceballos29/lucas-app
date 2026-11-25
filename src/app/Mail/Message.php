<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Arma el texto de un correo (cabeceras y cuerpo) según RFC 5322/2047/2045. Es PURO: no abre conexiones. Por eso lo difícil
 * (inyección de cabeceras, tildes en el asunto, el punto al inicio de línea) se prueba en unit.php sin un servidor de correo.
 */
final class Message
{
    /**
     * @param string $date Fecha RFC 2822 (`D, d M Y H:i:s O`); se pasa de fuera para poder probarlo.
     * @throws MailException Si una dirección o el asunto traen saltos de línea (inyección de cabeceras).
     */
    public static function build(string $from, string $fromName, string $to, string $subject, string $body, string $date, string $messageId): string
    {
        foreach (['remitente' => $from, 'nombre del remitente' => $fromName, 'destinatario' => $to, 'asunto' => $subject, 'identificador' => $messageId] as $campo => $valor) {
            // Un salto de línea en una cabecera permitiría añadir otras (Bcc: ...): el clásico «email header injection».
            if (preg_match('/[\r\n\0]/', $valor) === 1) {
                throw new MailException("El $campo no puede contener saltos de línea.");
            }
        }

        foreach (['remitente' => $from, 'destinatario' => $to] as $campo => $valor) {
            if (preg_match('/[<>\s]/', $valor) === 1 || filter_var($valor, FILTER_VALIDATE_EMAIL) === false) {
                throw new MailException("El $campo no es una dirección válida.");
            }
        }

        $headers = [
            'Date: ' . $date,
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        // Base64 en líneas de 76 caracteres: ningún carácter raro ni línea larga puede romper el protocolo.
        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n");
    }

    /** Un texto de cabecera con tildes o símbolos va codificado (`=?UTF-8?B?...?=`); si es ASCII simple, queda igual. */
    public static function encodeHeader(string $texto): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $texto) === 1 ? $texto : '=?UTF-8?B?' . base64_encode($texto) . '?=';
    }

    /**
     * El mensaje listo para la orden DATA de SMTP: fines de línea CRLF y, como una línea con solo un punto marca el final, las
     * líneas que EMPIEZAN con un punto llevan otro delante («dot-stuffing», RFC 5321).
     */
    public static function forData(string $message): string
    {
        $normalizado = str_replace(["\r\n", "\r"], "\n", $message);
        $stuffed = preg_replace('/^\./m', '..', $normalizado) ?? $normalizado;

        return str_replace("\n", "\r\n", rtrim($stuffed, "\n")) . "\r\n.\r\n";
    }
}
