<?php
declare(strict_types=1);

namespace Wordless\Mcp;

/**
 * Réponses HTTP du connecteur : JSON (jamais mis en cache) et CORS ouvert sans
 * cookies (Access-Control-Allow-Origin: *), comme l'exigent les clients MCP
 * qui tournent dans un navigateur (MCP Inspector) ou sur les serveurs de
 * Claude.ai et ChatGPT.
 */
final class Response
{
    public const ALLOW_HEADERS = 'Authorization, Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID';
    public const EXPOSE_HEADERS = 'Mcp-Session-Id, WWW-Authenticate';

    public static function cors(string $methods = 'GET, POST, OPTIONS'): void
    {
        if (headers_sent()) {
            return;
        }
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: ' . $methods);
        header('Access-Control-Allow-Headers: ' . self::ALLOW_HEADERS);
        header('Access-Control-Expose-Headers: ' . self::EXPOSE_HEADERS);
        header('Access-Control-Max-Age: 86400');
    }

    /** Réponse JSON. $headers : en-têtes supplémentaires (« Nom: valeur »). */
    public static function json(int $status, mixed $data, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            foreach ($headers as $h) {
                header($h);
            }
        }
        echo self::encode($data);
    }

    /** Réponse sans corps (202, 204…). */
    public static function status(int $status, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: no-store');
            foreach ($headers as $h) {
                header($h);
            }
        }
    }

    public static function encode(mixed $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR;
        return (string) json_encode($data, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }
}
